'use strict';

/**
 * Konfigurasi harness E2E. Urutan sama dengan scripts/lib/env.sh: config/workspace.env -> config/secrets.env ->
 * env proses (env proses yang tidak kosong menang). Juga penyimpan rahasia: semua teks yang keluar dari harness
 * (stdout, laporan JSON, pesan gagal) lewat `redact()`.
 *
 * Kunci yang dipakai: API_URL, FE_DIR, FE_STAGING_DIR, FE_SERVE_DIR, FE_SERVE_PORT, E2E_PORT, QA_DB, QA_DBS, PHP_BIN,
 * BE_DIR, CLIENT_ID, CLIENT_SECRET (opsional), QA_USER/QA_PASS, QA_<PROFIL>_USER/PASS, E2E_PROFILE_<profil>_DB.
 * Env per-run yang diisi run.sh: E2E_FEATURE_DIR, E2E_FEATURE, E2E_PROFILES, E2E_ALL_ROUTES, E2E_OUT_DIR.
 */

const fs = require('fs');
const path = require('path');

const E2E_DIR = path.resolve(__dirname, '..');
const AGENTIC_DIR = path.resolve(E2E_DIR, '..', '..');
const CONFIG_FILES = ['workspace.env', 'secrets.env'].map((f) => path.join(AGENTIC_DIR, 'config', f));

const DEFAULTS = {
  API_URL: 'http://127.0.0.1:8004',
  E2E_PORT: 4100,
  FE_SERVE_PORT: 4000,
  FE_SERVE_DIR: 'build',
};

let fileValues = null;
const secrets = new Set();

function parseEnvFile(file) {
  const values = {};
  if (!fs.existsSync(file)) return values;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/);
    if (!m) continue;
    let value = m[2].replace(/\s+#.*$/, '').trim(); // komentar di akhir baris
    if (value.length >= 2 && (value[0] === '"' || value[0] === "'") && value[value.length - 1] === value[0]) {
      value = value.slice(1, -1);
    }
    values[m[1]] = value;
  }
  return values;
}

function isSecretKey(key) {
  return /(PASS|SECRET|TOKEN)/i.test(key);
}

function load() {
  if (fileValues !== null) return;
  fileValues = Object.assign({}, ...CONFIG_FILES.map(parseEnvFile));
  const keys = new Set(Object.keys(fileValues));
  Object.keys(process.env).forEach((key) => {
    if (/^(QA_|E2E_|CLIENT_)/.test(key)) keys.add(key);
  });
  keys.forEach((key) => {
    if (isSecretKey(key)) addSecret(process.env[key] || fileValues[key]);
  });
}

/** Nilai config; env proses (tidak kosong) menang atas berkas. */
function get(key, def = null) {
  load();
  const env = process.env[key];
  if (env !== undefined && env !== '') return env;
  const value = fileValues[key];
  return value !== undefined && value !== '' ? value : def;
}

/** Nilai pertama yang terisi dari daftar kunci. */
function getFirst(keys, def = null) {
  for (const key of keys) {
    const value = get(key);
    if (value) return value;
  }
  return def;
}

/** Semua nilai berkas config (tanpa env proses): untuk perintah fixture yang dijalankan harness. */
function fileEnv() {
  load();
  return { ...fileValues };
}

/** Nama kunci di berkas config (tanpa nilai). */
function fileKeys() {
  load();
  return Object.keys(fileValues);
}

function addSecret(value) {
  if (typeof value === 'string' && value.length >= 4) secrets.add(value);
}

/** Samarkan rahasia yang dikenal + pola JWT / Bearer / field rahasia di JSON / query. */
function redact(input) {
  load();
  let text = String(input === undefined ? '' : input);
  [...secrets]
    .sort((a, b) => b.length - a.length)
    .forEach((secret) => {
      text = text.split(secret).join('***');
    });
  text = text.replace(/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/g, '***');
  text = text.replace(/(Bearer\s+)[A-Za-z0-9._~+/=-]+/gi, '$1***');
  text = text.replace(
    /"(access_?token|refresh_?token|client_?secret|password|token|secret|key|secret_?code|secret_?key|approval_?password|db_?pass)"\s*:\s*"[^"]*"/gi,
    '"$1":"***"',
  );
  return text;
}

/** Nama parameter query yang nilainya disamarkan di laporan. */
const SECRET_QUERY = /(token|secret|password|passwd|pass|key|signature|code|auth)/i;

/** URL untuk laporan: query rahasia disamarkan, nilai panjang dipotong. */
function safeUrl(raw) {
  try {
    const url = new URL(raw);
    for (const [name, value] of [...url.searchParams.entries()]) {
      if (SECRET_QUERY.test(name)) url.searchParams.set(name, '***');
      else if (value.length > 120) url.searchParams.set(name, `${value.slice(0, 40)}...`);
    }
    return redact(url.toString());
  } catch (e) {
    return redact(String(raw).split('?')[0]);
  }
}

const norm = (p) => path.resolve(String(p)).replace(/[\\/]+$/, '').toLowerCase();

function apiBase() {
  return String(get('API_URL', DEFAULTS.API_URL)).replace(/\/+$/, '');
}

function feDir() {
  return get('FE_DIR');
}

/** Build FE staging yang dilayani harness. Tidak boleh sama dengan <FE_DIR>/<FE_SERVE_DIR> milik developer. */
function stagingDir() {
  const dir = get('FE_STAGING_DIR');
  if (!dir) throw new Error('FE_STAGING_DIR belum diisi di config/workspace.env');
  if (feDir() && norm(dir) === norm(path.join(feDir(), get('FE_SERVE_DIR', DEFAULTS.FE_SERVE_DIR)))) {
    throw new Error(`FE_STAGING_DIR (${dir}) sama dengan build/ milik developer. Pakai folder staging terpisah.`);
  }
  return dir;
}

/** Port server statis harness; port serve developer (FE_SERVE_PORT, 4000) dilarang. */
function port() {
  const p = parseInt(get('E2E_PORT', String(DEFAULTS.E2E_PORT)), 10);
  const forbidden = [DEFAULTS.FE_SERVE_PORT, parseInt(get('FE_SERVE_PORT', '0'), 10)];
  if (forbidden.includes(p)) {
    throw new Error(`E2E_PORT=${p} dilarang: port itu dipakai developer (FE_SERVE_PORT). Pakai port lain (default ${DEFAULTS.E2E_PORT}).`);
  }
  return p;
}

function appBase() {
  return `http://127.0.0.1:${port()}`;
}

/** `.env` FE (read-only): client id/secret dan base path API yang ikut ter-build. */
function feEnv() {
  return feDir() ? parseEnvFile(path.join(feDir(), '.env')) : {};
}

function apiBasePath() {
  return String(get('E2E_API_BASEPATH', feEnv().REACT_APP_API_BASEPATH || 'api/v5')).replace(/^\/+|\/+$/g, '');
}

/** Folder fitur (absolut) dari run.sh; null bila run tanpa fitur (mis. --all-routes saja). */
function featureDir() {
  const dir = process.env.E2E_FEATURE_DIR;
  return dir ? path.resolve(dir) : null;
}

/** <feature-dir>/qa/e2e bila ada. */
function featureE2eDir() {
  const dir = featureDir();
  const e2e = dir && path.join(dir, 'qa', 'e2e');
  return e2e && fs.existsSync(e2e) ? e2e : null;
}

module.exports = {
  E2E_DIR,
  AGENTIC_DIR,
  CONFIG_FILES,
  DEFAULTS,
  get,
  getFirst,
  fileEnv,
  fileKeys,
  isSecretKey,
  addSecret,
  redact,
  safeUrl,
  apiBase,
  apiBasePath,
  feDir,
  feEnv,
  stagingDir,
  port,
  appBase,
  featureDir,
  featureE2eDir,
};
