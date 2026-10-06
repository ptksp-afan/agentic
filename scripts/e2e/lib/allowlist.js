'use strict';

/**
 * Allow-list error yang sudah diketahui dan terdokumentasi (pre-existing), supaya tidak menggagalkan
 * smoke. Sumber, berurutan: `scripts/e2e/allowlist.json` (global), `<feature-dir>/qa/e2e/allowlist.json`
 * (semua test fitur itu), `allow` di routes.json dan `test.use({ allow: [...] })` di spec.
 * Error yang cocok tetap dicatat di laporan (`allowed: "<id>"`), hanya tidak menggagalkan test.
 * Hanya untuk error LAMA yang bukan buatan fitur ini; error baru dari fitur harus diperbaiki, bukan di-allow.
 *
 * Bentuk entri (id, kind, match, reason, ref WAJIB):
 *   {
 *     "id": "ws-6001",                       // unik
 *     "kind": "console",                     // console | pageerror | response | requestfailed | timeout | *
 *     "match": "WebSocket connection to",    // regex; console/pageerror/timeout: teks; response/requestfailed: "METHOD url status"
 *     "status": 404,                         // opsional (response)
 *     "profiles": ["default"],               // opsional
 *     "paths": ["/setups/companies"],        // opsional, prefix path route FE
 *     "reason": "kenapa boleh",
 *     "ref": "ED-123",                       // tiket Jira (atau spec) yang mencatat error ini
 *     "since": "2026-10-03"                  // opsional
 *   }
 */

const fs = require('fs');
const path = require('path');

const config = require('./config');

const GLOBAL_FILE = path.join(config.E2E_DIR, 'allowlist.json');

function readEntries(file) {
  if (!file || !fs.existsSync(file)) return [];
  const json = JSON.parse(fs.readFileSync(file, 'utf8'));
  return Array.isArray(json) ? json : json.entries || [];
}

function validate(entries, source) {
  entries.forEach((e, i) => {
    if (!e.id || !e.kind || !e.match || !e.reason || !e.ref) {
      throw new Error(`allow-list ${source} entri #${i + 1}: id, kind, match, reason, ref (tiket) wajib diisi`);
    }
    // regex rusak harus ketahuan saat load, bukan diam-diam tidak cocok
    // eslint-disable-next-line no-new
    new RegExp(e.match);
  });
  return entries.map((e) => ({ ...e, source }));
}

function load(extra = []) {
  const dir = config.featureE2eDir();
  return [
    ...validate(readEntries(GLOBAL_FILE), 'allowlist.json'),
    ...validate(readEntries(dir && path.join(dir, 'allowlist.json')), 'fitur allowlist.json'),
    ...validate(extra, 'fitur (routes.json/spec)'),
  ];
}

/** Teks yang dicocokkan untuk satu error yang terkumpul. */
function subject(error) {
  if (error.kind === 'response' || error.kind === 'requestfailed') {
    return `${error.method || ''} ${error.url || ''} ${error.status || error.text || ''}`.trim();
  }
  return error.text || '';
}

/** @returns {object|null} entri allow-list yang cocok */
function match(entries, error, { profile, path: routePath }) {
  return (
    entries.find((e) => {
      if (e.kind !== '*' && e.kind !== error.kind) return false;
      if (e.status && Number(e.status) !== Number(error.status)) return false;
      if (e.profiles && !e.profiles.includes(profile)) return false;
      if (e.paths && !e.paths.some((p) => routePath === p || String(routePath || '').startsWith(`${p}/`))) return false;
      return new RegExp(e.match).test(subject(error));
    }) || null
  );
}

module.exports = { load, match, GLOBAL_FILE };
