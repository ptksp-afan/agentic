'use strict';

/**
 * ED-1069 - Archive: celah validasi pindah/rename folder & 500 select lokasi / transaksi terkait. Bug BE saja; FE tidak berubah,
 * jadi e2e membuktikan dari browser: BE menolak pindah ke diri sendiri/turunan (toast ARCHIVE404 id-ID), alur sah tetap sukses,
 * dan layar yang memanggil endpoint yang diperbaiki (select Lokasi, Show Related Transaction) terbuka tanpa error.
 *
 *   AC-13  : ⋯ Pindahkan A -> (Search) C -> Pindahkan -> toast ARCHIVE404, modal tetap, A tidak pindah; folder sah -> sukses.
 *            Pindah massal [A, X] ke C -> toast sama; pindah massal sah [M, M2] -> T sukses.
 *            CATATAN Search di dalam modal Pindahkan / pindah massal TIDAK bisa dipakai: Enter di kolom Cari maupun tombol "Cari"
 *            popover memicu submit form HTML asli (`formSubmissionGet` ke "/?") dan memuat ulang aplikasi (form Search bersarang di
 *            form modal; bug FE di luar item, dikarakterisasi di test terakhir). Pencarian pada AC-13 diganti di tingkat jaringan:
 *            GET archives pertama milik picker dialihkan ke GET archives?search={"query":...} (respons BE ASLI), sisanya (pilih
 *            baris, POST put-in, respons, toast) asli dari FE dan BE.
 *   AC-14  : tanpa error konsol/toast: Archive root & folder (A > B > C, breadcrumbs), Show Related Transaction di F,
 *            drawer Detail (select Lokasi), modal Pindahkan & pindah massal.
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA69E-".
 * Mutasi hanya pada data fixture; alur pindah sukses dipulihkan lewat `fixture.php restore`, teardown membuang semuanya.
 * Profil: [default] = QA_USER (role 3, bahasa ID). Jalankan dengan --workers 1:
 *   scripts/e2e/run.sh ED-1069 --profile default --workers 1
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const SHOW_RE = /\/document-archive\/archives\/[^/?]+(\?|$)/;
const PUTIN_RE = /\/document-archive\/documents\/put-in/;
const LOC_RE = /\/select\/document-archive\/archive\/locations(\?|$)/;
const P = '0QA69E-';
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

const listResponse = (page, pred) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 })
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];
const searchOf = (u) => u.searchParams.get('search') || '';

/** Baris tabel utama (bukan tabel di modal) dengan nama tepat. */
const rowOf = (page, name) =>
  page
    .locator('.archive-page .ant-table-row')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .first();

/** Baris di dalam modal/picker: dicocokkan lewat sel nama (teks sel = nama persis). */
const pickerRow = (scope, page, name) =>
  scope
    .locator('.ant-table-row')
    .filter({ has: page.locator('td').filter({ hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) })
    .first();

const MENU = { info: /^Info$/, view: /^(Lihat|View)$/, move: /^(Pindahkan|Move)$/, del: /^(Hapus|Delete)$/ };

async function rowMenuItems(page, name) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  const btn = row.locator('button.btn-action');
  expect(await btn.count(), `baris ${name} punya tombol ⋯`).toBeGreaterThan(0);
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());
  return { items, menu };
}

async function pickRowMenu(page, name, itemRegex) {
  const { menu, items } = await rowMenuItems(page, name);
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
  return items;
}

const drawer = (page) => page.locator('.ant-drawer-open').last();
const visibleModal = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();

async function closeTopLayer(page) {
  const closer = page.locator('.ant-drawer-open .ant-drawer-close, .ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last();
  if (await closer.count()) await closer.click();
  await page.waitForTimeout(700);
}

/** Buka layar Archive (muat ulang penuh) dan kembalikan respons list root. */
async function openArchive(app, page) {
  const rootResp = listResponse(page, (u) => !folderParam(u));
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  const root = await rootResp;
  expect(root.status, 'GET archives (root)').toBe(200);
  await app.settle();
  return root;
}

/** Buka folder dari daftar (klik sel nama) dan tunggu GET archives?id_archive. */
async function openFolder(page, name) {
  const resp = listResponse(page, (u) => !!folderParam(u));
  await rowOf(page, name).locator('td.cursor-pointer').first().click();
  return resp;
}

/** Catat setiap panggilan API Archive (status, metode, URL tanpa host) sebagai catatan laporan. */
function traceApi(page, monitor) {
  page.on('response', (r) => {
    const u = r.url();
    if (/\/document-archive\/|\/select\/document-archive\//.test(u)) {
      monitor.note(`API ${r.status()} ${r.request().method()} ${decodeURIComponent(u.replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 220)}`);
    }
  });
}

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  expect(api.length, 'ada panggilan API v5 tercatat (monitor aktif)').toBeGreaterThan(0);
}

/** Alur non-simpan: tanpa toast apa pun. */
const noToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-notice, .ant-notification-notice'), `${label}: tanpa toast/notifikasi`).toHaveCount(0);
};

/** Tanpa toast galat/peringatan (toast sukses sah). */
const noErrorToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-error, .ant-message-warning, .ant-notification-notice-error, .ant-notification-notice-warning'), `${label}: tanpa toast galat/peringatan`).toHaveCount(0);
};

/**
 * Keluarkan dari monitor hanya penolakan 400 put-in yang diharapkan (respons + log konsol axios), dan pastikan jumlahnya.
 * Error lain tetap menggagalkan test.
 */
function expectRejections(monitor, minimum, label) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === 400 && PUTIN_RE.test(e.url)) || (e.kind === 'console' && /Request failed with status code 400/.test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  const responses = dropped.filter((e) => e.kind === 'response').length;
  expect(responses, `${label}: ${minimum}x 400 POST documents/put-in tercatat (diharapkan)`).toBeGreaterThanOrEqual(minimum);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan (${responses} respons 400 put-in + log konsol axios)`);
}

/**
 * Pengganti Search di dalam modal (lihat catatan di kepala berkas): pasang pengalih jaringan SEBELUM modal dibuka. GET archives pertama
 * tanpa id_archive/search sesudahnya (= tabel picker) dialihkan ke GET archives?search={"query":<query>}&page=1 lewat `route.fetch({url})`
 * - respons asli BE diteruskan apa adanya ke FE. `done()` melepas pengalih dan mengembalikan true bila terpakai.
 */
async function armPickerSearch(page, query) {
  let used = false;
  const handler = async (route) => {
    const req = route.request();
    if (used || req.method() !== 'GET') return route.fallback();
    const u = new URL(req.url());
    if (folderParam(u) || u.searchParams.get('search')) return route.fallback();
    used = true;
    const target = new URL(req.url());
    target.searchParams.set('search', JSON.stringify({ query }));
    target.searchParams.set('page', '1');
    const resp = await route.fetch({ url: target.toString() });
    return route.fulfill({ response: resp });
  };
  await page.route(LIST_RE, handler);

  return {
    done: async () => {
      await page.unroute(LIST_RE, handler);
      return used;
    },
  };
}

/** Pilih baris tujuan (radio) dan klik tombol Pindahkan; kembalikan {status, reqBody, json} dari POST documents/put-in. */
async function selectTargetAndMove(scope, page, targetName) {
  const row = pickerRow(scope, page, targetName);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  await row.locator('input[type="radio"]').check();
  const btn = scope.locator('button.ant-btn-primary', { hasText: /^(Pindahkan|Move)$/ }).first();
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

/** Toast (message/notification) yang memuat teks tertentu. */
const toastWith = (page, text) => page.locator('.ant-message-notice, .ant-notification-notice').filter({ hasText: text });

/** Tangkap toast sebagai gambar sendiri (screenshot halaman penuh sering sesudah toast hilang) + catat teksnya. */
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

/** Keluar dari mode Pilih (konfirmasi bila ada pilihan). */
async function exitSelectMode(page) {
  const exit = page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first();
  if ((await exit.count()) === 0) return;
  await exit.click();
  const confirm = page.locator('.ant-modal-confirm').last();
  if (await confirm.count()) {
    await confirm.locator('button.ant-btn-primary').click();
  }
  await page.waitForTimeout(600);
}

// ====================================================================================================================
test.describe('ED-1069 validasi pindah folder (e2e)', () => {
  // setiap test mulai dari keadaan fixture awal (test yang gagal di tengah alur pindah sukses tidak boleh mengotori test berikutnya)
  test.beforeEach(() => {
    fx('restore');
  });
  test.afterEach(() => {
    fx('restore');
  });

  test('[default] ED-1069 AC-13 ⋯ Pindahkan A -> Search C -> Pindahkan: toast ARCHIVE404 (id-ID), modal tetap, A tidak pindah; folder sah -> sukses', async ({ app, page, monitor, session }) => {
    const f = ids();
    traceApi(page, monitor);
    expect(JSON.parse(fx('lang')).language, 'prasyarat: bahasa QA_USER = ID (teks toast id-ID)').toBe('ID');
    monitor.note(`DB sesi ${session.dbName}; fixture: A=${f[P + 'A']} B=${f[P + 'B']} C=${f[P + 'C']} T=${f[P + 'T']} M=${f[P + 'M']}`);
    const aBefore = prop('A');
    const bBefore = prop('B');
    expect(aBefore.id_archive_parent, 'prasyarat: A di root').toBeNull();
    expect(bBefore.id_archive_parent, 'prasyarat: B di bawah A').toBe(f[P + 'A']);

    const root = await openArchive(app, page);
    const names = rowsOf(root).map(rowName);
    for (const n of ['A', 'X', 'T', 'M', 'M2', 'F']) expect(names, `root memuat ${P}${n}`).toContain(P + n);
    await app.screenshot('AC13-01-root');

    // ---- 1. ⋯ Pindahkan A -> modal -> (Search) C: picker memuat hasil pencarian C (respons BE asli, lihat armPickerSearch)
    const arm = await armPickerSearch(page, P + 'C');
    const items = await pickRowMenu(page, P + 'A', MENU.move);
    monitor.note(`menu baris A: ${items.join(' | ')}`);
    const modal = visibleModal(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await pickerRow(modal, page, P + 'C').waitFor({ state: 'visible', timeout: 30000 });
    expect(await arm.done(), 'pengalih Search terpakai (GET picker dialihkan ke search C)').toBe(true);
    await app.settle();
    await expect(modal.locator('.ant-modal-title'), 'judul modal memuat nama A').toContainText(P + 'A');
    await expect(pickerRow(modal, page, P + 'A'), 'hasil search "0QA69E-C" tidak memuat A').toHaveCount(0);
    expect(await pickerRow(modal, page, P + 'C').locator('input[type="radio"]').isDisabled(), 'C (turunan A) bisa dipilih di picker: FE tidak menyaring turunan').toBe(false);
    await app.screenshot('AC13-02-modal-hasil-search-C');

    // ---- 2. pilih C (turunan A) -> Pindahkan -> ditolak BE
    const rej1 = await selectTargetAndMove(modal, page, P + 'C');
    expect(rej1.status, 'POST documents/put-in A -> C').toBe(400);
    expect(idsOfBody(rej1.reqBody), 'body: id_archives = [A]').toEqual([f[P + 'A']]);
    expect(parentOfBody(rej1.reqBody), 'body: id_archive_parent = C').toBe(f[P + 'C']);
    expect(JSON.stringify(rej1.json), 'respons memuat kode ARCHIVE404').toContain('ARCHIVE404');
    expect(JSON.stringify(rej1.json), 'respons memuat teks id-ID').toContain(MSG_404);
    await expect(toastWith(page, MSG_404).first(), 'toast ARCHIVE404 (id-ID) tampil').toBeVisible({ timeout: 10000 });
    expect(await shotToast(app, monitor, toastWith(page, MSG_404).first(), 'AC13-03a-toast-A-ke-C'), 'teks toast = pesan ARCHIVE404 id-ID').toContain(MSG_404);
    await app.screenshot('AC13-03-toast-ARCHIVE404-A-ke-C');
    await expect(modal, 'modal tetap terbuka sesudah penolakan').toBeVisible();
    await expect(pickerRow(modal, page, P + 'C').locator('input[type="radio"]'), 'pilihan C tetap (modal tidak di-reset)').toBeChecked();
    await expect(modal.locator('button.ant-btn-primary', { hasText: /^(Pindahkan|Move)$/ }).first(), 'tombol Pindahkan kembali aktif (tidak macet loading)').toBeEnabled({ timeout: 10000 });

    // ---- 3. anak langsung (B) pun ditolak: modal dibuka ulang dengan hasil search B
    await closeTopLayer(page);
    await expect(visibleModal(page)).toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(3500); // toast pertama hilang
    const armB = await armPickerSearch(page, P + 'B');
    await pickRowMenu(page, P + 'A', MENU.move);
    const modalB = visibleModal(page);
    await modalB.waitFor({ state: 'visible', timeout: 15000 });
    await pickerRow(modalB, page, P + 'B').waitFor({ state: 'visible', timeout: 30000 });
    expect(await armB.done(), 'pengalih Search B terpakai').toBe(true);
    const rej2 = await selectTargetAndMove(modalB, page, P + 'B');
    expect(rej2.status, 'POST documents/put-in A -> B').toBe(400);
    expect(JSON.stringify(rej2.json), 'respons memuat ARCHIVE404').toContain('ARCHIVE404');
    await expect(toastWith(page, MSG_404).first(), 'toast ARCHIVE404 (A -> B) tampil').toBeVisible({ timeout: 10000 });
    await shotToast(app, monitor, toastWith(page, MSG_404).first(), 'AC13-04a-toast-A-ke-B');
    await app.screenshot('AC13-04-toast-ARCHIVE404-A-ke-B');
    await expect(modalB, 'modal tetap terbuka').toBeVisible();

    // ---- 4. tutup modal: A tidak pindah (DB + layar)
    await closeTopLayer(page);
    await expect(visibleModal(page)).toHaveCount(0, { timeout: 10000 });
    const aAfter = prop('A');
    expect(aAfter, 'baris A di DB identik (induk, history, updated_at)').toEqual(aBefore);
    expect(prop('B'), 'baris B di DB identik').toEqual(bBefore);
    expect(prop('C').id_archive_parent, 'C tetap di bawah B').toBe(f[P + 'B']);
    await expect(rowOf(page, P + 'A'), 'A masih di root (layar)').toBeVisible();

    // ---- 5. folder sah: M -> T sukses (T ada di daftar root picker, tanpa Search), lalu pulihkan
    await page.waitForTimeout(2500);
    const mBefore = prop('M');
    expect(mBefore.id_archive_parent, 'prasyarat: M di root').toBeNull();
    const l2 = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, P + 'M', MENU.move);
    await l2;
    const modal2 = visibleModal(page);
    await modal2.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    const refresh = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
    const ok1 = await selectTargetAndMove(modal2, page, P + 'T');
    expect(ok1.status, 'POST documents/put-in M -> T (sah)').toBe(200);
    expect(JSON.stringify(ok1.json), 'respons sukses memuat ARCHIVE204').toContain('ARCHIVE204');
    await refresh.catch(() => null);
    await expect(visibleModal(page), 'modal menutup sesudah sukses').toHaveCount(0, { timeout: 10000 });
    await app.screenshot('AC13-05-sukses-M-ke-T');
    await noErrorToast(page, 'pindah sah M -> T');
    const mMoved = prop('M');
    expect(mMoved.id_archive_parent, 'DB: M kini di bawah T').toBe(f[P + 'T']);
    expect(String(mMoved.history || ''), 'DB: history M memuat entri place (root -> folder)').toMatch(/"action":"(place|move)"/);
    await app.settle();
    await expect(rowOf(page, P + 'M'), 'layar: M tidak lagi di root').toHaveCount(0);
    // T memuat M
    const inT = await openFolder(page, P + 'T');
    expect(inT.status, 'GET archives?id_archive=T').toBe(200);
    expect(rowsOf(inT).map(rowName), 'isi T memuat M').toContain(P + 'M');
    await app.screenshot('AC13-06-isi-T-memuat-M');

    // pulihkan ke keadaan awal (fixture) dan pastikan
    fx('restore');
    expect(prop('M'), 'M dipulihkan persis').toEqual(mBefore);
    expect(prop('T').id_archive_parent, 'T tetap di root').toBeNull();

    expectRejections(monitor, 2, 'AC-13 satu folder');
    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1069 AC-13 pindah massal [A, X] -> C: toast ARCHIVE404, modal tetap, tidak ada yang pindah; massal sah [M, M2] -> T sukses', async ({ app, page, monitor, session }) => {
    const f = ids();
    traceApi(page, monitor);
    monitor.note(`DB sesi ${session.dbName}`);
    const before = { A: prop('A'), X: prop('X'), M: prop('M'), M2: prop('M2'), T: prop('T') };
    await openArchive(app, page);

    // ---- pilih A dan X
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await rowOf(page, P + 'A').locator('input[type="checkbox"]').first().check();
    await rowOf(page, P + 'X').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = visibleModal(page);
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(bulk.locator('.ant-modal-title'), 'judul: Pindahkan - 2 dipilih').toContainText(/2/);
    await expect(pickerRow(bulk, page, P + 'A'), 'langkah konfirmasi memuat A').toBeVisible();
    await expect(pickerRow(bulk, page, P + 'X'), 'langkah konfirmasi memuat X').toBeVisible();
    await app.screenshot('AC13-07-massal-konfirmasi-A-X');

    const armBulk = await armPickerSearch(page, P + 'C');
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Lanjut|Next)$/ }).first().click();
    await pickerRow(bulk, page, P + 'C').waitFor({ state: 'visible', timeout: 30000 });
    expect(await armBulk.done(), 'pengalih Search C (massal) terpakai').toBe(true);
    await app.settle();
    await app.screenshot('AC13-08-massal-hasil-search-C');

    const rej = await selectTargetAndMove(bulk, page, P + 'C');
    expect(rej.status, 'POST documents/put-in [A, X] -> C').toBe(400);
    expect(idsOfBody(rej.reqBody).slice().sort(), 'body: id_archives = [A, X]').toEqual([f[P + 'A'], f[P + 'X']].sort());
    expect(parentOfBody(rej.reqBody), 'body: id_archive_parent = C').toBe(f[P + 'C']);
    expect(JSON.stringify(rej.json), 'respons memuat ARCHIVE404').toContain('ARCHIVE404');
    await expect(toastWith(page, MSG_404).first(), 'toast ARCHIVE404 (massal) tampil').toBeVisible({ timeout: 10000 });
    expect(await shotToast(app, monitor, toastWith(page, MSG_404).first(), 'AC13-09a-toast-massal'), 'teks toast massal = pesan ARCHIVE404 id-ID').toContain(MSG_404);
    await app.screenshot('AC13-09-massal-toast-ARCHIVE404');
    await expect(bulk, 'modal massal tetap terbuka').toBeVisible();
    await expect(bulk.locator('button.ant-btn-primary', { hasText: /^(Pindahkan|Move)$/ }).first(), 'tombol Pindahkan kembali aktif').toBeEnabled({ timeout: 10000 });

    // tidak ada yang pindah (juga X yang sebenarnya sah: satu aturan menolak seluruh permintaan)
    expect(prop('A'), 'A di DB identik').toEqual(before.A);
    expect(prop('X'), 'X di DB identik (permintaan ditolak utuh)').toEqual(before.X);

    await closeTopLayer(page);
    await expect(visibleModal(page)).toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(3500);

    // ---- massal sah: [M, M2] -> T (mode Pilih masih aktif; ganti pilihan)
    await rowOf(page, P + 'A').locator('input[type="checkbox"]').first().uncheck();
    await rowOf(page, P + 'X').locator('input[type="checkbox"]').first().uncheck();
    await rowOf(page, P + 'M').locator('input[type="checkbox"]').first().check();
    await rowOf(page, P + 'M2').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk2 = visibleModal(page);
    await bulk2.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(pickerRow(bulk2, page, P + 'M'), 'konfirmasi memuat M').toBeVisible();
    await expect(pickerRow(bulk2, page, P + 'M2'), 'konfirmasi memuat M2').toBeVisible();
    await expect(pickerRow(bulk2, page, P + 'A'), 'konfirmasi tidak lagi memuat A').toHaveCount(0);
    const pl2 = listResponse(page, (u) => !folderParam(u));
    await bulk2.locator('button.ant-btn-primary', { hasText: /^(Lanjut|Next)$/ }).first().click();
    await pl2.catch(() => null);
    await app.settle();
    const ok = await selectTargetAndMove(bulk2, page, P + 'T');
    expect(ok.status, 'POST documents/put-in [M, M2] -> T (sah)').toBe(200);
    expect(idsOfBody(ok.reqBody).slice().sort(), 'body: id_archives = [M, M2]').toEqual([f[P + 'M'], f[P + 'M2']].sort());
    expect(JSON.stringify(ok.json), 'respons sukses memuat ARCHIVE204').toContain('ARCHIVE204');
    await expect(visibleModal(page), 'modal massal menutup sesudah sukses').toHaveCount(0, { timeout: 10000 });
    await noErrorToast(page, 'pindah massal sah');
    await app.screenshot('AC13-10-massal-sukses-M-M2-ke-T');
    expect(prop('M').id_archive_parent, 'DB: M di bawah T').toBe(f[P + 'T']);
    expect(prop('M2').id_archive_parent, 'DB: M2 di bawah T').toBe(f[P + 'T']);
    await app.settle();
    await expect(rowOf(page, P + 'M'), 'layar: M tidak lagi di root').toHaveCount(0);
    await expect(rowOf(page, P + 'M2'), 'layar: M2 tidak lagi di root').toHaveCount(0);

    await exitSelectMode(page);
    fx('restore');
    expect(prop('M'), 'M dipulihkan persis').toEqual(before.M);
    expect(prop('M2'), 'M2 dipulihkan persis').toEqual(before.M2);
    expect(prop('T'), 'T tidak berubah').toEqual(before.T);

    expectRejections(monitor, 1, 'AC-13 massal');
    noServerError(monitor);
    monitor.check();
  });

  // KARAKTERISASI bug di luar item (B-FE-1, dicatat di qa-fe.md). FE tidak diubah oleh item ini. Bila kelak diperbaiki test ini SENGAJA
  // gagal: ubah jadi asersi bahwa Enter menjalankan GET archives?search={"query":...} tanpa navigasi, lalu pakai Search sungguhan di AC-13.
  test('[default] ED-1069 karakterisasi B-FE-1 (di luar item): Enter di kolom Cari modal Pindahkan dan pindah massal memicu submit form HTML asli ke "/?" dan memuat ulang aplikasi', async ({ app, page, monitor }) => {
    traceApi(page, monitor);
    const mBefore = prop('M');
    await openArchive(app, page);
    await pickRowMenu(page, P + 'M', MENU.move);
    const modal = visibleModal(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('B-FE-1-01-modal-pindahkan-sebelum-enter');
    const forms = await modal.locator('.search-bar input').first().evaluate((el) => ({
      nested: !!el.form.parentElement.closest('form'),
      formsInModal: document.querySelectorAll('.ant-modal form').length,
    }));
    monitor.note(`form dalam modal: ${JSON.stringify(forms)}`);
    expect(forms, 'form Search bersarang di form modal').toEqual({ nested: true, formsInModal: 2 });

    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Page.enable');
    const navs = [];
    cdp.on('Page.frameRequestedNavigation', (e) => navs.push(`${e.reason} ${e.url}`));
    const input = modal.locator('.search-bar input').first();
    await input.click();
    await page.keyboard.type(P + 'T', { delay: 40 });
    await page.keyboard.press('Enter');
    await page.waitForTimeout(3500);
    monitor.note(`navigasi sesudah Enter di kolom Cari modal: ${JSON.stringify(navs)}`);
    expect(navs.length, 'KARAKTERISASI B-FE-1: tepat satu navigasi penuh (submit form asli) sesudah Enter').toBe(1);
    expect(navs[0], 'jenis navigasi = formSubmissionGet ke "/?"').toMatch(/^formSubmissionGet .*\/\?$/);
    await app.settle();
    await expect(visibleModal(page), 'modal tertutup karena aplikasi dimuat ulang').toHaveCount(0);
    await app.screenshot('B-FE-1-02-sesudah-enter-aplikasi-dimuat-ulang');
    expect(prop('M'), 'tidak ada data yang berubah (tidak ada POST put-in)').toEqual(mBefore);

    // sama di langkah "Pilih Folder" pindah massal (struktur form yang sama)
    await expect(rowOf(page, P + 'M'), 'sesudah dimuat ulang: daftar root tampil').toBeVisible({ timeout: 30000 });
    await app.settle();
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await rowOf(page, P + 'M').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = visibleModal(page);
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    const pl = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Lanjut|Next)$/ }).first().click();
    await pl.catch(() => null);
    await app.settle();
    const navs2 = [];
    const cdp2 = await page.context().newCDPSession(page);
    await cdp2.send('Page.enable');
    cdp2.on('Page.frameRequestedNavigation', (e) => navs2.push(`${e.reason} ${e.url}`));
    const input2 = bulk.locator('.search-bar input').first();
    await input2.click();
    await page.keyboard.type(P + 'T', { delay: 40 });
    await page.keyboard.press('Enter');
    await page.waitForTimeout(3500);
    monitor.note(`navigasi sesudah Enter di kolom Cari pindah massal: ${JSON.stringify(navs2)}`);
    expect(navs2.length, 'KARAKTERISASI B-FE-1 (massal): tepat satu navigasi penuh sesudah Enter').toBe(1);
    expect(navs2[0], 'massal: formSubmissionGet ke "/?"').toMatch(/^formSubmissionGet .*\/\?$/);
    expect(prop('M'), 'massal: tidak ada data yang berubah').toEqual(mBefore);
    expect(monitor.responses.filter((r) => r.method === 'POST' && PUTIN_RE.test(r.url)).length, 'tidak ada POST put-in').toBe(0);
    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1069 AC-14 layar terbuka tanpa error: Archive root & folder, Show Related Transaction di F, drawer Detail (select Lokasi), modal Pindahkan & pindah massal', async ({ app, page, monitor, session }) => {
    const f = ids();
    monitor.note(`DB sesi ${session.dbName}; F=${f[P + 'F']}; DREAL=${f._hasReal ? 'ya (tipe ' + f._hasReal.type + ')' : 'tidak ada'}`);
    traceApi(page, monitor);

    // ---- 1. Archive root
    const root = await openArchive(app, page);
    expect(app.redirectOf(PATH), 'layar tidak dialihkan').toBeNull();
    for (const n of ['A', 'X', 'T', 'M', 'M2', 'F']) expect(rowsOf(root).map(rowName), `root memuat ${P}${n}`).toContain(P + n);
    await app.screenshot('AC14-01-root');

    // ---- 2. folder A > B > C (breadcrumbs benar, tanpa 500/siklus)
    const inA = await openFolder(page, P + 'A');
    expect(inA.status, 'GET archives?id_archive=A').toBe(200);
    expect(rowsOf(inA).map(rowName), 'isi A = [B]').toEqual([P + 'B']);
    await app.settle();
    await app.screenshot('AC14-02-dalam-A');
    const inB = await openFolder(page, P + 'B');
    expect(inB.status, 'GET archives?id_archive=B').toBe(200);
    expect(rowsOf(inB).map(rowName), 'isi B = [C]').toEqual([P + 'C']);
    await app.settle();
    const inC = await openFolder(page, P + 'C');
    expect(inC.status, 'GET archives?id_archive=C').toBe(200);
    const crumbs = (inC.body.result.breadcrumbs || []).map((b) => b.label || b.name);
    monitor.note(`breadcrumbs C: ${JSON.stringify(inC.body.result.breadcrumbs)}`);
    expect(crumbs, 'breadcrumbs C = A > B > C').toEqual([P + 'A', P + 'B', P + 'C']);
    await app.settle();
    await app.screenshot('AC14-03-dalam-C-breadcrumbs');
    // kembali ke root lewat breadcrumb
    const back = listResponse(page, (u) => !folderParam(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click();
    expect((await back).status, 'kembali ke root lewat breadcrumb').toBe(200);
    await app.settle();

    // ---- 3. Show Related Transaction di F (7 dokumen yatim + 1 dokumen bertransaksi nyata)
    const inF = await openFolder(page, P + 'F');
    expect(inF.status, 'GET archives?id_archive=F').toBe(200);
    await app.settle();
    const expectedDocs = ['D6', 'D7', 'D8', 'D9', 'D29', 'D31', 'D222'].concat(f._hasReal ? ['DREAL'] : []).map((n) => P + n);
    expect(rowsOf(inF).map(rowName).sort(), 'isi F = dokumen uji').toEqual(expectedDocs.sort());
    await app.screenshot('AC14-04-dalam-F');
    await page.locator('.archive-page .search-config').first().click();
    const pop = page.locator('.archive-page .search-bar-overlay').first();
    await pop.locator('.ant-checkbox-input').first().waitFor({ state: 'attached', timeout: 15000 });
    await page.waitForTimeout(700);
    await pop.locator('.ant-checkbox-input').first().check();
    const relResp = listResponse(page, (u) => /showRelatedTransaction/.test(searchOf(u)));
    await pop.locator('button').filter({ hasText: /Cari|Search/ }).first().click();
    const rel = await relResp;
    expect(rel.status, 'Show Related Transaction di F: GET archives 200 (dulu 500)').toBe(200);
    expect(folderParam(new URL(rel.url)), 'pencarian terbatas pada folder F').toBe(f[P + 'F']);
    const relRows = rowsOf(rel);
    expect(relRows.length, 'F: semua dokumen tampil').toBe(expectedDocs.length);
    let nonEmpty = 0;
    let totalRelated = 0;
    for (const r of relRows) {
      const n = rowName(r);
      expect(Array.isArray(r.related_transactions), `${n}: related_transactions ada (larik)`).toBe(true);
      if (n === P + 'DREAL') {
        nonEmpty = r.related_transactions.length;
        totalRelated += nonEmpty;
      } else {
        expect(r.related_transactions, `${n}: dokumen yatim -> related_transactions = []`).toEqual([]);
      }
    }
    monitor.note(`Show Related Transaction di F: ${relRows.length} dokumen; DREAL punya ${nonEmpty} transaksi terkait; yatim = [] (7 tipe)`);
    await page.waitForTimeout(1200);
    const bodyText = await page.locator('.archive-page .ant-table-tbody').innerText();
    expect(bodyText, 'tabel tanpa objek mentah').not.toMatch(/\[object Object\]/);
    expect(await page.locator('.archive-page .ant-table-tbody tr.ant-table-row').count(), 'jumlah baris tabel = dokumen + baris transaksi terkait').toBe(relRows.length + totalRelated);
    await app.screenshot('AC14-05-show-related-transaction-F');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await page.waitForTimeout(800);
    // kembali ke root
    const back2 = listResponse(page, (u) => !folderParam(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click();
    await back2;
    await app.settle();

    // ---- 4. drawer Detail folder B (di bawah A, several location): select Lokasi memanggil select locations?id_archive_parent=A
    await openFolder(page, P + 'A');
    await app.settle();
    const locCalls = [];
    page.on('request', (r) => {
      if (LOC_RE.test(r.url())) locCalls.push(decodeURIComponent(r.url()));
    });
    const locResp = page.waitForResponse((r) => LOC_RE.test(r.url()), { timeout: 45000 }).catch(() => null);
    const show = page.waitForResponse((r) => r.request().method() === 'GET' && SHOW_RE.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
    await pickRowMenu(page, P + 'B', MENU.view);
    const sr = await show;
    expect(sr.status(), 'GET archives/{B}').toBe(200);
    const sj = await sr.json();
    expect(sj.result.name, 'drawer memuat B').toBe(P + 'B');
    await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
    await app.settle();
    const sel = drawer(page).locator('.ant-select').first();
    await sel.waitFor({ state: 'visible', timeout: 15000 });
    let lr = await Promise.race([locResp, page.waitForTimeout(3000).then(() => 'timeout')]);
    if (lr === 'timeout') {
      await sel.click(); // select Lokasi memuat opsi saat dibuka
      lr = await locResp;
    }
    expect(lr && lr.status && lr.status(), 'GET select/document-archive/archive/locations (drawer Detail)').toBe(200);
    expect(locCalls.some((u) => u.includes(f[P + 'A'])), `select Lokasi memakai id_archive_parent = A (${locCalls.join(' ; ')})`).toBe(true);
    const lj = await lr.json();
    expect((lj.result.options || []).length, 'select Lokasi punya opsi').toBeGreaterThan(0);
    if (!(await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').count())) await sel.click();
    await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
    monitor.note(`opsi Lokasi di drawer B: ${(lj.result.options || []).length} opsi`);
    await app.screenshot('AC14-06-drawer-detail-select-lokasi');
    await page.keyboard.press('Escape');
    await closeTopLayer(page);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0);

    // ---- 5. modal Pindahkan (tanpa konfirmasi)
    const ml = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, P + 'B', MENU.move);
    expect((await ml).status, 'picker modal Pindahkan: GET archives').toBe(200);
    const modal = visibleModal(page);
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(pickerRow(modal, page, P + 'A'), 'picker memuat A').toBeVisible();
    await app.screenshot('AC14-07-modal-pindahkan');
    await closeTopLayer(page);
    await expect(visibleModal(page)).toHaveCount(0, { timeout: 10000 });

    // ---- 6. pindah massal (tanpa konfirmasi): Pilih -> centang -> Pindahkan -> Lanjut -> Pilih Folder -> Batal
    const back3 = listResponse(page, (u) => !folderParam(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click();
    await back3;
    await app.settle();
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await rowOf(page, P + 'X').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = visibleModal(page);
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC14-08-massal-konfirmasi');
    const pickerList = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Lanjut|Next)$/ }).first().click();
    await pickerList.catch(() => null);
    await app.settle();
    await expect(pickerRow(bulk, page, P + 'T'), 'langkah Pilih Folder memuat T').toBeVisible();
    await app.screenshot('AC14-09-massal-pilih-folder');
    await closeTopLayer(page);
    await expect(visibleModal(page)).toHaveCount(0, { timeout: 10000 });
    await exitSelectMode(page);
    await noToast(page, 'AC-14');

    // tidak ada perubahan data oleh alur baca-saja
    expect(prop('X').id_archive_parent, 'X tidak berubah').toBeNull();
    noServerError(monitor);
    monitor.check();
  });
});
