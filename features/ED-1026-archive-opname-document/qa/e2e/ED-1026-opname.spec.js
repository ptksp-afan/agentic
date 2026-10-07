'use strict';

/**
 * ED-1026 - Archive: Opname Document per folder (menu "+" / menu baris, modal 3 langkah: Select Folder, Opname Folder, Confirm).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA26-":
 *   ALPHA (subfolder S1[+X] S2 S3) | BETA (T1 T2; BETA + T1 sudah diopname hari ini 09:15, BD1 + T1D1 verified)
 *   GAMMA (GD1-3) | DELTA (DD1) | EPS (E01-E12)   -- GAMMA/DELTA/EPS tanpa subfolder.
 * User QA = [default] (role 3, bahasa ID), lokasi Semarang saja (diatur fixture). Mode role (env QA26_MODE):
 *   main   = role dengan Opname Document (semua test kecuali AC-3 negatif)
 *   norole = Opname Document dicabut dari role user QA (hanya test AC-3 negatif; dijalankan sebagai run kedua)
 *
 * Mutasi hanya pada data fixture + tabel archive_opname* (dibuang teardown). Opname root tidak pernah dikonfirmasi.
 * Test dijalankan berurutan (E2E_WORKERS=1): satu user, satu token.
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

const LIST_RE = /\/document-archive\/archives(\?|$)/;
const nameRe = (name) => new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`);

/** keadaan DB fixture (is_verified per dokumen + sesi opname), lewat fixture.php inspect (read-only) */
function inspectDb() {
  const out = execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'inspect'], { encoding: 'utf8', timeout: 90000 });
  return JSON.parse(out.trim().split('\n').pop());
}

function backdateDraft() {
  execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), 'backdate'], { encoding: 'utf8', timeout: 90000 });
}

/** catat panggilan API document-archive (method, path, query, body) */
function trace(page, monitor) {
  const calls = [];
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
  });
  return calls;
}
const callsOf = (calls, method, re) => calls.filter((c) => c.method === method && re.test(c.path));
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

async function openArchive(app, page) {
  const list = page.waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()), { timeout: 45000 });
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  await list;
  await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
}

async function openFolder(page, name) {
  const resp = page.waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && /id_?[aA]rchive=/.test(decodeURIComponent(r.url())), { timeout: 45000 });
  await archiveRow(page, name).locator('td.cursor-pointer').first().click();
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
  const row = archiveRow(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
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
/** teks sel nama = nama folder (+ "(dicentang ulang)" / "Dokumen yang langsung ..." menempel tanpa spasi), tanpa mencocokkan awalan folder lain */
const cellRe = (name) => new RegExp(`^\\s*${esc(name)}(?:$|[^\\w-]|Dokumen)`);
const step1Row = (page, name) =>
  modalRoot(page)
    .locator('.ant-table-tbody tr.ant-table-row')
    .filter({ has: page.locator('td:nth-child(2)', { hasText: cellRe(name) }) })
    .first();
const rowChecked = (row) => row.locator('.ant-table-selection-column input[type="checkbox"]').isChecked();
const rowDisabled = (row) => row.locator('.ant-table-selection-column input[type="checkbox"]').isDisabled();
const toastText = (page) => page.locator('.ant-message-notice, .ant-notification-notice');

const foldersResponse = (page) => page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames\/folders/.test(r.url()), { timeout: 45000 });

/** "+" -> Opname Dokumen; menunggu GET opnames/folders */
async function openOpnameFromPlus(page) {
  const folders = foldersResponse(page);
  const { menu } = await openPlus(page);
  await menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /^Opname Dokumen$/ }).click();
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

/** Step 2: scan lewat input (ketik + Enter) dan tunggu respons scan */
async function scanTyped(page, code) {
  const input = modalRoot(page).locator('input[placeholder*="Scan QR"]');
  const resp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/[^/]+\/scan/.test(r.url()), { timeout: 30000 });
  await input.click();
  await input.fill(code);
  await input.press('Enter');
  const r = await resp;
  const json = await r.json().catch(() => null);
  await page.waitForTimeout(250);
  return { status: r.status(), json };
}

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

/** label Segmented: { 'Semua': 3, 'Terverifikasi': 1, ... } */
async function segmented(page) {
  const labels = trimAll(await modalRoot(page).locator('.ant-segmented-item-label').allInnerTexts());
  const out = {};
  labels.forEach((l) => {
    const m = /^(.*?)\s*([\d.,]+)$/.exec(l);
    if (m) out[m[1]] = Number(m[2].replace(/[.,]/g, ''));
  });
  return out;
}
const segmentedClick = (page, re) => modalRoot(page).locator('.ant-segmented-item').filter({ hasText: re }).first().click();
const resultCells = async (page) => trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row td:last-child').allInnerTexts());
const firstCells = async (page) => trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row td:first-child').allInnerTexts());

async function cancelOpname(page, { confirmDialog = false } = {}) {
  const del = page.waitForResponse((r) => r.request().method() === 'DELETE' && /\/document-archive\/opnames\//.test(r.url()), { timeout: 20000 }).catch(() => null);
  await modalRoot(page).locator('.ant-modal-close').click();
  if (confirmDialog) {
    const dlg = page.locator('.ant-modal-confirm').last();
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    await dlg.locator('.ant-btn-primary').click();
  }
  const r = await del;
  await modalClosed(page);
  return r;
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

// ================================================================================================================
test.describe('ED-1026 opname per folder', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  // ---------------------------------------------------------------------------------------------- AC-3 / AC-4 menu
  test('[default] ED-1026 AC-3/AC-4 role ber-Opname Document: "+" (root & folder) dan menu baris memuat Opname', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    traceInit(monitor);
    await openArchive(app, page);

    // "+" di root: Opname Dokumen = item terakhir
    const plusRoot = await openPlus(page);
    monitor.note(`item "+" root: ${plusRoot.items.join(' | ')}`);
    expect(plusRoot.items[plusRoot.items.length - 1], 'Opname Dokumen = item terakhir "+" di root').toBe('Opname Dokumen');
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

    // di dalam folder: "+" memuat Opname Dokumen; baris dokumen tanpa Opname
    await openFolder(page, `${P}ALPHA`);
    const plusIn = await openPlus(page);
    monitor.note(`item "+" dalam folder: ${plusIn.items.join(' | ')}`);
    expect(plusIn.items, '"+" di dalam folder memuat Opname Dokumen').toContain('Opname Dokumen');
    expect(plusIn.items[plusIn.items.length - 1], 'Opname Dokumen sesudah Letakkan Dokumen').toBe('Opname Dokumen');
    expect(plusIn.items.indexOf('Letakkan Dokumen'), 'Letakkan Dokumen ada dan lebih dulu').toBeGreaterThanOrEqual(0);
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

    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1026 AC-3 role TANPA Opname Document: "+" dan menu baris tanpa Opname', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'norole', 'mode norole (run kedua, QA26_MODE=norole)');
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

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-3 hak View folder (ED-1025)
  test('[default] ED-1026 AC-3 hak folder: folder hanya View = menu Info/Lihat/Opname dan "+" tanpa Tambah/Letakkan; tanpa View = tanpa menu aksi', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
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

    // opname dari folder hanya-View tetap boleh (View efektif): Step 1 dilewati, Step 2 terbuka tanpa error 403
    const folders = foldersResponse(page);
    await plus.menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /^Opname Dokumen$/ }).click();
    await modalRoot(page).waitFor({ state: 'visible', timeout: 15000 });
    expect((await folders).status(), 'GET opnames/folders folder hanya View: 200').toBe(200);
    await modalRoot(page).locator('input[placeholder*="Scan QR"]').waitFor({ state: 'visible', timeout: 20000 });
    await expect.poll(() => modalTag(page), { message: 'tag = VIEWONLY', timeout: 15000 }).toBe(`${P}VIEWONLY`);
    expect(await stepTitles(page), 'stepper 2 langkah').toEqual(['Opname Folder', 'Konfirmasi']);
    await app.screenshot('ac3-opname-folder-hanya-view');
    const del = await cancelOpname(page, { confirmDialog: false });
    expect(del && del.status(), 'batal = DELETE').toBe(200);
    expect(callsOf(calls, 'POST', /^opnames$/).length, 'satu sesi dibuat').toBe(1);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- Step 1 di root
  test('[default] ED-1026 AC-4/AC-7 Step 1 dari "+" di root: tabel, baris All Archive terkunci, centang-semua, Cancel tanpa sesi, Next = POST', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);
    // satu dokumen root nyata yang terlihat user (list root halaman 2: folder mengisi halaman 1), kode = transaction_no
    const rootList = await apiCall(page, 'GET', 'document-archive/archives?page=2&pagination=10');
    const rootDocRow = ((rootList.json && rootList.json.result && rootList.json.result.data) || []).find((r) => Array.isArray(r.name));
    const rootDocCode = rootDocRow ? rootDocRow.name[0].transaction_no : undefined;

    const folders = await openOpnameFromPlus(page);
    const fr = await folders;
    expect(fr.status(), 'GET opnames/folders (root)').toBe(200);
    const fj = (await fr.json()).result;
    expect(new URL(fr.url()).searchParams.get('id_archive') || new URL(fr.url()).searchParams.get('idArchive'), 'root: tanpa parameter id_archive').toBeNull();
    expect(fj.folder.is_root, 'folder.is_root').toBe(true);
    await modalRows(page).first().waitFor({ state: 'visible', timeout: 20000 });

    // judul, tag, stepper
    expect(await modalHeading(page), 'judul memuat Opname Dokumen').toContain('Opname Dokumen');
    await expect.poll(() => modalTag(page), { message: 'tag Step 1 root', timeout: 15000 }).toBe('All Archive · root');
    expect(await stepTitles(page), 'stepper 3 langkah').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'langkah aktif').toBe('Pilih Folder');
    await expect(modalRoot(page).locator('.ant-alert-info'), 'alert info Step 1').toHaveCount(1);
    expect(trimAll(await modalRoot(page).locator('.ant-table-thead th').allInnerTexts()).filter(Boolean).map((t) => t.toLowerCase()), 'kolom tabel').toEqual(['folder', 'dokumen', 'opname hari ini']);

    // baris pertama = All Archive, tercentang dan terkunci
    const first = modalRows(page).first();
    await expect(first, 'baris pertama = All Archive').toContainText('All Archive');
    expect(await rowChecked(first), 'All Archive tercentang').toBe(true);
    expect(await rowDisabled(first), 'All Archive terkunci').toBe(true);

    // lokasi user = Semarang: tanpa CABANG - JOGJA; fixture + folder lain tampil
    const names = trimAll(await modalRows(page).locator('td:nth-child(2)').allInnerTexts());
    const rowsText = trimAll(await modalRows(page).allInnerTexts());
    monitor.note(`Step 1 root: ${rowsText.length} baris: ${names.join(' | ').slice(0, 400)}`);
    expect(rowsText.some((t) => /CABANG - JOGJA/.test(t)), 'tanpa CABANG - JOGJA (lokasi user di luar Yogyakarta)').toBe(false);
    expect(fj.children.some((c) => c.name === 'CABANG - JOGJA'), 'respons API juga tanpa CABANG - JOGJA').toBe(false);
    for (const n of ['Backup Arsip', 'CABANG - SEMARANG', 'PUSAT - MAGELANG', `${P}ALPHA`, `${P}BETA`, `${P}GAMMA`]) {
      expect(names.some((t) => cellRe(n).test(t)), `Step 1 memuat ${n}`).toBe(true);
    }

    // AC-7: belum diopname -> tercentang + "Belum diopname"; sudah diopname hari ini -> tidak tercentang + jam
    const alpha = step1Row(page, `${P}ALPHA`);
    expect(await rowChecked(alpha), 'ALPHA belum diopname: tercentang').toBe(true);
    await expect(alpha, 'ALPHA: Belum diopname').toContainText('Belum diopname');
    const beta = step1Row(page, `${P}BETA`);
    expect(await rowChecked(beta), 'BETA sudah diopname hari ini: tidak tercentang').toBe(false);
    await expect(beta, 'BETA: Sudah diopname hari ini · 09:15').toContainText('Sudah diopname hari ini · 09:15');
    await expect(beta, 'BETA tidak dicentang: tanpa toggle Lanjut').not.toContainText('Lanjut opname');

    // jumlah dokumen kolom Document = respons (subtree)
    const alphaCount = fj.children.find((c) => c.name === `${P}ALPHA`).document_count;
    expect(alphaCount, 'ALPHA document_count = 2 langsung + S1(2+1) + S2(1) + S3(1) = 7').toBe(7);
    await expect(alpha.locator('td').nth(2), 'kolom Document ALPHA = 7').toHaveText('7');

    // footer N folder dipilih · M dokumen = jumlah baris tercentang + dokumen
    const checkedRows = [];
    for (let i = 0; i < (await modalRows(page).count()); i++) checkedRows.push(await rowChecked(modalRows(page).nth(i)));
    const nChecked = checkedRows.filter(Boolean).length;
    const footer = await footerLeft(page);
    monitor.note(`footer Step 1: ${footer}`);
    expect(footer, 'footer: "N folder dipilih · M dokumen"').toMatch(new RegExp(`^${nChecked} folder dipilih · [\\d.,]+ dokumen$`));
    await app.screenshot('ac7-step1-root');

    // centang-semua (header) lalu lepas: baris All Archive tetap tercentang
    const headerBox = modalRoot(page).locator('.ant-table-thead input[type="checkbox"]');
    await headerBox.click();
    await page.waitForTimeout(300);
    let allChecked = true;
    for (let i = 0; i < (await modalRows(page).count()); i++) allChecked = allChecked && (await rowChecked(modalRows(page).nth(i)));
    expect(allChecked, 'centang-semua: semua baris tercentang').toBe(true);
    await headerBox.click();
    await page.waitForTimeout(300);
    expect(await rowChecked(modalRows(page).first()), 'lepas semua: baris All Archive tetap tercentang').toBe(true);
    expect(await rowChecked(alpha), 'lepas semua: ALPHA tidak tercentang').toBe(false);
    await expect.poll(() => footerLeft(page), { message: 'footer sesudah lepas semua: 1 folder dipilih', timeout: 15000 }).toMatch(/^1 folder dipilih · [\d.,]+ dokumen$/);

    // Cancel tanpa sesi: tanpa DELETE, tanpa POST
    await footerBtn(page, /^Batal$/).click();
    await modalClosed(page);
    await page.waitForTimeout(500);
    expect(callsOf(calls, 'DELETE', /^opnames\//).length, 'Cancel di Step 1: tanpa DELETE').toBe(0);
    expect(callsOf(calls, 'POST', /^opnames$/).length, 'Cancel di Step 1: tanpa POST').toBe(0);

    // buka lagi: pilih ALPHA + GAMMA saja -> Next = POST dengan body sesuai
    await openOpnameFromPlus(page);
    await step1Row(page, `${P}ALPHA`).waitFor({ state: 'visible', timeout: 20000 });
    // hanya ALPHA dan GAMMA tercentang
    await modalRoot(page).locator('.ant-table-thead input[type="checkbox"]').click(); // semua
    await page.waitForTimeout(200);
    await modalRoot(page).locator('.ant-table-thead input[type="checkbox"]').click(); // lepas semua
    await page.waitForTimeout(200);
    await step1Row(page, `${P}ALPHA`).locator('.ant-table-selection-column input').click();
    await step1Row(page, `${P}GAMMA`).locator('.ant-table-selection-column input').click();
    await page.waitForTimeout(200);
    await expect.poll(() => footerLeft(page), { message: '3 folder dipilih (All Archive + ALPHA + GAMMA)', timeout: 15000 }).toMatch(/^3 folder dipilih · [\d.,]+ dokumen$/);

    const post = page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 45000 });
    await footerBtn(page, /^Lanjut$/).click();
    const pr = await post;
    expect(pr.status(), 'POST opnames').toBe(200);
    expect((await pr.json()).msg_code, 'POST opnames: msg_code').toBe('ARCHIVE211');
    const postCall = callsOf(calls, 'POST', /^opnames$/)[0];
    const body = postCall.body;
    monitor.note(`body POST opnames: ${JSON.stringify(body)}`);
    expect(pick(body, 'id_archive', 'idArchive'), 'body: id_archive null (root)').toBeNull();
    expect(pick(body, 'is_continue', 'isContinue'), 'body: is_continue false').toBe(false);
    const sent = (body.folders || []).map((f) => pick(f, 'id_archive', 'idArchive')).sort();
    expect(sent, 'body: folders = ALPHA + GAMMA').toEqual([ids()[`${P}ALPHA`], ids()[`${P}GAMMA`]].sort());
    for (const f of body.folders) expect(pick(f, 'is_continue', 'isContinue'), 'is_continue per folder false (belum diopname)').toBe(false);

    // Step 2: stepper, tag = subfolder pertama + sisa; tanpa toast
    await modalRoot(page).locator('input[placeholder*="Scan QR"]').waitFor({ state: 'visible', timeout: 20000 });
    expect(await activeStep(page), 'langkah aktif Step 2').toBe('Opname Folder');
    await expect.poll(() => modalTag(page), { message: 'tag Step 2: subfolder pertama + 1 subfolder', timeout: 15000 }).toBe(`${P}ALPHA + 1 subfolder`);
    await expect(toastText(page), 'POST sesi: tanpa toast sukses').toHaveCount(0);
    await app.screenshot('ac4-step2-root-sesi');

    // dokumen tanpa folder (root, K-4): ikut cakupan lewat baris All Archive -> Verified, kolom Folder Step 3 = "All Archive"
    const rootDoc = rootDocCode;
    monitor.note(`dokumen root yang discan: ${rootDoc}`);
    expect(rootDoc, 'ada dokumen root terlihat di list').toBeTruthy();
    const rs = await scanTyped(page, rootDoc);
    expect(rs.json.result.row.result, 'dokumen root = verified (All Archive ikut cakupan)').toBe('verified');
    expect(rs.json.result.row.id_archive_folder, 'dokumen root: tanpa folder').toBeNull();
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    await page.waitForTimeout(1000);
    const rootRow = trimAll(await modalRows(page).first().locator('td').allInnerTexts());
    monitor.note(`baris Step 3 dokumen root: ${rootRow.join(' | ')}`);
    expect(rootRow[0], 'baris = dokumen root').toBe(rootDoc);
    expect(rootRow[2], 'kolom Folder dokumen root = All Archive').toBe('All Archive');
    await app.screenshot('ac17-step3-root-dokumen-tanpa-folder');

    // ada scan: batal dari Step 3 minta konfirmasi lalu DELETE
    const del = await cancelOpname(page, { confirmDialog: true });
    expect(del && del.status(), 'DELETE opnames/{id}').toBe(200);
    await page.waitForTimeout(400);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- Step 1 BETA
  test('[default] ED-1026 AC-7 BETA: sudah diopname, dicentang ulang + Lanjut opname, footer, Back/Next = PUT, peringatan Step 3, batal berkonfirmasi', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    const folders = await openOpnameFromRow(page, `${P}BETA`);
    const fr = await folders;
    const fj = (await fr.json()).result;
    const q = new URL(fr.url()).searchParams;
    expect(q.get('id_archive') || q.get('idArchive'), 'GET opnames/folders?id_archive = BETA').toBe(ids()[`${P}BETA`]);
    expect(fj.skip_select_folder, 'BETA: Step 1 tidak dilewati').toBe(false);
    await step1Row(page, `${P}BETA-T1`).waitFor({ state: 'visible', timeout: 20000 });

    expect(await stepTitles(page), 'stepper 3 langkah').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    await expect.poll(() => modalTag(page), { message: 'tag Step 1 = nama folder opname', timeout: 15000 }).toBe(`${P}BETA`);

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

    // footer: BETA(1 langsung) + T2(1) = 2 folder . 2 dokumen
    await expect.poll(() => footerLeft(page), { message: 'footer awal', timeout: 15000 }).toBe('2 folder dipilih · 2 dokumen');

    // centang T1 -> dicentang ulang + toggle aktif + teks; footer 3 folder . 4 dokumen
    await t1.locator('.ant-table-selection-column input').click();
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

    // Next pertama = POST
    const post = page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 45000 });
    await footerBtn(page, /^Lanjut$/).click();
    const pr = await post;
    expect(pr.status(), 'POST opnames').toBe(200);
    const body = callsOf(calls, 'POST', /^opnames$/)[0].body;
    monitor.note(`body POST opnames BETA: ${JSON.stringify(body)}`);
    expect(pick(body, 'id_archive', 'idArchive'), 'body: id_archive = BETA').toBe(ids()[`${P}BETA`]);
    expect(pick(body, 'is_continue', 'isContinue'), 'body: is_continue BETA = false (toggle mati)').toBe(false);
    const byId = Object.fromEntries((body.folders || []).map((f) => [pick(f, 'id_archive', 'idArchive'), pick(f, 'is_continue', 'isContinue')]));
    expect(Object.keys(byId).sort(), 'body: folders = T1 + T2').toEqual([ids()[`${P}BETA-T1`], ids()[`${P}BETA-T2`]].sort());
    expect(byId[ids()[`${P}BETA-T1`]], 'T1 is_continue true (sudah diopname + toggle aktif)').toBe(true);
    expect(byId[ids()[`${P}BETA-T2`]], 'T2 is_continue false (belum diopname)').toBe(false);

    // Step 2
    await modalRoot(page).locator('input[placeholder*="Scan QR"]').waitFor({ state: 'visible', timeout: 20000 });
    expect(await activeStep(page), 'langkah aktif').toBe('Opname Folder');
    await expect.poll(() => modalTag(page), { message: 'tag Step 2', timeout: 15000 }).toBe(`${P}BETA-T1 + 1 subfolder`);
    await page.waitForTimeout(600);
    const card = trimAll([await modalRoot(page).locator('.ant-card').filter({ hasText: /Status verifikasi saat ini/ }).first().innerText()])[0];
    monitor.note(`kartu status: ${card}`);
    expect(card, 'kartu status verifikasi: 2 / 4 (BD1 + T1D1 verified dari 4 dokumen)').toMatch(/2\s*\/\s*4/);
    await expect.poll(() => footerLeft(page), { message: 'footer Step 2', timeout: 15000 }).toBe(`${P}BETA-T1 + 1 subfolder · 4 dokumen`);
    const scanCall = await scanTyped(page, `${P}T2D1`);
    expect(scanCall.status, 'scan T2D1').toBe(200);
    expect(scanCall.json.result.row.result, 'T2D1 = verified').toBe('verified');

    // Finish -> Step 3: kartu, peringatan BETA (tanpa lanjut, 1 dokumen verified tak discan)
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    expect(await activeStep(page), 'langkah aktif Step 3').toBe('Konfirmasi');
    await modalRoot(page).locator('.ant-alert-warning').first().waitFor({ state: 'visible', timeout: 20000 });
    const warnings = trimAll(await modalRoot(page).locator('.ant-alert-warning').allInnerTexts());
    monitor.note(`peringatan Step 3: ${warnings.join(' || ')}`);
    expect(warnings.length, 'satu peringatan (hanya BETA tanpa lanjut)').toBe(1);
    expect(warnings[0], 'peringatan BETA: tanpa lanjut opname - 1 dokumen').toContain(`${P}BETA dijalankan tanpa lanjut opname — 1 dokumen`);
    const cardsText = trimAll(await modalRoot(page).locator('.ant-card').allInnerTexts());
    monitor.note(`kartu Step 3: ${cardsText.join(' | ')}`);
    expect(cardsText[0], 'Total dokumen 4').toMatch(/^4\s*Total dokumen/);
    expect(cardsText[1], 'Terverifikasi sesi ini 1').toMatch(/^1\s*Terverifikasi sesi ini/);
    expect(cardsText[4], 'Belum discan 3').toMatch(/^3\s*Belum discan/);
    await app.screenshot('ac17-step3-peringatan-beta');

    // Back -> Step 2 (scan tetap) -> Back -> Step 1 (pilihan tetap) -> Next = PUT, bukan POST
    await footerBtn(page, /^Kembali$/).click();
    await modalRoot(page).locator('input[placeholder*="Scan QR"]').waitFor({ state: 'visible', timeout: 20000 });
    await expect(modalRows(page), 'Step 2 sesudah Back: scan tetap (1 baris)').toHaveCount(1);
    expect((await firstCells(page))[0], 'baris scan = T2D1').toBe(`${P}T2D1`);
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}BETA-T1`).waitFor({ state: 'visible', timeout: 20000 });
    expect(await rowChecked(step1Row(page, `${P}BETA-T1`)), 'pilihan dipertahankan: T1 tercentang').toBe(true);
    await expect(modalRows(page).first().locator('.ant-switch'), 'pilihan dipertahankan: toggle BETA mati').not.toHaveClass(/ant-switch-checked/);
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/document-archive\/opnames\/[^/?]+(\?|$)/.test(r.url()), { timeout: 45000 });
    await footerBtn(page, /^Lanjut$/).click();
    expect((await put).status(), 'PUT opnames/{id}').toBe(200);
    expect(callsOf(calls, 'POST', /^opnames$/).length, 'Next kedua bukan POST baru').toBe(1);
    const putBody = callsOf(calls, 'PUT', /^opnames\/[^/]+$/)[0].body;
    expect(pick(putBody, 'id_archive', 'idArchive'), 'PUT tanpa id_archive').toBeUndefined();
    await modalRoot(page).locator('input[placeholder*="Scan QR"]').waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    await expect(modalRows(page), 'Step 2 sesudah PUT: scan T2D1 tetap').toHaveCount(1);

    // batal dengan scan: dialog konfirmasi; "Tidak" = tetap; "Ya" = DELETE
    await modalRoot(page).locator('.ant-modal-close').click();
    const dlg = page.locator('.ant-modal-confirm').last();
    await dlg.waitFor({ state: 'visible', timeout: 10000 });
    await expect(dlg, 'dialog batal').toContainText('Hasil scan sesi ini akan hilang. Batalkan opname ini?');
    await dlg.locator('.ant-btn:not(.ant-btn-primary)').click();
    await page.waitForTimeout(500);
    await expect(modalRoot(page), 'Tidak: modal tetap terbuka').toBeVisible();
    expect(callsOf(calls, 'DELETE', /^opnames\//).length, 'Tidak: tanpa DELETE').toBe(0);
    const del = await cancelOpname(page, { confirmDialog: true });
    expect(del && del.status(), 'Ya: DELETE opnames/{id}').toBe(200);

    // sesudah batal: BETA tetap hanya satu sesi terkonfirmasi (sesi batal tidak dihitung)
    const db = inspectDb();
    const betaSessions = db.sessions.filter((s) => s.folder === `${P}BETA`);
    expect(betaSessions.map((s) => s.status).sort(), 'DB: sesi BETA = 1 terkonfirmasi (seed) + 1 dibatalkan').toEqual([2, 3]);
    expect(db.docs[`${P}BD1`].is_verified, 'DB: BD1 tetap verified (sesi batal tidak mengubah)').toBe(1);
    expect(db.docs[`${P}T2D1`].is_verified, 'DB: T2D1 tetap unverified').toBe(0);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- ALPHA: subfolder sebagian
  test('[default] ED-1026 AC-7/AC-14 ALPHA: subfolder dicentang sebagian, sub-subfolder ikut, Not found di luar pilihan, Back/PUT menilai ulang', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
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

    // lepas S2 dan S3
    await step1Row(page, `${P}ALPHA-S2`).locator('.ant-table-selection-column input').click();
    await step1Row(page, `${P}ALPHA-S3`).locator('.ant-table-selection-column input').click();
    await page.waitForTimeout(300);
    await expect.poll(() => footerLeft(page), { message: 'footer sesudah S2,S3 dilepas', timeout: 10000 }).toBe('2 folder dipilih · 5 dokumen');
    await app.screenshot('ac7-step1-alpha-sebagian');

    const post = page.waitForResponse((r) => r.request().method() === 'POST' && /\/document-archive\/opnames(\?|$)/.test(r.url()), { timeout: 45000 });
    await footerBtn(page, /^Lanjut$/).click();
    expect((await post).status(), 'POST opnames').toBe(200);
    const body = callsOf(calls, 'POST', /^opnames$/)[0].body;
    expect((body.folders || []).map((f) => pick(f, 'id_archive', 'idArchive')), 'POST folders = S1 saja').toEqual([ids()[`${P}ALPHA-S1`]]);
    const input = modalRoot(page).locator('input[placeholder*="Scan QR"]');
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await expect.poll(() => modalTag(page), { message: 'tag Step 2 = S1 (satu subfolder, tanpa "+ N")', timeout: 15000 }).toBe(`${P}ALPHA-S1`);
    await expect.poll(() => footerLeft(page), { message: 'footer Step 2', timeout: 15000 }).toBe(`${P}ALPHA-S1 · 5 dokumen`);

    // sub-subfolder X ikut otomatis (Verified); dokumen langsung folder opname ikut; S2 (tak dicentang) = Not found
    expect((await scanTyped(page, `${P}S1XD1`)).json.result.row.result, 'S1XD1 (sub-subfolder, otomatis) = verified').toBe('verified');
    // kode yang sama diketik lagi < 2 dtk kemudian (mirip frame kamera berulang): diabaikan FE, tanpa POST scan baru
    const scansBefore = callsOf(calls, 'POST', /^opnames\/[^/]+\/scan$/).length;
    await input.fill(`${P}S1XD1`);
    await input.press('Enter');
    await page.waitForTimeout(900);
    expect(callsOf(calls, 'POST', /^opnames\/[^/]+\/scan$/).length, 'kode sama < 2 dtk: tanpa POST scan baru').toBe(scansBefore);
    expect(await input.inputValue(), 'input dikosongkan').toBe('');
    expect((await scanTyped(page, `${P}AD1`)).json.result.row.result, 'AD1 (langsung di ALPHA) = verified').toBe('verified');
    expect((await scanTyped(page, `${P}S2D1`)).json.result.row.result, 'S2D1 (S2 tak dicentang) = not_found').toBe('not_found');
    expect(await sessionCard(page), 'kartu: 3 discan, 2 verified, 1 not found').toEqual({ scanned: 3, verified: 2, notFound: 1, invalid: 0 });
    await expect(modalRows(page).locator('xpath=self::tr[contains(@class,"row-not-found")]'), 'S2D1 berlatar not-found').toHaveCount(1);

    // Back, centang S2 lagi, Next = PUT: S2D1 dinilai ulang menjadi Terverifikasi, scan tetap
    await footerBtn(page, /^Kembali$/).click();
    await step1Row(page, `${P}ALPHA-S2`).waitFor({ state: 'visible', timeout: 20000 });
    expect(await rowChecked(step1Row(page, `${P}ALPHA-S1`)), 'pilihan dipertahankan: S1 tercentang').toBe(true);
    expect(await rowChecked(step1Row(page, `${P}ALPHA-S2`)), 'pilihan dipertahankan: S2 tidak tercentang').toBe(false);
    await step1Row(page, `${P}ALPHA-S2`).locator('.ant-table-selection-column input').click();
    await page.waitForTimeout(300);
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/document-archive\/opnames\/[^/?]+(\?|$)/.test(r.url()), { timeout: 45000 });
    await footerBtn(page, /^Lanjut$/).click();
    expect((await put).status(), 'PUT opnames/{id}').toBe(200);
    expect(callsOf(calls, 'POST', /^opnames$/).length, 'tanpa POST kedua').toBe(1);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(800);
    await expect(modalRows(page), 'scan tetap (3 baris)').toHaveCount(3);
    await expect.poll(() => modalTag(page), { message: 'tag Step 2 = S1 + 1 subfolder', timeout: 15000 }).toBe(`${P}ALPHA-S1 + 1 subfolder`);
    const cells = Object.fromEntries((await Promise.all([0, 1, 2].map(async (i) => trimAll(await modalRows(page).nth(i).locator('td').allInnerTexts())))).map((r) => [r[0], r[4]]));
    monitor.note(`hasil sesudah PUT: ${JSON.stringify(cells)}`);
    expect(cells[`${P}S2D1`], 'S2D1 dinilai ulang: Terverifikasi (S2 kini dalam cakupan)').toBe('Terverifikasi');
    expect(await sessionCard(page), 'kartu sesudah PUT: 3 discan, 3 verified').toEqual({ scanned: 3, verified: 3, notFound: 0, invalid: 0 });
    await expect(modalRows(page).locator('xpath=self::tr[contains(@class,"row-not-found")]'), 'tanpa baris not-found').toHaveCount(0);

    const del = await cancelOpname(page, { confirmDialog: true });
    expect(del && del.status(), 'batal berkonfirmasi: DELETE').toBe(200);
    const db = inspectDb();
    expect(['S1XD1', 'AD1', 'S2D1'].map((d) => db.docs[`${P}${d}`].is_verified), 'DB: sesi batal tidak mengubah verified').toEqual([0, 0, 0]);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-8 ZULFA
  test('[default] ED-1026 AC-8 ZULFA (folder nyata tanpa subfolder): Step 1 dilewati, stepper 2 langkah, tag ZULFA, POST folders kosong', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);
    await openFolder(page, 'PUSAT - MAGELANG');
    await archiveRow(page, 'ZULFA').waitFor({ state: 'visible', timeout: 20000 });

    const folders = await openOpnameFromRow(page, 'ZULFA');
    const fr = await folders;
    const fj = (await fr.json()).result;
    expect(fj.skip_select_folder, 'ZULFA: skip_select_folder true').toBe(true);

    await modalRoot(page).locator('input[placeholder*="Scan QR"]').waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'stepper 2 langkah').toEqual(['Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'langkah aktif = Opname Folder').toBe('Opname Folder');
    await expect.poll(() => modalTag(page), { message: 'tag = ZULFA', timeout: 15000 }).toBe('ZULFA');
    await expect(footerBtn(page, /^Batal$/), 'tombol kiri = Batal (bukan Kembali)').toBeVisible();
    await expect(footerBtn(page, /^Kembali$/), 'tanpa Kembali').toHaveCount(0);
    const postCall = callsOf(calls, 'POST', /^opnames$/)[0];
    monitor.note(`body POST ZULFA: ${JSON.stringify(postCall.body)}`);
    expect(pick(postCall.body, 'id_archive', 'idArchive'), 'POST id_archive = ZULFA').toBeTruthy();
    expect(postCall.body.folders, 'POST folders: []').toEqual([]);
    await page.waitForTimeout(500);
    await expect.poll(() => footerLeft(page), { message: 'footer: ZULFA . N dokumen', timeout: 15000 }).toMatch(/^ZULFA · [\d.,]+ dokumen$/);
    await app.screenshot('ac8-zulfa-step2-tanpa-step1');

    const del = await cancelOpname(page, { confirmDialog: false });
    expect(del && del.status(), 'Batal tanpa scan = DELETE langsung').toBe(200);
    await expectSearchReactivated(page, calls, monitor);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- GAMMA: scan + Step 3 + Confirm
  test('[default] ED-1026 AC-13/14/17 GAMMA: scan (ketik, USB di luar input, kamera), Segmented, kelas baris, Step 3, Confirm', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    await openOpnameFromRow(page, `${P}GAMMA`);
    const input = modalRoot(page).locator('input[placeholder*="Scan QR"]');
    await input.waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'GAMMA tanpa subfolder: stepper 2 langkah').toEqual(['Opname Folder', 'Konfirmasi']);
    await expect.poll(() => modalTag(page), { message: 'tag = GAMMA', timeout: 15000 }).toBe(`${P}GAMMA`);
    await page.waitForTimeout(600);
    await expect(input, 'input scan autofocus').toBeFocused();
    const status0 = trimAll([await modalRoot(page).locator('.ant-card').filter({ hasText: /Status verifikasi saat ini/ }).first().innerText()])[0];
    expect(status0, 'status verifikasi awal 0 / 3').toMatch(/0\s*\/\s*3/);
    expect(await sessionCard(page), 'kartu sesi awal 0').toEqual({ scanned: 0, verified: 0, notFound: 0, invalid: 0 });
    await app.screenshot('ac14-step2-awal');

    // 1) ketik + Enter di input: Verified
    const s1 = await scanTyped(page, `${P}GD1`);
    expect(s1.status, 'scan GD1').toBe(200);
    expect(s1.json.msg_code, 'ARCHIVE212').toBe('ARCHIVE212');
    expect(s1.json.result.row.result, 'GD1 verified').toBe('verified');
    await expect(toastText(page), 'scan: tanpa toast sukses').toHaveCount(0);
    expect(await input.inputValue(), 'input dikosongkan sesudah Enter').toBe('');
    expect((await firstCells(page))[0], 'baris GD1 paling atas').toBe(`${P}GD1`);
    expect(await sessionCard(page), 'kartu sesi: 1 discan, 1 verified').toEqual({ scanned: 1, verified: 1, notFound: 0, invalid: 0 });

    // 2) Not found: dokumen ada di folder lain; 3) Invalid: kode tak dikenal
    await page.waitForTimeout(2200); // lewati jendela "frame berulang" 2 dtk
    const s2 = await scanTyped(page, `${P}AD1`);
    expect(s2.json.result.row.result, 'AD1 (folder lain) = not_found').toBe('not_found');
    expect(s2.json.result.row.is_out_of_scope, 'is_out_of_scope').toBe(true);
    const s3 = await scanTyped(page, 'QA26-TIDAK-ADA-123');
    expect(s3.json.result.row.result, 'kode tak dikenal = invalid').toBe('invalid');
    expect((await firstCells(page)).slice(0, 3), 'urutan terbaru di atas').toEqual(['QA26-TIDAK-ADA-123', `${P}AD1`, `${P}GD1`]);
    expect(await resultCells(page), 'kolom Hasil').toEqual(['Tidak valid', 'Tidak ditemukan', 'Terverifikasi']);
    await expect(modalRoot(page).locator('tr.row-invalid'), 'baris Invalid berkelas row-invalid').toHaveCount(1);
    await expect(modalRoot(page).locator('tr.row-not-found'), 'baris Not found berkelas row-not-found').toHaveCount(1);
    await expect(modalRoot(page).locator('tr.row-invalid td:first-child'), 'row-invalid = kode tak dikenal').toHaveText('QA26-TIDAK-ADA-123');
    await expect(modalRoot(page).locator('tr.row-not-found td:first-child'), 'row-not-found = AD1').toHaveText(`${P}AD1`);
    // kolom Tipe Transaksi, Salesman terisi untuk dokumen ada; kosong "—" untuk Invalid
    const gd1Cells = trimAll(await modalRoot(page).locator('.ant-table-tbody tr.ant-table-row').nth(2).locator('td').allInnerTexts());
    monitor.note(`baris GD1: ${gd1Cells.join(' | ')}`);
    expect(gd1Cells[1], 'GD1: tipe transaksi terisi').not.toBe('—');
    expect(gd1Cells[2], 'GD1: salesman').toBe('Sales QA26');
    expect(gd1Cells[3], 'GD1: waktu HH:mm').toMatch(/^\d{2}:\d{2}$/);
    const invCells = trimAll(await modalRoot(page).locator('.ant-table-tbody tr.row-invalid td').allInnerTexts());
    monitor.note(`baris Invalid: ${invCells.join(' | ')}`);
    expect(invCells.slice(1, 3), 'Invalid: tipe transaksi dan salesman kosong "—"').toEqual(['—', '—']);
    expect(invCells[3], 'Invalid: waktu scan tetap tampil HH:mm').toMatch(/^\d{2}:\d{2}$/);

    // 4) duplikat (huruf kecil): tanpa baris, tanpa angka baru
    await page.waitForTimeout(2200);
    const dup = await scanTyped(page, `${P}GD1`.toLowerCase());
    expect(dup.json.result.is_duplicate, 'duplikat: is_duplicate').toBe(true);
    await expect(modalRows(page), 'duplikat: tetap 3 baris').toHaveCount(3);
    expect(await sessionCard(page), 'duplikat: angka tetap').toEqual({ scanned: 3, verified: 1, notFound: 1, invalid: 1 });
    await expect(toastText(page), 'duplikat: tanpa pesan').toHaveCount(0);

    // 5) scanner USB dengan fokus DI LUAR input (klik judul modal), ketik cepat + Enter
    await modalTitle(page).click();
    await expect(input, 'fokus keluar dari input').not.toBeFocused();
    const archiveListBefore = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
    const usb = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/[^/]+\/scan/.test(r.url()), { timeout: 20000 });
    await page.keyboard.type(`${P}GD2`, { delay: 0 });
    await page.keyboard.press('Enter');
    const ur = await usb;
    expect(ur.status(), 'USB di luar input: POST scan').toBe(200);
    expect((await ur.json()).result.row.result, 'GD2 verified').toBe('verified');
    await page.waitForTimeout(500);
    expect((await firstCells(page))[0], 'USB: baris GD2 paling atas').toBe(`${P}GD2`);
    expect(await sessionCard(page), 'USB: 4 discan, 2 verified').toEqual({ scanned: 4, verified: 2, notFound: 1, invalid: 1 });
    expect(calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length, 'pencarian Archive tidak terpicu oleh scan').toBe(archiveListBefore);

    // 6) Segmented: jumlah = kartu; menyaring tabel; kembali ke input untuk scanner
    const seg = await segmented(page);
    monitor.note(`Segmented Step 2: ${JSON.stringify(seg)}`);
    expect(seg, 'Segmented = angka kartu sesi').toEqual({ Semua: 4, Terverifikasi: 2, 'Tidak ditemukan': 1, 'Tidak valid': 1 });
    await segmentedClick(page, /Terverifikasi/);
    await page.waitForTimeout(300);
    expect(await resultCells(page), 'filter Terverifikasi').toEqual(['Terverifikasi', 'Terverifikasi']);
    await expect(input, 'sesudah memilih filter fokus kembali ke input').toBeFocused();
    await segmentedClick(page, /Tidak ditemukan/);
    await page.waitForTimeout(300);
    expect(await resultCells(page), 'filter Tidak ditemukan').toEqual(['Tidak ditemukan']);
    await segmentedClick(page, /Tidak valid/);
    await page.waitForTimeout(300);
    expect(await resultCells(page), 'filter Tidak valid').toEqual(['Tidak valid']);
    await segmentedClick(page, /Semua/);
    await page.waitForTimeout(300);
    await expect(modalRows(page), 'filter Semua: 4 baris').toHaveCount(4);

    // scanner USB sesudah memilih filter tanpa klik input (fokus dikembalikan)
    await page.waitForTimeout(2200);
    const usb2 = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/[^/]+\/scan/.test(r.url()), { timeout: 20000 });
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
    // scan lewat modal kamera (scanner USB aktif di modal itu): modal TETAP terbuka, "Scan terakhir" berganti, pencarian Archive tidak terpicu
    const camListBefore = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
    const camScan = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/[^/]+\/scan/.test(r.url()), { timeout: 20000 });
    await page.keyboard.type('QA26-KAMERA-1', { delay: 0 });
    await page.keyboard.press('Enter');
    expect((await camScan).status(), 'scan dari modal kamera: POST scan').toBe(200);
    await page.waitForTimeout(600);
    await expect(camera.locator('.ant-modal-close'), 'modal kamera tetap terbuka sesudah scan').toBeVisible();
    const cameraText2 = trimAll([await page.locator('.ant-modal:visible').last().innerText()])[0];
    monitor.note(`modal kamera sesudah scan: ${cameraText2}`);
    expect(cameraText2, 'Scan terakhir berganti ke kode baru').toContain('QA26-KAMERA-1');
    expect(cameraText2, 'hasil scan terakhir = Tidak valid').toContain('Tidak valid');
    expect(calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length, 'modal kamera: pencarian Archive tidak terpicu').toBe(camListBefore);
    await app.screenshot('ac13-modal-kamera-sesudah-scan');
    await camera.locator('.ant-modal-close').click();
    await page.waitForTimeout(500);
    await expect(input, 'tutup kamera: fokus kembali ke input').toBeFocused();
    expect(await sessionCard(page), 'sesudah scan kamera: 6 discan, 2 invalid').toEqual({ scanned: 6, verified: 3, notFound: 1, invalid: 2 });

    // 8) Finish -> Step 3: filter awal Discan, kartu, tabel
    const docs1 = page.waitForResponse((r) => r.request().method() === 'GET' && /\/opnames\/[^/]+\/documents/.test(r.url()) && /result=scanned/.test(r.url()), { timeout: 30000 });
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    const d1 = await docs1;
    expect(d1.status(), 'GET documents?result=scanned').toBe(200);
    expect(await activeStep(page), 'langkah aktif = Konfirmasi').toBe('Konfirmasi');
    await page.waitForTimeout(800);
    const activeSeg = trimAll([await modalRoot(page).locator('.ant-segmented-item-selected').innerText()])[0];
    expect(activeSeg, 'filter awal = Discan (6)').toBe('Discan 6');
    const cards3 = trimAll(await modalRoot(page).locator('.ant-card').allInnerTexts());
    monitor.note(`kartu Step 3: ${cards3.join(' | ')}`);
    expect(cards3[0], 'Total dokumen = 3').toMatch(/^3\s*Total dokumen/);
    expect(cards3[1], 'Terverifikasi sesi ini = 3').toMatch(/^3\s*Terverifikasi sesi ini/);
    expect(cards3[2], 'Tidak ditemukan = 1').toMatch(/^1\s*Tidak ditemukan/);
    expect(cards3[3], 'Tidak valid = 2').toMatch(/^2\s*Tidak valid/);
    expect(cards3[4], 'Belum discan = 0 (Total - Verified sesi ini)').toMatch(/^0\s*Belum discan/);
    const seg3 = await segmented(page);
    monitor.note(`Segmented Step 3: ${JSON.stringify(seg3)}`);
    expect(seg3, 'Segmented Step 3 (All = total 3 + not found 1 + invalid 2)').toEqual({ Semua: 6, Discan: 6, Terverifikasi: 3, 'Tidak ditemukan': 1, 'Tidak valid': 2 });
    expect(await modalRoot(page).locator('.ant-alert-warning').count(), 'tanpa peringatan (tak ada verified yang turun)').toBe(0);
    expect(trimAll(await modalRoot(page).locator('.ant-table-thead th').allInnerTexts()).filter(Boolean), 'kolom Step 3').toEqual(['No Dokumen', 'Tipe Transaksi', 'Folder', 'Waktu', 'Hasil']);
    // kolom Folder: not found "Di luar scope opname", invalid "—"
    const rows3 = [];
    for (let i = 0; i < (await modalRows(page).count()); i++) rows3.push(trimAll(await modalRows(page).nth(i).locator('td').allInnerTexts()));
    monitor.note(`tabel Step 3 (Discan): ${JSON.stringify(rows3)}`);
    const nf = rows3.find((r) => r[0] === `${P}AD1`);
    const inv = rows3.find((r) => r[0] === 'QA26-TIDAK-ADA-123');
    expect(nf[2], 'Not found: Di luar scope opname').toBe('Di luar scope opname');
    expect(inv[2], 'Invalid: —').toBe('—');
    await app.screenshot('ac17-step3-discan');

    // Tidak ditemukan -> result=not_found page=1 (snake_case); Semua -> result=all
    const nfReq = page.waitForResponse((r) => /\/opnames\/[^/]+\/documents/.test(r.url()) && /result=not_found/.test(r.url()) && /page=1/.test(r.url()), { timeout: 20000 });
    await segmentedClick(page, /Tidak ditemukan/);
    expect((await nfReq).status(), 'GET documents?result=not_found&page=1').toBe(200);
    await page.waitForTimeout(400);
    expect(await firstCells(page), 'filter Tidak ditemukan: AD1').toEqual([`${P}AD1`]);
    const allReq = page.waitForResponse((r) => /\/opnames\/[^/]+\/documents/.test(r.url()) && /result=all/.test(r.url()), { timeout: 20000 });
    await segmentedClick(page, /Semua/);
    expect((await allReq).status(), 'GET documents?result=all').toBe(200);
    await page.waitForTimeout(400);
    await expect(modalRows(page), 'Semua: 3 dokumen cakupan + not found 1 + invalid 2 = 6 baris').toHaveCount(6);
    await app.screenshot('ac17-step3-semua');

    // Back -> Step 2: scan sama; Finish -> Step 3 lagi
    await footerBtn(page, /^Kembali$/).click();
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    await expect(modalRows(page), 'Back: scan tetap (6 baris)').toHaveCount(6);
    expect(await sessionCard(page), 'Back: kartu sesi tetap').toEqual({ scanned: 6, verified: 3, notFound: 1, invalid: 2 });
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(600);

    // Confirm Opname = Popconfirm -> PUT confirm; toast sukses; modal tertutup tanpa DELETE; list dimuat ulang
    const delBefore = callsOf(calls, 'DELETE', /^opnames\//).length;
    const listBefore = calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length;
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    await expect(pop, 'Popconfirm').toContainText('Konfirmasi opname ini? Status verifikasi dokumen dalam scope akan diperbarui.');
    await app.screenshot('ac17-step3-popconfirm');
    const conf = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/opnames\/[^/]+\/confirm/.test(r.url()), { timeout: 45000 });
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'PUT confirm').toBe(200);
    expect((await cr.json()).msg_code, 'ARCHIVE213').toBe('ARCHIVE213');
    await expect(toastText(page).first(), 'toast sukses').toContainText('Opname berhasil dikonfirmasi', { timeout: 5000 });
    await modalClosed(page);
    await page.waitForTimeout(1500);
    expect(callsOf(calls, 'DELETE', /^opnames\//).length, 'Confirm sukses: modal tertutup tanpa DELETE').toBe(delBefore);
    expect(calls.filter((c) => c.method === 'GET' && c.path.startsWith('archives')).length, 'list Archive dimuat ulang').toBeGreaterThan(listBefore);

    // modal tertutup: scanner pencarian Archive menyala lagi (scan USB di luar input memicu pencarian)
    await expectSearchReactivated(page, calls, monitor);
    await openArchive(app, page); // buang hasil pencarian

    // DB: verified + sesi terkonfirmasi (ringkasan BE)
    const db = inspectDb();
    expect(['GD1', 'GD2', 'GD3'].map((d) => db.docs[`${P}${d}`].is_verified), 'DB: GD1-3 verified').toEqual([1, 1, 1]);
    expect(db.docs[`${P}AD1`].is_verified, 'DB: AD1 (Not found) tidak berubah').toBe(0);
    const gs = db.sessions.filter((s) => s.folder === `${P}GAMMA`);
    expect(gs.length, 'DB: satu sesi GAMMA').toBe(1);
    expect(gs[0], 'DB: sesi GAMMA terkonfirmasi dengan angka final').toMatchObject({ status: 2, total: 3, scanned: 6, verified: 3, not_found: 1, invalid: 2, unscanned: 0, unverified: 0, confirmed: true });

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
  test('[default] ED-1026 AC-22 DELTA: bentrok ARCHIVE414 sesi lain -> kembali ke Step 1, scan tetap, Next = PUT, Confirm berhasil', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    // sesi B (UI): DELTA, scan DD1
    await openOpnameFromRow(page, `${P}DELTA`);
    const input = modalRoot(page).locator('input[placeholder*="Scan QR"]');
    await input.waitFor({ state: 'visible', timeout: 20000 });
    const sc = await scanTyped(page, `${P}DD1`);
    expect(sc.json.result.row.result, 'DD1 verified').toBe('verified');

    // sesi A (API, user yang sama) mengonfirmasi DELTA sesudah B memilih folder
    await page.waitForTimeout(1300);
    const mk = await apiCall(page, 'POST', 'document-archive/opnames', { id_archive: ids()[`${P}DELTA`], folders: [] });
    expect(mk.status, 'sesi A: POST opnames').toBe(200);
    const idA = mk.json.result.id_archive_opname;
    const okA = await apiCall(page, 'PUT', `document-archive/opnames/${idA}/confirm`);
    expect(okA.status, 'sesi A: confirm 200').toBe(200);
    expect(okA.json.msg_code, 'sesi A: ARCHIVE213').toBe('ARCHIVE213');

    // sesi B: Finish -> Step 3 -> Confirm -> 400 ARCHIVE414
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(800);
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    const conf = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/opnames\/[^/]+\/confirm/.test(r.url()), { timeout: 45000 });
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'sesi B: confirm ditolak 400').toBe(400);
    const cj = await cr.json();
    expect(cj.msg_code || cj.code, 'sesi B: ARCHIVE414').toBe('ARCHIVE414');
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

    // kembali ke Step 1 (stepper 3 langkah), DELTA "Sudah diopname hari ini", dicentang ulang + Lanjut
    await modalRows(page).first().waitFor({ state: 'visible', timeout: 20000 });
    expect(await stepTitles(page), 'kembali ke Step 1: stepper 3 langkah').toEqual(['Pilih Folder', 'Opname Folder', 'Konfirmasi']);
    expect(await activeStep(page), 'Step 1 aktif').toBe('Pilih Folder');
    await expect(modalRows(page).first(), 'DELTA: Sudah diopname hari ini').toContainText('Sudah diopname hari ini');
    await expect(modalRows(page).first(), 'DELTA: dicentang ulang').toContainText('(dicentang ulang)');
    await expect(modalRows(page).first().locator('.ant-switch'), 'DELTA: Lanjut opname aktif').toHaveClass(/ant-switch-checked/);
    await app.screenshot('ac22-kembali-step1');

    // Next = PUT (bukan POST baru), scan B masih ada
    const postsBefore = callsOf(calls, 'POST', /^opnames$/).length;
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/document-archive\/opnames\/[^/?]+(\?|$)/.test(r.url()), { timeout: 45000 });
    await footerBtn(page, /^Lanjut$/).click();
    expect((await put).status(), 'Next = PUT opnames/{id}').toBe(200);
    expect(callsOf(calls, 'POST', /^opnames$/).length, 'Next bukan POST baru').toBe(postsBefore);
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(700);
    await expect(modalRows(page), 'scan B masih ada').toHaveCount(1);
    expect((await firstCells(page))[0], 'baris scan B = DD1').toBe(`${P}DD1`);

    // konfirmasi lagi -> berhasil
    await footerBtn(page, /^Selesai Opname$/).click();
    await expect(modalTitle(page)).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(800);
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop2 = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop2.waitFor({ state: 'visible', timeout: 10000 });
    const conf2 = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/opnames\/[^/]+\/confirm/.test(r.url()), { timeout: 45000 });
    await pop2.locator('.ant-btn-primary').click();
    expect((await conf2).status(), 'sesi B: confirm kedua berhasil').toBe(200);
    await expect(toastText(page).first(), 'toast sukses').toContainText('Opname berhasil dikonfirmasi', { timeout: 5000 });
    await modalClosed(page);

    const db = inspectDb();
    const ds = db.sessions.filter((s) => s.folder === `${P}DELTA`);
    expect(ds.map((s) => s.status), 'DB: dua sesi terkonfirmasi (A lalu B)').toEqual([2, 2]);
    expect(db.docs[`${P}DD1`].is_verified, 'DB: DD1 verified').toBe(1);

    expectRejected(monitor, 'ARCHIVE414', { status: 400, urlRe: /\/opnames\/[^/]+\/confirm/ });
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- EPS: paginasi Step 3 + ARCHIVE415
  test('[default] ED-1026 AC-17 EPS: Step 3 paginasi server (page=2), batal; draft hari lain -> ARCHIVE415 tetap di Step 3', async ({ app, page, monitor }) => {
    test.skip(MODE !== 'main', 'mode main');
    const calls = trace(page, monitor);
    await openArchive(app, page);

    await openOpnameFromRow(page, `${P}EPS`);
    const input = modalRoot(page).locator('input[placeholder*="Scan QR"]');
    await input.waitFor({ state: 'visible', timeout: 20000 });
    await footerBtn(page, /^Selesai Opname$/).click(); // Finish tanpa scan boleh (K-11 viii)
    await expect(modalTitle(page), 'judul Step 3').toContainText('Konfirmasi Opname');
    await page.waitForTimeout(800);
    await expect(modalRows(page), 'Discan (tanpa scan): tabel kosong').toHaveCount(0);

    const all1 = page.waitForResponse((r) => /\/opnames\/[^/]+\/documents/.test(r.url()) && /result=all/.test(r.url()), { timeout: 20000 });
    await segmentedClick(page, /Semua/);
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
    const next = page.waitForResponse((r) => /\/opnames\/[^/]+\/documents/.test(r.url()) && /page=2/.test(r.url()), { timeout: 20000 });
    await modalRoot(page).locator('.ant-pagination-item-2').click();
    expect((await next).status(), 'GET documents?page=2').toBe(200);
    await page.waitForTimeout(500);
    await expect(modalRows(page), 'halaman 2: sisa baris').toHaveCount(12 - perPage);
    await app.screenshot('ac17-step3-paginasi-hal2');

    // draft dimulai di hari lain -> confirm 400 ARCHIVE415, modal tetap di Step 3
    backdateDraft();
    await footerBtn(page, /^Konfirmasi Opname$/).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    const conf = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/opnames\/[^/]+\/confirm/.test(r.url()), { timeout: 45000 });
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'confirm draft hari lain: 400').toBe(400);
    expect((await cr.json()).msg_code || (await cr.json()).code, 'ARCHIVE415').toBe('ARCHIVE415');
    await page.waitForTimeout(500);
    const toast = trimAll(await toastText(page).allInnerTexts()).join(' | ');
    monitor.note(`toast ARCHIVE415: ${toast}`);
    expect(toast, 'toast pesan hari lain').toContain('Sesi opname ini dimulai di hari lain');
    expect(await activeStep(page), 'tetap di Step 3').toBe('Konfirmasi');
    await expect(modalTitle(page), 'judul tetap Konfirmasi Opname').toContainText('Konfirmasi Opname');
    await app.screenshot('ac17-arch415-tetap-step3');

    // tanpa scan: batal langsung DELETE
    const del = await cancelOpname(page, { confirmDialog: false });
    expect(del && del.status(), 'DELETE sesi hari lain').toBe(200);
    expectRejected(monitor, 'ARCHIVE415', { status: 400, urlRe: /\/opnames\/[^/]+\/confirm/ });
    noServerError(monitor);
    monitor.check();
  });
});

function traceInit(monitor) {
  monitor.note(`mode QA26_MODE=${MODE}`);
}
