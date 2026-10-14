'use strict';

/**
 * ED-1027 - Archive: status verifikasi dokumen (kolom Document Verified, ringkasan di atas breadcrumb, modal detail per
 * Transaction Type, tab Verification di drawer folder, (rev 2026-10-08) klik V/T folder = modal verifikasi folder, dan tanpa
 * tampilan verifikasi untuk role tanpa permission `Opname Document`).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA27-"; angka
 * verified/total diketahui tangan (lihat kepala fixture.php):
 *   ALPHA 4/8 (S1 2/3, S1-X 1/1, S2 0/1) | BETA 2/3 | GAMMA 0/3 | EMPTY 0/0 | VIEWONLY 0/1 | NOVIEW "-" | dokumen root RD-V (V), RD-U
 *   Ringkasan All Archive (user QA) = dokumen aktif yang TERLIHAT user (data developer + kontribusi fixture); oracle ringkasan
 *   = respons BE (dan batas bawah 7 verified / 17 total kontribusi fixture), bukan angka mutlak: hak folder developer ikut
 *   menentukan dokumen yang terlihat (ED-1025) dan berubah di luar item ini.
 *   Sesi terkonfirmasi (ED-1026 rev: hanya status 2): S4 ALPHA ("0QA27-ALPHA + 2 subfolder"), S3 root, S2 GAMMA, S1 BETA (terlama,
 *   di luar 3 terbaru). Scope = format ED-1026 rev (K-14 a): "<folder> + N subfolder"; root id "Semua Arsip · root", en "All Archive · root".
 * User [default] = QA_USER (role 3, pemegang Opname Document + Folder Permissions, semua lokasi, bahasa ID).
 * User [user2] = QA_USER2 di PRESET role QA27-ROLE-E2E-U2 (ED-1149; dipasang fixture.php `up` sebelum login, dari NAMA permission eksplisit:
 *   List Archive/Add/Update/Delete/Move/View/Handover/Receive + Allow Access Website, TANPA Opname Document; bahasa EN, lokasi terbatas;
 *   role developer 3/5/6/22/29 tidak dibaca, tidak di-grant, tidak di-revoke; user_roles QA_USER2 dipulihkan persis di `down`):
 *   - test pertama = AC-25 (role tanpa permission: tidak ada tampilan verifikasi sama sekali, tidak ada request verifications*);
 *   - test kedua memberi PRESET itu `Opname Document` SEMENTARA lewat fixture.php grant-od (baris role_permissions yang dibuat saja,
 *     ditolak untuk role non-preset; dicabut persis di finally + revoke-od + down) lalu memuat ulang halaman (FE membaca permission dari
 *     auth/info saat mount): AC-13/16/17/26/28 versi EN dengan angka user lokasi terbatas.
 *
 * Mutasi: Display Setting (baris global modul diganti-ganti lalu dipulihkan fixture), Confirm Opname GAMMA lewat UI (POST opnames
 * tunggal, test terakhir [default]), Save drawer ALPHA (deskripsi), grant sementara Opname Document ke preset (role_permissions preset). Semuanya data
 * fixture / tabel archive_opname* / baris jurnal yang dibuang teardown.
 * Test dijalankan berurutan (E2E_WORKERS=1): satu user, satu token.
 * QA FE rev r2: default harness = 2 worker + `fullyParallel`, dan test di berkas ini mengubah state bersama (Display Setting global per
 * modul, grant `Opname Document` ke preset QA_USER2, Confirm Opname). Dua worker membuat test saling menimpa (AC-13/15/17/25 gagal acak).
 * Karena itu tiap test mengambil kunci lintas-proses (`takeStateLock`, lihat bawah) sehingga hasil tidak bergantung pada `--workers`.
 */

const fs = require('fs');
const os = require('os');
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
const COL_EN = 'Document Verified';
const SCOPE_ALPHA = `${P}ALPHA + 2 subfolder`; // ED-1026 rev K-14 a: folder opname + jumlah subfolder dicentang
const SCOPE_ROOT_ID = 'Semua Arsip · root'; // ED-1026 N-4
const SCOPE_ROOT_EN = 'All Archive · root';
const SUBTITLE_ALL_ID = 'Semua Arsip — seluruh dokumen di sistem'; // ED-1026 N-4
const SUBTITLE_ALL_EN = 'All Archive — all documents in the system';

function inspectDb() {
  const out = execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'inspect'], { encoding: 'utf8', timeout: 90000 });
  return JSON.parse(out.trim().split('\n').pop());
}

function displayDb() {
  const out = execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'display'], { encoding: 'utf8', timeout: 90000 });
  return JSON.parse(out.trim().split('\n').pop());
}

/** perintah fixture.php (grant-od / revoke-od): grant sementara `Opname Document` untuk PRESET role QA_USER2, jurnal + pencabutan persis */
function fixtureCmd(cmd) {
  return execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), cmd], { encoding: 'utf8', timeout: 90000 }).trim();
}
/** nonaktifkan (0) / aktifkan lagi (1) satu folder milik fixture (jalur error NYATA modal folder) */
const setFolderActive = (name, to) =>
  JSON.parse(execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'set-folder-active', name, String(to)], { encoding: 'utf8', timeout: 90000 }).trim().split('\n').pop());
const grantOd = () => JSON.parse(fixtureCmd('grant-od').split('\n').pop());
const revokeOd = () => fixtureCmd('revoke-od').split('\n').pop();

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
async function colIndex(page, title = COL) {
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

/**
 * Sel folder = tombol tautan (BR-17, AC-27): antd `button.ant-btn-link` berkelas `min-h-0` dan `p-0` berisi `V/T`.
 * Mengembalikan locator tombol di sel Document Verified baris `name` (bisa count 0 untuk `-` dan dokumen).
 */
const dvLink = async (page, name, title = COL) => {
  const idx = await colIndex(page, title);
  expect(idx, `kolom "${title}" ada`).toBeGreaterThanOrEqual(0);
  return rowOf(page, name).locator('td').nth(idx).locator('button');
};

/** tegakkan bentuk tautan sel folder (AC-27) dan kembalikan teksnya */
async function expectFolderLink(page, name, title = COL) {
  const link = await dvLink(page, name, title);
  await expect(link, `sel folder ${name}: tepat satu tombol`).toHaveCount(1);
  await expect(link, `sel folder ${name}: antd link`).toHaveClass(/ant-btn-link/);
  await expect(link, `sel folder ${name}: kelas min-h-0`).toHaveClass(/(^|\s)min-h-0(\s|$)/);
  await expect(link, `sel folder ${name}: kelas p-0`).toHaveClass(/(^|\s)p-0(\s|$)/);
  return trimAll([await link.innerText()])[0];
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
const verifTab = (page) => tabs(page).filter({ hasText: /^(Verifikasi|Verification)$/ });

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

// ---------------------------------------------------------------------------------------------- modal verifikasi folder (AC-26)
/** klik tautan V/T baris `name`; tunggu GET verifications/{id}; kembalikan { body, modal, url } sesudah modal siap */
async function openFolderModal(page, app, name, title = COL) {
  const link = await dvLink(page, name, title);
  await expect(link, `${name}: tautan ada`).toHaveCount(1);
  const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
  await link.click();
  const vr = await resp;
  expect(vr.status(), `${name}: GET verifications/{id} 200`).toBe(200);
  const modal = modalVisible(page);
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  await app.settle();
  return { body: (await vr.json()).result, modal, url: vr.url() };
}

async function closeModalX(page, label) {
  await modalVisible(page).locator('.ant-modal-close').click();
  await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), `${label}: ✕ menutup modal`).toHaveCount(0, { timeout: 10000 });
}

/** klik V/T tidak membuka folder / drawer dan tidak mengirim GET archives baru */
async function expectNothingOpened(page, calls, label, listN) {
  expect((await listCalls(calls)).length, `${label}: tidak ada GET archives baru`).toBe(listN);
  await expect(page.locator('.ant-drawer-open'), `${label}: tanpa drawer`).toHaveCount(0);
}

// =====================================================================================================================
/**
 * Kunci lintas-proses (mkdir atomik) untuk test berkas ini: state bersama (Display Setting global, grant role, sesi opname) tidak
 * boleh ditimpa test lain yang jalan bersamaan di worker/profil lain. Kunci basi (> 5 menit, worker mati) diambil alih.
 */
const STATE_LOCK = path.join(os.tmpdir(), 'ed1027-e2e-state.lock');
async function takeStateLock(maxWaitMs = 600000) {
  const t0 = Date.now();
  for (;;) {
    try {
      fs.mkdirSync(STATE_LOCK);
      fs.writeFileSync(path.join(STATE_LOCK, 'at'), String(Date.now()));
      return Date.now() - t0;
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      let at = 0;
      try {
        at = Number(fs.readFileSync(path.join(STATE_LOCK, 'at'), 'utf8'));
      } catch (_) {
        at = 0;
      }
      if (at && Date.now() - at > 300000) {
        fs.rmSync(STATE_LOCK, { recursive: true, force: true });
        continue;
      }
      if (Date.now() - t0 > maxWaitMs) throw new Error('kunci state e2e ED-1027 tidak kunjung terlepas');
      await new Promise((r) => setTimeout(r, 400));
    }
  }
}
let stateLockHeld = false; // per worker (satu test sekali jalan)
const releaseStateLock = () => {
  if (!stateLockHeld) return;
  stateLockHeld = false;
  fs.rmSync(STATE_LOCK, { recursive: true, force: true });
};

test.describe('ED-1027 status verifikasi dokumen', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    const waited = await takeStateLock();
    stateLockHeld = true;
    // menunggu kunci tidak boleh memakan jatah waktu test itu sendiri
    testInfo.setTimeout(testInfo.timeout + waited);
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  test.afterEach(async () => {
    releaseStateLock();
  });

  // ---------------------------------------------------------------------------------------------- AC-13 root / folder / pencarian
  test('[default] ED-1027 AC-13/AC-27 kolom Document Verified + ringkasan: root, dalam folder, pencarian, reload, breadcrumb; folder V/T = tautan, dokumen ikon, "-" bukan tombol', async ({ app, page, monitor }) => {
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

    // (rev FB-1, AC-27) sel folder = tombol tautan antd (button.ant-btn-link.min-h-0.p-0) berisi V/T; "-" dan ikon dokumen bukan tombol
    for (const [k, v] of Object.entries(hand)) {
      if (v === '-') {
        await expect(await dvLink(page, `${P}${k}`), `sel ${k} ("-" folder tanpa View): bukan tombol`).toHaveCount(0);
      } else {
        expect(await expectFolderLink(page, `${P}${k}`), `tautan ${k} berisi ${v}`).toBe(v);
      }
    }
    await expect(await dvLink(page, `${P}RD-V`), 'dokumen root RD-V: ikon, bukan tombol').toHaveCount(0);
    const listBefore = (await listCalls(calls)).length;

    // klik header kolom: tidak ada sort baru
    await dvTh.click();
    await page.waitForTimeout(800);
    expect((await listCalls(calls)).length, 'klik header Dokumen Terverifikasi: tanpa request sort').toBe(listBefore);

    // ringkasan = documentVerifiedSummary respons; verified fixture 7; total = dokumen aktif asli + 17
    const sum = await readSummary(page);
    const apiSum = res0.document_verified_summary;
    monitor.note(`ringkasan root UI: ${sum.text} | progress ${sum.percent}% | respons ${JSON.stringify(apiSum)}`);
    expect({ verified: sum.verified, total: sum.total }, 'ringkasan UI = document_verified_summary respons').toEqual(apiSum);
    // oracle longgar: dokumen developer yang terlihat user berubah oleh item lain (hak folder ED-1025), jadi hanya batas bawah kontribusi fixture
    expect(sum.verified, 'ringkasan: verified >= 7 (kontribusi fixture)').toBeGreaterThanOrEqual(7);
    expect(sum.total, 'ringkasan: total >= 17 (kontribusi fixture terlihat)').toBeGreaterThanOrEqual(17);
    expect(sum.verified, 'ringkasan: verified <= total').toBeLessThanOrEqual(sum.total);
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
    expect(await expectFolderLink(page, `${P}ALPHA-S1`), 'dalam folder: subfolder S1 = tautan 2/3').toBe('2/3');
    await expect(await dvLink(page, `${P}AD1`), 'dalam folder: dokumen AD1 bukan tombol').toHaveCount(0);
    await expect(await dvLink(page, `${P}AD2`), 'dalam folder: dokumen AD2 bukan tombol').toHaveCount(0);
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
    expect(await expectFolderLink(page, `${P}ALPHA`), 'hasil pencarian: folder ALPHA = tautan 4/8').toBe('4/8');
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
      // (rev AC-27, BR-2) picker Pindahkan / Bulk Move: nilai V/T folder teks biasa, bukan tautan
      const idx = (await modalHeads(modal)).indexOf(COL);
      const cells = modal.locator('.ant-table-tbody tr.ant-table-row').locator(`td:nth-child(${idx + 1})`);
      expect(await cells.count(), `${label}: ada sel kolom Document Verified`).toBeGreaterThan(0);
      await expect(cells.locator('button'), `${label}: sel Document Verified tanpa tombol (teks biasa)`).toHaveCount(0);
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
  test('[default] ED-1027 AC-16/AC-28/AC-29 modal Document Verified (All): satu GET verifications, header = ringkasan, tabel tipe + Total, 3 sesi (scope ED-1026 rev), catatan, tanpa footer (✕), CTA riwayat', async ({ app, page, monitor }) => {
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
    expect(title, `subjudul id "${SUBTITLE_ALL_ID}" (ED-1026 N-4)`).toContain(SUBTITLE_ALL_ID);
    expect(title, 'subjudul id bukan "All Archive"').not.toContain('All Archive');
    await expect(modal.locator('.ant-modal-footer'), 'modal tanpa footer (tutup lewat ✕)').toHaveCount(0);
    await expect(modal.locator('.ant-modal-close'), 'ikon ✕ ada').toHaveCount(1);

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
      ['06 Okt 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '8', '5', '1', '2'],
      ['05 Okt 2026 09:15', 'qa27root', SCOPE_ROOT_ID, '20', '9', '2', '1'],
      ['04 Okt 2026 14:30', 'qa27gamma', `${P}GAMMA`, '3', '1', '0', '0'],
    ]);
    const heads = trimAll(await modal.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean);
    monitor.note(`kolom sesi: ${JSON.stringify(heads)}`);
    expect(heads.map((h) => h.toLowerCase()), 'kolom sesi varian All').toEqual(['waktu', 'pengguna', 'scope', 'total dokumen', 'terverifikasi', 'tidak ditemukan', 'tidak valid']);
    expect(body.last_sessions.map((x) => x.created_by), 'respons: 3 sesi, urutan').toEqual(['qa27alpha', 'qa27root', 'qa27gamma']);
    // AC-28: scope di respons = tepat tiga kunci (tanpa first_name / other_count), format tampilan = BR-12
    for (const x of body.last_sessions) {
      expect(Object.keys(x.scope).sort(), `respons scope ${x.created_by}: tepat is_root, folder_name, subfolder_count`).toEqual(['folder_name', 'is_root', 'subfolder_count']);
    }
    expect(body.last_sessions.map((x) => x.scope), 'respons scope: S4 folder ALPHA + 2 subfolder, S3 root, S2 GAMMA').toEqual([
      { is_root: false, folder_name: `${P}ALPHA`, subfolder_count: 2 },
      { is_root: true, folder_name: null, subfolder_count: 0 },
      { is_root: false, folder_name: `${P}GAMMA`, subfolder_count: 0 },
    ]);
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

    // AC-19: baris sesi tidak bisa diklik (K-5 a): tanpa request, tanpa drawer baru
    const nBefore = calls.length;
    await modal.locator('.ant-table').nth(1).locator('tr.ant-table-row').first().click();
    await modal.locator('.ant-table').nth(1).locator('tr.ant-table-row').nth(1).locator('td').nth(2).click();
    await page.waitForTimeout(1200);
    expect(calls.length, 'klik baris sesi: tanpa request').toBe(nBefore);
    await expect(modal, 'klik baris sesi: modal tetap, tanpa drawer baru').toBeVisible();
    await expect(page.locator('.ant-drawer-open'), 'klik baris sesi: tanpa drawer').toHaveCount(0);
    await app.screenshot('ac16-01-modal-all-archive');

    // AC-29 (rev, BR-13): CTA "Lihat semua riwayat" dirender (ED-1028 menyediakan handler) dan membuka riwayat All Archive (tanpa id_archive)
    const cta = modal.getByText('Lihat semua riwayat', { exact: false }).first();
    await expect(cta, 'CTA "Lihat semua riwayat" dirender di modal All').toBeVisible();
    const histReq = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 30000 });
    await cta.click();
    const hr = await histReq;
    expect(hr.status(), 'CTA modal All: GET opnames 200').toBe(200);
    expect(new URL(hr.url()).searchParams.get('id_archive') || new URL(hr.url()).searchParams.get('idArchive'), 'riwayat All Archive: tanpa id_archive').toBeNull();
    const hist = modal.locator('.archive-opname-history');
    await expect(hist, 'drawer riwayat (inline di modal) terbuka').toBeVisible({ timeout: 15000 });
    await expect(hist, 'judul riwayat').toContainText('Riwayat Opname');
    await expect(hist, 'subjudul riwayat: Semua Arsip').toContainText('Semua Arsip');
    await app.screenshot('ac29-01-cta-modal-all-riwayat');
    await hist.locator('.ant-drawer-close').click();
    await expect(hist, 'drawer riwayat tertutup, modal tetap').toBeHidden({ timeout: 10000 });
    await expect(modal, 'modal tetap sesudah drawer riwayat ditutup').toBeVisible();

    // tutup lewat ✕ (tanpa footer); buka lagi = request baru
    await modal.locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '✕ menutup modal').toHaveCount(0, { timeout: 10000 });
    const resp2 = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    expect((await resp2).status(), 'buka lagi: GET verifications baru').toBe(200);
    await modalVisible(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    expect((await verifCalls(calls)).length, 'dua pembukaan = dua request').toBe(2);
    await modalVisible(page).locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '✕ menutup modal (kedua)').toHaveCount(0, { timeout: 10000 });

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
    // urutan Rincian, Izin (hanya dengan Folder Permissions, ED-1025), Verifikasi (hanya dengan Opname Document, BR-9); QA_USER memegang keduanya
    expect(tabNames, 'tab: Rincian, Izin, Verifikasi (urutan itu)').toEqual(['Rincian', 'Izin', 'Verifikasi']);
    expect((await verifCalls(calls)).length, 'drawer dibuka: belum ada GET verifications').toBe(0);
    await expect(drawer(page).locator('.ant-drawer-extra button.ant-btn-primary'), 'tombol Simpan di header drawer tetap ada').toBeVisible();

    const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
    await verifTab(page).click();
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
    expect(sess, 'sesi folder: hanya S4 (potongan ALPHA 8 dokumen / 4 verified)').toEqual([['06 Okt 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '8', '4']]);
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
    expect(text, 'tanpa NaN / objek mentah').not.toMatch(/NaN|\[object Object\]/);
    await app.screenshot('ac17-01-tab-verifikasi-ALPHA');

    // AC-29 (rev, BR-13): CTA di tab membuka riwayat dengan konteks folder ini (id_archive = ALPHA)
    const tabCta = pane.getByText('Lihat semua riwayat', { exact: false }).first();
    await expect(tabCta, 'CTA "Lihat semua riwayat" dirender di tab').toBeVisible();
    const tabHist = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 30000 });
    await tabCta.click();
    const thr = await tabHist;
    expect(thr.status(), 'CTA tab: GET opnames 200').toBe(200);
    const thu = new URL(thr.url());
    expect(thu.searchParams.get('id_archive') || thu.searchParams.get('idArchive'), 'CTA tab: riwayat dengan id_archive = ALPHA').toBe(fx[`${P}ALPHA`]);
    const tabHistEl = page.locator('.archive-opname-history'); // drawer riwayat inline = anak .ant-drawer-content drawer folder (dirinya juga .ant-drawer-open)
    await expect(tabHistEl, 'drawer riwayat (inline di drawer folder) terbuka').toBeVisible({ timeout: 15000 });
    await expect(tabHistEl, 'subjudul riwayat memuat nama folder').toContainText(`${P}ALPHA`);
    await app.screenshot('ac29-02-cta-tab-riwayat');
    await tabHistEl.locator('.ant-drawer-close').click();
    await expect(tabHistEl, 'drawer riwayat tertutup').toBeHidden({ timeout: 10000 });
    await expect(verifTab(page), 'tab Verifikasi tetap').toHaveCount(1);

    // sel kolom folder di list = angka kartu
    const cellInList = await cellText(page, `${P}ALPHA`);
    expect(cellInList, 'kolom Dokumen Terverifikasi ALPHA di list = kartu').toBe(`${card.verified}/${card.total}`);

    // pindah tab tidak me-request ulang
    await tabs(page).nth(1).click();
    await page.waitForTimeout(500);
    await verifTab(page).click();
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
    await verifTab(page).click();
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
    await verifTab(page).click();
    await resp3;
    expect((await verifCalls(calls)).length, 'tab dibuka lagi: request baru').toBe(n0 + 1);
    await closeTopLayer(page);

    // --- subfolder ALPHA-S1 (di dalam ALPHA): potongan sesi S4 = S1 + S1-X
    await openFolder(page, `${P}ALPHA`);
    await openDrawerView(page, `${P}ALPHA-S1`);
    await verifTab(page).click();
    const s1pane = drawer(page).locator('.ant-tabs-tabpane-active');
    await s1pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const c1 = await readCard(s1pane);
    expect({ verified: c1.verified, total: c1.total }, 'ALPHA-S1 kartu = 2/3').toEqual({ verified: 2, total: 3 });
    expect(await tableRows(s1pane, 1), 'ALPHA-S1: sesi S4 mencakup lewat S1 + S1-X: potongan 3 dokumen / 2 verified').toEqual([['06 Okt 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '3', '2']]);
    await app.screenshot('ac17-02-tab-verifikasi-ALPHA-S1');
    await closeTopLayer(page);
    await openArchive(app, page);

    // --- BETA: sesi root (S3) + sesi BETA (S1), potongan 3/2 masing-masing, terbaru di atas
    await openDrawerView(page, `${P}BETA`);
    await verifTab(page).click();
    const bpane = drawer(page).locator('.ant-tabs-tabpane-active');
    await bpane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const cb = await readCard(bpane);
    expect({ verified: cb.verified, total: cb.total }, 'BETA kartu = 2/3').toEqual({ verified: 2, total: 3 });
    expect(await tableRows(bpane, 1), 'BETA: S3 (root) lalu S1 (BETA)').toEqual([
      ['05 Okt 2026 09:15', 'qa27root', SCOPE_ROOT_ID, '3', '2'],
      ['01 Okt 2026 08:00', 'qa27oldest', `${P}BETA`, '3', '2'],
    ]);
    await closeTopLayer(page);

    // --- GAMMA: satu sesi (S2), 0/3 sekarang walau sesi mencatat 1 verified (snapshot, bukan angka saat ini)
    await openDrawerView(page, `${P}GAMMA`);
    await verifTab(page).click();
    const gpane = drawer(page).locator('.ant-tabs-tabpane-active');
    await gpane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const cg = await readCard(gpane);
    expect({ verified: cg.verified, total: cg.total }, 'GAMMA kartu = 0/3 (saat ini)').toEqual({ verified: 0, total: 3 });
    expect(await tableRows(gpane, 1), 'GAMMA: sesi S2 = potongan snapshot 3 dokumen / 1 verified').toEqual([['04 Okt 2026 14:30', 'qa27gamma', '0QA27-GAMMA', '3', '1']]);
    await closeTopLayer(page);

    // --- EMPTY (AC-18): 0 / 0, progress 0 %, tanpa NaN, sesi kosong
    await openDrawerView(page, `${P}EMPTY`);
    await verifTab(page).click();
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

    // --- VIEWONLY (hak View saja): Detail read-only, tab Verifikasi tetap ada dan berisi angka
    const vtabs = await openDrawerView(page, `${P}VIEWONLY`);
    expect(vtabs, 'VIEWONLY: tab Verifikasi tetap ada').toContain('Verifikasi');
    // Detail read-only (hak View saja). Tombol Simpan mengikuti ED-1025 rev (tampil bila role memegang Folder Permissions, untuk tab Izin): tidak dinilai di sini.
    await expect(drawer(page).locator('input#name'), 'VIEWONLY: field nama Detail nonaktif (read-only)').toBeDisabled();
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

  // ---------------------------------------------------------------------------------------------- AC-26 / AC-27 klik V/T folder
  // Catatan: GET archives mengirim `name` baris FOLDER sebagai larik [{id_transaction, transaction_no, transaction_type}] (bukan string);
  // handler klik harus menurunkan nama folder dari larik itu (pola ArchivePage index.js:241). Test memakai respons asli BE.
  test('[default] ED-1027 AC-26/AC-27 klik V/T folder di root membuka modal verifikasi folder: satu GET verifications/{id}, tanpa tab, header = sel, tanpa footer, CTA riwayat folder, tidak membuka folder', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    await openArchive(app, page);
    expect((await verifCalls(calls)).length, 'sebelum klik: 0 GET verifications').toBe(0);

    const rootCrumbs = trimAll(await page.locator('.archive-page .breadcrumbs').first().allInnerTexts());
    const rootN = (await listCalls(calls)).length;
    const cellValue = await expectFolderLink(page, `${P}ALPHA`);
    expect(cellValue, 'sel ALPHA = 4/8').toBe('4/8');
    const a = await openFolderModal(page, app, `${P}ALPHA`);
    const vc = await verifCalls(calls);
    expect(vc.length, 'tepat satu GET verifications/{id} per buka').toBe(1);
    expect(a.url, 'GET verifications/{id ALPHA}').toContain(`/verifications/${fx[`${P}ALPHA`]}`);
    expect(Object.keys(vc[0].query).filter((k) => /id_?archive|search|query|type/i.test(k)), 'tanpa parameter bisnis').toEqual([]);
    expect(a.body.id_archive, 'respons id_archive = ALPHA').toBe(fx[`${P}ALPHA`]);
    await expectNothingOpened(page, calls, 'klik ALPHA di root', rootN);
    expect(trimAll(await page.locator('.archive-page .breadcrumbs').first().allInnerTexts()), 'breadcrumb tidak berubah').toEqual(rootCrumbs);

    const title = (await a.modal.locator('.ant-modal-title').innerText()).replace(/\s+/g, ' ');
    monitor.note(`judul modal folder: ${title}`);
    expect(title, 'judul "Dokumen Terverifikasi"').toContain('Dokumen Terverifikasi');
    expect(title, 'subjudul = nama folder').toContain(`${P}ALPHA`);
    expect(title, 'bukan varian All').not.toContain('seluruh dokumen di sistem');
    await expect(a.modal.locator('.ant-tabs'), 'satu tampilan tanpa tab').toHaveCount(0);
    await expect(a.modal.locator('.ant-modal-footer'), 'tanpa footer').toHaveCount(0);
    const card = await readCard(a.modal);
    expect(`${card.verified}/${card.total}`, 'header modal = nilai sel').toBe(cellValue);
    expect({ verified: a.body.verified, total: a.body.total }, 'respons = 4/8').toEqual({ verified: 4, total: 8 });
    expect(card.percent, 'progress 50 %').toBeCloseTo(50, 1);
    expect(card.text, 'kalimat folder').toContain('4 dokumen terverifikasi dari 8 dokumen di folder ini');
    expect(await tableRows(a.modal, 0), 'tabel tipe + Total = respons BE').toEqual(expectedTypeRows(a.body).map(formatRow));
    expect(await tableRows(a.modal, 0), 'tabel tipe ALPHA = angka tangan').toEqual([
      ['Pesanan Penjualan', '2', '1', '3'], ['Pengantaran Pesanan', '1', '1', '2'], ['Faktur Penjualan', '0', '2', '2'], ['Retur Penjualan', '1', '0', '1'],
      ['Pembayaran Piutang', '0', '0', '0'], ['Bilyet Giro/Cek', '0', '0', '0'], ['Penagihan', '0', '0', '0'], ['Total', '4', '4', '8'],
    ]);
    expect(await tableRows(a.modal, 1), 'overview varian folder (BR-11): sesi S4, potongan ALPHA').toEqual([['06 Okt 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '8', '4']]);
    const sheads = trimAll(await a.modal.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean).map((h) => h.toLowerCase());
    expect(sheads, 'kolom overview varian folder').toEqual(['waktu', 'pengguna', 'scope sesi', 'dokumen folder', 'terverifikasi di folder ini']);
    const mtext = await a.modal.innerText();
    expect(mtext, 'catatan read-only folder').toContain('Read-only. Handover, Receive, dan Store adalah aktivitas Archive');
    expect(mtext, 'tanpa NaN / objek mentah').not.toMatch(/NaN|\[object Object\]/);
    await app.screenshot('ac26-01-modal-folder-ALPHA');

    // CTA -> riwayat folder ini (AC-29), tanpa error
    const cta = a.modal.getByText('Lihat semua riwayat', { exact: false }).first();
    await expect(cta, 'CTA dirender di modal folder').toBeVisible();
    const histReq = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 30000 });
    await cta.click();
    const hr = await histReq;
    expect(hr.status(), 'CTA modal folder: GET opnames 200').toBe(200);
    const hu = new URL(hr.url());
    expect(hu.searchParams.get('id_archive') || hu.searchParams.get('idArchive'), 'riwayat dengan id_archive = ALPHA').toBe(fx[`${P}ALPHA`]);
    const hist = a.modal.locator('.archive-opname-history');
    await expect(hist, 'drawer riwayat folder terbuka').toBeVisible({ timeout: 15000 });
    await expect(hist, 'subjudul riwayat memuat nama folder').toContainText(`${P}ALPHA`);
    await app.screenshot('ac26-02-modal-folder-cta-riwayat');
    await hist.locator('.ant-drawer-close').click();
    await expect(hist, 'drawer riwayat tertutup').toBeHidden({ timeout: 10000 });
    await closeModalX(page, 'ALPHA');

    // buka lagi = request baru (modal dibuang saat tutup)
    const a2 = await openFolderModal(page, app, `${P}ALPHA`);
    expect((await verifCalls(calls)).length, 'dua pembukaan = dua request').toBe(2);
    expect({ verified: a2.body.verified, total: a2.body.total }, 'buka lagi: 4/8').toEqual({ verified: 4, total: 8 });
    await closeModalX(page, 'ALPHA lagi');

    // tautan ringkasan tetap membuka varian All (satu GET verifications tanpa id)
    const allResp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    expect((await allResp).status(), 'tautan ringkasan: GET verifications (All) 200').toBe(200);
    const mAll = modalVisible(page);
    await mAll.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    expect((await mAll.locator('.ant-modal-title').innerText()).replace(/\s+/g, ' '), 'varian All tetap').toContain(SUBTITLE_ALL_ID);
    await closeModalX(page, 'All');

    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1027 AC-26/AC-27 klik V/T folder di mode Pilih membuka modal verifikasi tanpa mengubah pilihan baris', async ({ app, page, monitor }) => {
    const calls = trace(page);
    await openArchive(app, page);
    const rootN = (await listCalls(calls)).length;
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await page.waitForTimeout(500);
    const betaBox = rowOf(page, `${P}BETA`).locator('input[type="checkbox"]').first();
    await betaBox.check();
    const selectedBefore = await page.locator('.archive-page .ant-table-tbody .ant-checkbox-checked').count();
    expect(selectedBefore, 'mode Pilih: satu baris tercentang (BETA)').toBe(1);
    // klik V/T baris LAIN (GAMMA, tidak tercentang): pilihan tidak berubah, GAMMA tidak ikut tercentang
    const g = await openFolderModal(page, app, `${P}GAMMA`);
    expect({ verified: g.body.verified, total: g.body.total }, 'mode Pilih: modal GAMMA 0/3').toEqual({ verified: 0, total: 3 });
    await closeModalX(page, 'GAMMA (mode Pilih)');
    expect(await page.locator('.archive-page .ant-table-tbody .ant-checkbox-checked').count(), 'mode Pilih: pilihan tidak berubah sesudah klik V/T GAMMA').toBe(selectedBefore);
    await expect(betaBox, 'mode Pilih: BETA tetap tercentang').toBeChecked();
    // klik V/T baris yang SUDAH tercentang: tetap tercentang (klik tidak men-toggle)
    const b = await openFolderModal(page, app, `${P}BETA`);
    expect({ verified: b.body.verified, total: b.body.total }, 'mode Pilih: modal BETA 2/3').toEqual({ verified: 2, total: 3 });
    // AC-28 (rev) cabang modal folder, Scope root: sesi root (id "Semua Arsip · root", N-4) dan sesi folder, sama dengan tab drawer BETA
    expect(await tableRows(b.modal, 1), 'modal folder BETA: Scope root = "Semua Arsip · root", lalu sesi BETA').toEqual([
      ['05 Okt 2026 09:15', 'qa27root', SCOPE_ROOT_ID, '3', '2'],
      ['01 Okt 2026 08:00', 'qa27oldest', `${P}BETA`, '3', '2'],
    ]);
    await closeModalX(page, 'BETA (mode Pilih)');
    await expect(betaBox, 'mode Pilih: BETA tetap tercentang sesudah klik V/T-nya').toBeChecked();
    await expectNothingOpened(page, calls, 'klik V/T di mode Pilih', rootN);
    await page.locator('.archive-page button', { hasText: /Batal Pilih|Cancel/ }).first().click();
    await page.waitForTimeout(500);
    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1027 AC-26/AC-27 klik V/T subfolder di dalam folder dan folder di hasil pencarian: modal folder itu, breadcrumb dan pencarian tidak berubah', async ({ app, page, monitor }) => {
    const calls = trace(page);
    await openArchive(app, page);

    // ---- dalam folder: subfolder ALPHA-S1 (2/3); breadcrumb tetap ALPHA
    await openFolder(page, `${P}ALPHA`);
    const inCrumbs = trimAll(await page.locator('.archive-page .breadcrumbs').first().allInnerTexts());
    const inN = (await listCalls(calls)).length;
    const s1 = await openFolderModal(page, app, `${P}ALPHA-S1`);
    expect((await s1.modal.locator('.ant-modal-title').innerText()), 'dalam folder: subjudul = ALPHA-S1').toContain(`${P}ALPHA-S1`);
    expect({ verified: s1.body.verified, total: s1.body.total }, 'dalam folder: ALPHA-S1 = 2/3').toEqual({ verified: 2, total: 3 });
    expect((await readCard(s1.modal)).text, 'dalam folder: kartu = sel').toContain('2 / 3');
    expect(await tableRows(s1.modal, 1), 'ALPHA-S1: potongan sesi S4 = 3 dokumen / 2 verified').toEqual([['06 Okt 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '3', '2']]);
    await app.screenshot('ac26-03-modal-folder-dalam-folder');
    await closeModalX(page, 'ALPHA-S1');
    await expectNothingOpened(page, calls, 'klik V/T ALPHA-S1 di dalam folder', inN);
    expect(trimAll(await page.locator('.archive-page .breadcrumbs').first().allInnerTexts()), 'dalam folder: breadcrumb tidak berubah').toEqual(inCrumbs);
    // dokumen: ikon, bukan tombol, tidak ada request verifikasi
    const nV = (await verifCalls(calls)).length;
    await rowOf(page, `${P}AD1`).locator('td').nth(await colIndex(page, COL)).click();
    await page.waitForTimeout(600);
    expect((await verifCalls(calls)).length, 'klik sel dokumen: tanpa GET verifications').toBe(nV);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'klik sel dokumen: tanpa modal').toHaveCount(0);

    // ---- hasil pencarian: ALPHA-S1-X (1/1)
    const input = page.locator('.archive-page .search-bar input').first();
    const search = listResponse(page, (u) => /ALPHA/.test(u.searchParams.get('search') || ''));
    await input.fill(`${P}ALPHA`);
    await input.press('Enter');
    await search;
    await page.waitForTimeout(800);
    const sN = (await listCalls(calls)).length;
    const x = await openFolderModal(page, app, `${P}ALPHA-S1-X`);
    expect({ verified: x.body.verified, total: x.body.total }, 'hasil pencarian: ALPHA-S1-X = 1/1').toEqual({ verified: 1, total: 1 });
    expect((await x.modal.locator('.ant-modal-title').innerText()), 'hasil pencarian: subjudul = ALPHA-S1-X').toContain(`${P}ALPHA-S1-X`);
    await closeModalX(page, 'ALPHA-S1-X');
    await expectNothingOpened(page, calls, 'klik V/T di hasil pencarian', sN);
    expect(await input.inputValue(), 'pencarian tetap terisi (tidak direset)').toBe(`${P}ALPHA`);

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

  // ---------------------------------------------------------------------------------------------- BR-7 layar penuh di mobile
  test('[default] ED-1027 BR-7 (rev) modal Document Verified (All) layar penuh di mobile 375 px: terbuka tanpa error, lebar & tinggi = viewport, ✕ menutup', async ({ app, page, monitor }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
    const link = page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first();
    await link.scrollIntoViewIfNeeded();
    const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await link.click();
    expect((await resp).status(), 'mobile: GET verifications 200').toBe(200);
    const modal = modalVisible(page);
    await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const box = await modal.boundingBox();
    monitor.note(`mobile 375x800: kotak modal ${JSON.stringify(box)}`);
    expect(box.width, 'mobile: lebar modal = lebar viewport').toBeGreaterThanOrEqual(370);
    expect(box.height, 'mobile: tinggi modal = tinggi viewport').toBeGreaterThanOrEqual(790);
    await expect(modal.locator('.ant-modal-footer'), 'mobile: tanpa footer').toHaveCount(0);
    await app.screenshot('br7-mobile-modal-layar-penuh');
    await modal.locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'mobile: ✕ menutup modal').toHaveCount(0, { timeout: 10000 });
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
    // oracle longgar: item menu "+" bertambah oleh item lain (ED-1029 "Atur Izin per User"); yang dijaga item ini = tetap ada
    expect(rootItems, 'menu + di root memuat aksi Archive yang ada sebelumnya').toEqual(expect.arrayContaining(['Tambah Folder', 'Serahkan Dokumen', 'Terima Dokumen', 'Opname Dokumen']));
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
  test('[default] ED-1027 jalur error: GET verifications gagal -> toast + isi kosong (tanpa "0 / 0" palsu), ✕ tetap menutup, buka lagi normal; sama untuk tab drawer', async ({ app, page, monitor }) => {
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
    await expect(modal.locator('.ant-modal-footer'), 'modal tanpa footer').toHaveCount(0);
    await modal.locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '✕ tetap menutup modal').toHaveCount(0, { timeout: 10000 });

    // tab Verification drawer
    await openDrawerView(page, `${P}ALPHA`);
    await verifTab(page).click();
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
    expect(fx._expect.summary_verified, 'fixture dikenali').toBeGreaterThanOrEqual(7);
    noServerError(monitor);
    monitor.check();
  });


  // ---------------------------------------------------------------------------------------------- jalur error modal folder (BR-17)
  test('[default] ED-1027 AC-26 jalur error modal folder: folder tanpa View / hilang saat diklik (403 ARCHIVE407 simulasi) -> toast pesan server, isi kosong tanpa "0 / 0" palsu, ✕ menutup, buka lagi normal', async ({ app, page, monitor }) => {
    await openArchive(app, page);
    const verRe = /\/document-archive\/verifications(\/|\?|$)/;
    await page.route(verRe, async (route) => {
      await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ message: 'Folder QA27 simulasi ditolak', code: 'ARCHIVE407' }) });
    });
    await (await dvLink(page, `${P}ALPHA`)).click();
    const mf = modalVisible(page);
    await mf.waitFor({ state: 'visible', timeout: 15000 });
    await expect(page.locator('.ant-message-notice, .ant-notification-notice').filter({ hasText: 'Folder QA27 simulasi ditolak' }).first(), 'modal folder: toast = pesan error server').toBeVisible({ timeout: 10000 });
    await expect(mf.locator('.ant-empty'), 'modal folder: isi kosong (Empty)').toHaveCount(1);
    await expect(mf.locator('.ant-card'), 'modal folder: tanpa kartu angka').toHaveCount(0);
    expect(await mf.innerText(), 'modal folder: tanpa "0 / 0" palsu').not.toMatch(/\b0\s*\/\s*0\b/);
    await expect(mf.locator('.ant-modal-title'), 'modal folder: subjudul nama folder tetap').toContainText(`${P}ALPHA`);
    await app.screenshot('err-02-modal-folder-ditolak');
    await mf.locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'modal folder: ✕ menutup').toHaveCount(0, { timeout: 10000 });
    expectRejected(monitor, 'verifications/{folder} dimodifikasi 403', { status: 403, urlRe: /\/document-archive\/verifications\// });
    await page.unroute(verRe);

    // tanpa modifikasi: buka lagi = normal
    await (await dvLink(page, `${P}ALPHA`)).click();
    const m3 = modalVisible(page);
    await m3.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    const card3 = await readCard(m3);
    expect({ verified: card3.verified, total: card3.total }, 'sesudah pulih: modal folder ALPHA = 4/8').toEqual({ verified: 4, total: 8 });
    await closeTopLayer(page);
    noServerError(monitor);
    monitor.check();
  });

  // QA FE rev r2: jalur error NYATA (tanpa memodifikasi respons): folder fixture dinonaktifkan SESUDAH list dimuat, lalu V/T diklik.
  test('[default] ED-1027 AC-26 jalur error NYATA modal folder: folder hilang sesudah list dimuat -> BE 404 ARCHIVE400 asli, toast pesan server, isi kosong tanpa "0 / 0" palsu, ✕ menutup, aktif lagi = modal normal', async ({ app, page, monitor }) => {
    const calls = trace(page);
    await openArchive(app, page);
    const link = await dvLink(page, `${P}EMPTY`);
    await expect(link, 'EMPTY: tautan 0/0 ada sebelum folder dinonaktifkan').toHaveCount(1);
    let real = null;
    try {
      expect(setFolderActive(`${P}EMPTY`, 0), 'fixture: EMPTY dinonaktifkan').toMatchObject({ is_active: 0, rows: 1 });
      const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
      await link.click();
      const r = await resp;
      real = await r.json();
      expect(r.status(), 'BE asli: folder nonaktif = 404').toBe(404);
      const realCode = real.code || real.msg_code; // respons error BE memakai `code` (sukses: `msg_code`)
      expect(realCode, 'BE asli: ARCHIVE400').toBe('ARCHIVE400');
      expect(typeof real.message === 'string' && real.message.length > 0, 'BE asli: pesan error ada').toBe(true);
      expect(real.result, 'BE asli: tanpa result').toBeUndefined();
      monitor.note(`BE asli folder nonaktif: HTTP ${r.status()} ${realCode} "${real.message}"`);
      const mf = modalVisible(page);
      await mf.waitFor({ state: 'visible', timeout: 15000 });
      await expect(page.locator('.ant-message-notice, .ant-notification-notice').filter({ hasText: real.message }).first(), 'toast = pesan server asli').toBeVisible({ timeout: 10000 });
      await expect(mf.locator('.ant-empty'), 'isi kosong (Empty)').toHaveCount(1);
      await expect(mf.locator('.ant-card'), 'tanpa kartu angka').toHaveCount(0);
      expect(await mf.innerText(), 'tanpa "0 / 0" palsu').not.toMatch(/\b0\s*\/\s*0\b/);
      await expect(mf.locator('.ant-modal-title'), 'subjudul nama folder tetap').toContainText(`${P}EMPTY`);
      await app.screenshot('err-03-modal-folder-404-asli');
      await mf.locator('.ant-modal-close').click();
      await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '✕ menutup').toHaveCount(0, { timeout: 10000 });
    } finally {
      expect(setFolderActive(`${P}EMPTY`, 1), 'fixture: EMPTY diaktifkan lagi').toMatchObject({ is_active: 1, rows: 1 });
    }
    expectRejected(monitor, 'verifications/{folder} nonaktif (BE asli)', { status: 404, urlRe: /\/document-archive\/verifications\// });
    // aktif lagi: tautan yang sama membuka modal normal (0 / 0 yang SAH, bukan palsu)
    const ok = await openFolderModal(page, app, `${P}EMPTY`);
    expect({ verified: ok.body.verified, total: ok.body.total }, 'aktif lagi: respons EMPTY 0/0').toEqual({ verified: 0, total: 0 });
    expect((await readCard(ok.modal)).text, 'aktif lagi: kartu 0 / 0 sah').toMatch(/0\s*\/\s*0/);
    expect((await verifCalls(calls)).map((c) => c.status), 'dua request: 404 lalu 200').toEqual([404, 200]);
    await closeModalX(page, 'EMPTY aktif lagi');
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

  // ---------------------------------------------------------------------------------------------- AC-25 [user2] = role TANPA Opname Document
  test('[user2] ED-1027 AC-25 role tanpa Opname Document: Archive (root, folder, pencarian) tanpa kolom Document Verified, ringkasan dan request verifications*; Display Setting tanpa entri itu, simpan sukses, kolom tersimpan dipertahankan; drawer folder tanpa tab Verification; picker Pindahkan / Bulk Move tanpa kolom', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    const first = await openArchive(app, page);
    expect(first.status, '[user2] GET archives root 200').toBe(200);
    const res0 = first.body.result;

    // AC-22 sisi respons yang dibaca FE: tanpa kolom, tanpa field per baris, tanpa ringkasan
    expect(JSON.stringify(res0.columns || []), '[user2] respons: columns tanpa documentVerified').not.toMatch(/documentVerified|document_verified/i);
    expect(Object.keys(res0), '[user2] respons: tanpa document_verified_summary').not.toContain('document_verified_summary');
    expect(rowsOf(first).filter((r) => Object.prototype.hasOwnProperty.call(r, 'document_verified')).length, '[user2] respons: tak satu baris pun membawa document_verified').toBe(0);

    // FE root: tabel tampil, tanpa kolom, tanpa ringkasan + tautan
    const noVerificationUi = async (label) => {
      const heads = await headTexts(page);
      expect(heads.length, `${label}: tabel punya header`).toBeGreaterThan(0);
      expect(heads, `${label}: tanpa kolom Document Verified (EN)`).not.toContain(COL_EN);
      expect(heads, `${label}: tanpa kolom Dokumen Terverifikasi`).not.toContain(COL);
      const text = await page.locator('.archive-page').innerText();
      expect(text, `${label}: tanpa ringkasan header`).not.toMatch(/all documents in the system|seluruh dokumen di sistem/i);
      expect(text, `${label}: tanpa tautan detail per transaction type`).not.toMatch(/See detail per transaction type|Lihat detail per tipe transaksi/i);
      expect(await page.locator('.archive-page .ant-table-tbody').innerText(), `${label}: tanpa objek mentah`).not.toMatch(/\[object Object\]/);
      await expect(page.locator('.archive-page .ant-table-tbody button.ant-btn-link'), `${label}: tanpa tautan V/T di sel`).toHaveCount(0);
      await expect(page.locator('.archive-page .ant-table-tbody .anticon-check-circle, .archive-page .ant-table-tbody .anticon-close-circle'), `${label}: tanpa ikon verified / unverified`).toHaveCount(0);
    };
    await noVerificationUi('[user2] root');
    await expect(page.locator('.archive-page .ant-table-row').first(), '[user2] root: tabel punya baris').toBeVisible();
    await expect(rowOf(page, `${P}ALPHA`), '[user2] root: folder fixture ALPHA tampil').toBeVisible();
    monitor.note(`[user2] header root: ${JSON.stringify(await headTexts(page))}`);
    await app.screenshot('ac25-01-root-tanpa-verifikasi');

    // folder
    const inAlpha = await openFolder(page, `${P}ALPHA`);
    expect(inAlpha.status, '[user2] GET archives?id_archive=ALPHA 200').toBe(200);
    expect(Object.keys(inAlpha.body.result), '[user2] folder: respons tanpa document_verified_summary').not.toContain('document_verified_summary');
    expect(rowsOf(inAlpha).filter((r) => Object.prototype.hasOwnProperty.call(r, 'document_verified')).length, '[user2] folder: baris tanpa document_verified').toBe(0);
    await expect(rowOf(page, `${P}AD1`), '[user2] folder: dokumen AD1 tampil').toBeVisible();
    await noVerificationUi('[user2] folder ALPHA');
    await app.screenshot('ac25-02-folder-tanpa-verifikasi');

    // pencarian
    const input = page.locator('.archive-page .search-bar input').first();
    const search = listResponse(page, (u) => /0QA27/.test(u.searchParams.get('search') || ''));
    await input.fill(P);
    await input.press('Enter');
    const sr = await search;
    expect(sr.status, '[user2] GET archives?search 200').toBe(200);
    expect(Object.keys(sr.body.result), '[user2] pencarian: respons tanpa document_verified_summary').not.toContain('document_verified_summary');
    await page.waitForTimeout(800);
    await noVerificationUi('[user2] pencarian');
    await input.fill('');
    await input.press('Enter');
    await page.waitForTimeout(1200);
    await openArchive(app, page);

    // drawer folder: tanpa tab Verification, tanpa request verifications/{id}; Detail tetap terisi
    const tabNames = await openDrawerView(page, `${P}ALPHA`);
    monitor.note(`[user2] tab drawer ALPHA: ${JSON.stringify(tabNames)}`);
    expect(tabNames.join('|'), '[user2] drawer: tanpa tab Verification / Verifikasi').not.toMatch(/Verification|Verifikasi/);
    await expect(drawer(page).locator('input#name'), '[user2] drawer: Detail terisi (nama folder)').toHaveValue(`${P}ALPHA`);
    await expect(drawer(page).getByText(/Opname History|Riwayat Opname/), '[user2] drawer: tanpa riwayat opname').toHaveCount(0);
    await app.screenshot('ac25-03-drawer-tanpa-tab-verification');
    await closeTopLayer(page);

    // picker Pindahkan (satu folder) dan Bulk Move: tanpa kolom
    const modalHeads = async (modal) => trimAll(await modal.locator('.ant-table-thead th').allInnerTexts());
    const pick = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, `${P}GAMMA`, /^(Pindahkan|Move)$/);
    const pr = await pick;
    expect(pr.status, '[user2] picker Pindahkan: GET archives 200').toBe(200);
    expect(JSON.stringify(pr.body.result.columns || []), '[user2] picker: respons columns tanpa documentVerified').not.toMatch(/documentVerified/);
    const m1 = modalVisible(page);
    await m1.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(m1.locator('.ant-table-row').filter({ hasText: `${P}ALPHA` }).first(), '[user2] picker memuat ALPHA').toBeVisible();
    const h1 = await modalHeads(m1);
    expect(h1.length, '[user2] picker: tabel punya header').toBeGreaterThan(0);
    expect(h1, '[user2] picker: tanpa kolom Document Verified').not.toContain(COL_EN);
    expect(await m1.innerText(), '[user2] picker: tanpa objek mentah').not.toMatch(/\[object Object\]/);
    await app.screenshot('ac25-04-picker-pindahkan');
    await closeTopLayer(page);

    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await page.waitForTimeout(500);
    await rowOf(page, `${P}GAMMA`).locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = modalVisible(page);
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    const hb = await modalHeads(bulk);
    expect(hb.length, '[user2] Bulk Move konfirmasi: tabel punya header').toBeGreaterThan(0);
    expect(hb, '[user2] Bulk Move konfirmasi: tanpa kolom Document Verified').not.toContain(COL_EN);
    const pickerList = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Next|Lanjut|Berikutnya)$/ }).first().click();
    await pickerList.catch(() => null);
    await app.settle();
    const hb2 = await modalHeads(bulk);
    expect(hb2, '[user2] Bulk Move pilih folder: tanpa kolom Document Verified').not.toContain(COL_EN);
    expect(await bulk.innerText(), '[user2] Bulk Move: tanpa objek mentah').not.toMatch(/\[object Object\]/);
    await app.screenshot('ac25-05-bulk-move');
    await closeTopLayer(page);
    await page.locator('.archive-page button', { hasText: /^(Batal Pilih|Cancel)/ }).first().click().catch(() => null);

    // Display Setting: tanpa entri Document Verified; simpan (sembunyikan kolom lain) sukses; kolom tersimpan dipertahankan (BR-19)
    const before = displayDb();
    expect(before.current, '[user2] DB awal: documentVerified ada di current_columns (kolom global)').toContain('documentVerified');
    const openSetting = async () => {
      await page.locator('.archive-page button', { hasText: /Pengaturan Tampilan|Display Setting/ }).first().click();
      await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
      await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      await app.settle();
      return trimAll(await drawer(page).locator('.ant-table').first().locator('tr.ant-table-row').allInnerTexts());
    };
    const saveSetting = async () => {
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /display/i.test(r.url()), { timeout: 30000 });
      await drawer(page).locator('.ant-drawer-extra button.ant-btn-primary').click();
      const r = await put;
      expect(r.status(), '[user2] PUT display setting 200').toBe(200);
      await expect(page.locator('.ant-drawer-open'), '[user2] drawer menutup sesudah Save').toHaveCount(0, { timeout: 15000 });
      await page.waitForTimeout(1500);
    };
    const names = await openSetting();
    monitor.note(`[user2] kolom di Display Setting: ${JSON.stringify(names)}`);
    expect(names.length, '[user2] Display Setting memuat daftar kolom').toBeGreaterThan(0);
    expect(names.filter((n) => n === COL_EN || n === COL).length, '[user2] Display Setting: tanpa entri Document Verified').toBe(0);
    expect(names, '[user2] Display Setting memuat kolom Archive lain').toEqual(expect.arrayContaining(['Salesman', 'Status']));
    await app.screenshot('ac25-06-display-setting-tanpa-entri');
    const statusRow = drawer(page).locator('.ant-table').first().locator('tr.ant-table-row').filter({ hasText: /^\s*Status\s*$/ }).first();
    await expect(statusRow.locator('input[type="checkbox"]'), '[user2] kolom Status tercentang').toBeChecked();
    await statusRow.locator('input[type="checkbox"]').uncheck();
    await saveSetting();
    await expect.poll(async () => (await headTexts(page)).includes('Status'), { message: '[user2] kolom Status hilang sesudah Save', timeout: 15000 }).toBe(false);
    const hidden = displayDb();
    expect(hidden.current, '[user2] DB: documentVerified DIPERTAHANKAN di current_columns walau user tak melihatnya').toContain('documentVerified');
    expect(hidden.current, '[user2] DB: perubahan user (Status disembunyikan) tersimpan').not.toContain('history');
    await noVerificationUi('[user2] sesudah simpan Display Setting');
    // kembalikan: tampilkan Status lagi (selisih >= 1 detik dari PUT pertama; isi berbeda)
    const names2 = await openSetting();
    expect(names2.filter((n) => n === COL_EN || n === COL).length, '[user2] Display Setting (kedua): tanpa entri Document Verified').toBe(0);
    const statusRow2 = drawer(page).locator('.ant-table').first().locator('tr.ant-table-row').filter({ hasText: /^\s*Status\s*$/ }).first();
    await statusRow2.locator('input[type="checkbox"]').check();
    await saveSetting();
    await expect.poll(async () => (await headTexts(page)).includes('Status'), { message: '[user2] kolom Status kembali', timeout: 15000 }).toBe(true);
    const after = displayDb();
    expect(after.current, '[user2] DB: documentVerified masih di current_columns sesudah dua simpan').toContain('documentVerified');
    expect(after.current, '[user2] DB: Status kembali').toContain('history');
    expect(after.current.filter((x) => x === 'documentVerified').length, '[user2] DB: documentVerified tepat satu').toBe(1);

    // tidak satu pun request verifications* seumur test ini
    const vcalls = await verifCalls(calls);
    expect(vcalls.map((c) => c.url), '[user2] tidak ada request verifications*').toEqual([]);
    expect(monitor.responses.filter((r) => /\/document-archive\/verifications/.test(r.url)).length, '[user2] monitor: tak ada respons verifications*').toBe(0);
    expect(fx._expect.summary_total, 'fixture dikenali').toBeGreaterThanOrEqual(17);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-13/16/17/26/28 [user2] EN + Opname Document sementara
  test('[user2] ED-1027 AC-13/16/17/28 (EN, lokasi terbatas, Opname Document diberikan sementara lewat fixture): kolom, ringkasan, modal All, tab Verification, scope EN', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    const grant = grantOd();
    monitor.note(`[user2] grant sementara Opname Document: ${JSON.stringify(grant)}`);
    try {
      expect(grant.preset, '[user2] grant hanya ke role PRESET QA27-ROLE-E2E-*').toBe(true);
      expect(grant.made.length, '[user2] grant: satu baris role_permissions dibuat (preset belum memegangnya)').toBe(grant.roles.length);
      const first = await openArchive(app, page); // page baru: FE membaca permission (auth/info) sesudah grant
      const res0 = first.body.result;
      const apiSum = res0.document_verified_summary;
      expect(apiSum, '[user2] dengan Opname Document: respons membawa ringkasan').toBeTruthy();
      const heads = await headTexts(page);
      monitor.note(`[user2] header: ${JSON.stringify(heads)}`);
      expect(heads, '[user2] kolom Document Verified (EN)').toContain(COL_EN);
      const iDv = heads.indexOf(COL_EN);
      expect(heads[iDv - 1], '[user2] sesudah Salesman').toBe('Salesman');
      expect(heads[iDv + 1], '[user2] sebelum Status').toBe('Status');

      // ringkasan (EN) = respons; angka user2 <= angka seluruh dokumen user QA (dokumen aktif + 17)
      const sum = await readSummary(page, summaryBoxEn(page));
      monitor.note(`[user2] ringkasan: ${sum.text} | respons ${JSON.stringify(apiSum)}`);
      expect({ verified: sum.verified, total: sum.total }, '[user2] ringkasan UI = respons').toEqual(apiSum);
      expect(sum.verified, '[user2] verified <= total').toBeLessThanOrEqual(sum.total);
      await expect(page.locator('.archive-page').getByText('See detail per transaction type', { exact: false }).first(), '[user2] tautan (EN)').toBeVisible();
      const apiOf = (name) => (rowsOf({ body: first.body }).find((r) => (Array.isArray(r.name) ? r.name[0].transaction_no : r.name) === name) || {}).document_verified;
      for (const k of ['ALPHA', 'BETA', 'EMPTY', 'GAMMA', 'VIEWONLY', 'NOVIEW']) {
        const api = apiOf(`${P}${k}`);
        const expected = api === null ? '-' : `${fmt(api.verified)}/${fmt(api.total)}`;
        expect(await cellText(page, `${P}${k}`, COL_EN), `[user2] sel ${k} = respons`).toBe(expected);
      }
      expect(await cellText(page, `${P}ALPHA`, COL_EN), '[user2] ALPHA = 4/8').toBe('4/8');
      expect(await cellText(page, `${P}NOVIEW`, COL_EN), '[user2] NOVIEW tanpa View = -').toBe('-');
      expect(await cellText(page, `${P}VIEWONLY`, COL_EN), '[user2] VIEWONLY (hak hanya untuk QA_USER) = -').toBe('-');
      expect(await expectFolderLink(page, `${P}ALPHA`, COL_EN), '[user2] ALPHA tautan 4/8').toBe('4/8');
      await expect(await dvLink(page, `${P}NOVIEW`, COL_EN), '[user2] NOVIEW "-" bukan tombol').toHaveCount(0);
      await app.screenshot('user2-01-root');

      // modal All (EN)
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
      expect(title, `[user2] subjudul EN "${SUBTITLE_ALL_EN}"`).toContain(SUBTITLE_ALL_EN);
      await expect(modal.locator('.ant-modal-footer'), '[user2] modal tanpa footer').toHaveCount(0);
      const card = await readCard(modal);
      expect({ verified: card.verified, total: card.total }, '[user2] kartu = ringkasan').toEqual(apiSum);
      expect({ verified: body.verified, total: body.total }, '[user2] respons = ringkasan').toEqual(apiSum);
      expect(card.text, '[user2] kalimat EN').toContain(`${fmt(card.verified)} documents verified out of ${fmt(card.total)} documents in all folders`);
      expect(await tableRows(modal, 0), '[user2] tabel tipe = respons').toEqual(expectedTypeRows(body).map(formatRow));
      const sessions = await tableRows(modal, 1);
      monitor.note(`[user2] sesi: ${JSON.stringify(sessions)}`);
      // dijalankan sesudah profil default: test Confirm Opname (GAMMA) menambah sesi baru di atas dan menggeser S2 keluar dari 3 teratas
      expect(body.last_sessions.length, '[user2] respons: 3 sesi').toBe(3);
      expect(sessions.map((r) => r[1]), '[user2] sesi di UI = respons BE (urutan sama)').toEqual(body.last_sessions.map((x) => x.created_by));
      const who = sessions.map((r) => r[1]);
      expect(who.indexOf('qa27alpha'), '[user2] sesi S4 (ALPHA, folder terlihat) tampil').toBeGreaterThanOrEqual(0);
      expect(who.indexOf('qa27root'), '[user2] sesi S3 (root) tampil: sesi root terlihat semua pemegang Opname Document').toBeGreaterThan(who.indexOf('qa27alpha'));
      expect(who.join(' '), '[user2] sesi terlama (S1) tidak tampil').not.toMatch(/qa27oldest/);
      // AC-28 (EN): kolom Scope = format BR-12 dari objek scope respons; root "All Archive · root"
      const scopeText = (sc) => (sc.is_root ? SCOPE_ROOT_EN : sc.folder_name) + (sc.subfolder_count > 0 ? ` + ${sc.subfolder_count} subfolder` : '');
      expect(sessions.map((r) => r[2]), '[user2] kolom Scope EN = format BR-12 dari scope respons').toEqual(body.last_sessions.map((x) => scopeText(x.scope)));
      expect(sessions.find((r) => r[1] === 'qa27alpha')[2], '[user2] S4: "0QA27-ALPHA + 2 subfolder"').toBe(SCOPE_ALPHA);
      expect(sessions.find((r) => r[1] === 'qa27root')[2], '[user2] S3 root EN').toBe(SCOPE_ROOT_EN);
      for (const x of body.last_sessions) {
        expect(Object.keys(x.scope).sort(), `[user2] respons scope ${x.created_by}: tepat tiga kunci`).toEqual(['folder_name', 'is_root', 'subfolder_count']);
      }
      const mtext = await modal.innerText();
      expect(mtext, '[user2] judul overview EN').toContain('Opname History');
      expect(mtext, '[user2] catatan EN').toContain('Handover, Receive, and Store are Archive activities, not transaction types');
      expect(mtext, '[user2] tanpa objek mentah').not.toMatch(/\[object Object\]/);
      const s4row = sessions.find((r) => r[1] === 'qa27alpha');
      expect(s4row[0], '[user2] tanggal sesi memakai bulan EN').toBe('06 Oct 2026 16:40');
      await app.screenshot('user2-02-modal-en');
      // CTA (EN) membuka riwayat All
      const cta = modal.getByText('See all history', { exact: false }).first();
      if ((await cta.count()) > 0) {
        const hreq = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 30000 });
        await cta.click();
        expect((await hreq).status(), '[user2] CTA modal All (EN): GET opnames 200').toBe(200);
        await expect(modal.locator('.archive-opname-history'), '[user2] riwayat terbuka').toBeVisible({ timeout: 15000 });
        await modal.locator('.archive-opname-history .ant-drawer-close').click();
        await expect(modal.locator('.archive-opname-history'), '[user2] riwayat tertutup').toBeHidden({ timeout: 10000 });
      } else {
        monitor.note('[user2] teks CTA EN berbeda dari "See all history": dilewati (tak ada defect; teks milik ED-1028)');
      }
      await modal.locator('.ant-modal-close').click();
      await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '[user2] ✕ menutup modal').toHaveCount(0, { timeout: 10000 });

      // drawer ALPHA (EN): tab Verification (Detail + Verification; tab Izin butuh Folder Permissions, yang tidak dimiliki role ini)
      const tabNames = await openDrawerView(page, `${P}ALPHA`);
      monitor.note(`[user2] tab drawer: ${JSON.stringify(tabNames)}`);
      expect(tabNames[0], '[user2] tab pertama = Detail').toBe('Detail');
      expect(tabNames[tabNames.length - 1], '[user2] tab terakhir = Verification').toBe('Verification');
      expect(tabNames.join('|'), '[user2] tanpa Folder Permissions tidak ada tab Permission').not.toMatch(/Permission/);
      const nV = (await verifCalls(calls)).length;
      const r2 = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
      await verifTab(page).click();
      const vb = (await (await r2).json()).result;
      expect((await verifCalls(calls)).length, '[user2] tab dibuka: tepat satu GET verifications/{id} baru').toBe(nV + 1);
      const pane = drawer(page).locator('.ant-tabs-tabpane-active');
      await pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
      const c2 = await readCard(pane);
      expect({ verified: c2.verified, total: c2.total }, '[user2] kartu ALPHA = 4/8').toEqual({ verified: 4, total: 8 });
      expect({ verified: vb.verified, total: vb.total }, '[user2] respons ALPHA').toEqual({ verified: 4, total: 8 });
      expect(c2.text, '[user2] kalimat folder EN').toContain('4 documents verified out of 8 documents in this folder');
      const psheads = trimAll(await pane.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean).map((h) => h.toLowerCase());
      expect(psheads, '[user2] kolom sesi folder (EN)').toEqual(['time', 'user', 'session scope', 'folder documents', 'verified in this folder']);
      expect(await tableRows(pane, 1), '[user2] sesi folder ALPHA').toEqual([['06 Oct 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '8', '4']]);
      expect(await pane.innerText(), '[user2] catatan folder EN').toContain('Read-only. Handover, Receive, and Store are Archive activities');
      await app.screenshot('user2-03-tab-verification-en');
      await closeTopLayer(page);
      expect(fx._expect.summary_total, 'fixture dikenali').toBeGreaterThanOrEqual(17);

      noServerError(monitor);
      monitor.check();
    } finally {
      monitor.note(`[user2] ${revokeOd()}`);
    }
  });

  // ---------------------------------------------------------------------------------------------- AC-26 [user2] EN modal folder
  test('[user2] ED-1027 AC-26/AC-28 (EN, Opname Document diberikan sementara): klik V/T folder membuka modal folder berbahasa EN dengan scope EN, tanpa membuka folder', async ({ app, page, monitor }) => {
    const calls = trace(page);
    const grant = grantOd();
    monitor.note(`[user2] grant sementara Opname Document: ${JSON.stringify(grant)}`);
    try {
      await openArchive(app, page);
      // klik V/T ALPHA
      const nList = (await listCalls(calls)).length;
      const fresp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\//.test(r.url()), { timeout: 30000 });
      await (await dvLink(page, `${P}ALPHA`, COL_EN)).click();
      const fb = (await (await fresp).json()).result;
      const fm = modalVisible(page);
      await fm.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
      await app.settle();
      expect((await fm.locator('.ant-modal-title').innerText()), '[user2] modal folder: judul EN + subjudul nama folder').toMatch(/Document Verified[\s\S]*0QA27-ALPHA/);
      await expect(fm.locator('.ant-tabs'), '[user2] modal folder: tanpa tab').toHaveCount(0);
      const fc = await readCard(fm);
      expect({ verified: fc.verified, total: fc.total }, '[user2] modal folder ALPHA = 4/8').toEqual({ verified: 4, total: 8 });
      expect({ verified: fb.verified, total: fb.total }, '[user2] respons modal folder = 4/8').toEqual({ verified: 4, total: 8 });
      expect(fc.text, '[user2] modal folder: kalimat folder EN').toContain('4 documents verified out of 8 documents in this folder');
      const fheads = trimAll(await fm.locator('.ant-table').nth(1).locator('thead th').allInnerTexts()).filter(Boolean).map((h) => h.toLowerCase());
      expect(fheads, '[user2] modal folder: kolom sesi (EN)').toEqual(['time', 'user', 'session scope', 'folder documents', 'verified in this folder']);
      expect(await tableRows(fm, 1), '[user2] modal folder: sesi ALPHA').toEqual([['06 Oct 2026 16:40', 'qa27alpha', SCOPE_ALPHA, '8', '4']]);
      expect(await fm.innerText(), '[user2] modal folder: catatan EN').toContain('Read-only. Handover, Receive, and Store are Archive activities');
      expect((await listCalls(calls)).length, '[user2] klik V/T: tidak ada GET archives baru (folder tidak dibuka)').toBe(nList);
      await app.screenshot('user2-04-modal-folder-en');
      await fm.locator('.ant-modal-close').click();
      await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '[user2] modal folder: ✕ menutup').toHaveCount(0, { timeout: 10000 });

      noServerError(monitor);
      monitor.check();
    } finally {
      monitor.note(`[user2] ${revokeOd()}`);
    }
  });

  // ---------------------------------------------------------------------------------------------- BR-6: sesudah Confirm Opname (UI) angka diperbarui
  test('[default] ED-1027 BR-6/AC-12 Confirm Opname GAMMA lewat UI (ED-1026 rev: scan tanpa simpan, satu POST opnames = Konfirmasi): list dimuat ulang, ringkasan + kolom folder + modal naik, sesi baru di baris teratas', async ({ app, page, monitor }) => {
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
    // ED-1026 rev (FB-3): scan = POST opnames/scan (klasifikasi tanpa simpan); tidak ada data opname di DB sebelum Konfirmasi
    for (const code of [`${P}GD1`, `${P}GD2`]) {
      const sc = page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames\/scan(\?|$)/.test(r.url()), { timeout: 30000 });
      await input.click();
      await input.fill(code);
      await input.press('Enter');
      expect((await sc).status(), `scan ${code}`).toBe(200);
      await page.waitForTimeout(300);
    }
    expect(inspectDb().sessions.filter((x) => x.folder === `${P}GAMMA` && x.created_by !== 'qa27gamma'), 'scan belum menyimpan sesi apa pun').toEqual([]);
    await modalRoot.locator('button').filter({ hasText: /^Selesai Opname$/ }).click();
    await expect(modalRoot.locator('.ant-modal-title')).toContainText('Konfirmasi Opname');
    const confirmBtn = modalRoot.locator('button').filter({ hasText: /^Konfirmasi Opname$/ }).first();
    await expect(confirmBtn, 'Konfirmasi aktif sesudah pratinjau Step 3').toBeEnabled({ timeout: 20000 });
    const listBefore = (await listCalls(calls)).length;
    await confirmBtn.click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    // Konfirmasi = SATU POST opnames (scans + selected_at), bukan PUT confirm
    const conf = page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 60000 });
    const reloaded = listResponse(page, () => true);
    await pop.locator('.ant-btn-primary').click();
    expect((await conf).status(), 'POST opnames (Konfirmasi) 200').toBe(200);
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
    await verifTab(page).click();
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

    noServerError(monitor);
    monitor.check();
  });
});
