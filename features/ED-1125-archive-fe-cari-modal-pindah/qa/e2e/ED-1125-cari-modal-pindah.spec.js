'use strict';

/**
 * ED-1125 - Archive FE: Cari di modal Pindahkan / Pilih Folder pindah massal memuat ulang aplikasi (form bersarang) dan scan barcode
 * mencari di tabel halaman. Bug FE saja; e2e membuktikan dari browser (Chrome headless, build staging):
 *
 *   AC-1/2 : ⋯ Pindahkan A -> Cari modal: Enter / ikon filter -> Cari = 0 navigasi, 1 GET search, tabel modal = respons, modal tetap
 *   AC-3/4 : pindah massal [M, M2] -> Lanjut -> Cari di Pilih Folder (Enter / Cari): sama; Kembali -> Konfirmasi M, M2; mode Pilih tetap
 *   AC-5   : pilih tujuan dulu lalu cari: tanpa POST put-in, DB sama, pilihan tetap, Pindahkan aktif (kedua modal)
 *   AC-6   : hasil cari dipilih dengan aturan biasa: (a) A -> C = 400 ARCHIVE404, (b) M -> T = 200, (c) massal [M, M2] -> T = 200
 *   AC-7   : picker di A > B, cari T -> request tanpa idArchive, breadcrumb "Arsip"; reset (X) -> root
 *   AC-8   : regresi Search halaman (Enter dan Cari)
 *   AC-9   : layar tanpa error konsol / 5xx / toast galat; screenshot desktop dan 390 px
 *   AC-14  : scan saat modal Pindahkan terbuka -> halaman utuh, tepat 1 GET search (picker)
 *   AC-15  : scan di pindah massal: Konfirmasi = halaman utuh; Pilih Folder = seperti AC-14
 *   AC-16  : modal ditutup lewat Batal, ✕, sukses (single & massal) -> scan mencari di tabel halaman
 *   AC-17  : regresi Letakkan Dokumen: scan -> review berisi kode, halaman utuh; tutup -> scan halaman mencari
 *
 * Definisi (spec.md): "0 navigasi" = CDP Page.frameRequestedNavigation kosong dan penanda window bertahan; "GET search" = GET
 * document-archive/archives?search={"query":"<teks>"}&page=1 = 200; "scan" = fokus di luar input (klik judul modal), ketik kode tanpa
 * jeda lalu Enter; "halaman utuh" = baris, breadcrumb dan teks Cari halaman sama seperti sebelum aksi.
 *
 * Data uji: fixtures.json -> fixture.php (folder berawalan "0QA125-"), dibuang persis di teardown. Mutasi hanya pada data fixture;
 * alur pindah sukses dipulihkan lewat `fixture.php restore`. Profil [default] = QA_USER (bahasa ID). Jalankan --workers 1:
 *   scripts/e2e/run.sh ED-1125 --profile default --workers 1
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const PUTIN_RE = /\/document-archive\/documents\/put-in/;
const P = '0QA125-';
const MSG_404 = 'Tidak bisa dipindahkan ke dirinya sendiri atau subfoldernya';
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const rowName = (row) => (Array.isArray(row.name) ? (row.name[0] || {}).transaction_no : row.name);

const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));

/** fixture.php <perintah...>: baca baris / pulihkan. Keluaran hanya JSON/teks tanpa rahasia. */
function fx(...args) {
  const out = execFileSync(config.get('PHP_BIN', 'php'), [path.join(__dirname, 'fixture.php'), ...args], {
    encoding: 'utf8',
    cwd: config.AGENTIC_DIR,
    timeout: 90000,
    env: { ...process.env },
  });
  return out.trim();
}
const prop = (name) => JSON.parse(fx('prop', name));

const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const searchOf = (u) => u.searchParams.get('search') || '';
const queryOf = (u) => {
  try {
    return (JSON.parse(searchOf(u)) || {}).query;
  } catch (e) {
    return undefined;
  }
};
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];

const listResponse = (page, pred, timeout = 45000) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout })
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
const searchResponse = (page, query) => listResponse(page, (u) => queryOf(u) === query);

/** Catat setiap GET list dan POST put-in (untuk menghitung request sesudah aksi). */
function trace(page, monitor) {
  const calls = { gets: [], posts: [] };
  page.on('request', (r) => {
    const u = r.url();
    if (r.method() === 'GET' && LIST_RE.test(u)) {
      const url = new URL(u);
      calls.gets.push({ query: queryOf(url), search: searchOf(url), folder: folderParam(url), page: url.searchParams.get('page') });
    }
    if (r.method() === 'POST' && PUTIN_RE.test(u)) calls.posts.push(u);
  });
  page.on('response', (r) => {
    const u = r.url();
    if (/\/document-archive\/|\/select\/document-archive\//.test(u)) {
      monitor.note(`API ${r.status()} ${r.request().method()} ${decodeURIComponent(u.replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 200)}`);
    }
  });
  return {
    ...calls,
    mark: () => ({ gets: calls.gets.length, posts: calls.posts.length }),
    getsSince: (m) => calls.gets.slice(m.gets),
    searchGetsSince: (m) => calls.gets.slice(m.gets).filter((g) => g.search),
    postsSince: (m) => calls.posts.slice(m.posts),
    all: calls,
  };
}

/** Penanda window + CDP Page.frameRequestedNavigation: 0 navigasi = daftar kosong dan penanda bertahan. */
async function navTracker(page) {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Page.enable');
  const navs = [];
  cdp.on('Page.frameRequestedNavigation', (e) => navs.push(`${e.reason} ${e.url}`));
  const marker = `m${Date.now()}`;
  await page.evaluate((m) => {
    window.__ed1125 = m;
  }, marker);
  return {
    navs,
    survived: async () => (await page.evaluate(() => window.__ed1125).catch(() => null)) === marker,
    async expectNone(label) {
      expect(navs, `${label}: 0 navigasi (CDP Page.frameRequestedNavigation kosong)`).toEqual([]);
      expect(await this.survived(), `${label}: penanda window bertahan (aplikasi tidak dimuat ulang)`).toBe(true);
    },
  };
}

/** Keadaan tabel/breadcrumb/teks Cari HALAMAN (bukan yang di dalam modal). */
const pageState = (page) =>
  page.evaluate(() => {
    const outside = (el) => !el.closest('.ant-modal');
    const norm = (t) => (t || '').replace(/\s+/g, ' ').trim();
    const rows = [...document.querySelectorAll('.archive-page .ant-table-row')].filter(outside).map((r) => norm(r.innerText));
    const crumb = [...document.querySelectorAll('.archive-page .breadcrumbs')].find(outside);
    const input = [...document.querySelectorAll('.archive-page .search-bar input')].find(outside);
    return { rows, breadcrumb: crumb ? norm(crumb.innerText) : null, search: input ? input.value : null };
  });

/** Baris tabel halaman utama (bukan tabel di modal) dengan nama tepat. */
const rowOf = (page, name) =>
  page
    .locator('.archive-page .ant-table-row')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .filter({ hasNot: page.locator('.ant-modal') })
    .first();

/** Baris di dalam modal/picker/konfirmasi: sel nama = nama persis. */
const pickerRow = (scope, page, name) =>
  scope
    .locator('.ant-table-row')
    .filter({ has: page.locator('td').filter({ hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) })
    .first();

const MENU = { move: /^(Pindahkan|Move)$/, info: /^Info$/ };
const visibleModal = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
const modalInput = (modal) => modal.locator('.search-bar input').first();
const pageInput = (page) => page.locator('.archive-page .search-bar input').first();

async function pickRowMenu(page, name, itemRegex) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  const btn = row.locator('button.btn-action');
  expect(await btn.count(), `baris ${name} punya tombol ⋯`).toBeGreaterThan(0);
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
}

/** Buka layar Archive (muat ulang penuh) dan kembalikan respons list root. */
async function openArchive(app, page) {
  const rootResp = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  const root = await rootResp;
  expect(root.status, 'GET archives (root)').toBe(200);
  await app.settle();
  return root;
}

/** Buka folder dari daftar halaman (klik sel nama) dan tunggu GET archives?id_archive. */
async function openFolder(page, name) {
  const resp = listResponse(page, (u) => !!folderParam(u));
  await rowOf(page, name).locator('td.cursor-pointer').first().click();
  return resp;
}

/** ⋯ Pindahkan <name> (tabel halaman): kembalikan modal sesudah picker memuat root. */
async function openMove(app, page, name) {
  const root = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
  await pickRowMenu(page, name, MENU.move);
  const modal = visibleModal(page);
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  await root.catch(() => null);
  await app.settle();
  await expect(modal.locator('.ant-modal-title'), `judul modal memuat ${name}`).toContainText(name);
  return modal;
}

/** Mode Pilih -> centang nama-nama -> Pindahkan: modal pindah massal di langkah Konfirmasi. */
async function openBulk(app, page, names) {
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
  for (const n of names) await rowOf(page, n).locator('input[type="checkbox"]').first().check();
  await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
  const bulk = visibleModal(page);
  await bulk.waitFor({ state: 'visible', timeout: 15000 });
  await app.settle();
  await expect(bulk.locator('.ant-modal-title'), 'judul pindah massal: "Pindahkan - N dipilih"').toContainText(new RegExp(`${names.length}`));
  for (const n of names) await expect(pickerRow(bulk, page, n), `Konfirmasi memuat ${n}`).toBeVisible();
  return bulk;
}

const NEXT = /^(Lanjut|Next)$/;
const PREV = /^(Sebelum|Sebelumnya|Previous|Kembali|Back)$/;
const CANCEL = /^(Batal|Cancel)$/;

/** Konfirmasi -> Pilih Folder: tunggu GET root picker. */
async function toPickFolder(app, page, bulk) {
  const root = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
  await bulk.locator('button.ant-btn-primary', { hasText: NEXT }).first().click();
  await root.catch(() => null);
  await app.settle();
  await expect(modalInput(bulk), 'langkah Pilih Folder: kolom Cari picker tampil').toBeVisible();
}

/** Ketik sungguhan di kolom Cari (modal) lalu Enter; kembalikan respons GET search. */
async function typeAndEnter(page, input, text) {
  const resp = searchResponse(page, text);
  await input.click();
  await page.waitForTimeout(350); // Search.onInputClick memfokus ulang (cursor: all) sesudah 100 ms: ketikan lebih awal tertimpa
  await page.keyboard.press('Control+A');
  await page.keyboard.type(text, { delay: 40 });
  await page.keyboard.press('Enter');
  return resp;
}

/** Ketik di kolom Cari (modal), klik ikon filter lalu tombol Cari di popover; kembalikan respons + popover. */
async function typeAndCari(app, page, modal, text) {
  const input = modalInput(modal);
  await input.click();
  await page.waitForTimeout(350);
  await page.keyboard.press('Control+A');
  await page.keyboard.type(text, { delay: 40 });
  await modal.locator('.search-config').first().click();
  const overlay = modal.locator('.search-bar-overlay').first();
  await expect(overlay, 'popover filter picker terbuka').not.toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
  await page.waitForTimeout(700);
  const resp = searchResponse(page, text);
  await overlay.locator('button').filter({ hasText: /^(Cari|Search)$/ }).first().click();
  return { resp, overlay };
}

/** Tabel modal = isi respons (jumlah baris dan nama). */
async function expectModalTableIs(modal, page, resp, label) {
  const names = rowsOf(resp).map(rowName);
  expect(names.length, `${label}: respons punya baris`).toBeGreaterThan(0);
  // hanya baris yang TERLIHAT: panel Konfirmasi pindah massal tetap ter-mount (tersembunyi) di belakang Pilih Folder
  await expect.poll(async () => modal.locator('.ant-table-row:visible').count(), { timeout: 15000, message: `${label}: jumlah baris tabel modal = respons (${names.length})` }).toBe(names.length);
  for (const n of names) await expect(pickerRow(modal, page, n), `${label}: ${n} tampil di tabel modal`).toBeVisible();
}

const cdpFree = async (page) => page.waitForTimeout(500);

/** Alur non-simpan: tanpa toast apa pun. */
const noToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-notice, .ant-notification-notice'), `${label}: tanpa toast/notifikasi`).toHaveCount(0);
};
const noErrorToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-error, .ant-message-warning, .ant-notification-notice-error, .ant-notification-notice-warning'), `${label}: tanpa toast galat/peringatan`).toHaveCount(0);
};

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  expect(api.length, 'ada panggilan API v5 tercatat (monitor aktif)').toBeGreaterThan(0);
}

/** Keluarkan penolakan 400 put-in yang diharapkan (respons + log konsol axios) dan pastikan jumlahnya. */
function expectRejections(monitor, minimum, label) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === 400 && PUTIN_RE.test(e.url)) || (e.kind === 'console' && /Request failed with status code 400/.test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  const responses = dropped.filter((e) => e.kind === 'response').length;
  expect(responses, `${label}: ${minimum}x 400 POST documents/put-in tercatat (diharapkan)`).toBeGreaterThanOrEqual(minimum);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan (${responses} respons 400 put-in + log konsol axios)`);
}

const toastWith = (page, text) => page.locator('.ant-message-notice, .ant-notification-notice').filter({ hasText: text });

/** Tangkap toast sebagai gambar sendiri + catat teksnya. */
async function shotToast(app, monitor, toast, name) {
  const text = (await toast.innerText()).replace(/\s+/g, ' ').trim();
  monitor.note(`toast ${name}: "${text}"`);
  const rel = path.join('screenshots', app.profile, `${name}.png`);
  const abs = path.join(app.outDir, rel);
  fs.mkdirSync(path.dirname(abs), { recursive: true });
  await toast.screenshot({ path: abs });
  app.screenshots.push(rel.replace(/\\/g, '/'));
  return text;
}

/** Pilih baris tujuan (radio) dan klik Pindahkan; kembalikan {status, reqBody, json}. */
async function selectTargetAndMove(scope, page, targetName) {
  const row = pickerRow(scope, page, targetName);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  await row.locator('input[type="radio"]').check();
  const btn = scope.locator('button.ant-btn-primary', { hasText: MENU.move }).first();
  await expect(btn, `tombol Pindahkan aktif setelah ${targetName} dipilih`).toBeEnabled();
  const post = page.waitForResponse((r) => r.request().method() === 'POST' && PUTIN_RE.test(r.url()), { timeout: 45000 });
  await btn.click();
  const pr = await post;
  let reqBody = {};
  try {
    reqBody = JSON.parse(pr.request().postData() || '{}');
  } catch (e) {
    reqBody = { _raw: pr.request().postData() };
  }
  return { status: pr.status(), reqBody, json: await pr.json().catch(() => null) };
}
const idsOfBody = (b) => b.idArchives || b.id_archives || [];
const parentOfBody = (b) => b.idArchiveParent || b.id_archive_parent;

/** Keluar dari mode Pilih (konfirmasi bila ada pilihan). */
async function exitSelectMode(page) {
  const exit = page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first();
  if ((await exit.count()) === 0) return;
  await exit.click();
  const confirm = page.locator('.ant-modal-confirm').last();
  if (await confirm.count()) await confirm.locator('button.ant-btn-primary').click();
  await page.waitForTimeout(600);
}

/** Tutup modal teratas lewat ✕ atau Batal. */
async function closeModalX(page) {
  await visibleModal(page).locator('.ant-modal-close').click();
  await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });
  await page.waitForTimeout(500);
}
async function closeModalBatal(page, modal) {
  await modal.locator('button', { hasText: CANCEL }).first().click();
  await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });
  await page.waitForTimeout(500);
}

/** Scan USB: fokus di luar input (klik judul, bila ada), ketik kode tanpa jeda lalu Enter (satu panggilan agar jeda Enter < 30 ms). */
async function scan(page, code, focusLoc) {
  if (focusLoc) await focusLoc.click();
  await page.waitForTimeout(300);
  await page.keyboard.type(`${code}\n`, { delay: 0 });
}

async function pageReset(page) {
  const r = page.locator('.archive-page .reset-filter').first();
  if (await r.count()) {
    const resp = listResponse(page, () => true, 15000).catch(() => null);
    await r.click();
    await resp;
    await page.waitForTimeout(600);
  }
}

// ====================================================================================================================
test.describe('ED-1125 Cari dan scan di modal Pindahkan / pindah massal (e2e)', () => {
  test.beforeEach(() => {
    fx('restore');
  });
  test.afterEach(() => {
    fx('restore');
  });

  // ---------------------------------------------------------------------------------------------------- AC-1
  test('[default] ED-1125 AC-1 ⋯ Pindahkan A: ketik 0QA125-C + Enter di Cari modal = 0 navigasi, 1 GET search, tabel modal = respons, modal tetap, tanpa put-in', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    const aBefore = prop('A');
    await openArchive(app, page);
    const before = await pageState(page);
    const nav = await navTracker(page);
    const modal = await openMove(app, page, P + 'A');

    // struktur: tepat 1 <form> di modal (milik Search picker), tidak bersarang
    const forms = await page.evaluate(() => ({ inModal: document.querySelectorAll('.ant-modal form').length, nested: document.querySelectorAll('form form').length }));
    monitor.note(`form di modal: ${JSON.stringify(forms)}`);
    expect(forms, '1 form di modal, form di dalam form = 0').toEqual({ inModal: 1, nested: 0 });
    await app.screenshot('AC01-01-modal-pindahkan-A');

    const m = calls.mark();
    const resp = await typeAndEnter(page, modalInput(modal), P + 'C');
    await app.settle();
    expect(resp.status, 'GET search 200').toBe(200);
    const u = new URL(resp.url);
    expect(searchOf(u), 'search = {"query":"0QA125-C"}').toBe(JSON.stringify({ query: P + 'C' }));
    expect(u.searchParams.get('page'), 'page=1').toBe('1');
    expect(folderParam(u), 'tanpa idArchive/id_archive').toBeFalsy();
    expect(calls.searchGetsSince(m).length, 'tepat 1 GET search').toBe(1);

    await nav.expectNone('Enter di Cari modal');
    await expectModalTableIs(modal, page, resp, 'tabel modal');
    await expect(pickerRow(modal, page, P + 'C'), 'C ada di tabel modal').toBeVisible();
    await expect(pickerRow(modal, page, P + 'T'), 'T tidak ada').toHaveCount(0);
    await expect(pickerRow(modal, page, P + 'M'), 'M tidak ada').toHaveCount(0);
    await expect(modal, 'modal tetap terbuka').toBeVisible();
    await expect(modal.locator('.ant-modal-title'), 'judul "Pindahkan - 0QA125-A"').toHaveText(`Pindahkan - ${P}A`);
    await expect(modalInput(modal), 'teks Cari tetap').toHaveValue(P + 'C');
    expect(calls.postsSince(m).length, 'tanpa POST put-in').toBe(0);
    expect(await pageState(page), 'halaman utuh (baris, breadcrumb, teks Cari halaman)').toEqual(before);
    await app.screenshot('AC01-02-sesudah-enter-cari-C');

    await closeModalBatal(page, modal);
    expect(prop('A'), 'DB: baris A identik').toEqual(aBefore);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-2
  test('[default] ED-1125 AC-2 ⋯ Pindahkan A: ikon filter -> Cari (popover menutup) = 0 navigasi, 1 GET search, tabel modal = respons', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const before = await pageState(page);
    const nav = await navTracker(page);
    const modal = await openMove(app, page, P + 'A');

    const m = calls.mark();
    const { resp: respP, overlay } = await typeAndCari(app, page, modal, P + 'C');
    const resp = await respP;
    await app.settle();
    expect(resp.status, 'GET search 200').toBe(200);
    expect(searchOf(new URL(resp.url)), 'search = {"query":"0QA125-C"}').toBe(JSON.stringify({ query: P + 'C' }));
    expect(new URL(resp.url).searchParams.get('page'), 'page=1').toBe('1');
    expect(calls.searchGetsSince(m).length, 'tepat 1 GET search').toBe(1);
    await nav.expectNone('ikon filter -> Cari di modal');
    await expect(overlay, 'popover menutup').toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
    await expectModalTableIs(modal, page, resp, 'tabel modal');
    await expect(pickerRow(modal, page, P + 'C'), 'C ada di tabel modal').toBeVisible();
    await expect(pickerRow(modal, page, P + 'T'), 'T tidak ada').toHaveCount(0);
    await expect(modal.locator('.ant-modal-title'), 'judul modal tetap').toHaveText(`Pindahkan - ${P}A`);
    await expect(modalInput(modal), 'teks Cari tetap').toHaveValue(P + 'C');
    expect(calls.postsSince(m).length, 'tanpa POST put-in').toBe(0);
    expect(await pageState(page), 'halaman utuh').toEqual(before);
    await app.screenshot('AC02-01-sesudah-cari-lewat-popover');

    await closeModalX(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-3 / AC-4
  test('[default] ED-1125 AC-3/AC-4 pindah massal [M, M2] -> Lanjut -> Cari T (Enter, lalu tombol Cari) = 0 navigasi, GET search, tetap di Pilih Folder; Kembali = Konfirmasi M, M2; mode Pilih tetap', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const nav = await navTracker(page);
    const bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await app.screenshot('AC03-01-konfirmasi-M-M2');
    await toPickFolder(app, page, bulk);
    const forms = await page.evaluate(() => ({ inModal: document.querySelectorAll('.ant-modal form').length, nested: document.querySelectorAll('form form').length }));
    monitor.note(`form di modal pindah massal: ${JSON.stringify(forms)}`);
    expect(forms.nested, 'form di dalam form = 0 (pindah massal)').toBe(0);
    expect(forms.inModal, '1 form di modal (milik Search picker) di langkah Pilih Folder').toBe(1);

    const stillPick = async (label) => {
      await expect(bulk, `${label}: modal terbuka`).toBeVisible();
      await expect(bulk.locator('.ant-modal-title'), `${label}: judul "Pindahkan - 2 dipilih"`).toHaveText(/^Pindahkan - 2 dipilih$/);
      await expect(modalInput(bulk), `${label}: tetap di Pilih Folder (kolom Cari picker tampil)`).toBeVisible();
      await expect(bulk.locator('button.ant-btn-primary', { hasText: MENU.move }).first(), `${label}: tombol Pindahkan (langkah terakhir) tampil`).toBeVisible();
    };

    // AC-3: Enter
    const m = calls.mark();
    const resp = await typeAndEnter(page, modalInput(bulk), P + 'T');
    await app.settle();
    expect(resp.status, 'AC-3: GET search 200').toBe(200);
    expect(searchOf(new URL(resp.url)), 'AC-3: search = {"query":"0QA125-T"}').toBe(JSON.stringify({ query: P + 'T' }));
    expect(new URL(resp.url).searchParams.get('page'), 'AC-3: page=1').toBe('1');
    expect(folderParam(new URL(resp.url)), 'AC-3: tanpa idArchive').toBeFalsy();
    expect(calls.searchGetsSince(m).length, 'AC-3: tepat 1 GET search').toBe(1);
    await nav.expectNone('AC-3 Enter di Cari Pilih Folder');
    await expectModalTableIs(bulk, page, resp, 'AC-3 tabel');
    await expect(pickerRow(bulk, page, P + 'T'), 'AC-3: T ada').toBeVisible();
    await stillPick('AC-3');
    expect(calls.postsSince(m).length, 'AC-3: tanpa POST put-in').toBe(0);
    await app.screenshot('AC03-02-pilih-folder-sesudah-enter');

    // Kembali -> Konfirmasi masih M, M2; mode Pilih tetap
    await bulk.locator('button.light', { hasText: PREV }).first().click();
    await expect(pickerRow(bulk, page, P + 'M'), 'Kembali: Konfirmasi masih M').toBeVisible();
    await expect(pickerRow(bulk, page, P + 'M2'), 'Kembali: Konfirmasi masih M2').toBeVisible();
    await expect(bulk.locator('.ant-modal-title'), 'Kembali: judul tetap').toHaveText(/^Pindahkan - 2 dipilih$/);
    await expect(page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first(), 'mode Pilih tetap (tombol Batal Pilih)').toBeVisible();
    await expect(rowOf(page, P + 'M').locator('input[type="checkbox"]').first(), 'M masih tercentang di halaman').toBeChecked();
    await expect(rowOf(page, P + 'M2').locator('input[type="checkbox"]').first(), 'M2 masih tercentang di halaman').toBeChecked();
    await app.screenshot('AC03-03-kembali-konfirmasi');

    // AC-4: tombol Cari (ikon filter -> Cari)
    await bulk.locator('button.ant-btn-primary', { hasText: NEXT }).first().click();
    await expect(modalInput(bulk)).toBeVisible();
    await app.settle();
    const m2 = calls.mark();
    const { resp: respP, overlay } = await typeAndCari(app, page, bulk, P + 'T');
    const resp2 = await respP;
    await app.settle();
    expect(resp2.status, 'AC-4: GET search 200').toBe(200);
    expect(searchOf(new URL(resp2.url)), 'AC-4: search = {"query":"0QA125-T"}').toBe(JSON.stringify({ query: P + 'T' }));
    expect(calls.searchGetsSince(m2).length, 'AC-4: tepat 1 GET search').toBe(1);
    await nav.expectNone('AC-4 tombol Cari di Pilih Folder');
    await expect(overlay, 'AC-4: popover menutup').toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
    await expectModalTableIs(bulk, page, resp2, 'AC-4 tabel');
    await stillPick('AC-4');
    expect(calls.postsSince(m2).length, 'AC-4: tanpa POST put-in').toBe(0);
    await app.screenshot('AC04-01-pilih-folder-sesudah-cari');

    await closeModalX(page);
    await exitSelectMode(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-5
  test('[default] ED-1125 AC-5 kedua modal: pilih T dulu lalu ketik + Enter di Cari = tanpa POST put-in, DB sama, T tetap terpilih, Pindahkan aktif', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    const snap = { A: prop('A'), M: prop('M'), M2: prop('M2'), T: prop('T') };
    await openArchive(app, page);
    const nav = await navTracker(page);

    // ---- single: ⋯ Pindahkan A
    const modal = await openMove(app, page, P + 'A');
    const tRadio = () => pickerRow(modal, page, P + 'T').locator('input[type="radio"]');
    await tRadio().check();
    await expect(tRadio()).toBeChecked();
    const pindah = modal.locator('button.ant-btn-primary', { hasText: MENU.move }).first();
    await expect(pindah, 'T dipilih: Pindahkan aktif').toBeEnabled();
    const m = calls.mark();
    const resp = await typeAndEnter(page, modalInput(modal), P + 'T');
    await app.settle();
    expect(resp.status).toBe(200);
    await expectModalTableIs(modal, page, resp, 'single: tabel');
    expect(calls.postsSince(m).length, 'single: tanpa POST put-in').toBe(0);
    await nav.expectNone('single: Enter sesudah memilih T');
    await expect(tRadio(), 'single: T tetap terpilih di hasil cari').toBeChecked();
    await expect(pindah, 'single: Pindahkan tetap aktif').toBeEnabled();
    await app.screenshot('AC05-01-single-T-tetap-terpilih');
    // pencarian lain (T tidak ada di tabel): pilihan tetap di modal -> Pindahkan tetap aktif
    const resp2 = await typeAndEnter(page, modalInput(modal), P + 'C');
    await app.settle();
    expect(resp2.status).toBe(200);
    await expect(pindah, 'single: pilihan T bertahan walau T tidak ada di hasil cari -> Pindahkan aktif').toBeEnabled();
    expect(calls.postsSince(m).length, 'single: tetap tanpa POST put-in').toBe(0);
    expect(prop('A'), 'single: DB baris A sama').toEqual(snap.A);
    expect(prop('T'), 'single: DB baris T sama').toEqual(snap.T);
    await closeModalBatal(page, modal);

    // ---- massal [M, M2]
    const bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await toPickFolder(app, page, bulk);
    const tRadioB = () => pickerRow(bulk, page, P + 'T').locator('input[type="radio"]');
    await tRadioB().check();
    const pindahB = bulk.locator('button.ant-btn-primary', { hasText: MENU.move }).first();
    await expect(pindahB, 'massal: T dipilih -> Pindahkan aktif').toBeEnabled();
    const m2 = calls.mark();
    const respB = await typeAndEnter(page, modalInput(bulk), P + 'T');
    await app.settle();
    expect(respB.status).toBe(200);
    await expectModalTableIs(bulk, page, respB, 'massal: tabel');
    expect(calls.postsSince(m2).length, 'massal: tanpa POST put-in').toBe(0);
    await nav.expectNone('massal: Enter sesudah memilih T');
    await expect(tRadioB(), 'massal: T tetap terpilih').toBeChecked();
    await expect(pindahB, 'massal: Pindahkan tetap aktif').toBeEnabled();
    await app.screenshot('AC05-02-massal-T-tetap-terpilih');
    expect(prop('M'), 'massal: DB M sama').toEqual(snap.M);
    expect(prop('M2'), 'massal: DB M2 sama').toEqual(snap.M2);
    expect(prop('T'), 'massal: DB T sama').toEqual(snap.T);
    await closeModalX(page);
    await exitSelectMode(page);
    expect(calls.all.posts.length, 'tidak ada POST put-in sama sekali di test ini').toBe(0);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-6
  test('[default] ED-1125 AC-6 hasil cari dipilih dengan aturan biasa: (a) A -> C = 400 ARCHIVE404 (toast id-ID, modal tetap), (b) M -> T = 200 (DB), (c) massal [M, M2] -> T = 200 (DB); dipulihkan', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    const f = ids();
    const calls = trace(page, monitor);
    const snap = { A: prop('A'), B: prop('B'), C: prop('C'), M: prop('M'), M2: prop('M2'), T: prop('T') };
    await openArchive(app, page);
    const nav = await navTracker(page);

    // ---- (a) A -> cari C -> Pindahkan = ditolak
    const modalA = await openMove(app, page, P + 'A');
    await typeAndEnter(page, modalInput(modalA), P + 'C');
    await app.settle();
    const rej = await selectTargetAndMove(modalA, page, P + 'C');
    expect(rej.status, '(a) POST put-in A -> C').toBe(400);
    expect(idsOfBody(rej.reqBody), '(a) body: id_archives = [A]').toEqual([f[P + 'A']]);
    expect(parentOfBody(rej.reqBody), '(a) body: id_archive_parent = C').toBe(f[P + 'C']);
    expect(JSON.stringify(rej.json), '(a) respons memuat ARCHIVE404').toContain('ARCHIVE404');
    expect(JSON.stringify(rej.json), '(a) respons memuat teks id-ID').toContain(MSG_404);
    await expect(toastWith(page, MSG_404).first(), '(a) toast ARCHIVE404 (id-ID) tampil').toBeVisible({ timeout: 10000 });
    expect(await shotToast(app, monitor, toastWith(page, MSG_404).first(), 'AC06a-toast-A-ke-C'), '(a) teks toast = pesan ARCHIVE404 id-ID').toContain(MSG_404);
    await app.screenshot('AC06-01-a-toast-ARCHIVE404');
    await expect(modalA, '(a) modal tetap terbuka').toBeVisible();
    await expect(modalA.locator('button.ant-btn-primary', { hasText: MENU.move }).first(), '(a) tombol Pindahkan aktif lagi (tidak macet loading)').toBeEnabled({ timeout: 10000 });
    await nav.expectNone('(a)');
    await closeModalBatal(page, modalA);
    expect(prop('A'), '(a) DB: A tidak pindah (baris identik)').toEqual(snap.A);
    expect(prop('B'), '(a) DB: B identik').toEqual(snap.B);
    expect(prop('C'), '(a) DB: C identik').toEqual(snap.C);
    await page.waitForTimeout(3500); // toast hilang

    // ---- (b) M -> cari T -> Pindahkan = 200
    const modalM = await openMove(app, page, P + 'M');
    await typeAndEnter(page, modalInput(modalM), P + 'T');
    await app.settle();
    const refresh = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
    const ok1 = await selectTargetAndMove(modalM, page, P + 'T');
    expect(ok1.status, '(b) POST put-in M -> T').toBe(200);
    expect(idsOfBody(ok1.reqBody), '(b) body: id_archives = [M]').toEqual([f[P + 'M']]);
    expect(parentOfBody(ok1.reqBody), '(b) body: id_archive_parent = T').toBe(f[P + 'T']);
    expect(JSON.stringify(ok1.json), '(b) respons sukses ARCHIVE204').toContain('ARCHIVE204');
    await refresh.catch(() => null);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '(b) modal menutup sesudah sukses').toHaveCount(0, { timeout: 10000 });
    await noErrorToast(page, '(b)');
    await app.screenshot('AC06-02-b-sukses-M-ke-T');
    const mMoved = prop('M');
    expect(mMoved.id_archive_parent, '(b) DB: M di bawah T').toBe(f[P + 'T']);
    expect(String(mMoved.history || ''), '(b) DB: history M memuat entri place/move').toMatch(/"action":"(place|move)"/);
    await app.settle();
    await expect(rowOf(page, P + 'M'), '(b) layar: M tidak lagi di root').toHaveCount(0);
    fx('restore');
    expect(prop('M'), '(b) M dipulihkan persis').toEqual(snap.M);
    await page.waitForTimeout(3500);

    // ---- (c) massal [M, M2] -> cari T -> Pindahkan = 200
    await openArchive(app, page);
    const bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await toPickFolder(app, page, bulk);
    await typeAndEnter(page, modalInput(bulk), P + 'T');
    await app.settle();
    const ok2 = await selectTargetAndMove(bulk, page, P + 'T');
    expect(ok2.status, '(c) POST put-in [M, M2] -> T').toBe(200);
    expect(idsOfBody(ok2.reqBody).slice().sort(), '(c) body: id_archives = [M, M2]').toEqual([f[P + 'M'], f[P + 'M2']].sort());
    expect(parentOfBody(ok2.reqBody), '(c) body: id_archive_parent = T').toBe(f[P + 'T']);
    expect(JSON.stringify(ok2.json), '(c) respons sukses ARCHIVE204').toContain('ARCHIVE204');
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), '(c) modal menutup sesudah sukses').toHaveCount(0, { timeout: 10000 });
    await noErrorToast(page, '(c)');
    await app.screenshot('AC06-03-c-massal-sukses');
    expect(prop('M').id_archive_parent, '(c) DB: M di bawah T').toBe(f[P + 'T']);
    expect(prop('M2').id_archive_parent, '(c) DB: M2 di bawah T').toBe(f[P + 'T']);
    await exitSelectMode(page);
    fx('restore');
    expect(prop('M'), '(c) M dipulihkan persis').toEqual(snap.M);
    expect(prop('M2'), '(c) M2 dipulihkan persis').toEqual(snap.M2);
    expect(prop('T'), '(c) T tidak berubah').toEqual(snap.T);

    expect(calls.all.posts.length, 'tepat 3 POST put-in (a, b, c)').toBe(3);
    expectRejections(monitor, 1, 'AC-6(a)');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-7
  test('[default] ED-1125 AC-7 picker di A > B: cari 0QA125-T = request tanpa idArchive, breadcrumb "Arsip", tabel = respons; reset (X) = root', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    const f = ids();
    await openArchive(app, page);
    const nav = await navTracker(page);
    const modal = await openMove(app, page, P + 'M');
    const crumb = () => modal.locator('.breadcrumbs').first();
    const crumbText = async () => ((await crumb().innerText()).replace(/\s+/g, ' ').trim());

    // navigasi di picker: A lalu B
    const inA = listResponse(page, (u) => folderParam(u) === f[P + 'A']);
    await pickerRow(modal, page, P + 'A').locator('td.cursor-pointer').first().click();
    await inA;
    await app.settle();
    const inB = listResponse(page, (u) => folderParam(u) === f[P + 'B']);
    await pickerRow(modal, page, P + 'B').locator('td.cursor-pointer').first().click();
    await inB;
    await app.settle();
    const crumbIn = await crumbText();
    monitor.note(`breadcrumb picker di A > B: "${crumbIn}"`);
    expect(crumbIn, 'breadcrumb picker memuat A dan B').toMatch(new RegExp(`${esc(P)}A.*${esc(P)}B`));
    await app.screenshot('AC07-01-picker-di-A-B');

    // cari T dari dalam B
    const m = calls.mark();
    const resp = await typeAndEnter(page, modalInput(modal), P + 'T');
    await app.settle();
    expect(resp.status, 'GET search 200').toBe(200);
    const u = new URL(resp.url);
    expect(searchOf(u), 'search = {"query":"0QA125-T"}').toBe(JSON.stringify({ query: P + 'T' }));
    expect(u.searchParams.get('idArchive'), 'tanpa idArchive').toBeNull();
    expect(u.searchParams.get('id_archive'), 'tanpa id_archive').toBeNull();
    expect(calls.searchGetsSince(m).length, 'tepat 1 GET search').toBe(1);
    await nav.expectNone('cari dari dalam B');
    const crumbAfter = await crumbText();
    monitor.note(`breadcrumb picker sesudah cari: "${crumbAfter}"`);
    expect(crumbAfter, 'breadcrumb picker = "Archive" saja').toMatch(/^(Arsip|Archive)$/);
    await expectModalTableIs(modal, page, resp, 'tabel modal');
    await expect(pickerRow(modal, page, P + 'T'), 'T tampil').toBeVisible();
    await app.screenshot('AC07-02-sesudah-cari-T');

    // reset (X) -> root
    const resetResp = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
    await modal.locator('.reset-filter').first().click();
    const root = await resetResp;
    await app.settle();
    expect(root.status, 'reset: GET root 200').toBe(200);
    expect(folderParam(new URL(root.url)), 'reset: tanpa idArchive').toBeFalsy();
    expect(searchOf(new URL(root.url)), 'reset: tanpa search').toBe('');
    expect(await crumbText(), 'breadcrumb picker sesudah reset = "Archive"').toMatch(/^(Arsip|Archive)$/);
    await expectModalTableIs(modal, page, root, 'tabel modal sesudah reset (root)');
    for (const n of ['A', 'T', 'M2', 'F']) await expect(pickerRow(modal, page, P + n), `root picker memuat ${P}${n}`).toBeVisible();
    await expect(modalInput(modal), 'teks Cari kosong sesudah reset').toHaveValue('');
    await nav.expectNone('reset');
    await app.screenshot('AC07-03-sesudah-reset-root');

    await closeModalX(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-8
  test('[default] ED-1125 AC-8 regresi Search halaman: Enter dan tombol Cari = GET search, 0 navigasi', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const nav = await navTracker(page);

    const m = calls.mark();
    const resp = await typeAndEnter(page, pageInput(page), P + 'C');
    await app.settle();
    expect(resp.status, 'Enter: GET search 200').toBe(200);
    expect(searchOf(new URL(resp.url)), 'Enter: search = {"query":"0QA125-C"}').toBe(JSON.stringify({ query: P + 'C' }));
    expect(new URL(resp.url).searchParams.get('page'), 'Enter: page=1').toBe('1');
    expect(calls.searchGetsSince(m).length, 'Enter: tepat 1 GET search').toBe(1);
    await nav.expectNone('Enter di Search halaman');
    await expect(rowOf(page, P + 'C'), 'halaman memuat C').toBeVisible();
    await expect(rowOf(page, P + 'T'), 'halaman tanpa T').toHaveCount(0);
    await app.screenshot('AC08-01-halaman-enter');
    await pageReset(page);

    // tombol Cari (ikon filter -> Cari)
    const input = pageInput(page);
    await input.click();
    await page.waitForTimeout(350);
    await page.keyboard.type(P + 'T', { delay: 40 });
    await page.locator('.archive-page .search-config').first().click();
    const overlay = page.locator('.archive-page .search-bar-overlay').first();
    await expect(overlay).not.toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
    await page.waitForTimeout(700);
    const m2 = calls.mark();
    const respP = searchResponse(page, P + 'T');
    await overlay.locator('button').filter({ hasText: /^(Cari|Search)$/ }).first().click();
    const resp2 = await respP;
    await app.settle();
    expect(resp2.status, 'Cari: GET search 200').toBe(200);
    expect(calls.searchGetsSince(m2).length, 'Cari: tepat 1 GET search').toBe(1);
    await nav.expectNone('tombol Cari Search halaman');
    await expect(rowOf(page, P + 'T'), 'halaman memuat T').toBeVisible();
    await expect(overlay, 'popover menutup').toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
    await app.screenshot('AC08-02-halaman-cari');
    await pageReset(page);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-9
  test('[default] ED-1125 AC-9 layar tanpa error: root, modal Pindahkan (folder & dokumen), pindah massal 2 langkah, sebelum/sesudah cari dan scan; desktop dan 390 px', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    const calls = trace(page, monitor);
    await openArchive(app, page);
    await app.screenshot('AC09-01-root-desktop');
    const nav = await navTracker(page);

    // modal Pindahkan folder (A): sebelum, sesudah cari, sesudah scan
    const modal = await openMove(app, page, P + 'A');
    await app.screenshot('AC09-02-modal-folder-sebelum-desktop');
    await typeAndEnter(page, modalInput(modal), P + 'C');
    await app.settle();
    await app.screenshot('AC09-03-modal-folder-sesudah-cari-desktop');
    await scan(page, P + 'T', modal.locator('.ant-modal-title'));
    await app.settle();
    await expect(modalInput(modal)).toHaveValue(P + 'T');
    await app.screenshot('AC09-04-modal-folder-sesudah-scan-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(800);
    await app.screenshot('AC09-05-modal-folder-390');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(500);
    await closeModalBatal(page, modal);

    // modal Pindahkan dokumen (D6 di F)
    await pageReset(page);
    const inF = await openFolder(page, P + 'F');
    expect(inF.status).toBe(200);
    await app.settle();
    await expect(rowOf(page, P + 'D6'), 'F memuat dokumen D6').toBeVisible();
    const modalD = await openMove(app, page, P + 'D6');
    await app.screenshot('AC09-06-modal-dokumen-sebelum-desktop');
    await typeAndEnter(page, modalInput(modalD), P + 'T');
    await app.settle();
    await app.screenshot('AC09-07-modal-dokumen-sesudah-cari-desktop');
    await scan(page, P + 'C', modalD.locator('.ant-modal-title'));
    await app.settle();
    await expect(modalInput(modalD)).toHaveValue(P + 'C');
    await app.screenshot('AC09-08-modal-dokumen-sesudah-scan-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(800);
    await app.screenshot('AC09-09-modal-dokumen-390');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(500);
    await closeModalX(page);

    // pindah massal: 2 langkah
    await pageReset(page);
    const back = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click().catch(() => null);
    await back.catch(() => null);
    await app.settle();
    const bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await app.screenshot('AC09-10-massal-konfirmasi-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(800);
    await app.screenshot('AC09-11-massal-konfirmasi-390');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(500);
    await toPickFolder(app, page, bulk);
    await app.screenshot('AC09-12-massal-pilih-folder-sebelum-desktop');
    await typeAndEnter(page, modalInput(bulk), P + 'T');
    await app.settle();
    await app.screenshot('AC09-13-massal-pilih-folder-sesudah-cari-desktop');
    await scan(page, P + 'C', bulk.locator('.ant-modal-title'));
    await app.settle();
    await expect(modalInput(bulk)).toHaveValue(P + 'C');
    await app.screenshot('AC09-14-massal-pilih-folder-sesudah-scan-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(800);
    await app.screenshot('AC09-15-massal-pilih-folder-390');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(500);
    await closeModalX(page);
    await exitSelectMode(page);

    await nav.expectNone('AC-9 seluruh alur');
    expect(calls.all.posts.length, 'alur baca-saja: tanpa POST put-in').toBe(0);
    await noErrorToast(page, 'AC-9');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-14
  test('[default] ED-1125 AC-14 modal Pindahkan A + scan 0QA125-T: halaman utuh, tepat 1 GET search (picker), Cari picker berisi kode, tabel modal = respons, judul dan pilihan tetap', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const before = await pageState(page);
    const nav = await navTracker(page);
    const modal = await openMove(app, page, P + 'A');
    // pilih M dulu (pilihan harus bertahan)
    await pickerRow(modal, page, P + 'M').locator('input[type="radio"]').check();
    const pindah = modal.locator('button.ant-btn-primary', { hasText: MENU.move }).first();
    await expect(pindah).toBeEnabled();

    const m = calls.mark();
    const respP = searchResponse(page, P + 'T');
    await scan(page, P + 'T', modal.locator('.ant-modal-title'));
    const resp = await respP;
    await app.settle();
    await page.waitForTimeout(1500);
    expect(resp.status, 'GET search 200').toBe(200);
    expect(calls.searchGetsSince(m).length, 'tepat 1 GET search (dari picker)').toBe(1);
    expect(searchOf(new URL(resp.url)), 'search = {"query":"0QA125-T"}').toBe(JSON.stringify({ query: P + 'T' }));
    expect(calls.postsSince(m).length, 'tanpa POST put-in').toBe(0);
    await nav.expectNone('scan di modal Pindahkan');
    expect(await pageState(page), 'halaman utuh (baris, breadcrumb, teks Cari halaman kosong)').toEqual(before);
    expect((await pageState(page)).search, 'teks Cari halaman tetap kosong').toBe('');
    await expect(modalInput(modal), 'Cari picker berisi kode').toHaveValue(P + 'T');
    await expectModalTableIs(modal, page, resp, 'tabel modal');
    await expect(modal.locator('.ant-modal-title'), 'judul modal tetap').toHaveText(`Pindahkan - ${P}A`);
    await expect(pindah, 'pilihan modal tetap (Pindahkan aktif, M dipilih)').toBeEnabled();
    await app.screenshot('AC14-01-sesudah-scan-di-modal');

    await closeModalBatal(page, modal);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-15
  test('[default] ED-1125 AC-15 pindah massal [M, M2]: scan di Konfirmasi = halaman utuh, langkah dan item tetap; scan di Pilih Folder = seperti AC-14', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const before = await pageState(page);
    const nav = await navTracker(page);
    const bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    const before2 = await pageState(page);

    // scan di Konfirmasi (Pilih Folder belum pernah dibuka): scanner halaman mati, picker belum ada -> tidak terjadi apa-apa
    const m = calls.mark();
    await scan(page, P + 'T', bulk.locator('.ant-modal-title'));
    await page.waitForTimeout(2500);
    expect(calls.getsSince(m).length, 'Konfirmasi: tanpa GET archives apa pun').toBe(0);
    await nav.expectNone('scan di Konfirmasi');
    expect(await pageState(page), 'Konfirmasi: halaman utuh (sama seperti sebelum scan)').toEqual(before2);
    expect((await pageState(page)).search, 'Konfirmasi: teks Cari halaman tetap kosong').toBe('');
    await expect(bulk.locator('.ant-modal-title'), 'Konfirmasi: judul tetap').toHaveText(/^Pindahkan - 2 dipilih$/);
    await expect(pickerRow(bulk, page, P + 'M'), 'Konfirmasi: item M tetap').toBeVisible();
    await expect(pickerRow(bulk, page, P + 'M2'), 'Konfirmasi: item M2 tetap').toBeVisible();
    await expect(bulk.locator('button.ant-btn-primary', { hasText: NEXT }).first(), 'Konfirmasi: tetap di langkah Konfirmasi (tombol Lanjut)').toBeVisible();
    await app.screenshot('AC15-01-scan-di-konfirmasi');

    // Lanjut -> Pilih Folder; pilih M dulu? (M2/M adalah sumber; pilih T yang ada di root) lalu scan
    await toPickFolder(app, page, bulk);
    await pickerRow(bulk, page, P + 'T').locator('input[type="radio"]').check();
    const pindah = bulk.locator('button.ant-btn-primary', { hasText: MENU.move }).first();
    await expect(pindah).toBeEnabled();
    const m2 = calls.mark();
    const respP = searchResponse(page, P + 'A');
    await scan(page, P + 'A', bulk.locator('.ant-modal-title'));
    const resp = await respP;
    await app.settle();
    await page.waitForTimeout(1500);
    expect(resp.status, 'Pilih Folder: GET search 200').toBe(200);
    expect(calls.searchGetsSince(m2).length, 'Pilih Folder: tepat 1 GET search (dari picker)').toBe(1);
    expect(calls.postsSince(m2).length, 'Pilih Folder: tanpa POST put-in').toBe(0);
    await nav.expectNone('scan di Pilih Folder');
    expect(await pageState(page), 'Pilih Folder: halaman utuh').toEqual(before2);
    await expect(modalInput(bulk), 'Pilih Folder: Cari picker berisi kode').toHaveValue(P + 'A');
    await expectModalTableIs(bulk, page, resp, 'Pilih Folder: tabel modal');
    await expect(bulk.locator('.ant-modal-title'), 'Pilih Folder: judul tetap').toHaveText(/^Pindahkan - 2 dipilih$/);
    await expect(pindah, 'Pilih Folder: pilihan T tetap (Pindahkan aktif)').toBeEnabled();
    await app.screenshot('AC15-02-scan-di-pilih-folder');

    // catatan (dev, task 026): Sebelum -> Konfirmasi dengan Pilih Folder pernah dibuka: picker tersembunyi masih ter-mount.
    await bulk.locator('button.light', { hasText: PREV }).first().click();
    await expect(pickerRow(bulk, page, P + 'M'), 'Sebelum: Konfirmasi memuat M').toBeVisible();
    const m3 = calls.mark();
    await scan(page, P + 'F', bulk.locator('.ant-modal-title'));
    await page.waitForTimeout(2500);
    const hidden = calls.searchGetsSince(m3);
    monitor.note(`CATATAN scan di Konfirmasi sesudah Pilih Folder pernah dibuka: GET search=${hidden.length} (${JSON.stringify(hidden.map((g) => g.query))}); halaman utuh dan langkah tetap dinilai di bawah`);
    expect(await pageState(page), 'Sebelum -> scan: halaman utuh').toEqual(before2);
    expect((await pageState(page)).search, 'Sebelum -> scan: teks Cari halaman tetap kosong').toBe('');
    await expect(bulk.locator('.ant-modal-title'), 'Sebelum -> scan: judul tetap').toHaveText(/^Pindahkan - 2 dipilih$/);
    await expect(pickerRow(bulk, page, P + 'M2'), 'Sebelum -> scan: item M2 tetap').toBeVisible();
    await expect(bulk.locator('button.ant-btn-primary', { hasText: NEXT }).first(), 'Sebelum -> scan: tetap di Konfirmasi').toBeVisible();
    expect(before).toBeTruthy();

    await closeModalX(page);
    await exitSelectMode(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-16
  test('[default] ED-1125 AC-16 sesudah modal ditutup lewat Batal, ✕ dan sukses (single & massal): scan mencari di tabel halaman (GET search, Cari halaman berisi kode)', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    const calls = trace(page, monitor);
    const snap = { M: prop('M'), M2: prop('M2'), T: prop('T') };
    await openArchive(app, page);
    const nav = await navTracker(page);

    const scanPage = async (label, code) => {
      const m = calls.mark();
      const respP = searchResponse(page, code);
      await scan(page, code, null);
      const resp = await respP;
      await app.settle();
      await page.waitForTimeout(1200);
      expect(resp.status, `${label}: GET search 200`).toBe(200);
      expect(calls.searchGetsSince(m).length, `${label}: tepat 1 GET search (scanner halaman aktif lagi, picker modal sudah tidak ada)`).toBe(1);
      const st = await pageState(page);
      expect(st.search, `${label}: Cari halaman berisi kode`).toBe(code);
      expect(st.rows.length, `${label}: tabel halaman = respons (jumlah)`).toBe(rowsOf(resp).length);
      await expect(rowOf(page, code), `${label}: ${code} tampil di tabel halaman`).toBeVisible();
      await app.screenshot(`AC16-${label.replace(/[^A-Za-z0-9]+/g, '-')}`);
      await pageReset(page);
    };

    // ---- single: Batal, ✕, sukses
    let modal = await openMove(app, page, P + 'M');
    await closeModalBatal(page, modal);
    await scanPage('single-Batal', P + 'T');

    modal = await openMove(app, page, P + 'M');
    await closeModalX(page);
    await scanPage('single-X', P + 'C');

    modal = await openMove(app, page, P + 'M');
    const refresh = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
    const ok = await selectTargetAndMove(modal, page, P + 'T');
    expect(ok.status, 'single sukses: POST put-in M -> T').toBe(200);
    await refresh.catch(() => null);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'single sukses: modal menutup').toHaveCount(0, { timeout: 10000 });
    await app.settle();
    await page.waitForTimeout(3500);
    await scanPage('single-sukses', P + 'A');
    fx('restore');
    await nav.expectNone('AC-16 bagian single');

    // ---- massal: Batal (langkah Konfirmasi), ✕ (Pilih Folder), sukses (halaman dimuat ulang oleh test sesudah restore: penanda dibuat lagi)
    await openArchive(app, page);
    const nav2 = await navTracker(page);
    let bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await closeModalBatal(page, bulk);
    await exitSelectMode(page);
    await scanPage('massal-Batal', P + 'T');

    bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await toPickFolder(app, page, bulk);
    await closeModalX(page);
    await exitSelectMode(page);
    await scanPage('massal-X', P + 'C');

    bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await toPickFolder(app, page, bulk);
    const okB = await selectTargetAndMove(bulk, page, P + 'T');
    expect(okB.status, 'massal sukses: POST put-in [M, M2] -> T').toBe(200);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'massal sukses: modal menutup').toHaveCount(0, { timeout: 10000 });
    await app.settle();
    await page.waitForTimeout(3500);
    await scanPage('massal-sukses', P + 'A');
    await exitSelectMode(page);
    fx('restore');
    expect(prop('M'), 'M dipulihkan').toEqual(snap.M);
    expect(prop('M2'), 'M2 dipulihkan').toEqual(snap.M2);
    expect(prop('T'), 'T tidak berubah').toEqual(snap.T);

    await nav2.expectNone('AC-16 bagian massal');
    await noErrorToast(page, 'AC-16');
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-13 (pendukung)
  // AC-13 = MANUAL (Gate 2: developer menilai tampilan). Test ini hanya MENCATAT metrik tata letak modal (kotak elemen + gaya terhitung) sebagai
  // catatan laporan, supaya bisa dibandingkan dengan build tanpa perbaikan (FE_STAGING_DIR=<build HEAD>): `<form class="ant-form-vertical">`
  // pembungkus hilang di modal. Tidak ada asersi tampilan (tidak pernah FAIL karena tampilan).
  test('[default] ED-1125 AC-13 pendukung: metrik tata letak modal Pindahkan dan pindah massal (dicatat, bukan asersi tampilan)', async ({ app, page, monitor }) => {
    await openArchive(app, page);
    const measure = (modal, label) =>
      modal.evaluate((root, name) => {
        const pick = (sel) => {
          const el = root.querySelector(sel);
          if (!el) return null;
          const r = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          return {
            x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height),
            fs: cs.fontSize, lh: cs.lineHeight, ff: cs.fontFamily.slice(0, 24), mt: cs.marginTop, mb: cs.marginBottom, pt: cs.paddingTop, pb: cs.paddingBottom,
          };
        };
        const sels = ['.ant-modal-content', '.ant-modal-header', '.ant-modal-body', '.ant-steps', '.search-bar', '.breadcrumbs', '.ant-table-thead', '.ant-table-row', '.ant-pagination', 'button.ant-btn-primary'];
        const out = { label: name, formEls: root.querySelectorAll('form').length, wrapperFormClass: (root.querySelector('.ant-modal-body > form') || {}).className || null };
        sels.forEach((sel) => {
          out[sel] = pick(sel);
        });
        return out;
      }, label);
    const note = async (modal, label) => {
      const m = await measure(modal, label);
      monitor.note(`METRIK ${label}: ${JSON.stringify(m)}`);
    };

    const modal = await openMove(app, page, P + 'A');
    await app.screenshot('AC13p-01-modal-pindahkan');
    await note(modal, 'single');
    await modal.locator('.search-config').first().click();
    await page.waitForTimeout(900);
    await app.screenshot('AC13p-02-modal-pindahkan-popover-filter');
    await note(modal, 'single-popover');
    await closeModalX(page);

    const bulk = await openBulk(app, page, [P + 'M', P + 'M2']);
    await app.screenshot('AC13p-03-massal-konfirmasi');
    await note(bulk, 'massal-konfirmasi');
    await toPickFolder(app, page, bulk);
    await app.screenshot('AC13p-04-massal-pilih-folder');
    await note(bulk, 'massal-pilih-folder');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(800);
    await app.screenshot('AC13p-05-massal-pilih-folder-390');
    await note(bulk, 'massal-pilih-folder-390');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(500);
    await closeModalX(page);
    await exitSelectMode(page);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------------- AC-17
  test('[default] ED-1125 AC-17 regresi Letakkan Dokumen: scan -> langkah review berisi kode, halaman utuh; modal ditutup -> scan halaman mencari', async ({ app, page, monitor }) => {
    const calls = trace(page, monitor);
    await openArchive(app, page);
    await openFolder(page, P + 'A');
    await app.settle();
    const before = await pageState(page);
    const nav = await navTracker(page);

    const plus = page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
    await plus.click();
    const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    await menu.waitFor({ state: 'visible', timeout: 10000 });
    await menu.locator('li.ant-dropdown-menu-item', { hasText: /^(Letakkan Dokumen|Store Document)$/ }).first().click();
    const modal = visibleModal(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC17-01-modal-letakkan-scanner');

    const code = `${P}DOC17`;
    const m = calls.mark();
    await scan(page, code, modal.locator('.ant-modal-title'));
    await page.waitForTimeout(2000);
    await expect(modal.getByText(code, { exact: false }).first(), 'langkah review memuat kode hasil scan').toBeVisible({ timeout: 10000 });
    expect(calls.searchGetsSince(m).length, 'tanpa GET search saat modal Letakkan terbuka').toBe(0);
    expect(calls.getsSince(m).length, 'tanpa GET archives apa pun').toBe(0);
    await nav.expectNone('scan di Letakkan Dokumen');
    expect(await pageState(page), 'halaman utuh').toEqual(before);
    expect((await pageState(page)).search, 'Cari halaman tetap kosong').toBe('');
    await app.screenshot('AC17-02-review-berisi-kode');

    // ✕ di langkah review = kembali ke langkah scanner (perilaku lama `onCloseAdd`), ✕ kedua menutup modal
    await visibleModal(page).locator('.ant-modal-close').click();
    await page.waitForTimeout(800);
    await expect(visibleModal(page), 'sesudah ✕ pertama di review: modal masih terbuka (kembali ke scanner)').toBeVisible();
    await app.screenshot('AC17-02b-sesudah-x-pertama-kembali-ke-scanner');
    // pengamatan di luar item (pra-ada): `onCloseAdd` menyalakan scanner halaman walau modal masih terbuka di langkah scanner
    const mObs = calls.mark();
    await scan(page, `${P}DOC17B`, visibleModal(page).locator('.ant-modal-title'));
    await page.waitForTimeout(2000);
    monitor.note(`PENGAMATAN pra-ada (bukan bagian ED-1125): scan di langkah scanner Letakkan Dokumen sesudah ✕ pertama (modal masih terbuka): GET search=${JSON.stringify(calls.searchGetsSince(mObs).map((g) => g.query))}`);
    while ((await page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').count()) > 0) {
      await visibleModal(page).locator('.ant-modal-close').click();
      await page.waitForTimeout(800);
    }
    await page.waitForTimeout(500);
    await pageReset(page);
    const m2 = calls.mark();
    const respP = searchResponse(page, code);
    await scan(page, code, null);
    const resp = await respP;
    await app.settle();
    await page.waitForTimeout(1200);
    expect(resp.status, 'sesudah tutup: GET search 200').toBe(200);
    expect(calls.searchGetsSince(m2).length, 'sesudah tutup: tepat 1 GET search').toBe(1);
    expect((await pageState(page)).search, 'sesudah tutup: Cari halaman berisi kode').toBe(code);
    expect(calls.all.posts.length, 'tanpa POST put-in').toBe(0);
    await app.screenshot('AC17-03-sesudah-tutup-scan-halaman');
    await pageReset(page);

    noServerError(monitor);
    monitor.check();
  });
});
