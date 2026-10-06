'use strict';

/**
 * Daftar route per fitur (`<feature-dir>/qa/e2e/routes.json`) dan daftar "semua route" untuk mode --all-routes,
 * diturunkan dari sumber FE (read-only): `<FE_DIR>/src/routes/paths.js`.
 *
 * routes.json:
 *   { "routes": [ { "name", "path" (dari paths.js), "profiles"?: [..] (default: semua profil run ini),
 *                   "waitFor"?: selector yang wajib tampil, "expectRedirect"?: "/unathorized",
 *                   "forbidApi"?: regex URL API yang tidak boleh terpanggil, "ref"?: acuan spec/AC } ],
 *     "allow": [ ...entri allow-list, lihat lib/allowlist.js ] }
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const config = require('./config');
const { selectedProfiles } = require('./profiles');

/** Path FE yang bukan layar aplikasi (atau tidak bisa dibuka tanpa parameter). */
const EXCLUDED = new Set(['*', '/', '/unathorized', '/root', '/server-setting', '/sign-in', '/sign-up', '/eq']);

let pathsCache = null;

/** Nilai string dari `export default paths` di src/routes/paths.js; null bila FE_DIR/berkasnya tidak ada. */
function allPaths() {
  if (pathsCache) return pathsCache;
  const file = config.feDir() && path.join(config.feDir(), 'src', 'routes', 'paths.js');
  if (!file || !fs.existsSync(file)) return null;
  const src = fs.readFileSync(file, 'utf8');
  const paths = vm.runInNewContext(`${src.replace(/export\s+default\s+paths\s*;?/, '')}\n;paths`, {});
  pathsCache = [...new Set(Object.values(paths))].filter((p) => typeof p === 'string');
  return pathsCache;
}

/** Semua path layar yang bisa dibuka tanpa parameter (mode --all-routes), urut alfabet. */
function allowedPaths() {
  const list = allPaths();
  if (!list) throw new Error(`--all-routes butuh ${config.feDir() || 'FE_DIR'}/src/routes/paths.js (FE_DIR belum diisi/ada?)`);
  const includeAdd = config.get('E2E_INCLUDE_ADD', '0') === '1';
  return list
    .filter((p) => !EXCLUDED.has(p) && !p.includes(':') && !p.includes('*'))
    .filter((p) => includeAdd || !/\/add$/.test(p))
    .sort();
}

/** Isi routes.json fitur (atau null bila tidak ada), sudah divalidasi. */
function loadFeature() {
  const dir = config.featureE2eDir();
  const file = dir && path.join(dir, 'routes.json');
  if (!file || !fs.existsSync(file)) return null;
  const json = JSON.parse(fs.readFileSync(file, 'utf8'));
  const known = allPaths();
  const where = path.basename(config.featureDir());
  (json.routes || []).forEach((r, i) => {
    const at = `${where}/qa/e2e/routes.json route #${i + 1}`;
    if (!r.path || !String(r.path).startsWith('/')) throw new Error(`${at}: path wajib diisi, diawali "/"`);
    if (r.path.includes(':')) throw new Error(`${at}: path ber-parameter (${r.path}) tidak bisa dibuka; ujikan lewat spec`);
    if (r.profiles !== undefined && (!Array.isArray(r.profiles) || !r.profiles.length)) {
      throw new Error(`${at}: profiles harus array tidak kosong (atau hapus untuk memakai semua profil run)`);
    }
    // salah ketik path ketahuan di sini, bukan sebagai layar kosong
    if (known && !known.includes(r.path)) throw new Error(`${at}: path ${r.path} tidak ada di src/routes/paths.js FE`);
  });
  return { feature: json.feature || where, ...json, routes: json.routes || [], allow: json.allow || [] };
}

/** Profil yang menjalankan route ini: irisan `route.profiles` dengan profil run. */
function profilesOf(route) {
  const selected = selectedProfiles();
  return route.profiles ? route.profiles.filter((p) => selected.includes(p)) : selected;
}

module.exports = { allPaths, allowedPaths, loadFeature, profilesOf };
