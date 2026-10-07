'use strict';

/**
 * ED-1027 - Archive: status verifikasi dokumen (kolom Document Verified, ringkasan di atas breadcrumb, modal detail per
 * Transaction Type, tab Verification di drawer folder).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA27-"; angka
 * verified/total diketahui tangan (lihat kepala fixture.php):
 *   ALPHA 4/8 (S1 2/3, S1-X 1/1, S2 0/1) | BETA 2/3 | GAMMA 0/3 | EMPTY 0/0 | VIEWONLY 0/1 | NOVIEW "-" | dokumen root RD-V (V), RD-U
 *   Ringkasan All Archive (user QA) = dokumen aktif asli + 17 total, 7 verified.
 *   Sesi terkonfirmasi: S4 ALPHA ("ALPHA-S1 + 1 subfolder"), S3 root, S2 GAMMA, S1 BETA (terlama, di luar 3 terbaru) + 1 draft.
 * User [default] = QA_USER (role 3, semua lokasi, bahasa ID), [user2] = QA_USER2 (bahasa EN).
 *
 * Mutasi: Display Setting (baris global modul diganti-ganti lalu dipulihkan fixture), Confirm Opname GAMMA lewat UI (test terakhir),
 * Save drawer ALPHA (deskripsi). Semuanya data fixture / tabel archive_opname* yang dibuang teardown.
 * Test dijalankan berurutan (E2E_WORKERS=1): satu user, satu token.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const P = '0QA27-';
const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const trimAll = (arr) => arr.map((t) => t.replace(/\s+/g, ' ').trim());
const num = (s) => Number(String(s).replace(/[^\d]/g, ''));

const LIST_RE = /\/document-archive\/archives(\?|$)/;
const VERIF_RE = /^verifications(\/|$)/;
const COL = 'Dokumen Terverifikasi';

function inspectDb() {
  const out = execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'inspect'], { encoding: 'utf8', timeout: 90000 });
  return JSON.parse(out.trim().split('\n').pop());
}

function displayDb() {
  const out = execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'display'], { encoding: 'utf8', timeout: 90000 });
  return JSON.parse(out.trim().split('\n').pop());
}

/** catat respons API document-archive (method, path, query, status, body request, JSON response) */
function trace(page) {
  const calls = [];
  page.on('response', (r) => {
    const u = new URL(r.url());
    if (!/\/document-archive\//.test(u.pathname)) return;
    let body = null;
    try {
      body = r.request().postData() ? JSON.parse(r.request().postData()) : null;
    } catch (e) {
      body = r.request().postData();
    }
    const entry = {
      method: r.request().method(),
      path: u.pathname.replace(/^.*\/document-archive\//, ''),
      query: Object.fromEntries(u.searchParams),
      url: r.url(),
      status: r.status(),
      body,
      json: null,
    };
    entry.ready = r.json().then((j) => { entry.json = j; }).catch(() => null);
    calls.push(entry);
  });
  return calls;
}
const callsOf = async (calls, method, re) => {
  await Promise.all(calls.map((c) => c.ready));
  return calls.filter((c) => c.method === method && re.test(c.path));
};
const verifCalls = (calls) => callsOf(calls, 'GET', VERIF_RE);
const listCalls = (calls) => callsOf(calls, 'GET', /^archives$/);

const listResponse = (page, pred) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 })
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  expect(api.length, 'ada panggilan API v5 tercatat (monitor aktif)').toBeGreaterThan(0);
}


/** buang dari monitor penolakan yang SENGAJA dibuat (respons dimodifikasi), setelah memastikan terjadi */
function expectRejected(monitor, label, { status, urlRe }) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === status && urlRe.test(decodeURIComponent(e.url))) ||
    (e.kind === 'console' && new RegExp(`Request failed with status code ${status}`).test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  expect(dropped.filter((e) => e.kind === 'response').length, `${label}: respons ${status} tercatat (diharapkan)`).toBeGreaterThanOrEqual(1);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan`);
}

// ---------------------------------------------------------------------------------------------------- halaman Archive
const rowOf = (page, name) =>
  page
    .locator('.archive-page .ant-table-row')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .first();

async function openArchive(app, page) {
  const list = listResponse(page, () => true);
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  const resp = await list;
  await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
  return resp;
}

async function openFolder(page, name) {
  const resp = listResponse(page, (u) => !!folderParam(u));
  await rowOf(page, name).locator('td.cursor-pointer').first().click();
  const r = await resp;
  await page.waitForTimeout(500);
  return r;
}

/** indeks kolom (0-based) menurut teks header tabel utama */
async function colIndex(page, title) {
  const heads = trimAll(await page.locator('.archive-page .ant-table-thead th').allInnerTexts());
  return heads.indexOf(title);
}
const headTexts = async (page) => trimAll(await page.locator('.archive-page .ant-table-thead th').allInnerTexts());

async function cellText(page, name, title = COL) {
  const idx = await colIndex(page, title);
  expect(idx, `kolom "${title}" ada`).toBeGreaterThanOrEqual(0);
  return trimAll([await rowOf(page, name).locator('td').nth(idx).innerText()])[0];
}

/** ikon sel dokumen: 'verified' | 'unverified' | null */
async function docIcon(page, name) {
  const idx = await colIndex(page, COL);
  const cell = rowOf(page, name).locator('td').nth(idx);
  if ((await cell.locator('.anticon-check-circle').count()) > 0) return 'verified';
  if ((await cell.locator('.anticon-close-circle').count()) > 0) return 'unverified';
  return null;
}

// ringkasan di atas breadcrumb
const summaryBox = (page) => page.locator('.archive-page .ant-space').filter({ hasText: 'seluruh dokumen di sistem' }).first();
const summaryBoxEn = (page) => page.locator('.archive-page .ant-space').filter({ hasText: 'all documents in the system' }).first();

/** { verified, total, percent } dari ringkasan di atas breadcrumb */
async function readSummary(page, box = summaryBox(page)) {
  await box.waitFor({ state: 'visible', timeout: 15000 });
  const text = (await box.innerText()).replace(/\s+/g, ' ');
  const m = /([\d.,]+)\s*\/\s*([\d.,]+)/.exec(text);
  expect(m, `ringkasan "V / T" terbaca dari: ${text}`).not.toBeNull();
  const width = await box.locator('.ant-progress-bg').first().evaluate((el) => el.style.width);
  return { verified: num(m[1]), total: num(m[2]), percent: parseFloat(width), text };
}
const pct = (v, t) => (t <= 0 ? 0 : Math.round((v / t) * 1000) / 10);

const modalVisible = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
const drawer = (page) => page.locator('.ant-drawer-open').last();
const tabs = (page) => drawer(page).locator('.ant-tabs-tab');

async function rowMenuItems(page, name) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  const btn = row.locator('button.btn-action');
  if ((await btn.count()) === 0) return { items: [], menu: null };
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  return { menu, items: trimAll(await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()) };
}
async function pickRowMenu(page, name, itemRegex) {
  const { menu, items } = await rowMenuItems(page, name);
  expect(menu, `menu baris ${name} ada`).not.toBeNull();
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
  return items;
}

async function closeTopLayer(page) {
  const closer = page.locator('.ant-drawer-open .ant-drawer-close, .ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last();
  if (await closer.count()) await closer.click();
  await page.waitForTimeout(700);
}

/** Buka drawer "Lihat" folder; mengembalikan teks tab. */
async function openDrawerView(page, name, viewRe = /^(Lihat|View)$/) {
  await pickRowMenu(page, name, viewRe);
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  return trimAll(await tabs(page).allInnerTexts());
}


/**
 * Keterjangkauan kolom: bila tabel memotong kolom di sisi kanan (digulung horizontal), gulung sampai ujung dan pastikan kolom
 * terakhir benar-benar tampil di dalam tabel. Mengembalikan { need, reach, last } untuk dicatat.
 */
async function columnReach(page, table) {
  const need = await table.evaluate((t) => {
    const sc = t.querySelector('.ant-table-body') || t.querySelector('.ant-table-content');
    const ths = Array.from(t.querySelectorAll('thead th')).filter((th) => th.innerText.trim());
    const last = ths[ths.length - 1];
    const cr = t.getBoundingClientRect();
    return { scrollable: sc.scrollWidth > sc.clientWidth + 1, clippedBefore: last.getBoundingClientRect().right > cr.right + 1, last: last.innerText.trim() };
  });
  await table.evaluate((t) => {
    const sc = t.querySelector('.ant-table-body') || t.querySelector('.ant-table-content');
    sc.scrollLeft = sc.scrollWidth;
  });
  await page.waitForTimeout(500);
  const reach = await table.evaluate((t) => {
    const ths = Array.from(t.querySelectorAll('thead th')).filter((th) => th.innerText.trim());
    const last = ths[ths.length - 1];
    const r = last.getBoundingClientRect();
    const cr = t.getBoundingClientRect();
    return r.right <= cr.right + 2 && r.left >= cr.left - 2;
  });
  await table.evaluate((t) => {
    const sc = t.querySelector('.ant-table-body') || t.querySelector('.ant-table-content');
    sc.scrollLeft = 0;
  });
  await page.waitForTimeout(200);
  return { ...need, reach };
}

// ---------------------------------------------------------------------------------------------------- isi verifikasi
/** teks tabel (baris -> sel) di dalam wadah */
async function tableRows(scope, n = 0) {
  const table = scope.locator('.ant-table').nth(n);
  const rows = table.locator('.ant-table-tbody tr.ant-table-row');
  const count = await rows.count();
  const out = [];
  for (let i = 0; i < count; i += 1) {
    out.push(trimAll(await rows.nth(i).locator('td').allInnerTexts()));
  }
  return out;
}

/** kartu angka + kalimat + progress (modal atau tab) */
async function readCard(scope) {
  const card = scope.locator('.ant-card').first();
  await card.waitFor({ state: 'visible', timeout: 20000 });
  const text = (await card.innerText()).replace(/\s+/g, ' ');
  const m = /([\d.,]+)\s*\/\s*([\d.,]+)/.exec(text);
  const width = await card.locator('.ant-progress-bg').first().evaluate((el) => el.style.width);
  return { verified: num(m[1]), total: num(m[2]), percent: parseFloat(width), text };
}

/** baris tabel tipe harapan dari respons BE: [label, verified, unverified, total] + Total */
function expectedTypeRows(result) {
  const rows = result.transaction_types.map((r) => [r.label, String(r.verified), String(r.unverified), String(r.total)]);
  rows.push(['Total', String(result.verified), String(result.unverified), String(result.total)]);
  return rows;
}
const fmt = (n) => Number(n).toLocaleString('en-US');
const formatRow = (r) => [r[0], fmt(r[1]), fmt(r[2]), fmt(r[3])];

// =====================================================================================================================
test.describe('ED-1027 status verifikasi dokumen', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  // ---------------------------------------------------------------------------------------------- AC-13 root / folder / pencarian
  test('[default] ED-1027 AC-13 kolom Document Verified + ringkasan: root, dalam folder, pencarian, reload, breadcrumb', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    const first = await openArchive(app, page);
    expect(first.status, 'GET archives root 200').toBe(200);
    const res0 = first.body.result;

    // kolom: sesudah Salesman, sebelum Status, tidak bisa di-sort
    const heads = await headTexts(page);
    monitor.note(`header tabel: ${JSON.stringify(heads)}`);
    const iSal = heads.indexOf('Salesman');
    const iDv = heads.indexOf(COL);
    const iSt = heads.indexOf('Status');
    expect(iDv, 'kolom Dokumen Terverifikasi ada').toBeGreaterThan(-1);
    expect(iDv, 'tepat sesudah Salesman').toBe(iSal + 1);
    expect(iSt, 'sebelum Status').toBe(iDv + 1);
    const dvTh = page.locator('.archive-page .ant-table-thead th').nth(iDv);
    await expect(dvTh.locator('.ant-table-column-sorters'), 'header tanpa pengurut (sort=false)').toHaveCount(0);
    await expect(dvTh, 'th tanpa kelas has-sorters').not.toHaveClass(/ant-table-column-has-sorters/);
    expect((res0.columns || []).map((c) => c.data_index).filter((d) => d === 'documentVerified').length, 'respons memuat kolom documentVerified').toBe(1);

    // sel folder = respons BE = angka tangan; folder tanpa View = "-"
    const byName = {};
    rowsOf({ body: first.body }).forEach((r) => { byName[Array.isArray(r.name) ? r.name[0].transaction_no : r.name] = r; });
    const hand = { ALPHA: '4/8', BETA: '2/3', EMPTY: '0/0', GAMMA: '0/3', VIEWONLY: '0/1', NOVIEW: '-' };
    for (const [k, v] of Object.entries(hand)) {
      const uiCell = await cellText(page, `${P}${k}`);
      const api = (rowsOf({ body: first.body }).find((r) => (Array.isArray(r.name) ? r.name[0].transaction_no : r.name) === `${P}${k}`) || {}).document_verified;
      const apiText = api === null ? '-' : `${fmt(api.verified)}/${fmt(api.total)}`;
      expect(uiCell, `sel ${k}: UI = respons BE (${apiText})`).toBe(apiText);
      expect(uiCell, `sel ${k}: UI = angka tangan`).toBe(v);
    }
    // sel folder nyata = respons (angka berformat locale), tanpa [object Object]
    const rowTexts = await page.locator('.archive-page .ant-table-tbody').innerText();
    expect(rowTexts, 'tabel tanpa objek mentah').not.toMatch(/\[object Object\]/);
    const real = rowsOf({ body: first.body }).find((r) => r.name && (Array.isArray(r.name) ? r.name[0].transaction_no : r.name) === 'Backup Arsip');
    expect(await cellText(page, 'Backup Arsip'), 'folder nyata Backup Arsip = respons').toBe(`${fmt(real.document_verified.verified)}/${fmt(real.document_verified.total)}`);

    // klik sel V/T tidak melakukan apa-apa (K-7): tidak ada GET archives baru
    const listBefore = (await listCalls(calls)).length;
    await rowOf(page, `${P}ALPHA`).locator('td').nth(iDv).click();
    await page.waitForTimeout(800);
    expect((await listCalls(calls)).length, 'klik sel V/T: tanpa request').toBe(listBefore);
    await expect(page.locator('.ant-drawer-open, .ant-modal-wrap:not([style*="display: none"])'), 'klik sel V/T: tidak membuka apa pun').toHaveCount(0);

    // klik header kolom: tidak ada sort baru
    await dvTh.click();
    await page.waitForTimeout(800);
    expect((await listCalls(calls)).length, 'klik header Dokumen Terverifikasi: tanpa request sort').toBe(listBefore);

    // ringkasan = documentVerifiedSummary respons; verified fixture 7; total = dokumen aktif asli + 17
    const sum = await readSummary(page);
    const apiSum = res0.document_verified_summary;
    monitor.note(`ringkasan root UI: ${sum.text} | progress ${sum.percent}% | respons ${JSON.stringify(apiSum)}`);
    expect({ verified: sum.verified, total: sum.total }, 'ringkasan UI = document_verified_summary respons').toEqual(apiSum);
    expect(sum.verified, 'ringkasan: verified = 7 (fixture, awal 0)').toBe(fx._expect.summary_verified);
    expect(sum.total, 'ringkasan: total = dokumen aktif + 17 (fixture terlihat)').toBe(fx._expect.summary_total);
    expect(sum.percent, 'progress = V/T').toBeCloseTo(pct(sum.verified, sum.total), 1);
    await expect(page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first(), 'tautan detail').toBeVisible();
    // letak: di atas breadcrumb, di bawah toolbar
    const yBox = (await summaryBox(page).boundingBox()).y;
    const yCrumb = (await page.locator('.archive-page .breadcrumbs').first().boundingBox()).y;
    const yBar = (await page.locator('.archive-page .search-bar').first().boundingBox()).y;
    expect(yBox, 'ringkasan di atas breadcrumb').toBeLessThan(yCrumb);
    expect(yBox, 'ringkasan di bawah toolbar').toBeGreaterThan(yBar);
    await app.screenshot('ac13-01-root-kolom-dan-ringkasan');

    // reload (tombol muat ulang di samping pencarian): GET archives baru, ringkasan tetap
    const reloadBtn = page.locator('.archive-page button.button-filter-wrapper').first();
    await expect(reloadBtn, 'tombol muat ulang ada (root)').toBeVisible();
    const reloadN = (await listCalls(calls)).length;
    const rl = listResponse(page, () => true);
    await reloadBtn.click();
    const rr = await rl;
    expect(rr.body.result.document_verified_summary, 'reload: ringkasan sama').toEqual(apiSum);
    await page.waitForTimeout(600);
    expect((await listCalls(calls)).length, 'reload: GET archives baru').toBeGreaterThan(reloadN);
    const sumReload = await readSummary(page);
    expect({ verified: sumReload.verified, total: sumReload.total }, 'sesudah reload: ringkasan sama').toEqual(apiSum);

    // buka folder ALPHA: ringkasan sama (All Archive), subfolder + dokumen
    const inAlpha = await openFolder(page, `${P}ALPHA`);
    expect(inAlpha.status, 'GET archives?id_archive=ALPHA 200').toBe(200);
    expect(inAlpha.body.result.document_verified_summary, 'respons folder: ringkasan sama dengan root').toEqual(apiSum);
    const sumIn = await readSummary(page);
    expect({ verified: sumIn.verified, total: sumIn.total }, 'ringkasan di dalam folder tidak berubah (All Archive)').toEqual(apiSum);
    expect(await cellText(page, `${P}ALPHA-S1`), 'ALPHA-S1 = 2/3').toBe('2/3');
    expect(await cellText(page, `${P}ALPHA-S2`), 'ALPHA-S2 = 0/1').toBe('0/1');
    // dokumen: ikon (AD1 V, AD2 U, AD-HO Handed Over U, AD-TK Taken V); AD-NEG (-1) dan AD-DEL (0) tidak ada
    expect(await docIcon(page, `${P}AD1`), 'AD1 ikon verified').toBe('verified');
    expect(await docIcon(page, `${P}AD2`), 'AD2 ikon unverified').toBe('unverified');
    expect(await docIcon(page, `${P}AD-HO`), 'AD-HO (Handed Over) unverified, tetap berikon').toBe('unverified');
    expect(await docIcon(page, `${P}AD-TK`), 'AD-TK (Taken) verified, tetap berikon').toBe('verified');
    await expect(rowOf(page, `${P}AD-NEG`), 'dokumen is_active=-1 tidak tampil').toHaveCount(0);
    await expect(rowOf(page, `${P}AD-DEL`), 'dokumen is_active=0 tidak tampil').toHaveCount(0);
    const inRows = rowsOf(inAlpha);
    const apiDoc = inRows.find((r) => r.document && r.document.transaction_no === `${P}AD1`);
    expect(apiDoc.document_verified, 'respons dokumen AD1').toEqual({ verified: 1, total: 1 });
    expect(await cellText(page, `${P}AD1`), 'sel dokumen tanpa teks angka').toBe('');
    // tooltip ikon
    await rowOf(page, `${P}AD1`).locator('.anticon-check-circle').hover();
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip verified').toContainText('Terverifikasi');
    await rowOf(page, `${P}AD2`).locator('.anticon-close-circle').hover();
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip unverified').toContainText('Belum terverifikasi');
    await app.screenshot('ac13-02-dalam-folder-ALPHA');

    // pencarian: folder + dokumen lintas level; ringkasan tetap All Archive
    const input = page.locator('.archive-page .search-bar input').first();
    const search = listResponse(page, (u) => /0QA27/.test(u.searchParams.get('search') || ''));
    await input.fill(P);
    await input.press('Enter');
    const sr = await search;
    expect(sr.status, 'GET archives?search').toBe(200);
    expect(sr.body.result.document_verified_summary, 'respons pencarian: ringkasan sama').toEqual(apiSum);
    await page.waitForTimeout(600);
    const sumSearch = await readSummary(page);
    expect({ verified: sumSearch.verified, total: sumSearch.total }, 'ringkasan saat mencari = All Archive').toEqual(apiSum);
    const sRows = rowsOf(sr);
    monitor.note(`hasil cari "${P}" halaman 1: ${sRows.length} baris`);
    await expect(rowOf(page, `${P}RD-V`), 'pencarian memuat dokumen root RD-V').toBeVisible({ timeout: 15000 }).catch(() => null);
    if ((await rowOf(page, `${P}RD-V`).count()) === 0) {
      // dokumen root bisa di halaman berikutnya: cari spesifik
      const s2 = listResponse(page, (u) => /RD-V/.test(u.searchParams.get('search') || ''));
      await input.fill(`${P}RD-V`);
      await input.press('Enter');
      await s2;
      await page.waitForTimeout(600);
    }
    expect(await docIcon(page, `${P}RD-V`), 'RD-V (root, verified) ikon centang').toBe('verified');
    const s3 = listResponse(page, (u) => /RD-U/.test(u.searchParams.get('search') || ''));
    await input.fill(`${P}RD-U`);
    await input.press('Enter');
    const r3 = await s3;
    expect(r3.body.result.document_verified_summary, 'pencarian RD-U: ringkasan sama').toEqual(apiSum);
    await page.waitForTimeout(600);
    expect(await docIcon(page, `${P}RD-U`), 'RD-U (root, unverified) ikon lingkaran').toBe('unverified');
    const sum3 = await readSummary(page);
    expect({ verified: sum3.verified, total: sum3.total }, 'ringkasan hasil pencarian RD-U tetap All Archive').toEqual(apiSum);
    await app.screenshot('ac13-03-pencarian-dokumen-root');

    // pencarian folder: sel folder hasil pencarian = angka folder
    const s4 = listResponse(page, (u) => /ALPHA/.test(u.searchParams.get('search') || ''));
    await input.fill(`${P}ALPHA`);
    await input.press('Enter');
    await s4;
    await page.waitForTimeout(600);
    expect(await cellText(page, `${P}ALPHA`), 'folder di hasil pencarian = 4/8').toBe('4/8');
    expect(await cellText(page, `${P}ALPHA-S1-X`), 'subfolder S1-X di hasil pencarian = 1/1').toBe('1/1');

    // breadcrumb: dari dalam folder kembali ke root (klik "Arsip" di breadcrumb)
    await input.fill('');
    await input.press('Enter');
    await page.waitForTimeout(1200);
    await openFolder(page, `${P}ALPHA`);
    const crumbs = trimAll(await page.locator('.archive-page .breadcrumbs').first().allInnerTexts());
    monitor.note(`breadcrumb dalam folder: ${JSON.stringify(crumbs)}`);
    expect(crumbs.join(' '), 'breadcrumb memuat ALPHA').toContain(`${P}ALPHA`);
    const bc = listResponse(page, (u) => !folderParam(u) && !(u.searchParams.get('search') || '').length);
    await page.locator('.archive-page .breadcrumbs').getByText(/^Arsip$/).first().click();
    const bcr = await bc;
    expect(bcr.status, 'breadcrumb ke root: GET archives 200').toBe(200);
    expect(bcr.body.result.document_verified_summary, 'breadcrumb: respons ringkasan sama').toEqual(apiSum);
    await page.waitForTimeout(600);
    const sumRoot = await readSummary(page);
    expect({ verified: sumRoot.verified, total: sumRoot.total }, 'kembali ke root: ringkasan sama').toEqual(apiSum);

    expect((await verifCalls(calls)).length, 'tidak ada GET verifications sebelum modal/tab dibuka').toBe(0);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-14 Display Setting
  test('[default] ED-1027 AC-14 Display Setting: kolom Document Verified disembunyikan lalu ditampilkan, tabel tetap tampil', async ({ app, page, monitor }) => {
    await openArchive(app, page);
    const before = displayDb();
    monitor.note(`display setting DB sebelum: ${JSON.stringify(before.current)}`);
    expect(before.current, 'DB: kolom documentVerified ada di current_columns').toContain('documentVerified');
    expect(before.current.indexOf('documentVerified'), 'DB: documentVerified tepat sesudah Salesman (documentRelatedEmployeeName)').toBeGreaterThan(0);
    expect(await headTexts(page), 'tabel: kolom tampil').toContain(COL);

    const openSetting = async () => {
      await page.locator('.archive-page button', { hasText: /Pengaturan Tampilan|Display Setting/ }).first().click();
      await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
      await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      await app.settle();
      return drawer(page).locator('.ant-table').first().locator('tr.ant-table-row').filter({ hasText: COL }).first();
    };
    const saveSetting = async () => {
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /display/i.test(r.url()), { timeout: 30000 });
      await drawer(page).locator('.ant-drawer-extra button.ant-btn-primary').click();
      const r = await put;
      expect(r.status(), 'PUT display setting 200').toBe(200);
      await expect(page.locator('.ant-drawer-open'), 'drawer menutup sesudah Save').toHaveCount(0, { timeout: 15000 });
      await page.waitForTimeout(1500);
    };

    // 1. drawer: daftar kolom memuat "Dokumen Terverifikasi", tercentang
    const row = await openSetting();
    await expect(row, 'baris Dokumen Terverifikasi ada di Display Setting').toBeVisible();
    const names = trimAll(await drawer(page).locator('.ant-table').first().locator('tr.ant-table-row').allInnerTexts());
    monitor.note(`kolom di Display Setting: ${JSON.stringify(names)}`);
    expect(names, 'urutan: Dokumen Terverifikasi sesudah Salesman, sebelum Status').toEqual(expect.arrayContaining(['Salesman', COL, 'Status']));
    expect(names.indexOf(COL), 'sesudah Salesman').toBe(names.indexOf('Salesman') + 1);
    await expect(row.locator('input[type="checkbox"]'), 'tercentang (kolom aktif)').toBeChecked();
    await app.screenshot('ac14-01-display-setting-kolom');

    // 2. sembunyikan -> Save -> kolom hilang, tabel + ringkasan tetap
    await row.locator('input[type="checkbox"]').uncheck();
    await saveSetting();
    await expect.poll(async () => (await headTexts(page)).includes(COL), { message: 'kolom hilang dari tabel', timeout: 15000 }).toBe(false);
    await expect(page.locator('.archive-page .ant-table-row').first(), 'tabel tetap tampil (ada baris)').toBeVisible();
    expect(await page.locator('.archive-page .ant-table-tbody').innerText(), 'tabel tanpa objek mentah').not.toMatch(/\[object Object\]/);
    await expect(summaryBox(page), 'ringkasan tetap ada walau kolom disembunyikan').toBeVisible();
    const hidden = displayDb();
    expect(hidden.current, 'DB: documentVerified keluar dari current_columns').not.toContain('documentVerified');
    await app.screenshot('ac14-02-kolom-disembunyikan');

    // folder dibuka tanpa kolom: tetap normal
    await openFolder(page, `${P}ALPHA`);
    await expect(summaryBox(page), 'ringkasan tetap di dalam folder').toBeVisible();
    expect(await headTexts(page), 'di dalam folder: kolom tetap tersembunyi').not.toContain(COL);

    // 3. tampilkan lagi -> Save -> kolom kembali dengan isi
    const row2 = await openSetting();
    await expect(row2.locator('input[type="checkbox"]'), 'tidak tercentang').not.toBeChecked();
    await row2.locator('input[type="checkbox"]').check();
    await saveSetting();
    await expect.poll(async () => (await headTexts(page)).includes(COL), { message: 'kolom kembali', timeout: 15000 }).toBe(true);
    expect(await cellText(page, `${P}ALPHA-S1`), 'sel folder terisi lagi (2/3)').toBe('2/3');
    expect(await docIcon(page, `${P}AD1`), 'sel dokumen terisi lagi').toBe('verified');
    const after = displayDb();
    monitor.note(`display setting DB sesudah dikembalikan: ${JSON.stringify(after.current)}`);
    expect(after.current, 'DB: documentVerified kembali di current_columns').toContain('documentVerified');
    await app.screenshot('ac14-03-kolom-kembali');

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-15 picker Pindahkan + Bulk Move
  test('[default] ED-1027 AC-15 modal Pindahkan (folder & dokumen) dan Bulk Move: kolom Document Verified dirender, bukan objek', async ({ app, page, monitor }) => {
    await openArchive(app, page);

    const modalHeads = async (modal) => trimAll(await modal.locator('.ant-table-thead th').allInnerTexts());
    const modalCell = async (modal, name, title = COL) => {
      const heads = await modalHeads(modal);
      const idx = heads.indexOf(title);
      expect(idx, `modal: kolom "${title}" ada (${JSON.stringify(heads)})`).toBeGreaterThanOrEqual(0);
      const row = modal.locator('.ant-table-row').filter({ has: page.locator('td').filter({ hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) }).first();
      await row.waitFor({ state: 'visible', timeout: 15000 });
      return trimAll([await row.locator('td').nth(idx).innerText()])[0];
    };
    const noRaw = async (modal, label) => {
      const t = await modal.innerText();
      expect(t, `${label}: tanpa objek mentah`).not.toMatch(/\[object Object\]/);
    };

    // 1. Pindahkan satu folder: picker = tabel Archive di modal
    const pick1 = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, `${P}GAMMA`, /^(Pindahkan|Move)$/);
    const pr1 = await pick1;
    expect(pr1.status, 'picker Pindahkan folder: GET archives').toBe(200);
    const m1 = modalVisible(page);
    await m1.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(m1.locator('.ant-table-row').filter({ hasText: `${P}ALPHA` }).first(), 'picker memuat ALPHA').toBeVisible();
    expect(await modalHeads(m1), 'picker: kolom Dokumen Terverifikasi ada').toContain(COL);
    expect(await modalCell(m1, `${P}ALPHA`), 'picker: ALPHA = 4/8').toBe('4/8');
    expect(await modalCell(m1, `${P}EMPTY`), 'picker: EMPTY = 0/0').toBe('0/0');
    expect(await modalCell(m1, `${P}NOVIEW`), 'picker: NOVIEW = -').toBe('-');
    await noRaw(m1, 'picker folder');
    await app.screenshot('ac15-01-picker-pindahkan-folder');
    await closeTopLayer(page);

    // 2. Pindahkan satu dokumen (di dalam ALPHA)
    await openFolder(page, `${P}ALPHA`);
    const pick2 = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, `${P}AD2`, /^(Pindahkan|Move)$/);
    const pr2 = await pick2;
    expect(pr2.status, 'picker Pindahkan dokumen: GET archives').toBe(200);
    const m2 = modalVisible(page);
    await m2.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    expect(await modalCell(m2, `${P}ALPHA`), 'picker dokumen: ALPHA = 4/8').toBe('4/8');
    await noRaw(m2, 'picker dokumen');
    await app.screenshot('ac15-02-picker-pindahkan-dokumen');
    await closeTopLayer(page);

    // 3. Bulk Move: mode Pilih, centang satu dokumen (AD1) + satu folder (S1), Pindahkan -> Konfirmasi -> Berikutnya -> Pilih Folder
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await page.waitForTimeout(500);
    await rowOf(page, `${P}AD1`).locator('input[type="checkbox"]').first().check();
    await rowOf(page, `${P}ALPHA-S1`).locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = modalVisible(page);
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    expect(await modalHeads(bulk), 'Konfirmasi: kolom Dokumen Terverifikasi ada').toContain(COL);
    expect(await modalCell(bulk, `${P}ALPHA-S1`), 'Konfirmasi: folder S1 = 2/3').toBe('2/3');
    const heads = await modalHeads(bulk);
    const idx = heads.indexOf(COL);
    const docRow = bulk.locator('.ant-table-row').filter({ has: page.locator('td').filter({ hasText: new RegExp(`^\\s*${esc(P + 'AD1')}\\s*$`) }) }).first();
    await expect(docRow.locator('td').nth(idx).locator('.anticon-check-circle'), 'Konfirmasi: dokumen AD1 = ikon verified').toHaveCount(1);
    await noRaw(bulk, 'bulk konfirmasi');
    await app.screenshot('ac15-03-bulk-konfirmasi');
    const pickerList = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Next|Lanjut|Berikutnya)$/ }).first().click();
    await pickerList.catch(() => null);
    await app.settle();
    expect(await modalCell(bulk, `${P}ALPHA`), 'Pilih Folder: ALPHA = 4/8').toBe('4/8');
    expect(await modalCell(bulk, `${P}NOVIEW`), 'Pilih Folder: NOVIEW = -').toBe('-');
    await noRaw(bulk, 'bulk pilih folder');
    await app.screenshot('ac15-04-bulk-pilih-folder');
    await closeTopLayer(page);
    await page.locator('.archive-page button', { hasText: /^(Batal Pilih|Cancel)/ }).first().click().catch(() => null);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-16 modal All Archive
  test('[default] ED-1027 AC-16/AC-19 modal Document Verified: satu GET verifications, header = ringkasan, tabel tipe + Total, 3 sesi, catatan, Close', async ({ app, page, monitor }) => {
    const calls = trace(page);
    const first = await openArchive(app, page);
    const apiSum = first.body.result.document_verified_summary;
    const sum = await readSummary(page);
    expect((await verifCalls(calls)).length, 'sebelum klik tautan: 0 GET verifications').toBe(0);

    // buka modal
    const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    const vr = await resp;
    expect(vr.status(), 'GET verifications 200').toBe(200);
    const body = (await vr.json()).result;
    const modal = modalVisible(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const vc = await verifCalls(calls);
    expect(vc.length, 'tepat satu GET verifications saat modal dibuka').toBe(1);
    monitor.note(`GET verifications URL: ${vc[0].url.replace(/^https?:\/\/[^/]+/, '')}`);
    expect(Object.keys(vc[0].query).filter((k) => /id_?archive|search|query|type/i.test(k)), 'GET verifications tanpa parameter bisnis').toEqual([]);

    // judul + subjudul
    const title = (await modal.locator('.ant-modal-title').innerText()).replace(/\s+/g, ' ');
    monitor.note(`judul modal: ${title}`);
    expect(title, 'judul').toContain('Dokumen Terverifikasi');
    expect(title, 'subjudul "All Archive — seluruh dokumen di sistem"').toContain('All Archive — seluruh dokumen di sistem');

    // header = ringkasan list = respons
    const card = await readCard(modal);
    monitor.note(`kartu modal: ${card.text}`);
    expect({ verified: card.verified, total: card.total }, 'kartu = ringkasan list (UI)').toEqual({ verified: sum.verified, total: sum.total });
    expect({ verified: body.verified, total: body.total }, 'respons verifications = document_verified_summary list').toEqual(apiSum);
    expect(body.verified + body.unverified, 'verified + unverified = total').toBe(body.total);
    expect(card.text, 'kalimat All').toContain(`${fmt(card.verified)} dokumen terverifikasi dari ${fmt(card.total)} dokumen di seluruh folder`);
    expect(card.percent, 'progress modal = V/T').toBeCloseTo(pct(card.verified, card.total), 1);

    // tabel tipe: baris = respons (urutan + label BE), tipe 0 tetap ada, baris Total = header
    const typeRows = await tableRows(modal, 0);
    monitor.note(`tabel tipe: ${JSON.stringify(typeRows)}`);
    expect(typeRows, 'tabel tipe = respons BE (7 tipe + Total)').toEqual(expectedTypeRows(body).map(formatRow));
    expect(body.transaction_types.length, '7 tipe di daftar 01').toBe(7);
    expect(body.transaction_types.map((r) => r.transaction_type), 'urutan tipe 01').toEqual([6, 7, 8, 9, 29, 31, 222]);
    // verified per tipe dari fixture (awal 0 verified): 6 -> 4 (AD1, S1D1, BD1, RD-V), 7 -> 2 (S1XD1, T1D1), 9 -> 1 (AD-TK)
    expect(body.transaction_types.map((r) => r.verified), 'verified per tipe = angka tangan fixture').toEqual([4, 2, 0, 1, 0, 0, 0]);
    expect(typeRows.some((r) => r[1] === '0' && r[3] !== '0') || typeRows.some((r) => r[3] === '0'), 'ada baris bernilai 0 yang tetap tampil').toBe(true);
    const sumCols = body.transaction_types.reduce((a, r) => ({ v: a.v + r.verified, u: a.u + r.unverified, t: a.t + r.total }), { v: 0, u: 0, t: 0 });
    expect(sumCols, 'jumlah kolom = header (BE)').toEqual({ v: body.verified, u: body.unverified, t: body.total });
    const totalRow = modal.locator('.ant-table').nth(0).locator('tr.bold-row');
    await expect(totalRow, 'baris Total berkelas bold-row').toHaveCount(1);
    expect(trimAll([await totalRow.innerText()])[0], 'baris Total = header').toContain('Total');

    // overview 3 sesi: S4, S3, S2 (S1 terlama + draft tidak tampil), angka tersimpan
    const sess = await tableRows(modal, 1);
    monitor.note(`tabel sesi: ${JSON.stringify(sess)}`);
    expect(sess, 'tiga sesi terbaru, terbaru di atas').toEqual([
      ['06 Okt 2026 16:40', 'qa27alpha', '0QA27-ALPHA-S1 + 1 subfolder', '8', '5', '1', '2'],
      ['05 Okt 2026 09:15', 'qa27root', 'All Archive · root', '20', '9', '2', '1'],
      ['04 Okt 2026 14:30', 'qa27gamma', '0QA27-GAMMA', '3', '1', '0', '0'],
    ]);
    const heads = trimAll(await modal.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean);
    monitor.note(`kolom sesi: ${JSON.stringify(heads)}`);
    expect(heads.map((h) => h.toLowerCase()), 'kolom sesi varian All').toEqual(['waktu', 'pengguna', 'scope', 'total dokumen', 'terverifikasi', 'tidak ditemukan', 'tidak valid']);
    expect(body.last_sessions.map((x) => x.created_by), 'respons: 3 sesi, urutan').toEqual(['qa27alpha', 'qa27root', 'qa27gamma']);
    const modalText = await modal.innerText();
    expect(modalText, 'sesi terlama (S1) tidak tampil').not.toContain('qa27oldest');
    expect(modalText, 'draft tidak tampil').not.toContain('qa27draft');
    expect(modalText, 'judul overview').toContain('Riwayat Opname');
    expect(modalText, 'catatan Handover/Receive/Store').toContain('Handover, Receive, dan Store adalah aktivitas Archive, bukan tipe transaksi');
    expect(modalText, 'tanpa NaN').not.toMatch(/NaN/);
    expect(modalText, 'tanpa objek mentah').not.toMatch(/\[object Object\]/);

    const reachAll = await columnReach(page, modal.locator('.ant-table').nth(1));
    monitor.note(`keterjangkauan kolom tabel sesi (modal 1440 px): ${JSON.stringify(reachAll)}`);
    expect(reachAll.reach, `kolom terakhir (${reachAll.last}) terjangkau lewat gulir horizontal`).toBe(true);
    const reachType = await columnReach(page, modal.locator('.ant-table').nth(0));
    monitor.note(`keterjangkauan kolom tabel tipe (modal): ${JSON.stringify(reachType)}`);
    expect(reachType.reach, `kolom terakhir tabel tipe (${reachType.last}) terjangkau`).toBe(true);
    await app.screenshot('ac16-00-modal-kolom-sesi-digulir');

    // AC-19: CTA tidak dirender (belum ada handler), baris sesi tidak bisa diklik
    await expect(modal.getByText('Lihat semua riwayat'), 'CTA "Lihat semua riwayat" tidak dirender').toHaveCount(0);
    await expect(modal.getByText(/Lihat semua history/), 'CTA (en) tidak dirender').toHaveCount(0);
    const nBefore = calls.length;
    await modal.locator('.ant-table').nth(1).locator('tr.ant-table-row').first().click();
    await modal.locator('.ant-table').nth(1).locator('tr.ant-table-row').nth(1).locator('td').nth(2).click();
    await page.waitForTimeout(1200);
    expect(calls.length, 'klik baris sesi: tanpa request').toBe(nBefore);
    await expect(modal, 'klik baris sesi: modal tetap, tanpa drawer baru').toBeVisible();
    await expect(page.locator('.ant-drawer-open'), 'klik baris sesi: tanpa drawer').toHaveCount(0);
    await app.screenshot('ac16-01-modal-all-archive');

    // Close menutup; buka lagi = request baru
    await modal.locator('.ant-modal-footer button').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'Close menutup modal').toHaveCount(0, { timeout: 10000 });
    const resp2 = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    expect((await resp2).status(), 'buka lagi: GET verifications baru').toBe(200);
    await modalVisible(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    expect((await verifCalls(calls)).length, 'dua pembukaan = dua request').toBe(2);
    // ikon ✕ juga menutup
    await modalVisible(page).locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'ikon X menutup modal').toHaveCount(0, { timeout: 10000 });

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-17 drawer folder
  test('[default] ED-1027 AC-17/AC-18/AC-19 tab Verification di drawer folder: lazy, angka = kolom folder, sesi varian folder, Save tanpa field verifikasi', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    await openArchive(app, page);

    // --- ALPHA
    const tabNames = await openDrawerView(page, `${P}ALPHA`);
    monitor.note(`tab drawer ALPHA: ${JSON.stringify(tabNames)}`);
    expect(tabNames, 'tab: Rincian, Izin, Verifikasi (urutan itu)').toEqual(['Rincian', 'Izin', 'Verifikasi']);
    expect((await verifCalls(calls)).length, 'drawer dibuka: belum ada GET verifications').toBe(0);
    await expect(drawer(page).locator('.ant-drawer-extra button.ant-btn-primary'), 'tombol Simpan di header drawer tetap ada').toBeVisible();

    const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
    await tabs(page).nth(2).click();
    const vr = await resp;
    expect(vr.status(), 'GET verifications/{id} 200').toBe(200);
    expect(vr.url(), 'GET verifications/{id ALPHA}').toContain(`/verifications/${fx[`${P}ALPHA`]}`);
    const body = (await vr.json()).result;
    const pane = drawer(page).locator('.ant-tabs-tabpane-active');
    await pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    expect((await verifCalls(calls)).length, 'tab dibuka: tepat satu GET verifications/{id}').toBe(1);
    expect(body.id_archive, 'respons id_archive = ALPHA').toBe(fx[`${P}ALPHA`]);

    const card = await readCard(pane);
    monitor.note(`kartu tab ALPHA: ${card.text}`);
    expect({ verified: card.verified, total: card.total }, 'kartu = 4/8 (angka tangan = kolom ALPHA di list)').toEqual({ verified: 4, total: 8 });
    expect({ verified: body.verified, total: body.total }, 'respons = 4/8').toEqual({ verified: 4, total: 8 });
    expect(card.percent, 'progress 50 %').toBeCloseTo(50, 1);
    expect(card.text, 'kalimat folder').toContain('4 dokumen terverifikasi dari 8 dokumen di folder ini');
    const typeRows = await tableRows(pane, 0);
    expect(typeRows, 'tabel tipe ALPHA = respons BE').toEqual(expectedTypeRows(body).map(formatRow));
    expect(typeRows, 'tabel tipe ALPHA = angka tangan').toEqual([
      ['Pesanan Penjualan', '2', '1', '3'],
      ['Pengantaran Pesanan', '1', '1', '2'],
      ['Faktur Penjualan', '0', '2', '2'],
      ['Retur Penjualan', '1', '0', '1'],
      ['Pembayaran Piutang', '0', '0', '0'],
      ['Bilyet Giro/Cek', '0', '0', '0'],
      ['Penagihan', '0', '0', '0'],
      ['Total', '4', '4', '8'],
    ]);
    const sess = await tableRows(pane, 1);
    expect(sess, 'sesi folder: hanya S4 (potongan ALPHA 8 dokumen / 4 verified)').toEqual([['06 Okt 2026 16:40', 'qa27alpha', '0QA27-ALPHA-S1 + 1 subfolder', '8', '4']]);
    const sheads = trimAll(await pane.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean).map((h) => h.toLowerCase());
    monitor.note(`kolom sesi varian folder: ${JSON.stringify(sheads)}`);
    expect(sheads, 'kolom sesi varian folder').toEqual(['waktu', 'pengguna', 'scope sesi', 'dokumen folder', 'terverifikasi di folder ini']);
    expect(body.last_sessions[0].folder_total_documents, 'respons folder_total_documents').toBe(8);
    expect(body.last_sessions[0].folder_verified, 'respons folder_verified').toBe(4);
    const reachFolder = await columnReach(page, pane.locator('.ant-table').nth(1));
    monitor.note(`keterjangkauan kolom tabel sesi (drawer): ${JSON.stringify(reachFolder)}`);
    expect(reachFolder.reach, `kolom terakhir (${reachFolder.last}) terjangkau di drawer`).toBe(true);
    const reachFolderType = await columnReach(page, pane.locator('.ant-table').nth(0));
    monitor.note(`keterjangkauan kolom tabel tipe (drawer): ${JSON.stringify(reachFolderType)}`);
    expect(reachFolderType.reach, `kolom terakhir tabel tipe (${reachFolderType.last}) terjangkau di drawer`).toBe(true);
    const text = await pane.innerText();
    expect(text, 'catatan read-only').toContain('Read-only. Handover, Receive, dan Store adalah aktivitas Archive');
    await expect(pane.getByText('Lihat semua riwayat'), 'CTA tidak dirender di tab').toHaveCount(0);
    expect(text, 'tanpa NaN / objek mentah').not.toMatch(/NaN|\[object Object\]/);
    await app.screenshot('ac17-01-tab-verifikasi-ALPHA');

    // sel kolom folder di list = angka kartu
    const cellInList = await cellText(page, `${P}ALPHA`);
    expect(cellInList, 'kolom Dokumen Terverifikasi ALPHA di list = kartu').toBe(`${card.verified}/${card.total}`);

    // pindah tab tidak me-request ulang
    await tabs(page).nth(1).click();
    await page.waitForTimeout(500);
    await tabs(page).nth(2).click();
    await page.waitForTimeout(800);
    expect((await verifCalls(calls)).length, 'pindah tab (Izin <-> Verifikasi): tanpa request ulang').toBe(1);

    // Detail: isian tetap sesudah kembali dari tab Verifikasi; Save hanya mengirim field Detail/Permission
    await tabs(page).nth(0).click();
    await page.waitForTimeout(500);
    const desc = drawer(page).locator('textarea#description');
    await expect(desc, 'tab Rincian punya isian deskripsi').toBeVisible();
    const nameInput = drawer(page).locator('input#name');
    const nameVal = await nameInput.inputValue();
    expect(nameVal, 'tab Rincian: nama terisi').toBe(`${P}ALPHA`);
    await desc.fill('QA27 deskripsi uji');
    await tabs(page).nth(2).click();
    await page.waitForTimeout(600);
    await tabs(page).nth(0).click();
    await page.waitForTimeout(500);
    await expect(desc, 'kembali ke Rincian: deskripsi yang diketik tetap').toHaveValue('QA27 deskripsi uji');
    await expect(nameInput, 'kembali ke Rincian: nama tetap').toHaveValue(`${P}ALPHA`);
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/document-archive\/archives\//.test(r.url()), { timeout: 30000 });
    await drawer(page).locator('.ant-drawer-extra button.ant-btn-primary').click();
    const pr = await put;
    expect(pr.status(), 'PUT archives/{id} 200').toBe(200);
    const putBody = JSON.parse(pr.request().postData());
    monitor.note(`body PUT drawer: ${JSON.stringify(Object.keys(putBody))}`);
    expect(Object.keys(putBody).filter((k) => /verif|transaction|session|summary|unverified/i.test(k)), 'body Save tanpa field verifikasi').toEqual([]);
    expect(putBody.description, 'body Save memuat deskripsi Detail').toBe('QA27 deskripsi uji');
    await expect(page.locator('.ant-drawer-open'), 'drawer menutup sesudah Save').toHaveCount(0, { timeout: 15000 });
    await page.waitForTimeout(800);

    // buka ulang drawer: request lagi hanya saat tab dibuka
    const n0 = (await verifCalls(calls)).length;
    await openDrawerView(page, `${P}ALPHA`);
    await page.waitForTimeout(800);
    expect((await verifCalls(calls)).length, 'drawer dibuka lagi: belum ada request verifikasi baru').toBe(n0);
    const resp3 = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
    await tabs(page).nth(2).click();
    await resp3;
    expect((await verifCalls(calls)).length, 'tab dibuka lagi: request baru').toBe(n0 + 1);
    await closeTopLayer(page);

    // --- subfolder ALPHA-S1 (di dalam ALPHA): potongan sesi S4 = S1 + S1-X
    await openFolder(page, `${P}ALPHA`);
    await openDrawerView(page, `${P}ALPHA-S1`);
    await tabs(page).nth(2).click();
    const s1pane = drawer(page).locator('.ant-tabs-tabpane-active');
    await s1pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const c1 = await readCard(s1pane);
    expect({ verified: c1.verified, total: c1.total }, 'ALPHA-S1 kartu = 2/3').toEqual({ verified: 2, total: 3 });
    expect(await tableRows(s1pane, 1), 'ALPHA-S1: sesi S4 mencakup lewat S1 + S1-X: potongan 3 dokumen / 2 verified').toEqual([['06 Okt 2026 16:40', 'qa27alpha', '0QA27-ALPHA-S1 + 1 subfolder', '3', '2']]);
    await app.screenshot('ac17-02-tab-verifikasi-ALPHA-S1');
    await closeTopLayer(page);
    await openArchive(app, page);

    // --- BETA: sesi root (S3) + sesi BETA (S1), potongan 3/2 masing-masing, terbaru di atas
    await openDrawerView(page, `${P}BETA`);
    await tabs(page).nth(2).click();
    const bpane = drawer(page).locator('.ant-tabs-tabpane-active');
    await bpane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const cb = await readCard(bpane);
    expect({ verified: cb.verified, total: cb.total }, 'BETA kartu = 2/3').toEqual({ verified: 2, total: 3 });
    expect(await tableRows(bpane, 1), 'BETA: S3 (root) lalu S1 (BETA)').toEqual([
      ['05 Okt 2026 09:15', 'qa27root', 'All Archive · root', '3', '2'],
      ['01 Okt 2026 08:00', 'qa27oldest', '0QA27-BETA', '3', '2'],
    ]);
    await closeTopLayer(page);

    // --- GAMMA: satu sesi (S2), 0/3 sekarang walau sesi mencatat 1 verified (snapshot, bukan angka saat ini)
    await openDrawerView(page, `${P}GAMMA`);
    await tabs(page).nth(2).click();
    const gpane = drawer(page).locator('.ant-tabs-tabpane-active');
    await gpane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const cg = await readCard(gpane);
    expect({ verified: cg.verified, total: cg.total }, 'GAMMA kartu = 0/3 (saat ini)').toEqual({ verified: 0, total: 3 });
    expect(await tableRows(gpane, 1), 'GAMMA: sesi S2 = potongan snapshot 3 dokumen / 1 verified').toEqual([['04 Okt 2026 14:30', 'qa27gamma', '0QA27-GAMMA', '3', '1']]);
    await closeTopLayer(page);

    // --- EMPTY (AC-18): 0 / 0, progress 0 %, tanpa NaN, sesi kosong
    await openDrawerView(page, `${P}EMPTY`);
    await tabs(page).nth(2).click();
    const epane = drawer(page).locator('.ant-tabs-tabpane-active');
    await epane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const ce = await readCard(epane);
    expect({ verified: ce.verified, total: ce.total, percent: ce.percent }, 'EMPTY: 0 / 0, progress 0 %').toEqual({ verified: 0, total: 0, percent: 0 });
    const etext = await epane.innerText();
    expect(etext, 'EMPTY: tanpa NaN').not.toMatch(/NaN/);
    expect(await tableRows(epane, 0), 'EMPTY: semua baris tipe 0, Total 0').toEqual([
      ['Pesanan Penjualan', '0', '0', '0'], ['Pengantaran Pesanan', '0', '0', '0'], ['Faktur Penjualan', '0', '0', '0'], ['Retur Penjualan', '0', '0', '0'],
      ['Pembayaran Piutang', '0', '0', '0'], ['Bilyet Giro/Cek', '0', '0', '0'], ['Penagihan', '0', '0', '0'], ['Total', '0', '0', '0'],
    ]);
    expect(await tableRows(epane, 1), 'EMPTY: tanpa sesi = tabel kosong').toEqual([]);
    await expect(epane.locator('.ant-table').nth(1).locator('.ant-empty'), 'EMPTY: empty state standar').toHaveCount(1);
    await app.screenshot('ac18-01-tab-verifikasi-EMPTY');
    await closeTopLayer(page);

    // --- VIEWONLY (hak View saja): drawer read-only (tanpa Simpan), tab Verifikasi tetap ada dan berisi angka
    const vtabs = await openDrawerView(page, `${P}VIEWONLY`);
    expect(vtabs, 'VIEWONLY: tab Verifikasi tetap ada').toContain('Verifikasi');
    await expect(drawer(page).locator('.ant-drawer-extra button.ant-btn-primary'), 'VIEWONLY: tanpa tombol Simpan (read-only)').toHaveCount(0);
    await tabs(page).nth(vtabs.indexOf('Verifikasi')).click();
    const vpane = drawer(page).locator('.ant-tabs-tabpane-active');
    await vpane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    const cv = await readCard(vpane);
    expect({ verified: cv.verified, total: cv.total }, 'VIEWONLY kartu = 0/1').toEqual({ verified: 0, total: 1 });
    await app.screenshot('ac17-03-tab-verifikasi-VIEWONLY-readonly');
    await closeTopLayer(page);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-18 tanpa sesi / total 0 (respons dimodifikasi)
  test('[default] ED-1027 AC-18 modal All Archive tanpa dokumen dan tanpa sesi: 0 / 0, progress 0 %, tabel sesi kosong, tanpa NaN', async ({ app, page, monitor }) => {
    await openArchive(app, page);
    // respons GET verifications diganti: semua angka 0 dan tanpa sesi (bentuk BE, hanya nilai yang diubah)
    await page.route(/\/document-archive\/verifications(\?|$)/, async (route) => {
      const real = await route.fetch();
      const json = await real.json();
      json.result.verified = 0;
      json.result.unverified = 0;
      json.result.total = 0;
      json.result.transaction_types = json.result.transaction_types.map((t) => ({ ...t, verified: 0, unverified: 0, total: 0 }));
      json.result.last_sessions = [];
      await route.fulfill({ response: real, json });
    });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    const modal = modalVisible(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const card = await readCard(modal);
    expect({ verified: card.verified, total: card.total, percent: card.percent }, 'kartu 0 / 0, progress 0 %').toEqual({ verified: 0, total: 0, percent: 0 });
    const text = await modal.innerText();
    expect(text, 'tanpa NaN').not.toMatch(/NaN/);
    const typeRows = await tableRows(modal, 0);
    expect(typeRows.length, '8 baris (7 tipe + Total)').toBe(8);
    expect(typeRows.every((r) => r.slice(1).every((v) => v === '0')), 'semua baris bernilai 0').toBe(true);
    expect(await tableRows(modal, 1), 'tanpa sesi: tabel overview kosong').toEqual([]);
    await expect(modal.locator('.ant-table').nth(1).locator('.ant-empty'), 'empty state standar').toHaveCount(1);
    await app.screenshot('ac18-02-modal-kosong');
    await page.unroute(/\/document-archive\/verifications(\?|$)/);
    await closeTopLayer(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-20 regresi list
  test('[default] ED-1027 AC-20 regresi: sort, filter Type, pencarian, scan (scanner USB), mode Pilih, menu "+" dan modal Letakkan/Serahkan/Terima', async ({ app, page, monitor }) => {
    const calls = trace(page);
    const first = await openArchive(app, page);
    const apiSum = first.body.result.document_verified_summary;

    // sort kolom Nama (header yang bisa di-sort tetap jalan)
    const sortResp = listResponse(page, (u) => /sort/i.test(decodeURIComponent(u.search)));
    await page.locator('.archive-page .ant-table-thead th', { hasText: /^\s*Nama\s*$/ }).first().click();
    const sr = await sortResp;
    expect(sr.status, 'sort Nama: GET archives 200').toBe(200);
    expect(sr.body.result.document_verified_summary, 'sort: ringkasan sama').toEqual(apiSum);
    await page.waitForTimeout(800);
    const sorted = trimAll(await page.locator('.archive-page .ant-table-tbody tr.ant-table-row td:nth-child(2)').allInnerTexts());
    monitor.note(`sort Nama: ${JSON.stringify(sorted.slice(0, 4))}`);
    expect(sorted.length, 'sort: ada baris').toBeGreaterThan(0);
    expect((await readSummary(page)).total, 'sort: ringkasan tetap tampil').toBe(apiSum.total);

    // filter Type = Folder
    await page.locator('.archive-page .search-config').first().click();
    const pop = page.locator('.archive-page .search-bar-overlay').first();
    await pop.locator('.ant-select').first().waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(700);
    await pop.locator('.ant-select').first().click();
    const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
    await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
    const opts = trimAll(await dd.locator('.ant-select-item-option').allInnerTexts());
    monitor.note(`opsi Type: ${JSON.stringify(opts)}`);
    const folderOpt = dd.locator('.ant-select-item-option', { hasText: /^(Folder)$/ }).first();
    await folderOpt.click();
    const fResp = listResponse(page, (u) => /FOLDER/i.test(u.searchParams.get('search') || ''));
    await pop.locator('button[type="submit"], button.ant-btn-primary').filter({ hasText: /Cari|Search/ }).first().click();
    const fr = await fResp;
    expect(fr.status, 'filter Type=Folder: GET archives 200').toBe(200);
    expect(fr.body.result.document_verified_summary, 'filter: ringkasan tetap All Archive').toEqual(apiSum);
    await page.waitForTimeout(800);
    const types = trimAll(await page.locator('.archive-page .ant-table-tbody tr.ant-table-row td:nth-child(4)').allInnerTexts());
    expect(types.length, 'filter: ada baris').toBeGreaterThan(0);
    expect(types.every((t) => t === 'Folder'), 'filter Type=Folder: semua baris Folder').toBe(true);
    expect({ verified: (await readSummary(page)).verified, total: (await readSummary(page)).total }, 'filter: ringkasan UI sama').toEqual(apiSum);
    await app.screenshot('ac20-01-filter-type-folder');
    const rs = listResponse(page, () => true);
    await page.locator('.archive-page .reset-filter').first().click();
    await rs;
    await page.waitForTimeout(800);

    // pencarian lewat input (dipakai juga di AC-13) + scanner USB (ketikan cepat + Enter di luar input)
    await page.locator('.archive-page').click({ position: { x: 5, y: 5 } }).catch(() => null);
    const scanResp = listResponse(page, (u) => /0QA27-RD-V/.test(u.searchParams.get('search') || ''));
    await page.keyboard.type(`${P}RD-V`, { delay: 0 });
    await page.keyboard.press('Enter');
    const sc = await scanResp;
    expect(sc.status, 'scanner USB: GET archives?search=kode').toBe(200);
    expect(sc.body.result.document_verified_summary, 'scanner: ringkasan sama').toEqual(apiSum);
    await page.waitForTimeout(800);
    expect(await docIcon(page, `${P}RD-V`), 'hasil scan: dokumen RD-V berikon verified').toBe('verified');
    await app.screenshot('ac20-02-scan-usb');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await page.waitForTimeout(800);

    // transaksi terkait: filter "Tampilkan Transaksi Terkait" + dokumen nyata yang punya transaksi terkait (baca saja):
    // baris terkait tidak membawa document_verified -> sel kosong, tanpa crash
    await page.locator('.archive-page .search-config').first().click();
    const pop2 = page.locator('.archive-page .search-bar-overlay').first();
    await pop2.locator('.ant-select').first().waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(700);
    await page.locator('.archive-page .search-bar input').first().fill('DO-JOG/2511/00003');
    await pop2.locator('.ant-checkbox-input').first().check();
    const relResp = listResponse(page, (u) => /showRelatedTransaction/.test(u.searchParams.get('search') || ''));
    await pop2.locator('button').filter({ hasText: /Cari|Search/ }).first().click();
    const rel = await relResp;
    expect(rel.status, 'transaksi terkait: GET archives 200').toBe(200);
    const relRows = rowsOf(rel);
    expect(relRows.length, 'transaksi terkait: 1 dokumen hasil').toBe(1);
    expect((relRows[0].related_transactions || []).length, 'dokumen nyata punya transaksi terkait').toBeGreaterThan(0);
    const relName = relRows[0].related_transactions[0].name;
    const relNo = Array.isArray(relName) ? relName[0].transaction_no : relName;
    await page.waitForTimeout(1200);
    expect(await docIcon(page, 'DO-JOG/2511/00003'), 'dokumen induk: ikon sesuai respons').toBe(relRows[0].document_verified.verified === 1 ? 'verified' : 'unverified');
    expect(await cellText(page, relNo), `baris transaksi terkait (${relNo}): sel Dokumen Terverifikasi kosong`).toBe('');
    expect(await page.locator('.archive-page .ant-table-tbody').innerText(), 'transaksi terkait: tanpa objek mentah').not.toMatch(/\[object Object\]/);
    await app.screenshot('ac20-02b-transaksi-terkait');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await page.waitForTimeout(800);

    // mode Pilih: checkbox muncul, Batal Pilih mengembalikan
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await page.waitForTimeout(500);
    await expect(page.locator('.archive-page .ant-table-tbody input[type="checkbox"]').first(), 'mode Pilih: checkbox baris').toBeVisible();
    await expect(page.locator('.archive-page button', { hasText: /Batal Pilih|Cancel/ }).first(), 'mode Pilih: tombol Batal Pilih').toBeVisible();
    await expect(summaryBox(page), 'mode Pilih: ringkasan tetap ada').toBeVisible();
    await page.locator('.archive-page button', { hasText: /Batal Pilih|Cancel/ }).first().click();
    await page.waitForTimeout(500);
    await expect(page.locator('.archive-page .ant-table-tbody input[type="checkbox"]'), 'Batal Pilih: checkbox hilang').toHaveCount(0);

    // menu "+": item + modal terbuka tanpa error (root: Serahkan, Terima; dalam folder: Letakkan)
    const plus = () => page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
    const openPlusItem = async (re) => {
      await plus().click();
      const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
      await menu.waitFor({ state: 'visible', timeout: 10000 });
      const items = trimAll(await menu.locator('li.ant-dropdown-menu-item').allInnerTexts());
      await menu.locator('li.ant-dropdown-menu-item', { hasText: re }).first().click();
      await modalVisible(page).waitFor({ state: 'visible', timeout: 15000 });
      await app.settle();
      return items;
    };
    const rootItems = await openPlusItem(/^(Serahkan Dokumen|Hand Over Document)$/);
    monitor.note(`menu + root: ${JSON.stringify(rootItems)}`);
    expect(rootItems, 'menu + di root').toEqual(['Tambah Folder', 'Serahkan Dokumen', 'Terima Dokumen', 'Opname Dokumen']);
    await app.screenshot('ac20-03-modal-serahkan');
    await closeTopLayer(page);
    await openPlusItem(/^(Terima Dokumen|Receive Document)$/);
    await app.screenshot('ac20-04-modal-terima');
    await closeTopLayer(page);
    await openFolder(page, `${P}ALPHA`);
    const inItems = await openPlusItem(/^(Letakkan Dokumen|Store Document)$/);
    monitor.note(`menu + dalam folder: ${JSON.stringify(inItems)}`);
    expect(inItems, 'menu + dalam folder memuat Letakkan Dokumen').toContain('Letakkan Dokumen');
    await app.screenshot('ac20-05-modal-letakkan');
    await closeTopLayer(page);
    expect((await verifCalls(calls)).length, 'regresi: tanpa GET verifications yang tidak diminta').toBe(0);

    noServerError(monitor);
    monitor.check();
  });


  // ---------------------------------------------------------------------------------------------- jalur error (respons dimodifikasi)
  test('[default] ED-1027 jalur error: GET verifications gagal -> toast + isi kosong (tanpa "0 / 0" palsu), Close tetap menutup, buka lagi normal; sama untuk tab drawer', async ({ app, page, monitor }) => {
    const fx = ids();
    await openArchive(app, page);
    const verRe = /\/document-archive\/verifications(\/|\?|$)/;
    await page.route(verRe, async (route) => {
      await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Dokumen QA27 simulasi gagal', code: 'ARCHIVE400' }) });
    });

    // modal All Archive
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    const modal = modalVisible(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await expect(page.locator('.ant-message-notice, .ant-notification-notice').first(), 'toast error tampil').toBeVisible({ timeout: 10000 });
    await expect(modal.locator('.ant-empty'), 'modal: isi kosong (Empty)').toHaveCount(1);
    await expect(modal.locator('.ant-card'), 'modal: tanpa kartu angka').toHaveCount(0);
    expect(await modal.innerText(), 'modal: tanpa "0 / 0" palsu').not.toMatch(/\b0\s*\/\s*0\b/);
    await app.screenshot('err-01-modal-gagal-muat');
    await modal.locator('.ant-modal-footer button').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'Close tetap menutup modal').toHaveCount(0, { timeout: 10000 });

    // tab Verification drawer
    await openDrawerView(page, `${P}ALPHA`);
    await tabs(page).nth(2).click();
    const pane = drawer(page).locator('.ant-tabs-tabpane-active');
    await expect(pane.locator('.ant-empty'), 'tab: isi kosong (Empty)').toHaveCount(1, { timeout: 15000 });
    await expect(pane.locator('.ant-card'), 'tab: tanpa kartu angka').toHaveCount(0);
    expect(await pane.innerText(), 'tab: tanpa "0 / 0" palsu').not.toMatch(/\b0\s*\/\s*0\b/);
    // tab Rincian tetap normal
    await tabs(page).nth(0).click();
    await expect(drawer(page).locator('input#name'), 'tab Rincian tetap terisi').toHaveValue(`${P}ALPHA`);
    await closeTopLayer(page);

    expectRejected(monitor, 'verifications dimodifikasi 404', { status: 404, urlRe: /\/document-archive\/verifications/ });
    await page.unroute(verRe);

    // tanpa modifikasi: buka lagi = normal
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    const m2 = modalVisible(page);
    await m2.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    const card = await readCard(m2);
    expect(card.total, 'sesudah pulih: kartu normal').toBeGreaterThan(0);
    await closeTopLayer(page);
    expect(fx._expect.summary_verified, 'fixture dikenali').toBe(7);
    noServerError(monitor);
    monitor.check();
  });


  // ---------------------------------------------------------------------------------------------- layar akses ditolak: ringkasan hilang
  test('[default] ED-1027 BR-6 layar akses ditolak (folder tanpa View): ringkasan tidak tampil, Back ke Archive = ringkasan muncul lagi', async ({ app, page, monitor }) => {
    const fx = ids();
    const first = await openArchive(app, page);
    const apiSum = first.body.result.document_verified_summary;
    await readSummary(page);
    expect(await cellText(page, `${P}NOVIEW`), 'NOVIEW tanpa View: sel "-"').toBe('-');

    const denied = listResponse(page, (u) => folderParam(u) === fx[`${P}NOVIEW`]);
    await rowOf(page, `${P}NOVIEW`).locator('td.cursor-pointer').first().click();
    const dr = await denied;
    expect(dr.status, 'GET archives?id_archive=NOVIEW = 403').toBe(403);
    const screen = page.locator('.archive-access-denied');
    await screen.waitFor({ state: 'visible', timeout: 15000 });
    await expect(summaryBox(page), 'layar akses ditolak: ringkasan tidak tampil').toHaveCount(0);
    expect(await page.locator('.archive-page').innerText(), 'layar akses ditolak: tanpa teks ringkasan').not.toContain('seluruh dokumen di sistem');
    await app.screenshot('br6-03-akses-ditolak-tanpa-ringkasan');
    expectRejected(monitor, 'akses ditolak NOVIEW', { status: 403, urlRe: /\/document-archive\/archives\?/ });

    const back = listResponse(page, (u) => !folderParam(u));
    await screen.locator('button').first().click();
    const br = await back;
    expect(br.status, 'Back to Archive: GET archives root 200').toBe(200);
    await expect(page.locator('.archive-access-denied'), 'layar akses ditolak hilang').toHaveCount(0);
    await page.waitForTimeout(600);
    const sum = await readSummary(page);
    expect({ verified: sum.verified, total: sum.total }, 'Back to Archive: ringkasan muncul lagi (angka sama)').toEqual(apiSum);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-13 [user2] + bahasa EN
  test('[user2] ED-1027 AC-13/16/17 user lokasi terbatas (bahasa EN): kolom, ringkasan, modal, tab Verification dengan angka user itu', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    const first = await openArchive(app, page);
    const res0 = first.body.result;
    const apiSum = res0.document_verified_summary;
    const heads = await headTexts(page);
    monitor.note(`[user2] header: ${JSON.stringify(heads)}`);
    expect(heads, '[user2] kolom Document Verified (EN)').toContain('Document Verified');
    const iDv = heads.indexOf('Document Verified');
    expect(heads[iDv - 1], '[user2] sesudah Salesman').toBe('Salesman');

    // ringkasan (EN) = respons; angka user2 <= angka seluruh dokumen (user QA_USER: dokumen aktif + 17)
    const sum = await readSummary(page, summaryBoxEn(page));
    monitor.note(`[user2] ringkasan: ${sum.text} | respons ${JSON.stringify(apiSum)}`);
    expect({ verified: sum.verified, total: sum.total }, '[user2] ringkasan UI = respons').toEqual(apiSum);
    expect(sum.total, '[user2] total <= dokumen aktif + 17').toBeLessThanOrEqual(fx._expect.summary_total);
    await expect(page.locator('.archive-page').getByText('See detail per transaction type', { exact: false }).first(), '[user2] tautan (EN)').toBeVisible();
    const cellOf = async (name) => {
      const i = heads.indexOf('Document Verified');
      return trimAll([await rowOf(page, name).locator('td').nth(i).innerText()])[0];
    };
    // fixture is_all_location=1: terlihat user2; folder permission (VIEWONLY punya baris hanya untuk QA_USER) -> "-"
    const apiOf = (name) => (rowsOf({ body: first.body }).find((r) => (Array.isArray(r.name) ? r.name[0].transaction_no : r.name) === name) || {}).document_verified;
    for (const k of ['ALPHA', 'BETA', 'EMPTY', 'GAMMA', 'VIEWONLY', 'NOVIEW']) {
      const api = apiOf(`${P}${k}`);
      const expected = api === null ? '-' : `${fmt(api.verified)}/${fmt(api.total)}`;
      expect(await cellOf(`${P}${k}`), `[user2] sel ${k} = respons`).toBe(expected);
    }
    expect(await cellOf(`${P}ALPHA`), '[user2] ALPHA = 4/8').toBe('4/8');
    expect(await cellOf(`${P}NOVIEW`), '[user2] NOVIEW tanpa View = -').toBe('-');
    expect(await cellOf(`${P}VIEWONLY`), '[user2] VIEWONLY (hak hanya untuk QA_USER) = -').toBe('-');
    await app.screenshot('user2-01-root');

    // modal (EN)
    const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('See detail per transaction type', { exact: false }).first().click();
    const body = (await (await resp).json()).result;
    const modal = modalVisible(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const title = (await modal.locator('.ant-modal-title').innerText()).replace(/\s+/g, ' ');
    monitor.note(`[user2] judul modal: ${title}`);
    expect(title, '[user2] judul EN').toContain('Document Verified');
    expect(title, '[user2] subjudul EN').toContain('All Archive — all documents in the system');
    const card = await readCard(modal);
    expect({ verified: card.verified, total: card.total }, '[user2] kartu = ringkasan').toEqual(apiSum);
    expect({ verified: body.verified, total: body.total }, '[user2] respons = ringkasan').toEqual(apiSum);
    expect(card.text, '[user2] kalimat EN').toContain(`${fmt(card.verified)} documents verified out of ${fmt(card.total)} documents in all folders`);
    const typeRows = await tableRows(modal, 0);
    expect(typeRows, '[user2] tabel tipe = respons').toEqual(expectedTypeRows(body).map(formatRow));
    const sessions = await tableRows(modal, 1);
    monitor.note(`[user2] sesi: ${JSON.stringify(sessions)}`);
    // dijalankan sesudah profil default: bila test Confirm Opname (GAMMA) sudah jalan, ada satu sesi baru di atas dan S2 keluar dari 3 teratas
    expect(sessions.map((r) => r[1]), '[user2] sesi di UI = respons BE (urutan sama)').toEqual(body.last_sessions.map((x) => x.created_by));
    expect(body.last_sessions.length, '[user2] respons: 3 sesi').toBe(3);
    const who = sessions.map((r) => r[1]);
    expect(who.indexOf('qa27alpha'), '[user2] sesi S4 (ALPHA, folder terlihat) tampil').toBeGreaterThanOrEqual(0);
    expect(who.indexOf('qa27root'), '[user2] sesi S3 (root) tampil: sesi root terlihat semua pemegang List Archive').toBeGreaterThan(who.indexOf('qa27alpha'));
    expect(who.join(' '), '[user2] sesi terlama (S1) dan draft tidak tampil').not.toMatch(/qa27oldest|qa27draft/);
    const mtext = await modal.innerText();
    expect(mtext, '[user2] judul overview EN').toContain('Opname History');
    expect(mtext, '[user2] catatan EN').toContain('Handover, Receive, and Store are Archive activities, not transaction types');
    const sheads = trimAll(await modal.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean);
    monitor.note(`[user2] kolom sesi: ${JSON.stringify(sheads)}`);
    expect(mtext, '[user2] tanpa objek mentah').not.toMatch(/\[object Object\]/);
    const s4row = sessions.find((r) => r[1] === 'qa27alpha');
    expect(s4row[0], '[user2] tanggal sesi memakai bulan EN').toBe('06 Oct 2026 16:40');
    await app.screenshot('user2-02-modal-en');
    await closeTopLayer(page);

    // drawer ALPHA (EN): tab Verification
    const tabNames = await openDrawerView(page, `${P}ALPHA`);
    monitor.note(`[user2] tab drawer: ${JSON.stringify(tabNames)}`);
    expect(tabNames.length, '[user2] tiga tab').toBe(3);
    expect(tabNames[2], '[user2] tab ketiga = Verification').toBe('Verification');
    const r2 = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
    await tabs(page).nth(2).click();
    const vb = (await (await r2).json()).result;
    const pane = drawer(page).locator('.ant-tabs-tabpane-active');
    await pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    const c2 = await readCard(pane);
    expect({ verified: c2.verified, total: c2.total }, '[user2] kartu ALPHA = 4/8').toEqual({ verified: 4, total: 8 });
    expect({ verified: vb.verified, total: vb.total }, '[user2] respons ALPHA').toEqual({ verified: 4, total: 8 });
    expect(c2.text, '[user2] kalimat folder EN').toContain('4 documents verified out of 8 documents in this folder');
    const psheads = trimAll(await pane.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean).map((h) => h.toLowerCase());
    expect(psheads, '[user2] kolom sesi folder (EN)').toEqual(['time', 'user', 'session scope', 'folder documents', 'verified in this folder']);
    expect(await tableRows(pane, 1), '[user2] sesi folder ALPHA').toEqual([['06 Oct 2026 16:40', 'qa27alpha', '0QA27-ALPHA-S1 + 1 subfolder', '8', '4']]);
    expect(await pane.innerText(), '[user2] catatan folder EN').toContain('Read-only. Handover, Receive, and Store are Archive activities');
    await app.screenshot('user2-03-tab-verification-en');
    await closeTopLayer(page);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- BR-6: sesudah Confirm Opname (UI) angka diperbarui
  test('[default] ED-1027 BR-6/AC-12 Confirm Opname GAMMA lewat UI: list dimuat ulang, ringkasan + kolom folder + modal naik, sesi baru di baris teratas', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    const first = await openArchive(app, page);
    const sum0 = await readSummary(page);
    expect(await cellText(page, `${P}GAMMA`), 'sebelum: GAMMA 0/3').toBe('0/3');
    const apiSum0 = first.body.result.document_verified_summary;
    expect({ verified: sum0.verified, total: sum0.total }, 'sebelum: ringkasan = respons').toEqual(apiSum0);

    // Opname dari menu baris GAMMA (tanpa subfolder: Step 1 dilewati)
    const modalRoot = page.locator('.modal-opname-archive .ant-modal-content').last();
    const foldersResp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames\/folders/.test(r.url()), { timeout: 45000 });
    await pickRowMenu(page, `${P}GAMMA`, /^Opname$/);
    await foldersResp;
    await modalRoot.waitFor({ state: 'visible', timeout: 15000 });
    const input = modalRoot.locator('input[placeholder*="Scan QR"]');
    await input.waitFor({ state: 'visible', timeout: 20000 });
    for (const code of [`${P}GD1`, `${P}GD2`]) {
      const sc = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/[^/]+\/scan/.test(r.url()), { timeout: 30000 });
      await input.click();
      await input.fill(code);
      await input.press('Enter');
      expect((await sc).status(), `scan ${code}`).toBe(200);
      await page.waitForTimeout(300);
    }
    await modalRoot.locator('button').filter({ hasText: /^Selesai Opname$/ }).click();
    await expect(modalRoot.locator('.ant-modal-title')).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(600);
    const listBefore = (await listCalls(calls)).length;
    await modalRoot.locator('button').filter({ hasText: /^Konfirmasi Opname$/ }).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    const conf = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/opnames\/[^/]+\/confirm/.test(r.url()), { timeout: 45000 });
    const reloaded = listResponse(page, () => true);
    await pop.locator('.ant-btn-primary').click();
    expect((await conf).status(), 'PUT confirm 200').toBe(200);
    await expect(page.locator('.modal-opname-archive .ant-modal-content:visible'), 'modal opname tertutup').toHaveCount(0, { timeout: 15000 });
    const rl = await reloaded;
    await page.waitForTimeout(1500);
    expect((await listCalls(calls)).length, 'sesudah Confirm: list dimuat ulang').toBeGreaterThan(listBefore);
    const apiSum1 = rl.body.result.document_verified_summary;
    monitor.note(`ringkasan sebelum ${JSON.stringify(apiSum0)} -> sesudah ${JSON.stringify(apiSum1)}`);

    // ringkasan + kolom folder naik 2 (GD1, GD2 verified)
    expect(apiSum1, 'respons: ringkasan naik 2 verified, total sama').toEqual({ verified: apiSum0.verified + 2, total: apiSum0.total });
    const sum1 = await readSummary(page);
    expect({ verified: sum1.verified, total: sum1.total }, 'UI: ringkasan diperbarui sesudah Confirm').toEqual(apiSum1);
    expect(await cellText(page, `${P}GAMMA`), 'UI: GAMMA 2/3').toBe('2/3');
    await app.screenshot('br6-01-sesudah-confirm');

    // modal: verified naik, tipe 6 dan 7 (+1 masing-masing), sesi baru teratas, S2 (terlama dari tiga) keluar dari 3 teratas
    const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    const body = (await (await resp).json()).result;
    const modal = modalVisible(page);
    await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const card = await readCard(modal);
    expect({ verified: card.verified, total: card.total }, 'modal: kartu = ringkasan baru').toEqual(apiSum1);
    expect(body.transaction_types.map((r) => r.verified), 'modal: verified per tipe naik (6: 5, 7: 3)').toEqual([5, 3, 0, 1, 0, 0, 0]);
    expect(await tableRows(modal, 0), 'modal: tabel tipe = respons').toEqual(expectedTypeRows(body).map(formatRow));
    const sess = await tableRows(modal, 1);
    monitor.note(`modal sesi sesudah Confirm: ${JSON.stringify(sess)}`);
    expect(sess.length, 'maksimal 3 sesi').toBe(3);
    expect(sess[0][2], 'sesi baru teratas: scope GAMMA').toBe(`${P}GAMMA`);
    expect(sess[0].slice(3), 'sesi baru: total 3, verified 2, not found 0, invalid 0').toEqual(['3', '2', '0', '0']);
    expect(sess.map((r) => r[1]).slice(1), 'dua sesi berikutnya: S4 lalu S3 (S2 keluar)').toEqual(['qa27alpha', 'qa27root']);
    expect((await modal.innerText()), 'S2 tidak tampil').not.toContain('qa27gamma');
    await app.screenshot('br6-02-modal-sesudah-confirm');
    await closeTopLayer(page);

    // drawer GAMMA: sesi baru (3/2) + S2 (3/1)
    await openDrawerView(page, `${P}GAMMA`);
    await tabs(page).nth(2).click();
    const pane = drawer(page).locator('.ant-tabs-tabpane-active');
    await pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const cg = await readCard(pane);
    expect({ verified: cg.verified, total: cg.total }, 'drawer GAMMA = 2/3').toEqual({ verified: 2, total: 3 });
    const gs = await tableRows(pane, 1);
    expect(gs.length, 'drawer GAMMA: 2 sesi').toBe(2);
    expect(gs[0].slice(3), 'drawer GAMMA: sesi baru 3 / 2').toEqual(['3', '2']);
    expect(gs[1], 'drawer GAMMA: S2 snapshot 3 / 1').toEqual(['04 Okt 2026 14:30', 'qa27gamma', `${P}GAMMA`, '3', '1']);
    await closeTopLayer(page);

    // DB (baca saja): GD1, GD2 verified; GD3 tidak
    const db = inspectDb();
    expect(['GD1', 'GD2', 'GD3'].map((d) => db.docs[`${P}${d}`].is_verified), 'DB: GD1, GD2 verified, GD3 tidak').toEqual([1, 1, 0]);
    expect(fx._expect.summary_verified + 2, 'angka harapan').toBe(apiSum1.verified);

    noServerError(monitor);
    monitor.check();
  });
});
