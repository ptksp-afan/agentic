'use strict';

/**
 * ED-1150 prasyarat data (baca-saja, lewat HTTP), sebagai profil `sam` (QA_USER3), DB QA_DB. Dijalankan lewat
 * `qa/e2e/run-sam.sh --prereq` (env QA_SAM_USER/QA_SAM_PASS diisi wrapper). Memakai modul harness (login -> bind DB ->
 * GET -> log-out, pencabutan dicek). Tidak mencetak kredensial/token; tidak menulis DB.
 *
 * Prasyarat spec: root = 3 baris termasuk Folder A; Folder A = 2 baris (Folder B, Folder C); Folder B >= 10 baris
 * (398 dokumen). Keluaran terakhir = satu baris JSON {ok, ...}; exit 0 = terpenuhi, 1 = tidak (AC BLOCKED-by-data).
 */

const path = require('path');
const AGENTIC_DIR = path.resolve(__dirname, '..', '..', '..', '..');
const lib = (name) => require(path.join(AGENTIC_DIR, 'scripts', 'e2e', 'lib', name));
const config = lib('config');
const session = lib('session');
const { request } = lib('http');

const list = async (token, params) => {
  const qs = Object.entries(params).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
  const res = await request('GET', `document-archive/archives${qs ? `?${qs}` : ''}`, { token });
  return { status: res.status, result: (res.json && res.json.result) || {}, code: (res.json && (res.json.msg_code || res.json.code)) || '-' };
};
const nameOf = (r) => (Array.isArray(r.name) ? (r.name[0] || {}).transaction_no : r.name);

(async () => {
  const out = { ok: false, checks: [] };
  const add = (name, ok, detail) => out.checks.push({ name, ok, detail });
  const s = await session.openSession('sam');
  out.session = { userKey: s.userKey, db: s.dbName, notes: s.notes };
  let logout = null;
  try {
    const root = await list(s.token, { page: 1 });
    const rootRows = root.result.data || [];
    const folderA = rootRows.find((r) => nameOf(r) === 'Folder A');
    add('root HTTP 200', root.status === 200, { status: root.status, code: root.code });
    add('root = 3 baris', rootRows.length === 3 && root.result.total === 3, { rows: rootRows.map(nameOf), total: root.result.total });
    add('root memuat Folder A', Boolean(folderA), null);

    let folderB = null;
    let folderC = null;
    if (folderA) {
      const a = await list(s.token, { id_archive: folderA.id_archive, page: 1 });
      const aRows = a.result.data || [];
      folderB = aRows.find((r) => nameOf(r) === 'Folder B');
      folderC = aRows.find((r) => nameOf(r) === 'Folder C');
      add('Folder A HTTP 200', a.status === 200, { status: a.status, code: a.code });
      add('Folder A = 2 baris (Folder B, Folder C)', aRows.length === 2 && Boolean(folderB) && Boolean(folderC), { rows: aRows.map(nameOf), total: a.result.total });
    }
    if (folderB) {
      const b = await list(s.token, { id_archive: folderB.id_archive, page: 1 });
      const bRows = b.result.data || [];
      add('Folder B HTTP 200', b.status === 200, { status: b.status, code: b.code });
      add('Folder B >= 10 baris/halaman', bRows.length >= 10, { rows: bRows.length, total: b.result.total, per_page: b.result.per_page, last_page: b.result.last_page });
      const last = await list(s.token, { id_archive: folderB.id_archive, page: b.result.last_page || 1 });
      add('Folder B halaman terakhir ada', last.status === 200 && (last.result.data || []).length > 0, { page: b.result.last_page, rows: (last.result.data || []).length });
    } else {
      add('Folder B ditemukan', false, null);
    }
    out.ok = out.checks.every((c) => c.ok);
  } catch (e) {
    out.error = config.redact(e.message);
  } finally {
    try {
      logout = await session.logout(s.token);
    } catch (e) {
      logout = { error: config.redact(e.message) };
    }
    out.logout = logout;
  }
  console.log(config.redact(JSON.stringify(out, null, 2)));
  process.exit(out.ok && logout && logout.revoked ? 0 : 1);
})().catch((e) => {
  console.error(config.redact(e.message));
  process.exit(2);
});
