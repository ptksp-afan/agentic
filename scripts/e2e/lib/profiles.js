'use strict';

/**
 * Profil sesi, ditentukan config (tidak ada profil bawaan selain `default`). Satu profil = satu user + satu DB,
 * satu token per run (dibuat global setup, di-log-out global teardown). Nama profil = nama project Playwright =
 * tag di judul test (`[default]`, `[reguler]`, dst.).
 *
 *   default  user QA_USER/QA_PASS          DB QA_DB
 *   user2    user QA_USER2/QA_PASS2        DB QA_DB   (user berhak lebih sedikit, uji permission)
 *   <nama>   user QA_<NAMA>_USER/_PASS     DB E2E_PROFILE_<nama>_DB -> PROFILE_<nama>_DB -> QA_DB
 *
 * <NAMA> = nama profil huruf besar, karakter selain A-Z0-9 menjadi `_`. Kredensial kosong -> profil di-SKIP.
 */

const config = require('./config');

const NAME_RE = /^[A-Za-z][A-Za-z0-9_-]*$/;

const upper = (name) => name.toUpperCase().replace(/[^A-Z0-9]/g, '_');

function credentialKeys(name) {
  if (name === 'default') return ['QA_USER', 'QA_PASS'];
  if (name === 'user2') return ['QA_USER2', 'QA_PASS2'];
  return [`QA_${upper(name)}_USER`, `QA_${upper(name)}_PASS`];
}

/** DB tenant profil ini; null bila tidak ada config (sesi memakai satu-satunya DB milik user). */
function dbOf(name) {
  const keys = [];
  [name, upper(name)].forEach((n) => keys.push(`E2E_PROFILE_${n}_DB`, `PROFILE_${n}_DB`));
  return config.getFirst(keys, config.get('QA_DB'));
}

/** Profil yang dipakai run ini: env `E2E_PROFILES` (koma), default `default`. */
function selectedProfiles() {
  const names = String(process.env.E2E_PROFILES || process.env.E2E_PROFILE || 'default')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  const bad = names.filter((n) => !NAME_RE.test(n));
  if (bad.length) throw new Error(`Nama profil tidak valid: ${bad.join(', ')} (huruf, angka, _ atau -; diawali huruf)`);
  return [...new Set(names.length ? names : ['default'])];
}

/** Profil -> {name, db, userKey, passKey, user, pass}; user/pass null bila belum diisi. */
function profileOf(name) {
  const [userKey, passKey] = credentialKeys(name);
  const user = config.get(userKey);
  const pass = config.get(passKey);
  return { name, db: dbOf(name), userKey, passKey, user, pass, hasCredentials: Boolean(user && pass) };
}

function credentialKeyHint(name) {
  return credentialKeys(name).join('/');
}

/** DB boleh di-bind QA? QA_DBS kosong = tidak dibatasi. */
function dbAllowed(dbName) {
  const list = String(config.get('QA_DBS', ''))
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  return !list.length || list.includes(dbName);
}

module.exports = { NAME_RE, selectedProfiles, profileOf, credentialKeyHint, dbAllowed };
