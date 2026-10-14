'use strict';

/**
 * ED-1026 (revisi "opname tanpa draft", 2026-10-09) - Archive: Opname Document per folder (menu "+" / menu baris, modal 3 langkah:
 * Select Folder, Opname Folder, Confirm). Tidak ada data opname di DB sebelum Konfirmasi (FB-3): scan & pratinjau = POST tanpa simpan,
 * satu POST opnames = Konfirmasi, Cancel / X tanpa request.
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA26-" (di root):
 *   ALPHA (subfolder S1[+X] S2 S3) | BETA (T1 T2; BETA + T1 sudah diopname hari ini 09:15, BD1 + T1D1 verified)
 *   GAMMA (GD1-3) | DELTA (DD1) | EPS (E01-E12) | ZULFA (ZD1) | HIST (H1 H2) | OMEGA (OD0, OMEGA-S1 OD1, OMEGA-S2 OD2)
 *   VIEWONLY / NOVIEW (hak folder) | RD1 (root, verified) RD2 (root)   -- GAMMA/DELTA/EPS/ZULFA/HIST tanpa subfolder.
 * Spec TIDAK bergantung pada data developer: harapan isi Step 1 dan daftar folder diturunkan dari respons API saat run; baris folder
 * dicari ke semua halaman list (bukan diasumsikan di halaman 1); folder uji "tanpa subfolder" adalah ZULFA milik fixture.
 *
 * User QA = [default] (role 3, bahasa ID), lokasi Semarang saja (diatur fixture). Mode role (env QA26_MODE):
 *   main   = role dengan Opname Document (semua test kecuali AC-3 negatif)
 *   norole = QA_USER ditaruh sementara di PRESET role QA26-ROLE-E2E-NOOD (izin Archive eksplisit TANPA Opname Document, dipasang fixture.php `up`
 *            sebelum login; user_roles dipulihkan persis dan preset dibuang di `down`; role developer tidak disentuh, ED-1149) - hanya test
 *            AC-3 negatif / FB-8a; dijalankan sebagai run kedua
 *
 * Mutasi hanya pada data fixture + tabel archive_opname* (dibuang teardown). Opname root tidak pernah dikonfirmasi (menyentuh seluruh
 * dokumen root milik developer). Test berurutan (E2E_WORKERS=1): satu user, satu token.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const P = '0QA26-';
const MODE = process.env.QA26_MODE || 'main';
const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const trimAll = (arr) => arr.map((t) => t.replace(/\s+/g, ' ').trim());
const digits = (s) => String(s).replace(/[^\d]/g, '');
const ROOT = { id: 'Semua Arsip', en: 'All Archive' };

const LIST_RE = /\/document-archive\/archives(\?|$)/;
const nameRe = (name) => new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`);

const fixtureBin = () => [config.get('PHP_BIN'), path.join(__dirname, 'fixture.php')];
function fixtureCmd(...args) {
  const [bin, file] = fixtureBin();
  return execFileSync(bin, [file, ...args], { encoding: 'utf8', timeout: 90000 });
}

/** keadaan DB fixture (is_verified per dokumen + sesi opname milik fixture + jumlah baris tabel opname), fixture.php inspect (read-only) */
function inspectDb() {
  return JSON.parse(fixtureCmd('inspect').trim().split('\n').pop());
}

/** catat panggilan API document-archive (method, path, query, body) dan respons GET opnames/folders (selected_at) */
function trace(page, monitor) {
  const calls = [];
  const folderResponses = [];
  page.on('request', (r) => {
    const u = new URL(r.url());
    if (!/\/document-archive\//.test(u.pathname)) return;
    let body = null;
    try {
      body = r.postData() ? JSON.parse(r.postData()) : null;
    } catch (e) {
      body = r.postData();
    }
    calls.push({ method: r.method(), path: u.pathname.replace(/^.*\/document-archive\//, ''), query: Object.fromEntries(u.searchParams), body, at: Date.now() });
  });
  page.on('response', (r) => {
    const u = r.url();
    if (/\/document-archive\/opnames/.test(u)) monitor.note(`API ${r.status()} ${r.request().method()} ${decodeURIComponent(u.replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 160)}`);
    if (r.request().method() === 'GET' && /\/document-archive\/opnames\/folders/.test(u)) {
      r.json().then((j) => folderResponses.push(j && j.result ? j.result.selected_at : null)).catch(() => null);
    }
  });
  calls.folderSelectedAt = folderResponses;
  return calls;
}
const callsOf = (calls, method, re) => calls.filter((c) => c.method === method && re.test(c.path));
/** request yang MENYIMPAN data opname: PUT/DELETE apa pun, atau POST opnames (= Konfirmasi). scan/preview tidak menyimpan */
const isPersisting = (c) => c.method === 'PUT' || c.method === 'DELETE' || (c.method === 'POST' && c.path === 'opnames');
const persisting = (calls) => calls.filter(isPersisting);
const opnameCalls = (calls) => calls.filter((c) => /^opnames(\/|$)/.test(c.path));
const pick = (obj, ...keys) => {
  for (const k of keys) if (obj && obj[k] !== undefined) return obj[k];
  return undefined;
};

/** panggilan API dengan token sesi e2e dari dalam halaman (token tidak pernah keluar dari browser) */
async function apiCall(page, method, apiPath, body) {
  const basePath = config.get('E2E_API_BASEPATH', 'api/v5') || 'api/v5';
  return page.evaluate(
    async ({ method: m, apiPath: p, body: b, basePath: bp }) => {
      const server = JSON.parse(localStorage.getItem('server'));
      const token = JSON.parse(localStorage.getItem('bearer')).accessToken;
      const base = String(server).replace(/\/+$/, '');
      const res = await fetch(`${base}/${bp}/${p}`, {
        method: m,
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: b ? JSON.stringify(b) : undefined,
      });
      return { status: res.status, json: await res.json().catch(() => null) };
    },
    { method, apiPath, body, basePath },
  );
}

// ---------------------------------------------------------------------------------------------------- halaman Archive
const archiveRow = (page, name) => page.locator('.archive-page .ant-table-row').filter({ hasText: nameRe(name) }).first();
const waitList = (page) => page.waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()), { timeout: 45000 });

async function openArchive(app, page) {
  const list = waitList(page);
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  await list;
  await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
}

/** baris `name` di list Archive: dicari ke SEMUA halaman (data developer menggeser baris uji dari halaman 1) */
async function ensureRow(page, name) {
  const row = archiveRow(page, name);
  if (await row.count()) {
    await row.waitFor({ state: 'visible', timeout: 15000 });
    return row;
  }
  const first = page.locator('.archive-page .ant-pagination-item-1:not(.ant-pagination-item-active)').first();
  if (await first.count()) {
    const l = waitList(page);
    await first.click();
    await l;
    await page.waitForTimeout(400);
    if (await row.count()) return row;
  }
  for (let i = 0; i < 15; i += 1) {
    const next = page.locator('.archive-page .ant-pagination-next:not(.ant-pagination-disabled)').first();
    if (!(await next.count())) break;
    const l = waitList(page);
    await next.click();
    await l;
    await page.waitForTimeout(400);
    if (await row.count()) {
      await row.waitFor({ state: 'visible', timeout: 15000 });
      return row;
    }
  }
  throw new Error(`baris "${name}" tidak ditemukan di list Archive (semua halaman)`);
}

async function openFolder(page, name) {
  const row = await ensureRow(page, name);
  const resp = page.waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && /id_?[aA]rchive=/.test(decodeURIComponent(r.url())), { timeout: 45000 });
  await row.locator('td.cursor-pointer').first().click();
  await resp;
  await page.waitForTimeout(500);
}

const plusButton = (page) => page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();

async function openPlus(page) {
  await plusButton(page).waitFor({ state: 'visible', timeout: 15000 });
  await plusButton(page).click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = trimAll(await menu.locator('li.ant-dropdown-menu-item').allInnerTexts());
  return { menu, items };
}

async function rowMenu(page, name) {
  const row = await ensureRow(page, name);
  const btn = row.locator('button.btn-action');
  if ((await btn.count()) === 0) return { menu: null, items: [] };
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  return { menu, items: trimAll(await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()) };
}

// ---------------------------------------------------------------------------------------------------- modal opname
const modalRoot = (page) => page.locator('.modal-opname-archive .ant-modal-content').last();
const modalTitle = (page) => modalRoot(page).locator('.ant-modal-title');
const modalTag = async (page) => trimAll(await modalTitle(page).locator('.ant-tag').allInnerTexts())[0] || null;
const modalHeading = async (page) => trimAll([await modalTitle(page).innerText()])[0];
const stepTitles = async (page) => trimAll(await modalRoot(page).locator('.ant-steps-item-title').allInnerTexts());
const activeStep = async (page) => trimAll(await modalRoot(page).locator('.ant-steps-item-active .ant-steps-item-title').allInnerTexts())[0];
/** ModalAdd (footer=null): tindakan dirender di dalam body, bukan di .ant-modal-footer */
const footerRoot = (page) => modalRoot(page).locator('.ant-modal-body > div.text-center');
const footerLeft = async (page) => trimAll([await footerRoot(page).locator('.ant-typography').first().innerText()])[0];
const footerBtn = (page, re) => footerRoot(page).locator('button').filter({ hasText: re }).first();
const modalRows = (page) => modalRoot(page).locator('.ant-table-tbody tr.ant-table-row');
const modalClosed = (page) => expect(page.locator('.modal-opname-archive .ant-modal-content:visible'), 'modal opname tertutup').toHaveCount(0, { timeout: 15000 });
const scanInput = (page) => modalRoot(page).locator('input[placeholder*="Scan QR"]');
/** teks sel nama = nama folder (+ "(dicentang ulang)" / "Dokumen yang langsung ..." menempel tanpa spasi), tanpa mencocokkan awalan folder lain */
const cellRe = (name) => new RegExp(`^\\s*${esc(name)}(?:$|[^\\w-]|Dokumen)`);
const step1Row = (page, name) =>
  modalRoot(page)
    .locator('.ant-table-tbody tr.ant-table-row')
    .filter({ has: page.locator('td:nth-child(2)', { hasText: cellRe(name) }) })
    .first();
const rowChecked = (row) => row.locator('.ant-table-selection-column input[type="checkbox"]').isChecked();
const rowDisabled = (row) => row.locator('.ant-table-selection-column input[type="checkbox"]').isDisabled();
const rowBox = (page, name) => step1Row(page, name).locator('.ant-table-selection-column input');
const toastText = (page) => page.locator('.ant-message-notice, .ant-notification-notice');

const foldersResponse = (page) => page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames\/folders/.test(r.url()), { timeout: 45000 });
const previewResponse = (page) => page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames\/preview(\?|$)/.test(r.url()), { timeout: 45000 });
const previewDocsResponse = (page, pred) =>
  page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames\/preview\/documents(\?|$)/.test(r.url()) && (!pred || pred(r)), { timeout: 45000 });
const confirmResponse = (page) => page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 60000 });

/** "+" -> Opname Dokumen / Opname Document; menunggu GET opnames/folders */
async function openOpnameFromPlus(page, lang = 'id') {
  const folders = foldersResponse(page);
  const { menu } = await openPlus(page);
  await menu.locator('li.ant-dropdown-menu-item').filter({ hasText: lang === 'en' ? /^Opname Document$/ : /^Opname Dokumen$/ }).click();
  await modalRoot(page).waitFor({ state: 'visible', timeout: 15000 });
  return folders;
}

/** menu baris folder -> Opname */
async function openOpnameFromRow(page, name) {
  const folders = foldersResponse(page);
  const { menu } = await rowMenu(page, name);
  await menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /^Opname$/ }).click();
  await modalRoot(page).waitFor({ state: 'visible', timeout: 15000 });
  return folders;
}

/** Step 2: scan lewat input (ketik + Enter) dan tunggu respons POST opnames/scan (klasifikasi tanpa simpan) */
async function scanTyped(page, code) {
  const input = scanInput(page);
  const resp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames\/scan(\?|$)/.test(r.url()), { timeout: 30000 });
  await input.click();
  await input.fill(code);
  await input.press('Enter');
  const r = await resp;
  const json = await r.json().catch(() => null);
  await page.waitForTimeout(250);
  return { status: r.status(), json };
}
const scanRow = (res) => res.json && res.json.result && res.json.result.row;

/** angka kartu "Sesi opname berjalan" dari teks kartu */
async function sessionCard(page) {
  const card = modalRoot(page).locator('.ant-card').filter({ hasText: /Sesi opname berjalan/ }).first();
  const text = (await card.innerText()).replace(/\s+/g, ' ');
  const num = (label) => {
    const m = new RegExp(`(\\d[\\d.,]*)\\s*${label}`).exec(text);
    return m ? Number(m[1].replace(/[.,]/g, '')) : null;
  };
  return { scanned: num('discan'), verified: num('terverifikasi'), notFound: num('tidak ditemukan'), invalid: num('tidak valid') };
}

// ---- filter hasil: Tag.CheckableTag (FB-10)
const filterTags = (page) => modalRoot(page).locator('.ant-tag-checkable');
/** { counts: { 'Semua': 3, ... }, active: ['Semua'] } dari semua CheckableTag di modal (label + jumlah) */
async function filterState(page) {
  const tags = filterTags(page);
  const n = await tags.count();
  const counts = {};
  const active = [];
  const order = [];
  for (let i = 0; i < n; i += 1) {
    const text = trimAll([await tags.nth(i).innerText()])[0];
    const cls = (await tags.nth(i).getAttribute('class')) || '';
    const m = /^(.*?)\s*([\d.,]+)$/.exec(text);
    const label = m ? m[1] : text;
    counts[label] = m ? Number(m[2].replace(/[.,]/g, '')) : null;
    order.push(label);
    if (/ant-tag-checkable-checked/.test(cls)) active.push(label);
  }
  return { counts, active, order };
}
const tagClick = (page, re) => filterTags(page).filter({ hasText: re }).first().click();
const resultCells = async (page) => trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row td:last-child').allInnerTexts());
const firstCells = async (page) => trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row td:first-child').allInnerTexts());

/** tata letak area filter: tag membungkus (lebih dari satu baris) tanpa gulir horizontal */
async function filterLayout(scope) {
  return scope.evaluate((root) => {
    const first = root.querySelector('.ant-tag-checkable');
    const box = first.closest('.ant-space');
    const bx = box.getBoundingClientRect();
    const tags = Array.from(box.querySelectorAll('.ant-tag-checkable')).map((t) => {
      const r = t.getBoundingClientRect();
      return { top: Math.round(r.top), right: Math.round(r.right), left: Math.round(r.left) };
    });
    return {
      scrollW: box.scrollWidth,
      clientW: box.clientWidth,
      boxLeft: Math.round(bx.left),
      boxRight: Math.round(bx.right),
      rows: new Set(tags.map((t) => t.top)).size,
      minLeft: Math.min(...tags.map((t) => t.left)),
      maxRight: Math.max(...tags.map((t) => t.right)),
      count: tags.length,
    };
  });
}
function expectWrapped(layout, label) {
  expect(layout.scrollW, `${label}: tanpa gulir horizontal di area filter (scrollWidth <= clientWidth)`).toBeLessThanOrEqual(layout.clientW + 1);
  expect(layout.maxRight, `${label}: semua tag di dalam lebar area filter`).toBeLessThanOrEqual(layout.boxRight + 1);
  expect(layout.minLeft, `${label}: tag tidak keluar di kiri`).toBeGreaterThanOrEqual(layout.boxLeft - 1);
  expect(layout.rows, `${label}: tag membungkus ke lebih dari satu baris`).toBeGreaterThanOrEqual(2);
}

// ---- Steps (FB-11)
async function stepsInfo(page) {
  return modalRoot(page).evaluate((root) => {
    const c = root.querySelector('.steps-container');
    const steps = c.querySelector('.ant-steps');
    const active = c.querySelector('.ant-steps-item-active');
    const title = active.querySelector('.ant-steps-item-title');
    const cr = c.getBoundingClientRect();
    const tr = title.getBoundingClientRect();
    const body = root.querySelector('.ant-modal-body');
    return {
      cls: steps.className,
      scrollLeft: Math.round(c.scrollLeft),
      containerW: Math.round(cr.width),
      stepsScrollW: steps.scrollWidth,
      bodyW: Math.round(body.getBoundingClientRect().width),
      title: title.innerText.replace(/\s+/g, ' ').trim(),
      titleLeft: Math.round(tr.left - cr.left),
      titleRight: Math.round(tr.right - cr.left),
    };
  });
}
/** judul langkah aktif terlihat penuh di dalam .steps-container (sesudah gulir otomatis selesai) */
async function expectActiveStepVisible(page, label) {
  await expect
    .poll(
      async () => {
        const s = await stepsInfo(page);
        return s.titleLeft >= -1 && s.titleRight <= s.containerW + 1;
      },
      { message: `${label}: judul langkah aktif tergulir ke tampilan`, timeout: 6000 },
    )
    .toBe(true);
  return stepsInfo(page);
}

/** Batal / X: tutup modal. Bila ada scan: dialog konfirmasi (Ya = tutup, Tidak = tetap). Tidak pernah mengirim request. */
async function closeModal(page, { via = 'x', dialog = false } = {}) {
  if (via === 'x') await modalRoot(page).locator('.ant-modal-close').click();
  else await footerBtn(page, /^Batal$/).click();
  if (dialog) {
    const dlg = page.locator('.ant-modal-confirm').last();
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    await expect(dlg, 'dialog batal').toContainText('Hasil scan sesi ini akan hilang. Batalkan opname ini?');
    await dlg.locator('.ant-btn-primary').click();
  }
  await modalClosed(page);
}

/** sesudah modal opname ditutup: ketikan cepat + Enter di luar input memicu pencarian Archive (scanner pencarian menyala lagi) */
async function expectSearchReactivated(page, calls, monitor) {
  await page.waitForTimeout(600);
  await page.locator('.archive-page').click({ position: { x: 5, y: 5 } }).catch(() => null);
  const before = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
  await page.keyboard.type('QA26-CARI-123', { delay: 0 });
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1500);
  const after = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives'));
  monitor.note(`pencarian sesudah modal ditutup: ${after.length - before} GET archives baru`);
  expect(after.length, 'scanner pencarian Archive aktif lagi sesudah modal opname ditutup').toBeGreaterThan(before);
  expect(JSON.stringify(after[after.length - 1].query), 'pencarian memakai kode yang di-scan').toContain('QA26-CARI-123');
}

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
}

/** buang dari monitor penolakan yang memang diharapkan (respons + log konsol axios), setelah memastikan terjadi */
function expectRejected(monitor, label, { status, urlRe, minCount = 1 }) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === status && urlRe.test(decodeURIComponent(e.url))) ||
    (e.kind === 'console' && new RegExp(`Request failed with status code ${status}`).test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  expect(dropped.filter((e) => e.kind === 'response').length, `${label}: respons ${status} tercatat (diharapkan)`).toBeGreaterThanOrEqual(minCount);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan`);
}

/** keadaan tabel opname + kolom verifikasi dokumen uji tidak berubah (AC-24, AC-17 sebelum Konfirmasi) */
function expectDbUnchanged(before, label) {
  const after = inspectDb();
  expect(after.tables, `${label}: baris tabel opname (sesi/folder/dokumen) + dokumen ber-verifikasi tidak berubah`).toEqual(before.tables);
  expect(after.docs, `${label}: kolom verifikasi dokumen uji tidak berubah`).toEqual(before.docs);
  expect(after.sessions, `${label}: sesi milik fixture tidak berubah`).toEqual(before.sessions);
  return after;
}

/** baris root sudah diopname hari ini (data developer): toggle Lanjut default aktif -> dimatikan supaya peringatan root muncul */
async function turnOffRootContinue(page) {
  const sw = modalRows(page).first().locator('.ant-switch');
  if ((await sw.count()) && /ant-switch-checked/.test((await sw.getAttribute('class')) || '')) {
    await sw.click();
    await page.waitForTimeout(300);
  }
}

/**
 * Peringatan root Step 3 (N-4): bentuk teks bergantung pada data (root sudah diopname hari ini = "<root> dijalankan/runs without ..." dan
 * belum = "<root>: N dokumen/documents ..."), jadi variannya diturunkan dari `is_opnamed_today` respons pratinjau, bukan diasumsikan.
 * Mengembalikan { text, count } baris peringatan root, atau null.
 */
function findRootWarning(texts, warning, lang) {
  const root = ROOT[lang];
  const prefix = warning.is_opnamed_today
    ? lang === 'en' ? `${root} runs without continuing opname —` : `${root} dijalankan tanpa lanjut opname —`
    : `${root}:`;
  const unit = lang === 'en' ? 'documents' : 'dokumen';
  for (const t of texts) {
    const m = new RegExp(`^${esc(prefix)}\\s*([\\d.,]+)\\s*${unit}`).exec(t);
    if (m) return { text: t, count: digits(m[1]) };
  }
  return null;
}

/** username QA tidak boleh masuk catatan/galeri: disamarkan di DOM sebelum screenshot dan di teks catatan */
const maskText = (t) => String(t).split(ids()._user.username).join('<user QA>');
async function maskUsername(page) {
  await page.evaluate((name) => {
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (w.nextNode()) nodes.push(w.currentNode);
    nodes.forEach((n) => {
      if (n.nodeValue.includes(name)) n.nodeValue = n.nodeValue.split(name).join('<user QA>');
    });
  }, ids()._user.username);
}

const dateStr = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

// ================================================================================================================
test.describe('ED-1026 opname per folder (tanpa draft)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  // ---------------------------------------------------------------------------------------------- AC-3 / AC-4 menu
  test('[default] ED-1026 AC-3/AC-4 role ber-Opname Document: "+" (root & folder) dan menu baris memuat Opname; membuka menu tanpa request opnames', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    monitor.note(`mode QA26_MODE=${MODE}`);
    const calls = trace(page, monitor);
    await openArchive(app, page);

    // "+" di root: Opname Dokumen ada (tepat satu), sesudah item Archive lama; item sesudahnya milik item lain (ED-1029) tidak diasumsikan
    const plusRoot = await openPlus(page);
    monitor.note(`item "+" root: ${plusRoot.items.join(' | ')}`);
    expect(plusRoot.items.filter((t) => t === 'Opname Dokumen'), '"+" root: Opname Dokumen tepat satu').toHaveLength(1);
    const iOp = plusRoot.items.indexOf('Opname Dokumen');
    for (const earlier of ['Tambah Folder', 'Serahkan Dokumen', 'Terima Dokumen']) {
      const i = plusRoot.items.indexOf(earlier);
      expect(i, `"+" root memuat ${earlier}`).toBeGreaterThanOrEqual(0);
      expect(i, `${earlier} lebih dulu dari Opname Dokumen`).toBeLessThan(iOp);
    }
    await page.waitForTimeout(300);
    await app.screenshot('ac3-plus-root-opname-dokumen');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // menu baris folder (semua hak): Info, Pindahkan, Lihat, Opname, Hapus (urutan ini)
    const rm = await rowMenu(page, `${P}ALPHA`);
    monitor.note(`menu baris folder: ${rm.items.join(' | ')}`);
    expect(rm.items, 'menu baris folder: Info, Pindahkan, Lihat, Opname, Hapus').toEqual(['Info', 'Pindahkan', 'Lihat', 'Opname', 'Hapus']);
    await rm.menu.hover();
    await page.waitForTimeout(300);
    await app.screenshot('ac3-menu-baris-folder-opname');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // di dalam folder: "+" memuat Opname Dokumen sesudah Letakkan Dokumen; baris dokumen tanpa Opname
    await openFolder(page, `${P}ALPHA`);
    const plusIn = await openPlus(page);
    monitor.note(`item "+" dalam folder: ${plusIn.items.join(' | ')}`);
    expect(plusIn.items, '"+" di dalam folder memuat Opname Dokumen').toContain('Opname Dokumen');
    expect(plusIn.items.indexOf('Letakkan Dokumen'), 'Letakkan Dokumen ada').toBeGreaterThanOrEqual(0);
    expect(plusIn.items.indexOf('Opname Dokumen'), 'Opname Dokumen sesudah Letakkan Dokumen').toBeGreaterThan(plusIn.items.indexOf('Letakkan Dokumen'));
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    const docMenu = await rowMenu(page, `${P}AD1`);
    monitor.note(`menu baris dokumen: ${docMenu.items.join(' | ') || '(tanpa tombol)'}`);
    expect(docMenu.items.some((t) => /Opname/.test(t)), 'baris dokumen tanpa Opname').toBe(false);
    await page.keyboard.press('Escape');

    // subfolder dalam folder: menu Opname juga ada
    const sub = await rowMenu(page, `${P}ALPHA-S1`);
    expect(sub.items, 'subfolder: menu memuat Opname').toContain('Opname');
    await page.keyboard.press('Escape');

    // membuka menu / folder tidak mengirim request opnames sama sekali (modal baru bicara ke server saat dibuka)
    expect(opnameCalls(calls).map((c) => `${c.method} ${c.path}`), 'tanpa request opnames/* selama hanya membuka menu').toEqual([]);
    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1026 AC-3/FB-8a role (preset) TANPA Opname Document: "+" dan menu baris tanpa Opname, modal tidak di DOM, nol request opnames/*', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'norole', 'mode norole (run kedua, QA26_MODE=norole)');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    const plusRoot = await openPlus(page);
    monitor.note(`item "+" root (tanpa Opname Document): ${plusRoot.items.join(' | ')}`);
    expect(plusRoot.items.length, '"+" tetap punya item lain (Tambah Folder dst.)').toBeGreaterThan(0);
    expect(plusRoot.items.some((t) => /Opname/.test(t)), '"+" root tanpa Opname Dokumen').toBe(false);
    await app.screenshot('ac3-norole-plus-root');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    const rm = await rowMenu(page, `${P}ALPHA`);
    monitor.note(`menu baris folder (tanpa Opname Document): ${rm.items.join(' | ')}`);
    expect(rm.items, 'menu baris folder: Info, Pindahkan, Lihat, Hapus (tanpa Opname)').toEqual(['Info', 'Pindahkan', 'Lihat', 'Hapus']);
    await rm.menu.hover();
    await app.screenshot('ac3-norole-menu-baris');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    await openFolder(page, `${P}ALPHA`);
    const plusIn = await openPlus(page);
    expect(plusIn.items.some((t) => /Opname/.test(t)), '"+" dalam folder tanpa Opname Dokumen').toBe(false);
    await page.keyboard.press('Escape');
    const docMenu = await rowMenu(page, `${P}AD1`);
    expect(docMenu.items.some((t) => /Opname/.test(t)), 'baris dokumen tanpa Opname').toBe(false);
    await page.keyboard.press('Escape');

    // FB-8a: tanpa permission tidak ada tampilan opname: modal tidak ada di DOM, tidak ada request opnames/* sepanjang sesi
    await expect(page.locator('.modal-opname-archive'), 'modal opname tidak ada di DOM').toHaveCount(0);
    expect(opnameCalls(calls).map((c) => `${c.method} ${c.path}`), 'tanpa request opnames/* sepanjang sesi').toEqual([]);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-3 hak View folder (ED-1025)
  test('[default] ED-1026 AC-3 hak folder: folder hanya View = menu Info/Lihat/Opname; tanpa View = tanpa menu aksi; opname dari folder hanya-View tanpa request tulis', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    // hanya View: Info, Lihat, Opname (tanpa Pindahkan, Hapus)
    const vo = await rowMenu(page, `${P}VIEWONLY`);
    monitor.note(`menu baris VIEWONLY: ${vo.items.join(' | ')}`);
    expect(vo.items, 'folder hanya View: Info, Lihat, Opname').toEqual(['Info', 'Lihat', 'Opname']);
    await vo.menu.hover();
    await app.screenshot('ac3-menu-folder-hanya-view');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // tanpa View (folder permission aktif tanpa baris): tanpa menu aksi
    const nv = await rowMenu(page, `${P}NOVIEW`);
    monitor.note(`menu baris NOVIEW: ${nv.items.join(' | ') || '(tanpa tombol aksi)'}`);
    expect(nv.items.length, 'folder tanpa View: tanpa menu aksi (tanpa Opname)').toBe(0);

    // di dalam folder hanya-View: "+" memuat Opname Dokumen tetapi tanpa Tambah Folder / Letakkan Dokumen (tanpa Store)
    await openFolder(page, `${P}VIEWONLY`);
    const plus = await openPlus(page);
    monitor.note(`"+" di folder hanya View: ${plus.items.join(' | ')}`);
    expect(plus.items, '"+" folder hanya View: Opname Dokumen ada').toContain('Opname Dokumen');
    expect(plus.items.some((t) => /Tambah Folder|Letakkan Dokumen/.test(t)), '"+" folder hanya View: tanpa Tambah Folder / Letakkan Dokumen').toBe(false);

    // opname dari folder hanya-View tetap boleh (View efektif): Step 1 dilewati, Step 2 terbuka tanpa error 403, pratinjau 200
    const folders = foldersResponse(page);
    const preview = previewResponse(page);
    await plus.menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /^Opname Dokumen$/ }).click();
    await modalRoot(page).waitFor({ state: 'visible', timeout: 15000 });
    expect((await folders).status(), 'GET opnames/folders folder hanya View: 200').toBe(200);
    expect((await preview).status(), 'POST opnames/preview folder hanya View: 200').toBe(200);
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'tag = VIEWONLY (langsung, tanpa menunggu request)').toBe(`${P}VIEWONLY`);
    expect(await stepTitles(page), 'stepper 2 langkah').toEqual(['Opname Folder', 'Konfirmasi']);
    await app.screenshot('ac3-opname-folder-hanya-view');
    await closeModal(page, { via: 'x' });
    expect(persisting(calls), 'membuka & menutup opname folder hanya-View: tanpa request tulis').toEqual([]);
    expectDbUnchanged(before, 'folder hanya-View');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- Step 1 di root (id, N-4)
  test('[default] ED-1026 AC-4/AC-7/AC-28 Step 1 dari "+" di root (id): isi = API, "Semua Arsip", Batal tanpa request, Next = satu pratinjau, tag "+ N subfolder", dokumen root', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    const folders = await openOpnameFromPlus(page);
    const fr = await folders;
    expect(fr.status(), 'GET opnames/folders (root)').toBe(200);
    const fj = (await fr.json()).result;
    expect(new URL(fr.url()).searchParams.get('id_archive') || new URL(fr.url()).searchParams.get('idArchive'), 'root: tanpa parameter id_archive').toBeNull();
    expect(fj.folder.is_root, 'folder.is_root').toBe(true);
    expect(fj.selected_at, 'selected_at = waktu server Y-m-d H:i:s').toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    await modalRows(page).first().waitFor({ state: 'visible', timeout: 20000 });

    // judul, tag (N-4: root diterjemahkan), stepper; tag langsung ada (tanpa menunggu request lain)
    expect(await modalHeading(page), 'judul memuat Opname Dokumen').toContain('Opname Dokumen');
    expect(await modalTag(page), 'tag Step 1 root (id) = "Semua Arsip · root"').toBe(`${ROOT.id} · root`);
    expect(await stepTitles(page), 'stepper 3 langkah').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'langkah aktif').toBe('Pilih Folder');
    await expect(modalRoot(page).locator('.ant-alert-info'), 'alert info Step 1').toHaveCount(1);
    expect(trimAll(await modalRoot(page).locator('.ant-table-thead th').allInnerTexts()).filter(Boolean).map((t) => t.toLowerCase()), 'kolom tabel').toEqual(['folder', 'dokumen', 'opname hari ini']);

    // baris pertama = "Semua Arsip" (root, terjemahan), tercentang dan terkunci
    const first = modalRows(page).first();
    await expect(first, 'baris pertama = Semua Arsip (id)').toContainText(ROOT.id);
    await expect(first, 'tanpa teks Inggris "All Archive" di Step 1 (id)').not.toContainText('All Archive');
    expect(await rowChecked(first), 'baris root tercentang').toBe(true);
    expect(await rowDisabled(first), 'baris root terkunci').toBe(true);

    // isi tabel = respons API (data-independen): urutan, nama, centang default, status opname hari ini, jumlah dokumen
    const children = fj.children;
    await expect(modalRows(page), 'jumlah baris = 1 (root) + children API').toHaveCount(1 + children.length);
    monitor.note(`Step 1 root: ${children.length} subfolder dari API`);
    expect(children.some((c) => c.name === 'CABANG - JOGJA'), 'respons API tanpa CABANG - JOGJA (lokasi user Semarang)').toBe(false);
    expect(trimAll(await modalRoot(page).locator('.ant-table-tbody').allInnerTexts()).join(' ').includes('CABANG - JOGJA'), 'UI tanpa CABANG - JOGJA').toBe(false);
    let checkedKids = 0;
    let checkedDocs = 0;
    for (let i = 0; i < children.length; i += 1) {
      const c = children[i];
      const row = modalRows(page).nth(i + 1);
      const cells = trimAll(await row.locator('td').allInnerTexts());
      expect(cells[1], `baris ${i + 1}: nama = ${c.name}`).toContain(c.name);
      expect(await rowChecked(row), `${c.name}: tercentang = is_default_checked`).toBe(!!c.is_default_checked);
      expect(digits(cells[2]), `${c.name}: kolom Dokumen = document_count`).toBe(String(c.document_count));
      if (c.opnamed_today) expect(cells[3], `${c.name}: sudah diopname hari ini`).toContain('Sudah diopname hari ini');
      else expect(cells[3], `${c.name}: belum diopname`).toContain('Belum diopname');
      if (c.is_default_checked) {
        checkedKids += 1;
        checkedDocs += Number(c.document_count);
      }
    }
    // folder uji milik fixture selalu ada di Step 1 (View penuh / hanya-View) dengan nilai pasti
    for (const n of [`${P}ALPHA`, `${P}BETA`, `${P}GAMMA`, `${P}VIEWONLY`]) expect(children.some((c) => c.name === n), `Step 1 memuat ${n}`).toBe(true);
    expect(children.some((c) => c.name === `${P}NOVIEW`), 'NOVIEW (folder permission tanpa baris user) tidak muncul').toBe(false);
    const alpha = step1Row(page, `${P}ALPHA`);
    const beta = step1Row(page, `${P}BETA`);
    expect(await rowChecked(alpha), 'ALPHA belum diopname: tercentang').toBe(true);
    await expect(alpha, 'ALPHA: Belum diopname').toContainText('Belum diopname');
    await expect(alpha.locator('td').nth(2), 'kolom Document ALPHA = 7 (2 langsung + S1 2+1 + S2 1 + S3 1)').toHaveText('7');
    expect(await rowChecked(beta), 'BETA sudah diopname hari ini: tidak tercentang').toBe(false);
    await expect(beta, 'BETA: Sudah diopname hari ini · 09:15').toContainText('Sudah diopname hari ini · 09:15');
    await expect(beta, 'BETA tidak dicentang: tanpa toggle Lanjut').not.toContainText('Lanjut opname');

    // footer "N folder dipilih · M dokumen": N = baris tercentang, M = dokumen folder root + subfolder tercentang (dari API)
    const footer = await footerLeft(page);
    monitor.note(`footer Step 1: ${footer}`);
    const m = /^(\d+) folder dipilih · ([\d.,]+) dokumen$/.exec(footer);
    expect(m, 'footer: "N folder dipilih · M dokumen"').toBeTruthy();
    expect(Number(m[1]), 'N = 1 + subfolder tercentang').toBe(1 + checkedKids);
    expect(Number(digits(m[2])), 'M = dokumen root + dokumen subfolder tercentang').toBe(Number(fj.folder.document_count) + checkedDocs);
    await app.screenshot('ac7-step1-root');

    // centang-semua (header) lalu lepas: baris root tetap tercentang
    const headerBox = modalRoot(page).locator('.ant-table-thead input[type="checkbox"]');
    await headerBox.click();
    await page.waitForTimeout(300);
    let allChecked = true;
    for (let i = 0; i < (await modalRows(page).count()); i += 1) allChecked = allChecked && (await rowChecked(modalRows(page).nth(i)));
    expect(allChecked, 'centang-semua: semua baris tercentang').toBe(true);
    await headerBox.click();
    await page.waitForTimeout(300);
    expect(await rowChecked(modalRows(page).first()), 'lepas semua: baris root tetap tercentang').toBe(true);
    expect(await rowChecked(alpha), 'lepas semua: ALPHA tidak tercentang').toBe(false);
    await expect.poll(() => footerLeft(page), { message: 'footer sesudah lepas semua: 1 folder dipilih', timeout: 15000 }).toMatch(/^1 folder dipilih · [\d.,]+ dokumen$/);

    // Batal tanpa scan: modal langsung tertutup, tanpa dialog, tanpa request apa pun selain GET folders
    await footerBtn(page, /^Batal$/).click();
    await modalClosed(page);
    await expect(page.locator('.ant-modal-confirm'), 'Batal tanpa scan: tanpa dialog').toHaveCount(0);
    await page.waitForTimeout(500);
    expect(opnameCalls(calls).map((c) => `${c.method} ${c.path}`), 'Batal di Step 1: hanya GET opnames/folders').toEqual(['GET opnames/folders']);

    // buka lagi: pilih ALPHA + GAMMA saja -> Next = SATU POST opnames/preview (tanpa simpan), body di level atas
    await openOpnameFromPlus(page);
    await step1Row(page, `${P}ALPHA`).waitFor({ state: 'visible', timeout: 20000 });
    await headerBox.click(); // semua
    await page.waitForTimeout(200);
    await headerBox.click(); // lepas semua
    await page.waitForTimeout(200);
    await rowBox(page, `${P}ALPHA`).click();
    await rowBox(page, `${P}GAMMA`).click();
    await page.waitForTimeout(200);
    await turnOffRootContinue(page);
    await expect.poll(() => footerLeft(page), { message: '3 folder dipilih (root + ALPHA + GAMMA)', timeout: 15000 }).toMatch(/^3 folder dipilih · [\d.,]+ dokumen$/);

    const nCallsBefore = calls.length;
    const prev = previewResponse(page);
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    // tag Step 2 langsung = tag Step 1 + " + N subfolder" (N = subfolder dicentang, lokal; tanpa menunggu server)
    expect(await modalTag(page), 'tag Step 2: Semua Arsip · root + 2 subfolder (langsung)').toBe(`${ROOT.id} · root + 2 subfolder`);
    expect(await activeStep(page), 'langkah aktif Step 2').toBe('Opname Folder');
    const pr = await prev;
    expect(pr.status(), 'POST opnames/preview').toBe(200);
    const pj = (await pr.json()).result;
    expect(pj.scope, 'preview.scope = tepat 3 kunci').toEqual({ is_root: true, folder_name: null, subfolder_count: 2 });
    const newCalls = calls.slice(nCallsBefore).map((c) => `${c.method} ${c.path}`);
    monitor.note(`request sejak Next: ${newCalls.join(', ')}`);
    expect(newCalls, 'Next: hanya satu POST opnames/preview (tanpa POST opnames / PUT / DELETE)').toEqual(['POST opnames/preview']);
    const body = callsOf(calls, 'POST', /^opnames\/preview$/)[0].body;
    monitor.note(`body POST preview: ${JSON.stringify(body)}`);
    expect(pick(body, 'id_archive', 'idArchive'), 'body: id_archive null (root)').toBeNull();
    expect(pick(body, 'is_continue', 'isContinue'), 'body: is_continue false').toBe(false);
    expect((body.folders || []).map((f) => pick(f, 'id_archive', 'idArchive')).sort(), 'body: folders = ALPHA + GAMMA (level atas, tanpa "selection")').toEqual([ids()[`${P}ALPHA`], ids()[`${P}GAMMA`]].sort());
    expect(body.scans, 'body: scans kosong').toEqual([]);
    await expect
      .poll(
        async () => {
          const m = /^(.*) · ([\d.,]+) dokumen$/.exec((await footerLeft(page)) || '');
          return m ? [m[1], digits(m[2])] : null;
        },
        { message: 'footer Step 2 = tag + total dokumen pratinjau', timeout: 15000 },
      )
      .toEqual([`${ROOT.id} · root + 2 subfolder`, String(pj.counts.total_documents)]);
    await expect(toastText(page), 'tanpa toast sukses').toHaveCount(0);
    await app.screenshot('ac4-step2-root-sesi');

    // dokumen tanpa folder (root, K-4): ikut cakupan -> Verified; kolom Folder Step 3 = "Semua Arsip" (N-4)
    const rs = await scanTyped(page, `${P}RD2`);
    expect(scanRow(rs).result, 'dokumen root = verified (cakupan root mencakup dokumen tanpa folder)').toBe('verified');
    expect(scanRow(rs).id_archive_folder, 'dokumen root: tanpa folder').toBeNull();
    const sc = await sessionCard(page);
    expect(sc, 'kartu sesi: 1 discan 1 verified').toEqual({ scanned: 1, verified: 1, notFound: 0, invalid: 0 });
    const s3 = previewResponse(page);
    const s3docs = previewDocsResponse(page);
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    expect(await modalTag(page), 'tag Step 3 sama dengan Step 2').toBe(`${ROOT.id} · root + 2 subfolder`);
    const s3j = (await (await s3).json()).result;
    expect((await s3docs).status(), 'POST preview/documents (Step 3)').toBe(200);
    await page.waitForTimeout(800);
    const rootRow = trimAll(await modalRows(page).first().locator('td').allInnerTexts());
    monitor.note(`baris Step 3 dokumen root: ${rootRow.join(' | ')}`);
    expect(rootRow[0], 'baris = dokumen root RD2').toBe(`${P}RD2`);
    expect(rootRow[2], 'kolom Folder dokumen root = Semua Arsip (id)').toBe(ROOT.id);
    // peringatan root: RD1 (verified, tak discan) -> "Semua Arsip: N dokumen ..."; N = unverify_count API
    const rootWarn = (s3j.warnings || []).find((w) => w.id_archive === null);
    expect(rootWarn, 'API: peringatan root ada (RD1 verified tak discan)').toBeTruthy();
    const warnTexts = trimAll(await modalRoot(page).locator('.ant-alert-warning').allInnerTexts());
    monitor.note(`peringatan Step 3: ${warnTexts.join(' || ')}`);
    const rootFound = findRootWarning(warnTexts, rootWarn, 'id');
    expect(rootFound, `peringatan root memakai label "${ROOT.id}" (varian is_opnamed_today=${rootWarn.is_opnamed_today})`).toBeTruthy();
    expect(rootFound.count, 'jumlah di peringatan root = unverify_count API').toBe(String(rootWarn.unverify_count));
    expect(warnTexts.join(' '), 'tanpa teks Inggris "All Archive" di Step 3 (id)').not.toContain('All Archive');
    await app.screenshot('ac28-step3-root-semua-arsip');

    // ada scan: X meminta konfirmasi, lalu tutup TANPA request tulis
    await closeModal(page, { via: 'x', dialog: true });
    expect(persisting(calls), 'tutup sesi root: tanpa POST opnames / PUT / DELETE').toEqual([]);
    expectDbUnchanged(before, 'sesi root');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- Step 1 BETA
  test('[default] ED-1026 AC-7 BETA: sudah diopname, dicentang ulang + Lanjut opname, footer, Back/Next memuat ulang Step 1, peringatan Step 3, tutup tanpa request', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    const folders = await openOpnameFromRow(page, `${P}BETA`);
    const fr = await folders;
    const fj = (await fr.json()).result;
    const q = new URL(fr.url()).searchParams;
    expect(q.get('id_archive') || q.get('idArchive'), 'GET opnames/folders?id_archive = BETA').toBe(ids()[`${P}BETA`]);
    expect(fj.skip_select_folder, 'BETA: Step 1 tidak dilewati').toBe(false);
    await step1Row(page, `${P}BETA-T1`).waitFor({ state: 'visible', timeout: 20000 });

    expect(await stepTitles(page), 'stepper 3 langkah').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    expect(await modalTag(page), 'tag Step 1 = nama folder opname').toBe(`${P}BETA`);

    // baris pertama = BETA sendiri: sudah diopname hari ini => dicentang ulang + toggle Lanjut default aktif
    const self = modalRows(page).first();
    await expect(self, 'baris pertama = BETA').toContainText(`${P}BETA`);
    await expect(self, 'teks kecil dokumen langsung').toContainText('Dokumen yang langsung berada di folder ini');
    expect(await rowChecked(self), 'BETA tercentang').toBe(true);
    expect(await rowDisabled(self), 'BETA terkunci').toBe(true);
    await expect(self, 'BETA: Sudah diopname hari ini · 09:15').toContainText('Sudah diopname hari ini · 09:15');
    await expect(self, 'BETA: (dicentang ulang)').toContainText('(dicentang ulang)');
    await expect(self.locator('.ant-switch'), 'BETA: toggle Lanjut opname aktif').toHaveClass(/ant-switch-checked/);
    await expect(self, 'BETA: teks Lanjut opname').toContainText('Lanjut opname: dokumen yang sudah verified dan tidak discan tetap verified.');

    // T1 sudah diopname: tidak tercentang; T2 belum: tercentang
    const t1 = step1Row(page, `${P}BETA-T1`);
    const t2 = step1Row(page, `${P}BETA-T2`);
    expect(await rowChecked(t1), 'T1 sudah diopname: tidak tercentang').toBe(false);
    await expect(t1, 'T1: Sudah diopname hari ini · 09:15').toContainText('Sudah diopname hari ini · 09:15');
    await expect(t1.locator('.ant-switch'), 'T1 belum dicentang: tanpa toggle').toHaveCount(0);
    expect(await rowChecked(t2), 'T2 belum diopname: tercentang').toBe(true);
    await expect(t2, 'T2: Belum diopname').toContainText('Belum diopname');
    await expect.poll(() => footerLeft(page), { message: 'footer awal: BETA(1 langsung) + T2(1)', timeout: 15000 }).toBe('2 folder dipilih · 2 dokumen');

    // centang T1 -> dicentang ulang + toggle aktif + teks; footer 3 folder . 4 dokumen
    await rowBox(page, `${P}BETA-T1`).click();
    await page.waitForTimeout(300);
    expect(await rowChecked(t1), 'T1 dicentang').toBe(true);
    await expect(t1, 'T1: (dicentang ulang)').toContainText('(dicentang ulang)');
    await expect(t1.locator('.ant-switch'), 'T1: toggle aktif').toHaveClass(/ant-switch-checked/);
    await expect(t1, 'T1: teks akibat Lanjut').toContainText('Lanjut opname: dokumen yang sudah verified dan tidak discan tetap verified.');
    await expect.poll(() => footerLeft(page), { message: 'footer sesudah T1 dicentang', timeout: 15000 }).toBe('3 folder dipilih · 4 dokumen');
    await app.screenshot('ac7-step1-beta-dicentang-ulang');

    // matikan toggle BETA: label + teks merah
    await self.locator('.ant-switch').click();
    await page.waitForTimeout(300);
    await expect(self.locator('.ant-switch'), 'BETA: toggle mati').not.toHaveClass(/ant-switch-checked/);
    await expect(self, 'BETA: label Tidak lanjut opname').toContainText('Tidak lanjut opname');
    await expect(self, 'BETA: teks Tidak lanjut').toContainText('Tidak lanjut: dokumen verified yang tidak discan pada sesi ini berubah menjadi unverified.');
    await app.screenshot('ac7-step1-beta-tidak-lanjut');

    // Next = satu POST preview (BUKAN POST opnames)
    const prev = previewResponse(page);
    await footerBtn(page, /^Lanjut$/).click();
    expect((await prev).status(), 'POST opnames/preview').toBe(200);
    const body = callsOf(calls, 'POST', /^opnames\/preview$/)[0].body;
    monitor.note(`body POST preview BETA: ${JSON.stringify(body)}`);
    expect(pick(body, 'id_archive', 'idArchive'), 'body: id_archive = BETA').toBe(ids()[`${P}BETA`]);
    expect(pick(body, 'is_continue', 'isContinue'), 'body: is_continue BETA = false (toggle mati)').toBe(false);
    const byId = Object.fromEntries((body.folders || []).map((f) => [pick(f, 'id_archive', 'idArchive'), pick(f, 'is_continue', 'isContinue')]));
    expect(Object.keys(byId).sort(), 'body: folders = T1 + T2').toEqual([ids()[`${P}BETA-T1`], ids()[`${P}BETA-T2`]].sort());
    expect(byId[ids()[`${P}BETA-T1`]], 'T1 is_continue true (sudah diopname + toggle aktif)').toBe(true);
    expect(byId[ids()[`${P}BETA-T2`]], 'T2 is_continue false (belum diopname)').toBe(false);
    expect(persisting(calls), 'Next: tanpa request tulis').toEqual([]);

    // Step 2: tag = BETA + 2 subfolder (langsung), kartu status 2 / 4
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await activeStep(page), 'langkah aktif').toBe('Opname Folder');
    expect(await modalTag(page), 'tag Step 2: BETA + 2 subfolder (T1 + T2 dicentang)').toBe(`${P}BETA + 2 subfolder`);
    await expect
      .poll(async () => trimAll([await modalRoot(page).locator('.ant-card').filter({ hasText: /Status verifikasi saat ini/ }).first().innerText()])[0], { message: 'kartu status verifikasi: 2 / 4', timeout: 15000 })
      .toMatch(/2\s*\/\s*4/);
    await expect.poll(() => footerLeft(page), { message: 'footer Step 2', timeout: 15000 }).toBe(`${P}BETA + 2 subfolder · 4 dokumen`);
    const scanCall = await scanTyped(page, `${P}T2D1`);
    expect(scanCall.status, 'scan T2D1').toBe(200);
    expect(scanRow(scanCall).result, 'T2D1 = verified').toBe('verified');

    // Finish -> Step 3: kartu, peringatan BETA (tanpa lanjut, 1 dokumen verified tak discan)
    const prev3 = previewResponse(page);
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    expect(await activeStep(page), 'langkah aktif Step 3').toBe('Konfirmasi');
    // pengamatan: sebelum POST preview Step 3 kembali, kartu masih memakai ringkasan Step 2 (Konfirmasi dinonaktifkan selama itu)
    const staleCards = trimAll(await modalRoot(page).locator('.ant-card').allInnerTexts());
    monitor.note(`kartu Step 3 tepat sesudah Selesai Opname (sebelum pratinjau Step 3 kembali): ${staleCards.join(' | ')}`);
    await expect(footerBtn(page, /^Konfirmasi Opname$/), 'Konfirmasi nonaktif sampai pratinjau Step 3 kembali').toBeDisabled();
    expect((await prev3).status(), 'POST opnames/preview (Step 3)').toBe(200);
    await expect(footerBtn(page, /^Konfirmasi Opname$/), 'Konfirmasi aktif sesudah pratinjau Step 3').toBeEnabled({ timeout: 15000 });
    await modalRoot(page).locator('.ant-alert-warning').first().waitFor({ state: 'visible', timeout: 20000 });
    const warnings = trimAll(await modalRoot(page).locator('.ant-alert-warning').allInnerTexts());
    monitor.note(`peringatan Step 3: ${warnings.join(' || ')}`);
    expect(warnings.length, 'satu peringatan (hanya BETA tanpa lanjut)').toBe(1);
    expect(warnings[0], 'peringatan BETA: tanpa lanjut opname - 1 dokumen').toContain(`${P}BETA dijalankan tanpa lanjut opname — 1 dokumen`);
    const cardsText = trimAll(await modalRoot(page).locator('.ant-card').allInnerTexts());
    expect(cardsText[0], 'Total dokumen 4').toMatch(/^4\s*Total dokumen/);
    expect(cardsText[1], 'Terverifikasi sesi ini 1').toMatch(/^1\s*Terverifikasi sesi ini/);
    expect(cardsText[4], 'Belum discan 3').toMatch(/^3\s*Belum discan/);
    await app.screenshot('ac17-step3-peringatan-beta');

    // Back -> Step 2 (scan tetap) -> Back -> Step 1 dimuat ulang (GET folders baru), pilihan tetap -> Next = POST preview lagi, scan tetap
    await footerBtn(page, /^Kembali$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    await expect(modalRows(page), 'Step 2 sesudah Back: scan tetap (1 baris)').toHaveCount(1);
    expect((await firstCells(page))[0], 'baris scan = T2D1').toBe(`${P}T2D1`);
    const foldersAgain = foldersResponse(page);
    await footerBtn(page, /^Kembali$/).click();
    expect((await foldersAgain).status(), 'Back ke Step 1: GET opnames/folders lagi (selected_at baru)').toBe(200);
    await step1Row(page, `${P}BETA-T1`).waitFor({ state: 'visible', timeout: 20000 });
    expect(await rowChecked(step1Row(page, `${P}BETA-T1`)), 'pilihan dipertahankan: T1 tercentang').toBe(true);
    await expect(modalRows(page).first().locator('.ant-switch'), 'pilihan dipertahankan: toggle BETA mati').not.toHaveClass(/ant-switch-checked/);
    expect(calls.folderSelectedAt.length, 'dua kali memuat Step 1').toBe(2);
    const prev2 = previewResponse(page);
    await footerBtn(page, /^Lanjut$/).click();
    expect((await prev2).status(), 'Next kedua: POST opnames/preview').toBe(200);
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    await expect.poll(() => modalRows(page).count(), { message: 'Step 2 sesudah Next: scan T2D1 tetap (dinilai ulang)', timeout: 15000 }).toBe(1);
    expect(persisting(calls), 'Back/Next: tanpa request tulis').toEqual([]);

    // X dengan scan: dialog konfirmasi; "Tidak" = tetap; "Ya" = tutup tanpa request
    await modalRoot(page).locator('.ant-modal-close').click();
    const dlg = page.locator('.ant-modal-confirm').last();
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    await expect(dlg, 'dialog batal').toContainText('Hasil scan sesi ini akan hilang. Batalkan opname ini?');
    await dlg.locator('.ant-btn:not(.ant-btn-primary)').click();
    await page.waitForTimeout(500);
    await expect(modalRoot(page), 'Tidak: modal tetap terbuka').toBeVisible();
    await closeModal(page, { via: 'x', dialog: true });
    expect(persisting(calls), 'Ya: tutup tanpa POST opnames / PUT / DELETE').toEqual([]);
    expectDbUnchanged(before, 'BETA');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- ALPHA: tag "+ N subfolder", duplikat, nilai ulang
  test('[default] ED-1026 AC-14/AC-11/FB-2 ALPHA: tag "+ N subfolder" (3/1/2/0), duplikat tanpa request, scan dinilai ulang saat pilihan berubah, tanpa request tulis', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    const folders = await openOpnameFromRow(page, `${P}ALPHA`);
    const fj = (await (await folders).json()).result;
    expect(fj.skip_select_folder, 'ALPHA punya subfolder: Step 1 tidak dilewati').toBe(false);
    expect(fj.children.map((c) => [c.name, c.document_count]), 'children: S1(2+1) S2(1) S3(1), tanpa sub-subfolder X').toEqual([
      [`${P}ALPHA-S1`, 3], [`${P}ALPHA-S2`, 1], [`${P}ALPHA-S3`, 1],
    ]);
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await expect(modalRows(page), 'Step 1: baris ALPHA + S1 + S2 + S3 (sub-subfolder X tidak bisa dipilih)').toHaveCount(4);
    await expect(modalRows(page).first().locator('.ant-switch'), 'ALPHA belum diopname: tanpa toggle Lanjut').toHaveCount(0);
    await expect(modalRows(page).first(), 'ALPHA: Belum diopname').toContainText('Belum diopname');
    for (const n of ['S1', 'S2', 'S3']) expect(await rowChecked(step1Row(page, `${P}ALPHA-${n}`)), `${n} belum diopname: tercentang`).toBe(true);
    await expect.poll(() => footerLeft(page), { message: 'footer awal: ALPHA 2 + 3 + 1 + 1', timeout: 10000 }).toBe('4 folder dipilih · 7 dokumen');
    expect(await modalTag(page), 'tag Step 1 = "<F>" tanpa akhiran').toBe(`${P}ALPHA`);

    // (a) 3 subfolder dicentang -> "<F> + 3 subfolder"
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'Step 2: tag "<F> + 3 subfolder"').toBe(`${P}ALPHA + 3 subfolder`);
    await expect.poll(() => footerLeft(page), { message: 'footer Step 2', timeout: 15000 }).toBe(`${P}ALPHA + 3 subfolder · 7 dokumen`);
    await app.screenshot('ac14-step2-tag-3-subfolder');

    // (b) Back, lepas S2 dan S3 -> "<F> + 1 subfolder"
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S2`).waitFor({ state: 'visible', timeout: 20000 });
    expect(await rowChecked(step1Row(page, `${P}ALPHA-S2`)), 'pilihan dipertahankan: S2 tercentang').toBe(true);
    await rowBox(page, `${P}ALPHA-S2`).click();
    await rowBox(page, `${P}ALPHA-S3`).click();
    await expect.poll(() => footerLeft(page), { message: 'footer sesudah S2,S3 dilepas', timeout: 10000 }).toBe('2 folder dipilih · 5 dokumen');
    await app.screenshot('ac7-step1-alpha-sebagian');
    const prevB = previewResponse(page);
    await footerBtn(page, /^Lanjut$/).click();
    expect((await prevB).status(), 'POST opnames/preview').toBe(200);
    const bodyB = callsOf(calls, 'POST', /^opnames\/preview$/).pop().body;
    expect((bodyB.folders || []).map((f) => pick(f, 'id_archive', 'idArchive')), 'preview folders = S1 saja').toEqual([ids()[`${P}ALPHA-S1`]]);
    const input = scanInput(page);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'Step 2: tag "<F> + 1 subfolder" (nama folder opname, bukan nama subfolder)').toBe(`${P}ALPHA + 1 subfolder`);
    await expect.poll(() => footerLeft(page), { message: 'footer Step 2', timeout: 15000 }).toBe(`${P}ALPHA + 1 subfolder · 5 dokumen`);

    // sub-subfolder X ikut otomatis (Verified); dokumen langsung folder opname ikut; S2 (tak dicentang) = Not found
    expect(scanRow(await scanTyped(page, `${P}S1XD1`)).result, 'S1XD1 (sub-subfolder, otomatis) = verified').toBe('verified');
    // kode yang sama diketik lagi < 2 dtk kemudian (frame kamera berulang): diabaikan, tanpa POST scan baru
    const scansBefore = callsOf(calls, 'POST', /^opnames\/scan$/).length;
    await input.fill(`${P}S1XD1`);
    await input.press('Enter');
    await page.waitForTimeout(900);
    expect(callsOf(calls, 'POST', /^opnames\/scan$/).length, 'kode sama < 2 dtk: tanpa POST scan baru').toBe(scansBefore);
    expect(await input.inputValue(), 'input dikosongkan').toBe('');
    expect(scanRow(await scanTyped(page, `${P}AD1`)).result, 'AD1 (langsung di ALPHA) = verified').toBe('verified');
    const nf = await scanTyped(page, `${P}S2D1`);
    expect(scanRow(nf).result, 'S2D1 (S2 tak dicentang) = not_found').toBe('not_found');
    expect(scanRow(nf).scanned_at, 'scanned_at = waktu server').toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    expect(await sessionCard(page), 'kartu: 3 discan, 2 verified, 1 not found').toEqual({ scanned: 3, verified: 2, notFound: 1, invalid: 0 });
    await expect(modalRows(page).locator('xpath=self::tr[contains(@class,"row-not-found")]'), 'S2D1 berlatar not-found').toHaveCount(1);
    // BR-14 (AC-11): kode sama dengan huruf kecil + spasi akhir, setelah jendela 2 dtk -> tanpa request, baris & angka tetap
    await page.waitForTimeout(2300);
    const scansBefore2 = callsOf(calls, 'POST', /^opnames\/scan$/).length;
    await input.fill(`${P}ad1  `.toLowerCase());
    await input.press('Enter');
    await page.waitForTimeout(900);
    expect(callsOf(calls, 'POST', /^opnames\/scan$/).length, 'duplikat (huruf kecil + spasi akhir): tanpa POST scan').toBe(scansBefore2);
    await expect(modalRows(page), 'duplikat: tetap 3 baris').toHaveCount(3);
    expect(await sessionCard(page), 'duplikat: angka tetap').toEqual({ scanned: 3, verified: 2, notFound: 1, invalid: 0 });
    await expect(toastText(page), 'duplikat: tanpa pesan').toHaveCount(0);

    // (c) Back, centang S2 lagi -> "<F> + 2 subfolder"; scan dinilai ulang lewat preview/documents result=scanned (urut lama -> baru)
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S2`).waitFor({ state: 'visible', timeout: 20000 });
    expect(await rowChecked(step1Row(page, `${P}ALPHA-S1`)), 'pilihan dipertahankan: S1 tercentang').toBe(true);
    expect(await rowChecked(step1Row(page, `${P}ALPHA-S2`)), 'pilihan dipertahankan: S2 tidak tercentang').toBe(false);
    await rowBox(page, `${P}ALPHA-S2`).click();
    await page.waitForTimeout(300);
    const prevC = previewResponse(page);
    const reclass = previewDocsResponse(page, (r) => /scanned/.test(r.request().postData() || ''));
    await footerBtn(page, /^Lanjut$/).click();
    expect((await prevC).status(), 'POST opnames/preview').toBe(200);
    expect((await reclass).status(), 'POST preview/documents result=scanned (klasifikasi ulang)').toBe(200);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'Step 2: tag "<F> + 2 subfolder"').toBe(`${P}ALPHA + 2 subfolder`);
    const reBody = callsOf(calls, 'POST', /^opnames\/preview\/documents$/).pop().body;
    expect(reBody.result, 'klasifikasi ulang: result=scanned').toBe('scanned');
    expect((reBody.scans || []).map((s) => s.code), 'scans urut lama -> baru').toEqual([`${P}S1XD1`, `${P}AD1`, `${P}S2D1`]);
    for (const s of reBody.scans) expect(pick(s, 'scanned_at', 'scannedAt'), 'scanned_at format Y-m-d H:i:s').toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    await expect.poll(() => modalRows(page).count(), { message: 'scan tetap (3 baris)', timeout: 15000 }).toBe(3);
    await expect
      .poll(async () => {
        const cells = Object.fromEntries((await Promise.all([0, 1, 2].map(async (i) => trimAll(await modalRows(page).nth(i).locator('td').allInnerTexts())))).map((r) => [r[0], r[4]]));
        return cells[`${P}S2D1`];
      }, { message: 'S2D1 dinilai ulang: Terverifikasi (S2 kini dalam cakupan)', timeout: 15000 })
      .toBe('Terverifikasi');
    expect(await sessionCard(page), 'kartu sesudah dinilai ulang: 3 discan, 3 verified').toEqual({ scanned: 3, verified: 3, notFound: 0, invalid: 0 });
    await expect(modalRows(page).locator('xpath=self::tr[contains(@class,"row-not-found")]'), 'tanpa baris not-found').toHaveCount(0);

    // (d) Back, lepas semua subfolder -> tag "<F>" tanpa akhiran; S1XD1 + S2D1 jadi Tidak ditemukan, AD1 tetap
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S2`).waitFor({ state: 'visible', timeout: 20000 });
    await rowBox(page, `${P}ALPHA-S1`).click();
    await rowBox(page, `${P}ALPHA-S2`).click();
    await page.waitForTimeout(300);
    await footerBtn(page, /^Lanjut$/).click();
    await input.waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'Step 2: tanpa subfolder dicentang = "<F>" tanpa akhiran').toBe(`${P}ALPHA`);
    await expect
      .poll(async () => {
        const out = {};
        const n = await modalRows(page).count();
        for (let i = 0; i < n; i += 1) {
          const r = trimAll(await modalRows(page).nth(i).locator('td').allInnerTexts());
          out[r[0]] = r[4];
        }
        return out;
      }, { message: 'dinilai ulang: hanya AD1 (langsung di ALPHA) yang tetap Terverifikasi', timeout: 15000 })
      .toEqual({ [`${P}S1XD1`]: 'Tidak ditemukan', [`${P}AD1`]: 'Terverifikasi', [`${P}S2D1`]: 'Tidak ditemukan' });

    // tutup dengan scan: dialog -> Ya; tidak ada request tulis dan DB tidak berubah
    await closeModal(page, { via: 'x', dialog: true });
    expect(persisting(calls), 'ALPHA: tidak ada POST opnames / PUT / DELETE sepanjang sesi').toEqual([]);
    expectDbUnchanged(before, 'ALPHA');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-8 ZULFA (folder fixture tanpa subfolder)
  test('[default] ED-1026 AC-8 ZULFA (folder tanpa subfolder, belum diopname): Step 1 dilewati, stepper 2 langkah, tag langsung, hanya GET folders + POST preview, Batal tanpa request', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    const folders = await openOpnameFromRow(page, `${P}ZULFA`);
    const prev = previewResponse(page);
    const fj = (await (await folders).json()).result;
    expect(fj.skip_select_folder, 'ZULFA: skip_select_folder true').toBe(true);
    expect(fj.children, 'ZULFA tanpa subfolder').toEqual([]);
    expect(fj.folder.opnamed_today, 'ZULFA belum diopname hari ini').toBeNull();

    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'stepper 2 langkah').toEqual(['Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'langkah aktif = Opname Folder').toBe('Opname Folder');
    expect(await modalTag(page), 'tag = ZULFA (langsung)').toBe(`${P}ZULFA`);
    await expect(footerBtn(page, /^Batal$/), 'tombol kiri = Batal (bukan Kembali)').toBeVisible();
    await expect(footerBtn(page, /^Kembali$/), 'tanpa Kembali').toHaveCount(0);
    expect((await prev).status(), 'POST opnames/preview').toBe(200);
    const previewCall = callsOf(calls, 'POST', /^opnames\/preview$/)[0];
    monitor.note(`body POST preview ZULFA: ${JSON.stringify(previewCall.body)}`);
    expect(pick(previewCall.body, 'id_archive', 'idArchive'), 'preview id_archive = ZULFA').toBe(ids()[`${P}ZULFA`]);
    expect(previewCall.body.folders, 'preview folders: []').toEqual([]);
    expect(opnameCalls(calls).map((c) => `${c.method} ${c.path}`), 'dibuka: hanya GET opnames/folders + POST opnames/preview (tanpa request tulis)').toEqual(['GET opnames/folders', 'POST opnames/preview']);
    await expect.poll(() => footerLeft(page), { message: 'footer: ZULFA . N dokumen', timeout: 15000 }).toMatch(new RegExp(`^${P}ZULFA · [\\d.,]+ dokumen$`));
    await app.screenshot('ac8-zulfa-step2-tanpa-step1');

    // Batal tanpa scan: langsung tertutup, tanpa dialog, tanpa request tambahan; scanner pencarian menyala lagi
    await footerBtn(page, /^Batal$/).click();
    await modalClosed(page);
    await expect(page.locator('.ant-modal-confirm'), 'Batal tanpa scan: tanpa dialog').toHaveCount(0);
    expect(opnameCalls(calls).length, 'Batal: tidak ada request baru').toBe(2);
    await expectSearchReactivated(page, calls, monitor);
    expectDbUnchanged(before, 'ZULFA');

    // dengan scan: dialog; Tidak = tetap, Ya = tutup tanpa request
    await openArchive(app, page);
    await openOpnameFromRow(page, `${P}ZULFA`);
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(scanRow(await scanTyped(page, `${P}ZD1`)).result, 'ZD1 = verified').toBe('verified');
    await footerBtn(page, /^Batal$/).click();
    const dlg = page.locator('.ant-modal-confirm').last();
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    await dlg.locator('.ant-btn:not(.ant-btn-primary)').click();
    await page.waitForTimeout(400);
    await expect(modalRoot(page), 'Tidak: modal tetap').toBeVisible();
    await closeModal(page, { via: 'batal', dialog: true });
    expect(persisting(calls), 'ZULFA: tanpa POST opnames / PUT / DELETE').toEqual([]);
    expectDbUnchanged(before, 'ZULFA (dengan scan)');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- GAMMA: scan + CheckableTag + Step 3 + Confirm (satu POST)
  test('[default] ED-1026 AC-13/14/17 GAMMA: scan (ketik, USB di luar input, kamera), CheckableTag Step 2 & 3, tanpa request tulis sampai Konfirmasi, satu POST opnames', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    await openOpnameFromRow(page, `${P}GAMMA`);
    const input = scanInput(page);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'GAMMA tanpa subfolder: stepper 2 langkah').toEqual(['Opname Folder', 'Konfirmasi']);
    expect(await modalTag(page), 'tag = GAMMA (langsung)').toBe(`${P}GAMMA`);
    await page.waitForTimeout(600);
    await expect(input, 'input scan autofocus').toBeFocused();
    await expect
      .poll(async () => trimAll([await modalRoot(page).locator('.ant-card').filter({ hasText: /Status verifikasi saat ini/ }).first().innerText()])[0], { message: 'status verifikasi awal 0 / 3', timeout: 15000 })
      .toMatch(/0\s*\/\s*3/);
    expect(await sessionCard(page), 'kartu sesi awal 0').toEqual({ scanned: 0, verified: 0, notFound: 0, invalid: 0 });
    const f0 = await filterState(page);
    monitor.note(`CheckableTag Step 2 awal: ${JSON.stringify(f0)}`);
    expect(f0.order, 'Step 2: tag filter Semua / Terverifikasi / Tidak ditemukan / Tidak valid').toEqual(['Semua', 'Terverifikasi', 'Tidak ditemukan', 'Tidak valid']);
    expect(f0.active, 'tepat satu tag aktif: Semua').toEqual(['Semua']);
    await expect(modalRoot(page).locator('.ant-segmented'), 'tanpa Segmented lagi').toHaveCount(0);
    await app.screenshot('ac14-step2-awal');

    // 1) ketik + Enter di input: Verified, tanpa toast, baris baru paling atas
    const s1 = await scanTyped(page, `${P}GD1`);
    expect(s1.status, 'scan GD1').toBe(200);
    expect(s1.json.msg_code, 'ARCHIVE212').toBe('ARCHIVE212');
    expect(scanRow(s1).result, 'GD1 verified').toBe('verified');
    await expect(toastText(page), 'scan: tanpa toast sukses').toHaveCount(0);
    expect(await input.inputValue(), 'input dikosongkan sesudah Enter').toBe('');
    expect((await firstCells(page))[0], 'baris GD1 paling atas').toBe(`${P}GD1`);
    expect(await sessionCard(page), 'kartu sesi: 1 discan, 1 verified').toEqual({ scanned: 1, verified: 1, notFound: 0, invalid: 0 });
    expect(persisting(calls), 'sesudah scan: belum ada request tulis').toEqual([]);

    // 2) Not found: dokumen ada di folder lain; 3) Invalid: kode tak dikenal
    await page.waitForTimeout(2200); // lewati jendela "frame berulang" 2 dtk
    const s2 = await scanTyped(page, `${P}AD1`);
    expect(scanRow(s2).result, 'AD1 (folder lain) = not_found').toBe('not_found');
    expect(scanRow(s2).is_out_of_scope, 'is_out_of_scope').toBe(true);
    const s3 = await scanTyped(page, 'QA26-TIDAK-ADA-123');
    expect(scanRow(s3).result, 'kode tak dikenal = invalid').toBe('invalid');
    expect((await firstCells(page)).slice(0, 3), 'urutan terbaru di atas').toEqual(['QA26-TIDAK-ADA-123', `${P}AD1`, `${P}GD1`]);
    expect(await resultCells(page), 'kolom Hasil').toEqual(['Tidak valid', 'Tidak ditemukan', 'Terverifikasi']);
    await expect(modalRoot(page).locator('tr.row-invalid'), 'baris Invalid berkelas row-invalid').toHaveCount(1);
    await expect(modalRoot(page).locator('tr.row-not-found'), 'baris Not found berkelas row-not-found').toHaveCount(1);
    await expect(modalRoot(page).locator('tr.row-invalid td:first-child'), 'row-invalid = kode tak dikenal').toHaveText('QA26-TIDAK-ADA-123');
    await expect(modalRoot(page).locator('tr.row-not-found td:first-child'), 'row-not-found = AD1').toHaveText(`${P}AD1`);
    const gd1Cells = trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row').nth(2).locator('td').allInnerTexts());
    expect(gd1Cells[1], 'GD1: tipe transaksi terisi').not.toBe('—');
    expect(gd1Cells[2], 'GD1: salesman').toBe('Sales QA26');
    expect(gd1Cells[3], 'GD1: waktu HH:mm').toMatch(/^\d{2}:\d{2}$/);
    const invCells = trimAll(await modalRoot(page).locator('.ant-table-tbody tr.row-invalid td').allInnerTexts());
    expect(invCells.slice(1, 3), 'Invalid: tipe transaksi dan salesman kosong "—"').toEqual(['—', '—']);
    expect(invCells[3], 'Invalid: waktu scan tetap tampil HH:mm').toMatch(/^\d{2}:\d{2}$/);

    // 4) duplikat (huruf kecil): tanpa request, tanpa baris, tanpa angka baru
    await page.waitForTimeout(2200);
    const scanReqBefore = callsOf(calls, 'POST', /^opnames\/scan$/).length;
    await input.fill(`${P}GD1`.toLowerCase());
    await input.press('Enter');
    await page.waitForTimeout(900);
    expect(callsOf(calls, 'POST', /^opnames\/scan$/).length, 'duplikat: tanpa POST scan').toBe(scanReqBefore);
    await expect(modalRows(page), 'duplikat: tetap 3 baris').toHaveCount(3);
    expect(await sessionCard(page), 'duplikat: angka tetap').toEqual({ scanned: 3, verified: 1, notFound: 1, invalid: 1 });
    await expect(toastText(page), 'duplikat: tanpa pesan').toHaveCount(0);

    // 5) scanner USB dengan fokus DI LUAR input (klik judul modal), ketik cepat + Enter
    await modalTitle(page).click();
    await expect(input, 'fokus keluar dari input').not.toBeFocused();
    const archiveListBefore = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
    const usb = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/scan(\?|$)/.test(r.url()), { timeout: 20000 });
    await page.keyboard.type(`${P}GD2`, { delay: 0 });
    await page.keyboard.press('Enter');
    const ur = await usb;
    expect(ur.status(), 'USB di luar input: POST scan').toBe(200);
    expect((await ur.json()).result.row.result, 'GD2 verified').toBe('verified');
    await page.waitForTimeout(500);
    expect((await firstCells(page))[0], 'USB: baris GD2 paling atas').toBe(`${P}GD2`);
    expect(await sessionCard(page), 'USB: 4 discan, 2 verified').toEqual({ scanned: 4, verified: 2, notFound: 1, invalid: 1 });
    expect(calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length, 'pencarian Archive tidak terpicu oleh scan').toBe(archiveListBefore);

    // 6) CheckableTag Step 2: jumlah = kartu; tepat satu aktif; klik menyaring tabel; klik tag aktif tidak mengosongkan; fokus kembali ke input
    const f1 = await filterState(page);
    monitor.note(`CheckableTag Step 2: ${JSON.stringify(f1)}`);
    expect(f1.counts, 'tag = angka kartu sesi').toEqual({ Semua: 4, Terverifikasi: 2, 'Tidak ditemukan': 1, 'Tidak valid': 1 });
    expect(f1.active).toEqual(['Semua']);
    await tagClick(page, /^Terverifikasi/);
    await page.waitForTimeout(300);
    expect((await filterState(page)).active, 'klik Terverifikasi: hanya itu aktif').toEqual(['Terverifikasi']);
    expect(await resultCells(page), 'filter Terverifikasi').toEqual(['Terverifikasi', 'Terverifikasi']);
    await expect(input, 'sesudah memilih filter fokus kembali ke input').toBeFocused();
    await tagClick(page, /^Terverifikasi/);
    await page.waitForTimeout(300);
    expect((await filterState(page)).active, 'klik tag aktif: tetap aktif, tidak kosong').toEqual(['Terverifikasi']);
    expect(await resultCells(page), 'klik tag aktif: tabel tidak berubah').toEqual(['Terverifikasi', 'Terverifikasi']);
    await tagClick(page, /^Tidak ditemukan/);
    await page.waitForTimeout(300);
    expect(await resultCells(page), 'filter Tidak ditemukan').toEqual(['Tidak ditemukan']);
    await tagClick(page, /^Tidak valid/);
    await page.waitForTimeout(300);
    expect(await resultCells(page), 'filter Tidak valid').toEqual(['Tidak valid']);
    await tagClick(page, /^Semua/);
    await page.waitForTimeout(300);
    await expect(modalRows(page), 'filter Semua: 4 baris').toHaveCount(4);
    expect((await filterState(page)).active).toEqual(['Semua']);

    // scanner USB sesudah memilih filter tanpa klik input (fokus dikembalikan)
    await page.waitForTimeout(2200);
    const usb2 = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/scan(\?|$)/.test(r.url()), { timeout: 20000 });
    await page.keyboard.type(`${P}GD3`, { delay: 0 });
    await page.keyboard.press('Enter');
    expect((await usb2).status(), 'scan sesudah filter tanpa klik input').toBe(200);
    await page.waitForTimeout(400);
    expect(await sessionCard(page), '5 discan, 3 verified').toEqual({ scanned: 5, verified: 3, notFound: 1, invalid: 1 });

    // 7) modal kamera (kamera nyata tidak ada di runner: hanya buka/tutup dan hasil scan terakhir)
    await modalRoot(page).locator('.anticon-scan').click();
    const camera = page.locator('.ant-modal-wrap:visible').last();
    await page.waitForTimeout(1200);
    await app.screenshot('ac4-modal-kamera');
    const cameraText = trimAll([await page.locator('.ant-modal:visible').last().innerText()])[0];
    monitor.note(`modal kamera: ${cameraText}`);
    expect(cameraText, 'modal kamera: Scan terakhir + kode + hasil').toContain('Scan terakhir');
    expect(cameraText, 'modal kamera menampilkan scan terakhir (GD3)').toContain(`${P}GD3`);
    expect(cameraText, 'modal kamera menampilkan hasil scan terakhir').toContain('Terverifikasi');
    // kode yang SUDAH discan (GD1) lewat modal kamera: tanpa request, "Scan terakhir" menampilkan baris lamanya (BR-14)
    await page.waitForTimeout(2200);
    const camReqBefore = callsOf(calls, 'POST', /^opnames\/scan$/).length;
    await page.keyboard.type(`${P}GD1`, { delay: 0 });
    await page.keyboard.press('Enter');
    await page.waitForTimeout(900);
    expect(callsOf(calls, 'POST', /^opnames\/scan$/).length, 'kamera: kode yang sudah discan tanpa request').toBe(camReqBefore);
    const cameraTextDup = trimAll([await page.locator('.ant-modal:visible').last().innerText()])[0];
    expect(cameraTextDup, 'kamera: Scan terakhir = baris lama GD1 (kode + hasil)').toContain(`${P}GD1`);
    // scan baru lewat modal kamera (scanner USB aktif di modal itu): modal TETAP terbuka, "Scan terakhir" berganti, pencarian Archive tidak terpicu
    const camListBefore = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
    const camScan = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/scan(\?|$)/.test(r.url()), { timeout: 20000 });
    await page.keyboard.type('QA26-KAMERA-1', { delay: 0 });
    await page.keyboard.press('Enter');
    expect((await camScan).status(), 'scan dari modal kamera: POST scan').toBe(200);
    await page.waitForTimeout(600);
    await expect(camera.locator('.ant-modal-close'), 'modal kamera tetap terbuka sesudah scan').toBeVisible();
    const cameraText2 = trimAll([await page.locator('.ant-modal:visible').last().innerText()])[0];
    expect(cameraText2, 'Scan terakhir berganti ke kode baru').toContain('QA26-KAMERA-1');
    expect(cameraText2, 'hasil scan terakhir = Tidak valid').toContain('Tidak valid');
    expect(calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length, 'modal kamera: pencarian Archive tidak terpicu').toBe(camListBefore);
    await app.screenshot('ac13-modal-kamera-sesudah-scan');
    await camera.locator('.ant-modal-close').click();
    await page.waitForTimeout(500);
    await expect(input, 'tutup kamera: fokus kembali ke input').toBeFocused();
    expect(await sessionCard(page), 'sesudah scan kamera: 6 discan, 2 invalid').toEqual({ scanned: 6, verified: 3, notFound: 1, invalid: 2 });
    // sampai di sini (6 scan, filter, kamera): TIDAK ADA request tulis, DB tidak berubah
    expect(persisting(calls), 'sebelum Konfirmasi: tidak ada POST opnames / PUT / DELETE').toEqual([]);
    expectDbUnchanged(before, 'GAMMA sebelum Konfirmasi');

    // 8) Finish -> Step 3: POST preview + preview/documents result=scanned, scans urut lama -> baru
    const prev3 = previewResponse(page);
    const docs1 = previewDocsResponse(page);
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    expect((await prev3).status(), 'POST opnames/preview (Step 3)').toBe(200);
    const d1 = await docs1;
    expect(d1.status(), 'POST preview/documents result=scanned').toBe(200);
    expect(await activeStep(page), 'langkah aktif = Konfirmasi').toBe('Konfirmasi');
    const docsBody = callsOf(calls, 'POST', /^opnames\/preview\/documents$/).pop().body;
    monitor.note(`body preview/documents Step 3: result=${docsBody.result} page=${docsBody.page} scans=${(docsBody.scans || []).length}`);
    expect(docsBody.result, 'Step 3: result awal = scanned').toBe('scanned');
    expect(docsBody.page, 'Step 3: page=1').toBe(1);
    expect((docsBody.scans || []).map((s) => s.code), 'scans urut lama -> baru').toEqual([`${P}GD1`, `${P}AD1`, 'QA26-TIDAK-ADA-123', `${P}GD2`, `${P}GD3`, 'QA26-KAMERA-1']);
    await page.waitForTimeout(800);
    const f3 = await filterState(page);
    monitor.note(`CheckableTag Step 3: ${JSON.stringify(f3)}`);
    expect(f3.order, 'Step 3: Semua / Discan / Terverifikasi / Tidak ditemukan / Tidak valid').toEqual(['Semua', 'Discan', 'Terverifikasi', 'Tidak ditemukan', 'Tidak valid']);
    expect(f3.active, 'filter awal = Discan (6)').toEqual(['Discan']);
    expect(f3.counts, 'jumlah tag Step 3 (All = total 3 + not found 1 + invalid 2)').toEqual({ Semua: 6, Discan: 6, Terverifikasi: 3, 'Tidak ditemukan': 1, 'Tidak valid': 2 });
    await expect(modalRoot(page).locator('.ant-segmented'), 'Step 3 tanpa Segmented').toHaveCount(0);
    const cards3 = trimAll(await modalRoot(page).locator('.ant-card').allInnerTexts());
    monitor.note(`kartu Step 3: ${cards3.join(' | ')}`);
    expect(cards3[0], 'Total dokumen = 3').toMatch(/^3\s*Total dokumen/);
    expect(cards3[1], 'Terverifikasi sesi ini = 3').toMatch(/^3\s*Terverifikasi sesi ini/);
    expect(cards3[2], 'Tidak ditemukan = 1').toMatch(/^1\s*Tidak ditemukan/);
    expect(cards3[3], 'Tidak valid = 2').toMatch(/^2\s*Tidak valid/);
    expect(cards3[4], 'Belum discan = 0 (Total - Verified sesi ini)').toMatch(/^0\s*Belum discan/);
    expect(await modalRoot(page).locator('.ant-alert-warning').count(), 'tanpa peringatan (tak ada verified yang turun)').toBe(0);
    expect(trimAll(await modalRoot(page).locator('.ant-table-thead th').allInnerTexts()).filter(Boolean), 'kolom Step 3').toEqual(['No Dokumen', 'Tipe Transaksi', 'Folder', 'Waktu', 'Hasil']);
    const rows3 = [];
    for (let i = 0; i < (await modalRows(page).count()); i += 1) rows3.push(trimAll(await modalRows(page).nth(i).locator('td').allInnerTexts()));
    monitor.note(`tabel Step 3 (Discan): ${JSON.stringify(rows3)}`);
    expect(rows3.find((r) => r[0] === `${P}AD1`)[2], 'Not found: Di luar scope opname').toBe('Di luar scope opname');
    expect(rows3.find((r) => r[0] === 'QA26-TIDAK-ADA-123')[2], 'Invalid: —').toBe('—');
    await app.screenshot('ac17-step3-discan');

    // klik tag Step 3 -> POST preview/documents baru, result snake_case, halaman 1; klik tag aktif -> tanpa request
    const invReq = previewDocsResponse(page, (r) => /"result":"invalid"/.test(r.request().postData() || ''));
    await tagClick(page, /^Tidak valid/);
    expect((await invReq).status(), 'POST preview/documents result=invalid').toBe(200);
    await page.waitForTimeout(400);
    const invBody = callsOf(calls, 'POST', /^opnames\/preview\/documents$/).pop().body;
    expect([invBody.result, invBody.page], 'body: result=invalid page=1').toEqual(['invalid', 1]);
    expect((await filterState(page)).active, 'hanya Tidak valid aktif').toEqual(['Tidak valid']);
    expect(await firstCells(page), 'filter Tidak valid: 2 baris invalid').toEqual(['QA26-KAMERA-1', 'QA26-TIDAK-ADA-123']);
    const nDocsCalls = callsOf(calls, 'POST', /^opnames\/preview\/documents$/).length;
    await tagClick(page, /^Tidak valid/);
    await page.waitForTimeout(700);
    expect(callsOf(calls, 'POST', /^opnames\/preview\/documents$/).length, 'klik tag aktif: tanpa request baru').toBe(nDocsCalls);
    const nfReq = previewDocsResponse(page, (r) => /"result":"not_found"/.test(r.request().postData() || ''));
    await tagClick(page, /^Tidak ditemukan/);
    expect((await nfReq).status(), 'POST preview/documents result=not_found').toBe(200);
    await page.waitForTimeout(400);
    expect(await firstCells(page), 'filter Tidak ditemukan: AD1').toEqual([`${P}AD1`]);
    const allReq = previewDocsResponse(page, (r) => /"result":"all"/.test(r.request().postData() || ''));
    await tagClick(page, /^Semua/);
    expect((await allReq).status(), 'POST preview/documents result=all').toBe(200);
    await page.waitForTimeout(400);
    await expect(modalRows(page), 'Semua: 3 dokumen cakupan + not found 1 + invalid 2 = 6 baris').toHaveCount(6);
    await app.screenshot('ac17-step3-semua');

    // Back -> Step 2: scan sama (dinilai ulang); Finish -> Step 3 lagi
    await footerBtn(page, /^Kembali$/).click();
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await expect.poll(() => modalRows(page).count(), { message: 'Back: scan tetap (6 baris)', timeout: 15000 }).toBe(6);
    expect(await sessionCard(page), 'Back: kartu sesi tetap').toEqual({ scanned: 6, verified: 3, notFound: 1, invalid: 2 });
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(800);
    expect(persisting(calls), 'Back/Finish: masih tanpa request tulis').toEqual([]);

    // Confirm Opname = Popconfirm -> SATU POST opnames (scans + selectedAt); toast sukses; modal tertutup TANPA dialog; list dimuat ulang
    const listBefore = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    await expect(pop, 'Popconfirm').toContainText('Konfirmasi opname ini? Status verifikasi dokumen dalam scope akan diperbarui.');
    await app.screenshot('ac17-step3-popconfirm');
    const conf = confirmResponse(page);
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'POST opnames (Konfirmasi)').toBe(200);
    const crj = await cr.json();
    expect(crj.msg_code, 'ARCHIVE213').toBe('ARCHIVE213');
    await expect(toastText(page).first(), 'toast sukses').toContainText('Opname berhasil dikonfirmasi', { timeout: 5000 });
    await modalClosed(page);
    await expect(page.locator('.ant-modal-confirm'), 'Confirm sukses: tanpa dialog "Hasil scan akan hilang"').toHaveCount(0);
    await page.waitForTimeout(1500);
    const writes = persisting(calls);
    expect(writes.map((c) => `${c.method} ${c.path}`), 'satu-satunya request tulis seumur sesi: tepat satu POST opnames').toEqual(['POST opnames']);
    const cbody = writes[0].body;
    monitor.note(`body Konfirmasi: folders=${JSON.stringify(cbody.folders)} scans=${(cbody.scans || []).length} selectedAt=${pick(cbody, 'selected_at', 'selectedAt')}`);
    expect(pick(cbody, 'id_archive', 'idArchive'), 'Konfirmasi: id_archive = GAMMA').toBe(ids()[`${P}GAMMA`]);
    expect((cbody.scans || []).map((s) => s.code), 'Konfirmasi: scans urut lama -> baru').toEqual([`${P}GD1`, `${P}AD1`, 'QA26-TIDAK-ADA-123', `${P}GD2`, `${P}GD3`, 'QA26-KAMERA-1']);
    expect(pick(cbody, 'selected_at', 'selectedAt'), 'Konfirmasi: selectedAt = selected_at GET opnames/folders terakhir').toBe(calls.folderSelectedAt[calls.folderSelectedAt.length - 1]);
    expect(calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length, 'list Archive dimuat ulang').toBeGreaterThan(listBefore);
    await expectSearchReactivated(page, calls, monitor);
    await openArchive(app, page); // buang hasil pencarian

    // DB: tepat satu sesi baru (sesi GAMMA), dokumen verified; AC-31: scope sesi tersimpan = tepat tiga kunci
    const db = inspectDb();
    expect(db.tables.archive_opnames, 'DB: tepat satu sesi baru').toBe(before.tables.archive_opnames + 1);
    expect(['GD1', 'GD2', 'GD3'].map((d) => db.docs[`${P}${d}`].is_verified), 'DB: GD1-3 verified').toEqual([1, 1, 1]);
    expect(db.docs[`${P}AD1`].is_verified, 'DB: AD1 (Not found) tidak berubah').toBe(0);
    const gs = db.sessions.filter((s) => s.folder === `${P}GAMMA`);
    expect(gs.length, 'DB: satu sesi GAMMA').toBe(1);
    expect(gs[0], 'DB: sesi GAMMA terkonfirmasi dengan angka final').toMatchObject({ status: 2, total: 3, scanned: 6, verified: 3, not_found: 1, invalid: 2, unscanned: 0, unverified: 0, confirmed: true });
    const sess = await apiCall(page, 'GET', `document-archive/opnames/${crj.result.id_archive_opname}`);
    expect(sess.status, 'GET opnames/{id}').toBe(200);
    expect(Object.keys(sess.json.result.scope).sort(), 'AC-31: scope = tepat is_root, folder_name, subfolder_count').toEqual(['folder_name', 'is_root', 'subfolder_count']);
    expect(sess.json.result.scope, 'scope sesi GAMMA').toEqual({ is_root: false, folder_name: `${P}GAMMA`, subfolder_count: 0 });

    // buka lagi dari menu GAMMA: sudah diopname hari ini -> Step 1 tidak dilewati, GAMMA dicentang ulang + Lanjut opname
    const folders2 = await openOpnameFromRow(page, `${P}GAMMA`);
    const fj2 = (await (await folders2).json()).result;
    expect(fj2.skip_select_folder, 'GAMMA sudah diopname hari ini: Step 1 tidak dilewati').toBe(false);
    expect(fj2.folder.opnamed_today, 'folder.opnamed_today terisi').toBeTruthy();
    await modalRows(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await expect(modalRows(page).first(), 'GAMMA: Sudah diopname hari ini').toContainText('Sudah diopname hari ini');
    await expect(modalRows(page).first(), 'GAMMA: dicentang ulang').toContainText('(dicentang ulang)');
    await expect(modalRows(page).first().locator('.ant-switch'), 'GAMMA: Lanjut opname aktif').toHaveClass(/ant-switch-checked/);
    await app.screenshot('ac7-step1-gamma-sesudah-konfirmasi');
    await footerBtn(page, /^Batal$/).click();
    await modalClosed(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-22 bentrok
  test('[default] ED-1026 AC-22 DELTA: bentrok ARCHIVE414 (sesi lain) -> kembali ke Step 1, scan tetap, Next memuat ulang, Konfirmasi kedua berhasil; kirim ulang body = 414', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    // sesi B (UI): DELTA, scan DD1
    await openOpnameFromRow(page, `${P}DELTA`);
    const input = scanInput(page);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    const sc = await scanTyped(page, `${P}DD1`);
    expect(scanRow(sc).result, 'DD1 verified').toBe('verified');
    expect(calls.folderSelectedAt.length, 'B memuat Step 1 satu kali').toBe(1);
    const selectedB = calls.folderSelectedAt[0];

    // sesi A (API, user yang sama) mengonfirmasi DELTA SESUDAH B memuat Step 1 (selected_at A = selected_at B: sah, hari ini, <= sekarang)
    await page.waitForTimeout(1300);
    const mk = await apiCall(page, 'POST', 'document-archive/opnames', { id_archive: ids()[`${P}DELTA`], folders: [], scans: [], selected_at: selectedB });
    expect(mk.status, 'sesi A: POST opnames (Konfirmasi)').toBe(200);
    expect(mk.json.msg_code, 'sesi A: ARCHIVE213').toBe('ARCHIVE213');

    // sesi B: Finish -> Step 3 -> Konfirmasi -> 400 ARCHIVE414
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(1000);
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    const conf = confirmResponse(page);
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'sesi B: Konfirmasi ditolak 400').toBe(400);
    const cj = await cr.json();
    expect(cj.msg_code || cj.code, 'sesi B: ARCHIVE414').toBe('ARCHIVE414');
    const firstBody = callsOf(calls, 'POST', /^opnames$/).pop().body;
    expect(pick(firstBody, 'selected_at', 'selectedAt'), 'Konfirmasi B memakai selected_at saat B memuat Step 1').toBe(selectedB);
    // username QA tidak boleh masuk catatan/galeri: disamarkan di catatan dan di DOM sebelum screenshot
    const uname = ids()._user.username;
    const mask = (t) => t.split(uname).join('<user QA>');
    const toast = mask(trimAll(await toastText(page).allInnerTexts()).join(' | '));
    monitor.note(`toast ARCHIVE414: ${toast}`);
    expect(toast, 'toast memuat pesan bentrok BE').toMatch(/Folder .*0QA26-DELTA.* sudah diopname oleh .* pukul \d{2}:\d{2} selama sesi ini berjalan/);
    await page.evaluate((u) => {
      document.querySelectorAll('.ant-message-notice, .ant-notification-notice').forEach((el) => {
        el.innerHTML = el.innerHTML.split(u).join('&lt;user QA&gt;');
      });
    }, uname);
    await app.screenshot('ac22-bentrok-toast');

    // kembali ke Step 1 (stepper 3 langkah), DELTA "Sudah diopname hari ini", dicentang ulang + Lanjut; GET folders dimuat ulang (selected_at baru)
    await modalRows(page).first().waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'kembali ke Step 1: stepper 3 langkah').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'Step 1 aktif').toBe('Pilih Folder');
    await expect(modalRows(page).first(), 'DELTA: Sudah diopname hari ini').toContainText('Sudah diopname hari ini');
    await expect(modalRows(page).first(), 'DELTA: dicentang ulang').toContainText('(dicentang ulang)');
    await expect(modalRows(page).first().locator('.ant-switch'), 'DELTA: Lanjut opname aktif').toHaveClass(/ant-switch-checked/);
    await expect.poll(() => calls.folderSelectedAt.length, { message: 'Step 1 dimuat ulang sesudah 414', timeout: 10000 }).toBe(2);
    const selectedB2 = calls.folderSelectedAt[1];
    await app.screenshot('ac22-kembali-step1');

    // Next = POST preview (bukan Konfirmasi), scan B masih ada
    const postsBefore = callsOf(calls, 'POST', /^opnames$/).length;
    const prev = previewResponse(page);
    await footerBtn(page, /^Lanjut$/).click();
    expect((await prev).status(), 'Next = POST opnames/preview').toBe(200);
    expect(callsOf(calls, 'POST', /^opnames$/).length, 'Next bukan Konfirmasi baru').toBe(postsBefore);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await expect.poll(() => modalRows(page).count(), { message: 'scan B masih ada', timeout: 15000 }).toBe(1);
    expect((await firstCells(page))[0], 'baris scan B = DD1').toBe(`${P}DD1`);

    // konfirmasi lagi -> berhasil, dengan selected_at yang BARU
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(1000);
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop2 = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop2.waitFor({ state: 'visible', timeout: 10000 });
    const conf2 = confirmResponse(page);
    await pop2.locator('.ant-btn-primary').click();
    const cr2 = await conf2;
    expect(cr2.status(), 'sesi B: Konfirmasi kedua berhasil').toBe(200);
    await expect(toastText(page).first(), 'toast sukses').toContainText('Opname berhasil dikonfirmasi', { timeout: 5000 });
    await modalClosed(page);
    const secondBody = callsOf(calls, 'POST', /^opnames$/).pop().body;
    expect(pick(secondBody, 'selected_at', 'selectedAt'), 'Konfirmasi kedua memakai selected_at baru (hasil muat ulang Step 1)').toBe(selectedB2);
    expect(selectedB2 >= selectedB, 'selected_at baru >= yang lama').toBe(true);

    const db = inspectDb();
    const ds = db.sessions.filter((s) => s.folder === `${P}DELTA`);
    expect(ds.map((s) => s.status), 'DB: dua sesi terkonfirmasi (A lalu B)').toEqual([2, 2]);
    expect(db.docs[`${P}DD1`].is_verified, 'DB: DD1 verified').toBe(1);

    // kirim ulang body Konfirmasi yang sama (Replay XHR) -> 400 ARCHIVE414, tetap dua sesi (tanpa sesi ganda)
    const replay = await apiCall(page, 'POST', 'document-archive/opnames', secondBody);
    expect(replay.status, 'kirim ulang body yang sama: 400').toBe(400);
    expect(replay.json.msg_code || replay.json.code, 'kirim ulang: ARCHIVE414').toBe('ARCHIVE414');
    expect(inspectDb().sessions.filter((s) => s.folder === `${P}DELTA`).length, 'DB: tetap dua sesi sesudah kirim ulang').toBe(2);

    expectRejected(monitor, 'ARCHIVE414', { status: 400, urlRe: /\/document-archive\/opnames(\?|$)/, minCount: 2 });
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- EPS: paginasi Step 3 + ARCHIVE415
  test('[default] ED-1026 AC-17 EPS: Step 3 paginasi server (page=2, ganti tag = halaman 1), ARCHIVE415 (selectedAt hari lain) = toast, tetap di Step 3', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    await openOpnameFromRow(page, `${P}EPS`);
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    const docs0 = previewDocsResponse(page);
    await footerBtn(page, /^Selesai Opname$/).click(); // Finish tanpa scan boleh (K-11 viii)
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    await docs0;
    await page.waitForTimeout(600);
    await expect(modalRows(page), 'Discan (tanpa scan): tabel kosong').toHaveCount(0);

    const all1 = previewDocsResponse(page, (r) => /"result":"all"/.test(r.request().postData() || ''));
    await tagClick(page, /^Semua/);
    const a1 = await all1;
    const a1j = (await a1.json()).result;
    expect(a1j.total, 'Semua: 12 dokumen cakupan').toBe(12);
    await page.waitForTimeout(500);
    const perPage = a1j.per_page;
    await expect(modalRows(page), `halaman 1: ${perPage} baris`).toHaveCount(perPage);
    const unscanned = trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row').first().locator('td').allInnerTexts());
    monitor.note(`baris belum discan: ${unscanned.join(' | ')}`);
    expect(unscanned[2], 'Belum discan: folder = EPS').toBe(`${P}EPS`);
    expect(unscanned[3], 'Belum discan: waktu "—"').toBe('—');
    expect(unscanned[4], 'Belum discan: hasil').toBe('Belum discan');
    const next = previewDocsResponse(page, (r) => /"page":2/.test(r.request().postData() || ''));
    await modalRoot(page).locator('.ant-pagination-item-2').click();
    expect((await next).status(), 'POST preview/documents page=2').toBe(200);
    await page.waitForTimeout(500);
    await expect(modalRows(page), 'halaman 2: sisa baris').toHaveCount(12 - perPage);
    await app.screenshot('ac17-step3-paginasi-hal2');
    // ganti tag dari halaman 2 -> request baru halaman 1
    const back1 = previewDocsResponse(page, (r) => /"result":"verified"/.test(r.request().postData() || ''));
    await tagClick(page, /^Terverifikasi/);
    await back1;
    const lastBody = callsOf(calls, 'POST', /^opnames\/preview\/documents$/).pop().body;
    expect([lastBody.result, lastBody.page], 'ganti tag dari halaman 2: result=verified page=1').toEqual(['verified', 1]);
    await tagClick(page, /^Semua/);
    await page.waitForTimeout(600);

    // ARCHIVE415: selectedAt "hari lain" (permintaan Konfirmasi diubah di jaringan jadi kemarin) -> 400, toast, TETAP di Step 3
    const yesterday = new Date(Date.now() - 86400000);
    await page.route(/\/document-archive\/opnames(\?.*)?$/, async (route) => {
      const req = route.request();
      if (req.method() !== 'POST') return route.continue();
      const b = JSON.parse(req.postData() || '{}');
      for (const k of ['selectedAt', 'selected_at']) if (k in b) b[k] = `${dateStr(yesterday)} 10:00:00`;
      return route.continue({ postData: JSON.stringify(b) });
    });
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    const conf = confirmResponse(page);
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'Konfirmasi dengan selectedAt kemarin: 400').toBe(400);
    const crj = await cr.json();
    expect(crj.msg_code || crj.code, 'ARCHIVE415').toBe('ARCHIVE415');
    await page.unroute(/\/document-archive\/opnames(\?.*)?$/);
    await page.waitForTimeout(500);
    const toast = trimAll(await toastText(page).allInnerTexts()).join(' | ');
    monitor.note(`toast ARCHIVE415: ${toast}`);
    expect(toast, 'toast pesan hari lain').toContain('Sesi opname ini dimulai di hari lain');
    expect(await activeStep(page), 'tetap di Step 3').toBe('Konfirmasi');
    await expect(modalTitle(page), 'judul tetap Konfirmasi Opname').toContainText('Konfirmasi Opname');
    await app.screenshot('ac17-arch415-tetap-step3');

    // tanpa scan: X menutup langsung; DB tidak berubah (Konfirmasi ditolak tidak menyimpan apa pun)
    await closeModal(page, { via: 'x' });
    expectRejected(monitor, 'ARCHIVE415', { status: 400, urlRe: /\/document-archive\/opnames(\?|$)/ });
    expectDbUnchanged(before, 'EPS (ARCHIVE415)');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-24 Cancel / X tanpa request
  test('[default] ED-1026 AC-24 Cancel / X / Back lalu Cancel / tanpa scan: nol request tulis, DB sama, buka lagi = tanpa scan; gagal muat folder menutup modal', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    // (1) Step 2 dengan scan Verified + Not found + Invalid -> X -> "Tidak" (tetap) -> X -> "Ya"
    await openOpnameFromRow(page, `${P}ALPHA`);
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(scanRow(await scanTyped(page, `${P}AD1`)).result, 'AD1 verified').toBe('verified');
    expect(scanRow(await scanTyped(page, `${P}GD1`)).result, 'GD1 (folder lain) not_found').toBe('not_found');
    expect(scanRow(await scanTyped(page, 'QA26-TIDAK-ADA-9')).result, 'kode tak dikenal invalid').toBe('invalid');
    expect(await sessionCard(page), '3 scan').toEqual({ scanned: 3, verified: 1, notFound: 1, invalid: 1 });
    await modalRoot(page).locator('.ant-modal-close').click();
    let dlg = page.locator('.ant-modal-confirm').last();
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    await expect(dlg, 'dialog batal').toContainText('Hasil scan sesi ini akan hilang. Batalkan opname ini?');
    await app.screenshot('ac24-dialog-batal');
    await dlg.locator('.ant-btn:not(.ant-btn-primary)').click();
    await page.waitForTimeout(500);
    await expect(modalRoot(page), '"Tidak": modal tetap terbuka').toBeVisible();
    expect(await sessionCard(page), '"Tidak": scan tetap').toEqual({ scanned: 3, verified: 1, notFound: 1, invalid: 1 });
    await closeModal(page, { via: 'x', dialog: true });
    expectDbUnchanged(before, '(1) X dengan scan');

    // (2) buka lagi = tanpa scan (angka 0, tabel kosong); Back ke Step 1 lalu Batal tanpa scan = langsung tertutup, tanpa dialog
    await openOpnameFromRow(page, `${P}ALPHA`);
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await sessionCard(page), '(2) buka lagi: angka sesi 0').toEqual({ scanned: 0, verified: 0, notFound: 0, invalid: 0 });
    await expect(modalRows(page), '(2) buka lagi: tabel kosong').toHaveCount(0);
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Batal$/).click();
    await modalClosed(page);
    await expect(page.locator('.ant-modal-confirm'), '(2) Back lalu Batal tanpa scan: tanpa dialog').toHaveCount(0);

    // (3) scan, Back ke Step 1, Batal -> dialog (ada scan) -> Ya
    await openOpnameFromRow(page, `${P}ALPHA`);
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(scanRow(await scanTyped(page, `${P}AD2`)).result, 'AD2 verified').toBe('verified');
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await closeModal(page, { via: 'batal', dialog: true });

    // (4) Step 3 tanpa scan -> X langsung tertutup; Step 3 dengan scan -> X -> dialog -> Ya
    await openOpnameFromRow(page, `${P}ALPHA`);
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await closeModal(page, { via: 'x' });
    await expect(page.locator('.ant-modal-confirm'), '(4) Step 3 tanpa scan: X tanpa dialog').toHaveCount(0);
    await openOpnameFromRow(page, `${P}ALPHA`);
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(scanRow(await scanTyped(page, `${P}AD1`)).result, 'AD1 verified').toBe('verified');
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(800);
    await closeModal(page, { via: 'x', dialog: true });

    // seluruh rangkaian: nol request tulis; GET opnames/folders + preview/scan saja; DB (tabel opname + kolom verifikasi) sama
    const kinds = [...new Set(opnameCalls(calls).map((c) => `${c.method} ${c.path}`))].sort();
    monitor.note(`jenis request opnames: ${kinds.join(', ')}`);
    expect(kinds, 'hanya GET folders, POST preview, POST preview/documents, POST scan').toEqual(['GET opnames/folders', 'POST opnames/preview', 'POST opnames/preview/documents', 'POST opnames/scan'].sort());
    expect(persisting(calls), 'Cancel/X/Back: tidak ada POST opnames / PUT / DELETE').toEqual([]);
    expectDbUnchanged(before, 'AC-24 seluruh rangkaian');
    expect(inspectDb().docs[`${P}AD1`].is_verified, 'AD1 tetap unverified').toBe(0);

    // gagal muat folder menutup modal tanpa request lain (dikondisikan: GET opnames/folders dijawab 404 ARCHIVE400)
    await page.route(/\/document-archive\/opnames\/folders/, (route) =>
      route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Folder tidak ditemukan', code: 'ARCHIVE400' }) }),
    );
    const nBefore = opnameCalls(calls).length;
    const { menu } = await rowMenu(page, `${P}ALPHA`);
    await menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /^Opname$/ }).click();
    await page.waitForTimeout(1500);
    await modalClosed(page);
    await page.unroute(/\/document-archive\/opnames\/folders/);
    expect(opnameCalls(calls).slice(nBefore).map((c) => `${c.method} ${c.path}`), 'gagal muat folder: modal tertutup, tidak ada request lain').toEqual(['GET opnames/folders']);
    expectRejected(monitor, 'folders 404', { status: 404, urlRe: /\/document-archive\/opnames\/folders/ });
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- K-16: ARCHIVE416
  test('[default] ED-1026 BR-22/K-16 OMEGA: subfolder terpilih dipindah di tengah sesi -> scan ditolak 416, kembali ke Step 1, scan tetap', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    await openArchive(app, page);

    await openOpnameFromRow(page, `${P}OMEGA`);
    await step1Row(page, `${P}OMEGA-S2`).waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'tag OMEGA + 2 subfolder').toBe(`${P}OMEGA + 2 subfolder`);
    expect(scanRow(await scanTyped(page, `${P}OD1`)).result, 'OD1 verified').toBe('verified');

    fixtureCmd('reparent', `${P}OMEGA-S2`, 'root'); // S2 bukan lagi subfolder langsung OMEGA
    const folders = foldersResponse(page);
    const bad = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/scan(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.waitForTimeout(2300);
    await scanInput(page).fill(`${P}OD0`);
    await scanInput(page).press('Enter');
    const br = await bad;
    expect(br.status(), 'scan sesudah S2 dipindah: 400').toBe(400);
    expect((await br.json()).code || (await br.json()).msg_code, 'ARCHIVE416').toBe('ARCHIVE416');
    expect((await folders).status(), 'kembali ke Step 1: GET opnames/folders dimuat ulang').toBe(200);
    await step1Row(page, `${P}OMEGA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'Step 1 dengan stepper lengkap').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'Step 1 aktif').toBe('Pilih Folder');
    await expect(step1Row(page, `${P}OMEGA-S2`), 'S2 tak lagi subfolder langsung: tidak ada di Step 1').toHaveCount(0);
    const toast = trimAll(await toastText(page).allInnerTexts()).join(' | ');
    monitor.note(`toast ARCHIVE416: ${toast}`);
    expect(toast, 'toast pesan 416').toContain('subfolder langsung');
    await app.screenshot('k16-kembali-step1');

    // scan sebelumnya tetap (OD1), scan yang gagal boleh diulang
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    expect(await modalTag(page), 'tag OMEGA + 1 subfolder').toBe(`${P}OMEGA + 1 subfolder`);
    await expect.poll(() => modalRows(page).count(), { message: 'scan OD1 tetap', timeout: 15000 }).toBe(1);
    expect((await firstCells(page))[0], 'baris OD1').toBe(`${P}OD1`);
    await page.waitForTimeout(2300);
    expect(scanRow(await scanTyped(page, `${P}OD0`)).result, 'OD0 (langsung di OMEGA) kini verified').toBe('verified');
    await closeModal(page, { via: 'x', dialog: true });
    expect(persisting(calls), 'OMEGA: tanpa request tulis').toEqual([]);
    expectRejected(monitor, 'ARCHIVE416', { status: 400, urlRe: /\/opnames\/scan/ });
    expectDbUnchanged(before, 'OMEGA');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-29 Steps, AC-14 wrap (375 / 768 / desktop)
  test('[default] ED-1026 AC-4/AC-14/AC-29 Steps small+navigation (lebar dibatasi, gulir otomatis di 375 px) dan filter CheckableTag membungkus; desktop 768 375', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();

    const runPass = async (width, height) => {
      await page.setViewportSize({ width, height });
      await openArchive(app, page);
      await openFolder(page, `${P}ALPHA`);
      const { menu } = await openPlus(page);
      await menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /^Opname Dokumen$/ }).click();
      await modalRoot(page).waitFor({ state: 'visible', timeout: 15000 });
      await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    };

    // ---- desktop 1440: stepper kecil + navigation, lebar dibatasi (tidak memenuhi modal), tidak tergulir
    await runPass(1440, 1000);
    const d1 = await stepsInfo(page);
    monitor.note(`Steps desktop: ${JSON.stringify(d1)}`);
    expect(d1.cls, 'Steps size small').toMatch(/ant-steps-small/);
    expect(d1.cls, 'Steps type navigation').toMatch(/ant-steps-navigation/);
    // (ED-1149) usang: FE 41480e37c (penyesuaian UI developer 2026-10-12, docs/specs/Archive/018) menghapus max-width 803px dari `.steps-container`;
    // desain sekarang = container selebar isi modal, stepper tetap min-width 803px (di layar kecil terpotong + gulir otomatis, dites di 375 px di bawah)
    expect(d1.stepsScrollW, '.ant-steps tetap min-width 803 px (container tanpa max-width sejak 41480e37c)').toBeGreaterThanOrEqual(803);
    expect(d1.containerW, 'stepper tidak memenuhi modal (lebih sempit dari body modal)').toBeLessThan(d1.bodyW - 40);
    expect(d1.scrollLeft, 'desktop Step 1: tidak tergulir').toBe(0);
    expect(d1.title, 'langkah aktif Step 1').toBe('Pilih Folder');
    await app.screenshot('ac29-steps-desktop-step1');
    await closeModal(page, { via: 'batal' });

    // ---- 768 px (sm): screenshot tiap langkah
    await runPass(768, 900);
    await app.screenshot('ac29-steps-768-step1');
    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(700);
    await app.screenshot('ac29-steps-768-step2');
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(900);
    await app.screenshot('ac29-steps-768-step3');
    await closeModal(page, { via: 'x' });

    // ---- 375 px (xs): gulir otomatis ke langkah aktif tiap pindah langkah (maju dan mundur), filter membungkus
    await runPass(375, 800);
    const s1 = await expectActiveStepVisible(page, '375 Step 1');
    monitor.note(`Steps 375 Step 1: ${JSON.stringify(s1)}`);
    expect(s1.cls, 'Steps small + navigation (375)').toMatch(/ant-steps-small.*ant-steps-navigation|ant-steps-navigation.*ant-steps-small/);
    expect(s1.stepsScrollW, 'stepper lebih lebar dari wadah (terpotong, bisa digulir)').toBeGreaterThan(s1.containerW);
    expect(s1.scrollLeft, '375 Step 1: posisi awal (0)').toBe(0);
    expect(s1.title).toBe('Pilih Folder');
    await app.screenshot('ac29-steps-375-step1');

    await footerBtn(page, /^Lanjut$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    const s2 = await expectActiveStepVisible(page, '375 Step 2');
    monitor.note(`Steps 375 Step 2: ${JSON.stringify(s2)}`);
    expect(s2.title, 'langkah aktif Step 2').toBe('Opname Folder');
    expect(s2.scrollLeft, '375 Step 2: stepper tergulir (scrollLeft > 0)').toBeGreaterThan(0);
    await app.screenshot('ac29-steps-375-step2');

    // scan 4 kode supaya keempat tag terisi, lalu cek pembungkusan di 375 px
    expect(scanRow(await scanTyped(page, `${P}AD1`)).result).toBe('verified');
    await page.waitForTimeout(2200);
    expect(scanRow(await scanTyped(page, `${P}GD1`)).result).toBe('not_found');
    await page.waitForTimeout(2200);
    expect(scanRow(await scanTyped(page, 'QA26-TIDAK-ADA-375')).result).toBe('invalid');
    await expect.poll(async () => (await filterState(page)).counts.Semua, { message: 'filter Step 2: Semua = 3', timeout: 10000 }).toBe(3);
    const w2 = await filterLayout(modalRoot(page));
    monitor.note(`filter Step 2 di 375: ${JSON.stringify(w2)}`);
    expect(w2.count, 'Step 2: 4 tag').toBe(4);
    expectWrapped(w2, 'Step 2 (375 px)');
    await app.screenshot('ac14-step2-filter-375');

    // modal kamera di 375 px (buka/tutup tanpa error)
    await modalRoot(page).locator('.anticon-scan').click();
    await page.waitForTimeout(1000);
    await app.screenshot('ac4-modal-kamera-375');
    await page.locator('.ant-modal-wrap:visible').last().locator('.ant-modal-close').click();
    await page.waitForTimeout(500);

    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    const s3 = await expectActiveStepVisible(page, '375 Step 3');
    monitor.note(`Steps 375 Step 3: ${JSON.stringify(s3)}`);
    expect(s3.title, 'langkah aktif Step 3').toBe('Konfirmasi');
    expect(s3.scrollLeft, '375 Step 3: tergulir lebih jauh dari Step 2').toBeGreaterThan(s2.scrollLeft);
    await page.waitForTimeout(900);
    const w3 = await filterLayout(modalRoot(page));
    monitor.note(`filter Step 3 di 375: ${JSON.stringify(w3)}`);
    expect(w3.count, 'Step 3: 5 tag').toBe(5);
    expectWrapped(w3, 'Step 3 (375 px)');
    await app.screenshot('ac29-steps-375-step3');

    // kembali: Step 2 lalu Step 1 -> stepper kembali ke posisi awal
    await footerBtn(page, /^Kembali$/).click();
    await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
    const b2 = await expectActiveStepVisible(page, '375 kembali ke Step 2');
    expect(b2.title).toBe('Opname Folder');
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S1`).waitFor({ state: 'visible', timeout: 20000 });
    await expect.poll(async () => (await stepsInfo(page)).scrollLeft, { message: '375 kembali ke Step 1: stepper kembali ke posisi awal', timeout: 6000 }).toBe(0);
    const b1 = await expectActiveStepVisible(page, '375 kembali ke Step 1');
    expect(b1.title).toBe('Pilih Folder');
    await app.screenshot('ac29-steps-375-kembali-step1');

    // tutup (ada scan): dialog -> Ya; tanpa request tulis
    await closeModal(page, { via: 'x', dialog: true });
    expect(persisting(calls), '375 px: tanpa request tulis').toEqual([]);
    expectDbUnchanged(before, '375 px');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-30 riwayat ED-1028 (CheckableTag)
  test('[default] ED-1026 AC-30/AC-31 riwayat opname (drawer Hasil Opname, ED-1028) dengan CheckableTag: default Discan, jumlah dari result_counts, ganti tag = request halaman 1, wrap di 375 px, Scope "<F> + N subfolder"', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    // dua sesi terkonfirmasi lewat API (Konfirmasi = satu POST): HIST (N=0: verified H1, not found ZD1, invalid, H2 belum discan) dan OMEGA + S1 (N=1)
    const mkSession = async (name, folders, codes) => {
      const f = await apiCall(page, 'GET', `document-archive/opnames/folders?id_archive=${ids()[`${P}${name}`]}`);
      expect(f.status, `GET opnames/folders ${name}`).toBe(200);
      const at = f.json.result.selected_at;
      const res = await apiCall(page, 'POST', 'document-archive/opnames', {
        id_archive: ids()[`${P}${name}`],
        folders: folders.map((n) => ({ id_archive: ids()[`${P}${n}`], is_continue: false })),
        scans: codes.map((code) => ({ code, scanned_at: at })),
        selected_at: at,
      });
      expect(res.status, `POST opnames ${name}`).toBe(200);
      return res.json.result.id_archive_opname;
    };
    await page.waitForTimeout(1200);
    const idHist = await mkSession('HIST', [], [`${P}H1`, `${P}ZD1`, 'QA26-INV-HIST']);
    const idOmega = await mkSession('OMEGA', ['OMEGA-S1'], [`${P}OD1`]);

    // AC-31: scope sesi tersimpan = tepat tiga kunci; subfolder_count = subfolder dicentang
    const sH = await apiCall(page, 'GET', `document-archive/opnames/${idHist}`);
    expect(sH.json.result.scope, 'scope HIST').toEqual({ is_root: false, folder_name: `${P}HIST`, subfolder_count: 0 });
    const sO = await apiCall(page, 'GET', `document-archive/opnames/${idOmega}`);
    expect(sO.json.result.scope, 'scope OMEGA + 1 subfolder').toEqual({ is_root: false, folder_name: `${P}OMEGA`, subfolder_count: 1 });
    const rc = sH.json.result.result_counts;
    expect(rc, 'result_counts HIST').toEqual({ all: 4, scanned: 3, verified: 1, not_found: 1, invalid: 1, unscanned: 1 });

    // overview root -> Lihat semua riwayat -> klik sesi HIST -> Hasil Opname
    const verif = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 45000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    await verif;
    const overview = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await overview.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const histResp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 45000 });
    await overview.getByText('Lihat semua riwayat').first().click();
    await histResp;
    const hist = page.locator('.archive-opname-history.ant-drawer-open').first();
    await hist.waitFor({ state: 'visible', timeout: 15000 });
    await hist.locator('tbody tr.ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    const histRows = hist.locator('tbody tr.ant-table-row');
    const rowTexts = trimAll(await histRows.allInnerTexts());
    monitor.note(`riwayat (halaman 1): ${maskText(rowTexts.slice(0, 4).join(' || '))}`);
    expect(rowTexts.some((t) => t.includes(`${P}OMEGA + 1 subfolder`)), 'kolom Scope sesi OMEGA = "<folder> + 1 subfolder"').toBe(true);
    const hrow = histRows.filter({ hasText: new RegExp(`${esc(P)}HIST(?![A-Za-z-])`) }).first();
    expect(await hrow.count(), 'baris sesi HIST ada di halaman 1 riwayat').toBe(1);
    const detailP = page.waitForResponse((r) => r.request().method() === 'GET' && new RegExp(`/document-archive/opnames/${idHist}(\\?|$)`).test(r.url()), { timeout: 30000 });
    const docsP = page.waitForResponse((r) => r.request().method() === 'GET' && new RegExp(`/document-archive/opnames/${idHist}/documents`).test(r.url()), { timeout: 30000 });
    await hrow.locator('td').nth(2).click();
    await detailP;
    const docs = await docsP;
    const res = hist.locator('.ant-drawer.ant-drawer-open');
    await res.waitFor({ state: 'visible', timeout: 15000 });
    await res.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(800);
    expect(new URL(docs.url()).searchParams.get('result'), 'default result=scanned').toBe('scanned');
    expect(new URL(docs.url()).searchParams.get('page'), 'page=1').toBe('1');

    // CheckableTag di Hasil Opname: 5 tag + jumlah dari result_counts, default Discan, tanpa Segmented
    const tagState = async () => {
      const tags = res.locator('.ant-tag-checkable');
      const n = await tags.count();
      const out = { order: [], counts: {}, active: [] };
      for (let i = 0; i < n; i += 1) {
        const t = trimAll([await tags.nth(i).innerText()])[0];
        const m = /^(.*?)\s*([\d.,]+)$/.exec(t);
        out.order.push(m[1]);
        out.counts[m[1]] = Number(digits(m[2]));
        if (/ant-tag-checkable-checked/.test((await tags.nth(i).getAttribute('class')) || '')) out.active.push(m[1]);
      }
      return out;
    };
    const ts0 = await tagState();
    monitor.note(`CheckableTag riwayat: ${JSON.stringify(ts0)}`);
    expect(ts0.order, 'riwayat: Semua / Discan / Terverifikasi / Tidak ditemukan / Tidak valid').toEqual(['Semua', 'Discan', 'Terverifikasi', 'Tidak ditemukan', 'Tidak valid']);
    expect(ts0.counts, 'jumlah dari result_counts').toEqual({ Semua: rc.all, Discan: rc.scanned, Terverifikasi: rc.verified, 'Tidak ditemukan': rc.not_found, 'Tidak valid': rc.invalid });
    expect(ts0.active, 'default Discan').toEqual(['Discan']);
    await expect(res.locator('.ant-segmented'), 'tanpa Segmented').toHaveCount(0);
    await maskUsername(page);
    await app.screenshot('ac30-riwayat-hasil-opname');

    // ganti tag -> GET documents result=all page=1; tag aktif diklik lagi -> tanpa request baru; dari halaman lain -> halaman 1
    const allP = page.waitForResponse((r) => r.request().method() === 'GET' && new RegExp(`/opnames/${idHist}/documents`).test(r.url()) && /result=all/.test(r.url()), { timeout: 30000 });
    await res.locator('.ant-tag-checkable').filter({ hasText: /^Semua/ }).first().click();
    const allR = await allP;
    expect(new URL(allR.url()).searchParams.get('page'), 'ganti tag: page=1').toBe('1');
    await page.waitForTimeout(500);
    expect((await tagState()).active, 'hanya Semua aktif').toEqual(['Semua']);
    await expect(res.locator('tbody tr.ant-table-row'), 'Semua: 4 baris (2 cakupan + not found + invalid)').toHaveCount(4);
    const nDocs = calls.filter((c) => c.method === 'GET' && new RegExp(`^opnames/${idHist}/documents`).test(c.path)).length;
    await res.locator('.ant-tag-checkable').filter({ hasText: /^Semua/ }).first().click();
    await page.waitForTimeout(700);
    expect(calls.filter((c) => c.method === 'GET' && new RegExp(`^opnames/${idHist}/documents`).test(c.path)).length, 'klik tag aktif: tanpa request baru').toBe(nDocs);
    const nfP = page.waitForResponse((r) => r.request().method() === 'GET' && /result=not_found/.test(r.url()) && /page=1/.test(r.url()), { timeout: 30000 });
    await res.locator('.ant-tag-checkable').filter({ hasText: /^Tidak ditemukan/ }).first().click();
    expect((await nfP).status(), 'GET documents result=not_found page=1').toBe(200);
    await page.waitForTimeout(500);
    await expect(res.locator('tbody tr.ant-table-row'), 'Tidak ditemukan: 1 baris').toHaveCount(1);

    // 375 px: tag membungkus tanpa gulir horizontal
    await page.setViewportSize({ width: 375, height: 800 });
    await page.waitForTimeout(900);
    const wl = await filterLayout(res);
    monitor.note(`filter riwayat di 375: ${JSON.stringify(wl)}`);
    expect(wl.count, '5 tag').toBe(5);
    expectWrapped(wl, 'riwayat (375 px)');
    await maskUsername(page);
    await app.screenshot('ac30-riwayat-375');
    await page.setViewportSize({ width: 1440, height: 1000 });
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-28 bahasa Inggris
  test('[default] ED-1026 AC-28 bahasa en: root "All Archive · root", baris pertama "All Archive", peringatan root "All Archive" (bukan "Semua Arsip")', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    const before = inspectDb();
    fixtureCmd('lang', 'EN');
    try {
      await openArchive(app, page);
      await openOpnameFromPlus(page, 'en');
      await modalRows(page).first().waitFor({ state: 'visible', timeout: 20000 });
      expect(await modalTag(page), 'tag Step 1 (en)').toBe(`${ROOT.en} · root`);
      await expect(modalRows(page).first(), 'baris pertama (en) = All Archive').toContainText(ROOT.en);
      await expect(modalRows(page).first(), 'tanpa "Semua Arsip" (en)').not.toContainText(ROOT.id);
      // hanya baris root: Next -> Step 2 tag tanpa akhiran; Finish -> Step 3 dengan peringatan root (RD1 verified tak discan)
      const headerBox = modalRoot(page).locator('.ant-table-thead input[type="checkbox"]');
      await headerBox.click();
      await page.waitForTimeout(200);
      await headerBox.click();
      await page.waitForTimeout(200);
      await turnOffRootContinue(page);
      const prev = previewResponse(page);
      await modalRoot(page).locator('.ant-modal-body > div.text-center button.ant-btn-primary').first().click();
      expect((await prev).status(), 'POST opnames/preview (en)').toBe(200);
      await scanInput(page).waitFor({ state: 'visible', timeout: 20000 });
      expect(await modalTag(page), 'tag Step 2 (en), N = 0 tanpa akhiran').toBe(`${ROOT.en} · root`);
      const s3 = previewResponse(page);
      await modalRoot(page).locator('.ant-modal-body > div.text-center button.ant-btn-primary').first().click();
      const s3j = (await (await s3).json()).result;
      const rootWarnEn = (s3j.warnings || []).find((w) => w.id_archive === null);
      expect(rootWarnEn, 'API: peringatan root ada').toBeTruthy();
      await modalRoot(page).locator('.ant-alert-warning').first().waitFor({ state: 'visible', timeout: 20000 });
      const warn = trimAll(await modalRoot(page).locator('.ant-alert-warning').allInnerTexts());
      monitor.note(`peringatan Step 3 (en): ${warn.join(' || ')}`);
      const foundEn = findRootWarning(warn, rootWarnEn, 'en');
      expect(foundEn, `peringatan root (en) memakai label "${ROOT.en}" (varian is_opnamed_today=${rootWarnEn.is_opnamed_today})`).toBeTruthy();
      expect(foundEn.count, 'jumlah di peringatan root (en) = unverify_count API').toBe(String(rootWarnEn.unverify_count));
      expect(warn.join(' '), 'tanpa "Semua Arsip" (en)').not.toContain(ROOT.id);
      await app.screenshot('ac28-step3-root-all-archive-en');
      await closeModal(page, { via: 'x' });
      expect(persisting(calls), 'en: tanpa request tulis').toEqual([]);
    } finally {
      fixtureCmd('lang', 'ID');
    }
    expectDbUnchanged(before, 'en');
    noServerError(monitor);
    monitor.check();
  });
});
