'use strict';

/**
 * Login lewat API + isi localStorage yang sama dengan yang ditulis UI sign-in FE
 * (`containers/SignIn/signIn.function.js`, `helpers/authHelper.js`, `utils/storageKey.js`):
 *
 *   server           "http://127.0.0.1:8004"        (utils/request.js apiBaseUrl = server + REACT_APP_API_BASEPATH)
 *   bearer           {"accessToken": "<jwt>"}        (saveToken)
 *   databases        [{dbName, alias, isGlobal}]     (saveUserDatabases, respons GET auth/database di-camelCase)
 *   selectedDatabase {dbName, alias, isGlobal}       (saveSelectedDatabase sesudah PUT auth/database)
 *   wsHost/wsPort/secretKey                          (saveWebsocket dari websocket_conf respons login)
 *
 * Urutan API sama dengan UI: POST auth/token -> GET auth/database -> PUT auth/database. Login dan bind
 * berurutan per profil, karena bind mencabut token user itu yang belum bernama. Bind dicoba tanpa
 * force_login dulu; bila user sudah punya sesi di DB itu (GS0500) diulang dengan force_login=1 (diizinkan
 * developer untuk DB lokal) dan dicatat di ringkasan. E2E_FORCE_LOGIN=0 mematikannya.
 *
 * Token hanya di memori: env proses (diwariskan ke worker Playwright) dan storageState context
 * browser. Tidak pernah ditulis ke berkas atau dicetak. Tidak pernah menulis `oauth_access_tokens`.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const config = require('./config');
const { request, msgCode } = require('./http');
const { profileOf, credentialKeyHint, dbAllowed } = require('./profiles');

const ENV_PREFIX = 'E2E_SESSION_';

class SessionSkip extends Error {}

let dbSecretCache = null;

function clientId() {
  return parseInt(config.get('CLIENT_ID', config.feEnv().REACT_APP_CLIENT_ID || '4'), 10);
}

/** Secret client OAuth: CLIENT_SECRET (config) -> REACT_APP_CLIENT_SECRET di .env FE (yang ikut ter-build). */
function clientSecret() {
  const value = config.get('CLIENT_SECRET', config.feEnv().REACT_APP_CLIENT_SECRET || null);
  config.addSecret(value);
  return value;
}

/**
 * Cadangan: secret dari `oauth_clients` DB default server BE (read-only, PHP_BIN + BE_DIR), dipakai hanya bila
 * secret dari .env FE ditolak (invalid_client). Hasil tidak pernah dicetak. null bila BE_DIR/PHP_BIN tidak ada.
 */
function clientSecretFromDb() {
  if (dbSecretCache !== null) return dbSecretCache || null;
  dbSecretCache = '';
  const beDir = config.get('BE_DIR');
  const php = config.get('PHP_BIN');
  if (!beDir || !php || !fs.existsSync(path.join(beDir, 'vendor', 'autoload.php'))) return null;
  try {
    dbSecretCache = execFileSync(php, [path.join(__dirname, 'oauth-client-secret.php'), String(clientId()), beDir], {
      cwd: beDir,
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 60000,
    })
      .toString()
      .trim();
  } catch (e) {
    dbSecretCache = '';
  }
  config.addSecret(dbSecretCache);
  return dbSecretCache || null;
}

function camelKey(key) {
  if (key.includes('-')) return key;
  const trailing = key.endsWith('_');
  const base = trailing ? key.slice(0, -1) : key;
  const camel = base.toLowerCase().replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase());
  return trailing ? `${camel}_` : camel;
}

/** Sama dengan `convertKeysToCamelCase` di FE `utils/request.js` (cukup untuk objek DB). */
function camelize(value) {
  if (Array.isArray(value)) return value.map(camelize);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.entries(value).map(([k, v]) => [camelKey(k), camelize(v)]));
}

function jti(token) {
  try {
    const payload = JSON.parse(Buffer.from(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString());
    return payload.jti || null;
  } catch (e) {
    return null;
  }
}

async function postToken(profile, secret) {
  return request('POST', 'auth/token', {
    body: {
      username: profile.user,
      password: profile.pass,
      grant_type: 'password',
      client_id: clientId(),
      client_secret: secret,
    },
  });
}

async function login(profile) {
  let res = await postToken(profile, clientSecret());
  let token = res.json && res.json.result && res.json.result.access_token;
  if (!token && /invalid_client/.test(res.text || '')) {
    // secret build FE tidak cocok dengan server -> coba secret dari DB BE (read-only), bila tersedia
    const fallback = clientSecretFromDb();
    if (fallback) {
      res = await postToken(profile, fallback);
      token = res.json && res.json.result && res.json.result.access_token;
    }
  }
  if (!token) {
    const fields = res.json && res.json.errors ? ` (validasi: ${Object.keys(res.json.errors).join(', ')})` : '';
    const message = res.json && res.json.message ? `, pesan: ${String(res.json.message).slice(0, 160)}` : '';
    throw new Error(config.redact(`login ${profile.userKey} gagal: HTTP ${res.status}, kode ${msgCode(res)}${fields}${message}`));
  }
  config.addSecret(token);
  config.addSecret(res.json.result.refresh_token);
  return { token, websocket: res.json.result.websocket_conf || {} };
}

async function logout(token) {
  const res = await request('GET', 'auth/log-out', { token, timeout: 30000 });
  // verifikasi lewat HTTP saja: token yang sudah dicabut -> 401 di auth/info
  const check = await request('GET', 'auth/info', { token, timeout: 30000 });
  return { status: res.status, revoked: check.status === 401 };
}

const notAllowedDb = (name, db) => `profil ${name}: DB ${db} tidak ada di QA_DBS (config/workspace.env); tambahkan bila memang boleh di-bind QA`;

/**
 * Buka sesi satu profil. Melempar SessionSkip (dengan pesan jelas) bila profil tidak bisa dijalankan
 * di lingkungan ini; token yang sempat dibuat selalu di-log-out sebelum melempar.
 */
async function openSession(profileName) {
  const profile = profileOf(profileName);
  if (!profile.hasCredentials) {
    throw new SessionSkip(`profil ${profileName}: kredensial ${credentialKeyHint(profileName)} tidak diisi di config/secrets.env`);
  }
  if (profile.db && !dbAllowed(profile.db)) throw new SessionSkip(notAllowedDb(profileName, profile.db));

  const { token, websocket } = await login(profile);
  const notes = [];
  try {
    const dbRes = await request('GET', 'auth/database', { token });
    if (dbRes.status !== 200 || !dbRes.json || !dbRes.json.result) {
      throw new Error(`GET auth/database: HTTP ${dbRes.status}, kode ${msgCode(dbRes)}`);
    }
    const databases = dbRes.json.result.databases || [];

    let dbName = profile.db;
    if (!dbName) {
      // alur FE: satu DB -> langsung dipilih tanpa layar pilih DB
      if (databases.length !== 1) {
        throw new Error(
          `profil ${profileName}: user punya ${databases.length} DB, isi QA_DB atau E2E_PROFILE_${profileName}_DB (pilihan: ${databases
            .map((d) => d.db_name)
            .join(', ')})`,
        );
      }
      dbName = databases[0].db_name;
      if (!dbAllowed(dbName)) throw new SessionSkip(notAllowedDb(profileName, dbName));
    }
    const database = databases.find((d) => d.db_name === dbName);
    if (!database) {
      throw new Error(`profil ${profileName}: user ${profile.userKey} tidak punya akses aktif ke DB ${dbName}`);
    }

    let bind = await request('PUT', 'auth/database', { token, body: { db_name: dbName, force_login: 0 } });
    const bound = (r) => r.status === 200 && r.json && r.json.result && r.json.result.database && r.json.result.database.db_name === dbName;
    if (!bound(bind) && msgCode(bind) === 'GS0500') {
      if (config.get('E2E_FORCE_LOGIN', '1') === '0') {
        throw new Error(`profil ${profileName}: user ${profile.userKey} sudah punya sesi di ${dbName} (GS0500) dan E2E_FORCE_LOGIN=0`);
      }
      notes.push(`sesi lain ${profile.userKey} di ${dbName} dicabut (force_login=1)`);
      bind = await request('PUT', 'auth/database', { token, body: { db_name: dbName, force_login: 1 } });
    }
    if (!bound(bind)) {
      const code = msgCode(bind);
      const hint = code === 'GE0106' ? ' (batas online user DB itu penuh)' : '';
      throw new Error(`profil ${profileName}: PUT auth/database ${dbName} gagal: HTTP ${bind.status}, kode ${code}${hint}`);
    }

    const infoRes = await request('GET', 'auth/info', { token });
    if (infoRes.status !== 200 || !infoRes.json || !infoRes.json.result) {
      throw new Error(`profil ${profileName}: GET auth/info: HTTP ${infoRes.status}, kode ${msgCode(infoRes)}`);
    }
    const info = infoRes.json.result;

    return {
      profile: profileName,
      userKey: profile.userKey,
      dbName,
      token,
      jti: jti(token),
      databases,
      database,
      websocket: { host: websocket.host, port: websocket.port, key: websocket.key },
      notes,
      // ringkasan auth/info saja (tanpa permissions/secret_code), untuk spec dan laporan
      info: {
        equalIntegration: Number(info.equal_integration || 0),
        selectedDatabase: info.selected_database || null,
        isGlobalUser: Number(info.is_global || 0),
        companyName: (info.company && info.company.company_name) || null,
        idCompany: (info.company && info.company.id_company) || null,
        roles: (info.roles || []).map((r) => ({
          idRole: r.id_role,
          isSuperadmin: r.is_superadmin,
          isActive: r.is_active,
        })),
        permissionCount: Array.isArray(info.permissions) ? info.permissions.length : null,
      },
    };
  } catch (err) {
    await logout(token).catch(() => null);
    throw err;
  }
}

/** storageState Playwright (objek di memori, bukan berkas) yang meniru hasil sign-in UI. */
function storageStateFor(session, appOrigin) {
  const local = {
    server: config.apiBase(),
    bearer: { accessToken: session.token },
    databases: camelize(session.databases),
    selectedDatabase: camelize(session.database),
    wsHost: session.websocket.host,
    wsPort: session.websocket.port,
    secretKey: session.websocket.key,
  };
  return {
    cookies: [],
    origins: [
      {
        origin: appOrigin,
        localStorage: Object.entries(local)
          .filter(([, v]) => v !== undefined && v !== null)
          .map(([name, value]) => ({ name, value: JSON.stringify(value) })),
      },
    ],
  };
}

const envKey = (profileName) => ENV_PREFIX + profileName.toUpperCase().replace(/[^A-Z0-9]/g, '_');

/** Sesi ke env proses supaya worker Playwright mewarisinya (global setup -> worker), tanpa berkas. */
function exportSession(session) {
  process.env[envKey(session.profile)] = JSON.stringify(session);
}

function exportSkip(profileName, reason) {
  process.env[envKey(profileName)] = JSON.stringify({ profile: profileName, skip: reason });
}

/** Di worker: sesi profil (atau {skip}). */
function importSession(profileName) {
  const raw = process.env[envKey(profileName)];
  if (!raw) return { profile: profileName, skip: `sesi ${profileName} tidak disiapkan global setup (E2E_PROFILES?)` };
  const session = JSON.parse(raw);
  if (session.token) config.addSecret(session.token);
  return session;
}

/** Ringkasan aman untuk dicetak / laporan. */
function describe(session) {
  if (session.skip) return { profile: session.profile, skip: session.skip };
  return {
    profile: session.profile,
    user_key: session.userKey,
    db: session.dbName,
    equal_integration: session.info.equalIntegration,
    role_levels: [...new Set(session.info.roles.filter((r) => r.isActive !== 0).map((r) => r.isSuperadmin))],
    company: session.info.companyName,
    notes: session.notes,
  };
}

module.exports = {
  SessionSkip,
  openSession,
  logout,
  storageStateFor,
  exportSession,
  exportSkip,
  importSession,
  describe,
  camelize,
};
