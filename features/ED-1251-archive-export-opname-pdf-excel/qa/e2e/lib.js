'use strict';

/** Pembantu bersama spec e2e ED-1251 (BUKAN spec: nama berkas tidak berakhiran .spec.js). */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { expect, config } = require('e2e-harness');

// ------------------------------------------------------------------------------------------------ pembantu fixture / berkas
const fixture = (...args) => execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), ...args], { encoding: 'utf8', timeout: 90000 });
const lastJson = (out) => JSON.parse(out.trim().split('\n').pop());
const fx = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));
const own = () => lastJson(fixture('own'));
const DL_DIR = path.join(config.AGENTIC_DIR, 'work', 'ed1251-fe', 'dl', new Date().toISOString().replace(/[:.]/g, '-'));
const save = (name, buf) => {
  fs.mkdirSync(DL_DIR, { recursive: true });
  const file = path.join(DL_DIR, name);
  fs.writeFileSync(file, buf);
  return file;
};
const LIGATURES = { 'ﬀ': 'ff', 'ﬁ': 'fi', 'ﬂ': 'fl', 'ﬃ': 'ffi', 'ﬄ': 'ffl', 'ﬅ': 'st', 'ﬆ': 'st' };
const norm = (s) => String(s).replace(/[ﬀ-ﬆ]/g, (c) => LIGATURES[c]).replace(/\s+/g, ' ');
const pdfOf = (buf, name) => {
  const info = lastJson(fixture('pdf', save(name, buf)));
  if (info.ok) {
    info.text = norm(info.text);
    info.ptexts = (info.ptexts || []).map(norm);
  }
  return info;
};
const xlsxOf = (buf, name) => {
  const info = lastJson(fixture('xlsx', save(name, buf)));
  if (info.ok) info.cells = Object.values(info.rows).flatMap((r) => Object.values(r)).map(norm);
  return info;
};
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const trimAll = (arr) => arr.map((t) => t.replace(/\s+/g, ' ').trim());
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const LANG = {
  id: {
    link: 'Lihat detail per tipe transaksi', cta: 'Lihat semua riwayat', resultTitle: 'Hasil Opname', verif: 'Verifikasi', view: /^Lihat$/, dv: 'Dokumen Terverifikasi',
    customize: 'Sesuaikan Template Cetak', save: 'Simpan', download: 'Unduh', customizeBtn: 'Sesuaikan', pdfReportName: 'Opname Arsip', by: 'Diopname Oleh',
    sumTitle: 'Ringkasan', detTitle: 'Rincian', time: 'Waktu', invalid: 'Tidak Valid', sumCols: ['Total Dokumen', 'Terverifikasi', 'Tidak Ditemukan', 'Tidak Valid', 'Belum Discan'],
    detCols: ['No. Dokumen', 'Tipe Transaksi', 'Folder', 'Waktu', 'Hasil'],
  },
  en: {
    link: 'See detail per transaction type', cta: 'See all history', resultTitle: 'Opname Result', verif: 'Verification', view: /^View$/, dv: 'Document Verified',
    customize: 'Customize Print Template', save: 'Save', download: 'Download', customizeBtn: 'Customize', pdfReportName: 'Opname Archive', by: 'Opname By',
    sumTitle: 'Summaries', detTitle: 'Detail', time: 'Time', invalid: 'Invalid', sumCols: ['Total Documents', 'Verified', 'Not Found', 'Invalid', 'Not Scanned'],
    detCols: ['Document No.', 'Transaction Type', 'Folder', 'Time', 'Result'],
  },
};

// ------------------------------------------------------------------------------------------------ pembantu layar (pola ED-1028 e2e)
const OPNAMES_RE = /\/document-archive\/opnames(\?|$)/;
const waitOpnames = (page, pred) =>
  page.waitForResponse((r) => r.request().method() === 'GET' && OPNAMES_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 });
const waitList = (page) => page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives(\?|$)/.test(r.url()), { timeout: 45000 });
const modalVisible = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
const drawerOpen = (page) => page.locator('.ant-drawer-open').last();
const hist = (page) => page.locator('.archive-opname-history.ant-drawer-open').first();
const resDrawer = (page) => hist(page).locator('.ant-drawer.ant-drawer-open:has(.ant-card)').first();
const pdfDrawer = (page) => page.locator('.drawer-pdf.ant-drawer-open').first();
const customizeDrawer = (page) => page.locator('.drawer-customize.ant-drawer-open').first();
const histTable = (page) => hist(page).locator('.ant-table').first();
const histRowsLoc = (page) => histTable(page).locator('tbody tr.ant-table-row');
const searchInput = (page) => hist(page).locator('.search-bar input').first();
const rowOf = (page, name) => page.locator('.archive-page .ant-table-row').filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) }).first();

/** baris sesi S1/S2 di tabel Opname History lewat nama folder di kolom Scope */
const sessionRow = (page, key) => histRowsLoc(page).filter({ hasText: key === 'S1' ? fx().folder_names.F : fx().folder_names.G }).first();
const actionCell = (row) => row.locator('td').last();
const printBtn = (row) => actionCell(row).getByRole('button', { name: 'Print Preview', exact: true });
const excelBtn = (row) => actionCell(row).getByRole('button', { name: 'Excel', exact: true });
const idOf = (key) => fx().ids[key];
const reUrl = (id, tail) => new RegExp(`/document-archive/opnames/${id}/${tail}(\\?|$)`);

async function pdfWorker(page) {
  const file = path.join(config.get('FE_DIR'), 'node_modules', 'pdfjs-dist', 'build', 'pdf.worker.min.js');
  await page.route('https://unpkg.com/pdfjs-dist@3.0.279/build/pdf.worker.min.js', (route) =>
    route.fulfill({ status: 200, path: file, contentType: 'application/javascript', headers: { 'access-control-allow-origin': '*' } }),
  );
}

async function openArchive(app, page) {
  await pdfWorker(page);
  const list = waitList(page);
  await app.open('/archives', { waitFor: '.archive-page .ant-table' });
  await list;
  await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
}

async function openModal(app, page, lang = 'id') {
  const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
  await page.locator('.archive-page').getByText(LANG[lang].link, { exact: false }).first().click();
  await resp;
  const modal = modalVisible(page);
  await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  await app.settle();
  return modal;
}

/** buka drawer Opname History dari modal All Archive (modal 05) */
async function openHistoryFromModal(app, page, lang = 'id') {
  const modal = await openModal(app, page, lang);
  const respP = waitOpnames(page);
  await modal.getByText(LANG[lang].cta).first().click();
  await respP;
  await hist(page).waitFor({ state: 'visible', timeout: 15000 });
  await sessionRow(page, 'S1').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(600);
}

async function pickRowMenu(page, name, itemRegex) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  await row.locator('button.btn-action').click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
}

/** drawer folder (Lihat) -> tab Verification -> CTA -> drawer riwayat folder */
async function openFolderHistoryFromTab(app, page, name, lang = 'id') {
  await pickRowMenu(page, name, LANG[lang].view);
  const dr = drawerOpen(page);
  await dr.waitFor({ state: 'visible', timeout: 15000 });
  await dr.locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  const tabs = trimAll(await dr.locator('.ant-tabs-tab').allInnerTexts());
  const idx = tabs.indexOf(LANG[lang].verif);
  expect(idx, `tab ${LANG[lang].verif} ada (tab: ${JSON.stringify(tabs)})`).toBeGreaterThan(-1);
  const vresp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\/[^/?]+/.test(r.url()), { timeout: 30000 });
  await dr.locator('.ant-tabs-tab').nth(idx).click();
  await vresp;
  const pane = dr.locator('.ant-tabs-tabpane-active');
  await pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  const respP = waitOpnames(page);
  await pane.getByText(LANG[lang].cta).first().click();
  await respP;
  await hist(page).waitFor({ state: 'visible', timeout: 15000 });
  await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(600);
}

/** klik nilai Document Verified (V/T) baris folder -> modal verifikasi folder -> CTA -> riwayat folder */
async function openFolderHistoryFromModal(app, page, name, lang = 'id') {
  const heads = trimAll(await page.locator('.archive-page .ant-table-thead th').allInnerTexts());
  const idx = heads.indexOf(LANG[lang].dv);
  expect(idx, `kolom "${LANG[lang].dv}" ada (header: ${JSON.stringify(heads)})`).toBeGreaterThanOrEqual(0);
  const link = rowOf(page, name).locator('td').nth(idx).locator('button');
  await expect(link, `${name}: tautan V/T ada`).toHaveCount(1);
  const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\/[^/?]+/.test(r.url()), { timeout: 30000 });
  await link.click();
  expect((await resp).status(), 'GET verifications/{id} 200').toBe(200);
  const modal = modalVisible(page);
  await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  const respP = waitOpnames(page);
  await modal.getByText(LANG[lang].cta).first().click();
  await respP;
  await hist(page).waitFor({ state: 'visible', timeout: 15000 });
  await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(600);
}

/** id DOM ganda di seluruh dokumen (hanya id yang tampil lebih dari sekali) */
const duplicateIds = (page) =>
  page.evaluate(() => {
    const seen = {};
    document.querySelectorAll('[id]').forEach((e) => {
      seen[e.id] = (seen[e.id] || 0) + 1;
    });
    return Object.entries(seen).filter(([, n]) => n > 1).map(([id, n]) => `${id} x${n}`);
  });

/** buang dari monitor penolakan yang SENGAJA dibuat (intercept), setelah memastikan terjadi */
function expectRejected(monitor, label, { status, urlRe }) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === status && urlRe.test(decodeURIComponent(e.url))) ||
    (e.kind === 'console' && new RegExp(`Request failed with status code ${status}`).test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  expect(dropped.filter((e) => e.kind === 'response').length, `${label}: respons ${status} tercatat (diharapkan)`).toBeGreaterThanOrEqual(1);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan`);
}

/** tooltip tombol DrawerPdf: DrawerPdf (komponen bersama) bisa me-remount tombol saat render ulang, jadi hover diulang sampai tooltip tampil */
async function expectTooltip(page, btn, text, label) {
  await expect
    .poll(
      async () => {
        await page.mouse.move(5, 5);
        await btn.hover();
        await page.waitForTimeout(500);
        return trimAll(await page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').allInnerTexts());
      },
      { message: label, timeout: 25000 },
    )
    .toContain(text);
}

const noServerError = (monitor) => {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
};

/** tunggu DrawerPdf siap: tombol aksi di judul ada (muncul sesudah GET pdf selesai) */
async function pdfReady(page) {
  const d = pdfDrawer(page);
  await d.waitFor({ state: 'visible', timeout: 30000 });
  await d.locator('.ant-drawer-extra button').first().waitFor({ state: 'visible', timeout: 30000 });
  return d;
}

/** klik Print baris/judul lalu kembalikan { resp (GET pdf), buf } */
async function clickPrint(page, id, button) {
  const respP = page.waitForResponse((r) => r.request().method() === 'GET' && reUrl(id, 'pdf').test(r.url()), { timeout: 60000 });
  await button.click();
  const resp = await respP;
  const buf = await resp.body();
  return { resp, buf };
}

/** isi PDF S1/S2 yang diminta FE harus milik sesi itu (scope, penanda ID) dan berbahasa user */
function expectPdfOf(info, key, lang = 'id', label = '') {
  const L = LANG[lang];
  const f = fx();
  expect(info.ok, `${label} bukan PDF: ${JSON.stringify(info).slice(0, 200)}`).toBe(true);
  expect(info.pages, `${label} PDF punya halaman`).toBeGreaterThanOrEqual(1);
  expect(info.text, `${label} judul laporan "${L.pdfReportName}"`).toContain(L.pdfReportName);
  expect(info.text, `${label} Folder: scope sesi ${key}`).toContain(`Folder: ${key === 'S1' ? `${f.folder_names.F} + 2 subfolder` : f.folder_names.G}`);
  expect(info.text, `${label} ${L.by}: ${f.sessions[key].created_by}`).toContain(`${L.by}: ${f.sessions[key].created_by}`);
  expect(info.text, `${label} judul tabel ${L.sumTitle}`).toContain(L.sumTitle);
  expect(info.text, `${label} judul tabel ${L.detTitle}`).toContain(L.detTitle);
  const doc = key === 'S1' ? f.names.dF1 : f.names.g1;
  expect(info.text, `${label} baris Rincian memuat dokumen sesi ${key} (${doc})`).toContain(doc);
  // sesi lain tidak ikut
  const other = key === 'S1' ? f.names.g1 : f.names.dF1;
  expect(info.text, `${label} tidak memuat dokumen sesi lain (${other})`).not.toContain(other);
}

function expectXlsxOf(info, key, lang = 'id', label = '') {
  const L = LANG[lang];
  const f = fx();
  expect(info.ok, `${label} bukan XLSX: ${JSON.stringify(info).slice(0, 200)}`).toBe(true);
  expect(info.sheet, `${label} sheet Report`).toBe('Report');
  const cells = info.cells;
  expect(cells, `${label} judul laporan`).toContain(L.pdfReportName);
  expect(cells.join('\n'), `${label} scope sesi ${key}`).toContain(`Folder: ${key === 'S1' ? `${f.folder_names.F} + 2 subfolder` : f.folder_names.G}`);
  expect(cells, `${label} baris Rincian memuat dokumen sesi ${key}`).toContain(key === 'S1' ? f.names.dF1 : f.names.g1);
  expect(cells, `${label} tanpa dokumen sesi lain`).not.toContain(key === 'S1' ? f.names.g1 : f.names.dF1);
}

// ------------------------------------------------------------------------------------------------ revisi review 2026-10-26 (QA FE r2)
const FOOTER_RE = /(?:Halaman|Page)\s+(\d+)\s+(?:dari|of)\s+(\d+)/g;

/**
 * R-3: footer report-footer-1 ("Dicetak oleh <user>" + "Halaman i dari N" / "Printed by" + "Page i of N") harus ada di SETIAP halaman PDF.
 * Kembalikan { pages, withFooter, missing[], wrongNumber[], noUser[] }; lulus bila missing/wrongNumber/noUser kosong.
 */
function footerReport(info, user) {
  const out = { pages: info.pages, withFooter: 0, missing: [], wrongNumber: [], noUser: [] };
  (info.ptexts || []).forEach((t, i) => {
    const all = [...t.matchAll(FOOTER_RE)];
    if (!all.length) out.missing.push(i + 1);
    else {
      out.withFooter += 1;
      if (!all.some((m) => Number(m[1]) === i + 1 && Number(m[2]) === info.pages)) out.wrongNumber.push(i + 1);
    }
    if (user && !new RegExp(`(?:Dicetak oleh|Printed by)\\s*:?\\s*${esc(user)}`).test(t)) out.noUser.push(i + 1);
  });
  if ((info.ptexts || []).length !== info.pages) out.missing.push(`ptexts=${(info.ptexts || []).length} != pages=${info.pages}`);
  return out;
}

/** gambar zoom satu elemen ke galeri e2e (screenshots/<profil>/<nama>.png) dan daftarkan di app.screenshots */
async function crop(app, page, locator, name, pad = 10) {
  const box = await locator.boundingBox();
  const rel = path.join('screenshots', 'default', `${name}.png`);
  const abs = path.join(process.env.E2E_OUT_DIR, rel);
  fs.mkdirSync(path.dirname(abs), { recursive: true });
  const vp = page.viewportSize();
  const x = Math.max(0, box.x - pad);
  const y = Math.max(0, box.y - pad);
  await page.screenshot({ path: abs, clip: { x, y, width: Math.min(box.width + 2 * pad, vp.width - x), height: Math.min(box.height + 2 * pad, vp.height - y) } });
  app.screenshots.push(rel.replace(/\\/g, '/'));
  return rel;
}

/** path svg (atribut d) ikon antd dari paket ikon FE (pembanding filled vs outlined dari sumbernya, bukan dari kode item) */
const antIconPaths = (name) => {
  const mod = require(path.join(config.get('FE_DIR'), 'node_modules', '@ant-design', 'icons-svg', 'lib', 'asn', `${name}.js`));
  const icon = (mod.default || mod).icon;
  return icon.children.map((c) => c.attrs.d);
};

module.exports = {
  FOOTER_RE,
  footerReport,
  crop,
  antIconPaths,
  fixture,
  lastJson,
  fx,
  own,
  save,
  norm,
  pdfOf,
  xlsxOf,
  esc,
  trimAll,
  sleep,
  LANG,
  OPNAMES_RE,
  waitOpnames,
  waitList,
  modalVisible,
  drawerOpen,
  hist,
  resDrawer,
  pdfDrawer,
  customizeDrawer,
  histTable,
  histRowsLoc,
  searchInput,
  rowOf,
  sessionRow,
  actionCell,
  printBtn,
  excelBtn,
  idOf,
  reUrl,
  pdfWorker,
  openArchive,
  openModal,
  openHistoryFromModal,
  pickRowMenu,
  openFolderHistoryFromTab,
  openFolderHistoryFromModal,
  duplicateIds,
  expectRejected,
  noServerError,
  expectTooltip,
  pdfReady,
  clickPrint,
  expectPdfOf,
  expectXlsxOf,
};
