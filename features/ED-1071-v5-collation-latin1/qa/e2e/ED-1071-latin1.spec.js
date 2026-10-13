'use strict';

/**
 * ED-1071 - Archive: karakter di luar latin1 (emoji, CJK, Yunani) = 422 GE0748 (bukan 500, bukan "?"). Bug BE saja; FE tidak berubah
 * (K-6 a: `setFieldsError` + toast standar). e2e membuktikan dari browser, sebagai QA_USER (role 3, bahasa dari DB):
 *
 *   AC-10 a : layar terbuka tanpa error: Archive (daftar), drawer folder (tab Detail dan Izin), Izin Arsip by User (/archives/permissions).
 *   AC-10 b : "+" -> Tambah Folder, Nama `QA71 😀`, Simpan -> POST create-folder 422, teks GE0748 di bawah Nama, modal tetap terbuka,
 *             tanpa folder (DB: jumlah archives sama, tak ada "QA71 %"; daftar UI tanpa baris itu).
 *   AC-10 c : drawer folder uji, Deskripsi `中文`, Simpan -> PUT archives/{id} 422, teks GE0748 di bawah Deskripsi, drawer tetap terbuka,
 *             DB folder tidak berubah (nama, description, updated_at).
 *   AC-10 d : Search `😀` di Archive -> GET archives?search 200 total 0 -> tabel kosong, tanpa error/toast.
 *   AC-10 e : tab Izin di drawer: "+ Add User", ketik `😀` di select User -> GET select users 200 `options` [] -> tanpa opsi, tanpa error;
 *             juga select User di halaman Izin Arsip by User.
 *
 * Data uji: satu folder root `QA71-E2E-A` dari fixture (fixtures.json -> fixture.php), dibuang persis di teardown (bersama folder
 * "QA71 %" yang salah dibuat spec, bila ada: itu dilaporkan sebagai kegagalan). Semua flow non-mutasi (tak ada Simpan yang sukses).
 * Jalankan dengan satu worker: scripts/e2e/run.sh ED-1071 --profile default --workers 1
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const PERM_PATH = '/archives/permissions';
const NAME = 'QA71-E2E-A';
const BAD_NAME = 'QA71 \u{1F600}'; // QA71 😀
const BAD_DESC = '中文'; // 中文
const EMOJI = '\u{1F600}';

/** Teks GE0748 persis (en_EN.php / id_ID.php; spec #7). */
const GE0748 = {
  ID: 'Mengandung karakter yang tidak didukung (mis. emoji, huruf Yunani/Mandarin). Gunakan huruf Latin.',
  EN: 'Contains unsupported characters (e.g. emoji, Greek or Chinese letters). Use Latin characters only.',
};

const LIST_RE = /\/document-archive\/archives(\?|$)/;
const SHOW_RE = /\/document-archive\/archives\/[^/?]+(\?|$)/;
const CREATE_RE = /\/document-archive\/archives\/create-folder(\?|$)/;
const USERS_RE = /\/select\/document-archive\/archive\/users(\?|$)/;
const SEARCH_FOLDER = "input[placeholder='Cari folder'], input[placeholder='Search folder']";
const TAB_PERMISSION = /^(Permission|Izin)$/;
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));

/** fixture.php <perintah>: keluaran JSON/teks tanpa rahasia. */
function fx(...args) {
  return execFileSync(config.get('PHP_BIN', 'php'), [path.join(__dirname, 'fixture.php'), ...args], {
    encoding: 'utf8',
    cwd: config.AGENTIC_DIR,
    timeout: 90000,
    env: { ...process.env },
  }).trim();
}
const snap = () => JSON.parse(fx('snap').split('\n').pop());
const prop = () => JSON.parse(fx('prop').split('\n').pop());
const userLang = () => (/^en/i.test(JSON.parse(fx('lang').split('\n').pop()).language || '') ? 'EN' : 'ID');

const listResponse = (page, pred) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 })
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];

/** Baris tabel utama dengan nama tepat. */
const rowOf = (page, name) =>
  page
    .locator('.archive-page .ant-table-row')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .first();

const drawer = (page) => page.locator('.ant-drawer-open').last();
const visibleModal = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();

async function openArchive(app, page) {
  const rootResp = listResponse(page, (u) => !folderParam(u));
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  const root = await rootResp;
  expect(root.status, 'GET archives (root)').toBe(200);
  await app.settle();
  return root;
}

/** Menu ⋯ baris -> item bernama (Lihat/View dst.). */
async function pickRowMenu(page, name, itemRegex) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  const btn = row.locator('button.btn-action');
  expect(await btn.count(), `baris ${name} punya tombol ⋯`).toBeGreaterThan(0);
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
  return items;
}

/** Buka drawer folder uji lewat menu baris "Lihat/View"; tunggu GET archives/{id} dan skeleton hilang. */
async function openDrawer(page) {
  const show = page.waitForResponse((r) => r.request().method() === 'GET' && SHOW_RE.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
  const items = await pickRowMenu(page, NAME, /^(Lihat|View)$/);
  const r = await show;
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  return { status: r.status(), body: await r.json().catch(() => null), menu: items };
}

const tabs = (page) => drawer(page).locator('.ant-tabs-tab');
const tabTexts = async (page) => (await tabs(page).allInnerTexts()).map((t) => t.trim());

async function openPermissionTab(page) {
  const texts = await tabTexts(page);
  const idx = texts.findIndex((t) => TAB_PERMISSION.test(t));
  expect(idx, `tab Izin ada (tab: ${texts.join(' | ')})`).toBeGreaterThanOrEqual(0);
  await tabs(page).nth(idx).click();
  await page.waitForTimeout(300);
  await drawer(page).locator('.ant-switch').first().waitFor({ state: 'visible', timeout: 10000 });
}

/** Tutup drawer tanpa menyimpan; bila FE menanyakan konfirmasi buang perubahan, jawab ya. */
async function closeDrawerDiscard(page) {
  await drawer(page).locator('.ant-drawer-close').click();
  await page.waitForTimeout(600);
  const confirm = page.locator('.ant-modal-confirm').last();
  if (await confirm.count()) {
    await confirm.locator('.ant-modal-confirm-btns button.ant-btn-primary').click();
    await page.waitForTimeout(600);
  }
  await expect(page.locator('.ant-drawer-open'), 'drawer tertutup').toHaveCount(0, { timeout: 10000 });
}

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

/** Tanpa toast/notifikasi apa pun. */
const noToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-notice, .ant-notification-notice'), `${label}: tanpa toast/notifikasi`).toHaveCount(0);
};

/**
 * Keluarkan dari monitor hanya penolakan 422 yang diharapkan (respons + log konsol axios `console.error(error)` dari useStore/useUpdate),
 * dan pastikan jumlah respons-nya. Error lain tetap menggagalkan test.
 */
function expectRejection(monitor, { method, urlRe, count }, label) {
  const isExpected = (e) =>
    (e.kind === 'response' && e.status === 422 && e.method === method && urlRe.test(e.url)) || (e.kind === 'console' && /Request failed with status code 422/.test(e.text || ''));
  const dropped = monitor.errors.filter(isExpected);
  const responses = dropped.filter((e) => e.kind === 'response').length;
  expect(responses, `${label}: ${count}x 422 ${method} tercatat (diharapkan)`).toBe(count);
  monitor.errors = monitor.errors.filter((e) => !isExpected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan (${responses} respons 422 + ${dropped.length - responses} log konsol axios)`);
}

/** Buka "+" -> item Tambah Folder. */
async function openAddFolder(page) {
  const plus = page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
  await plus.waitFor({ state: 'visible', timeout: 15000 });
  await plus.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: /Add Folder|Tambah Folder/ }).first().click();
  const modal = visibleModal(page);
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  return modal;
}

/**
 * Ketik di select User dan tunggu GET select users dengan search tepat; kembalikan respons. `expectOpen`: klik membuka dropdown dengan
 * GET awal (tab Izin: opsi dimuat saat dibuka); di halaman Izin Arsip by User opsi sudah dimuat saat layar dibuka, jadi tanpa GET awal.
 */
async function typeInUserSelect(page, select, text, { expectOpen = true } = {}) {
  if (expectOpen) {
    const opened = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
    await select.click();
    expect((await opened).status(), 'GET select users (buka dropdown)').toBe(200);
  } else {
    await select.click();
  }
  const searched = page.waitForResponse((r) => USERS_RE.test(r.url()) && decodeURIComponent(r.url()).includes(text), { timeout: 30000 });
  await page.keyboard.type(text);
  const r = await searched;
  await page.waitForTimeout(800);
  return { status: r.status(), url: r.url(), body: await r.json().catch(() => null) };
}

test.describe('ED-1071 karakter di luar latin1 (QA_USER)', () => {
  test('[default] ED-1071 AC-10 a: Archive, drawer folder (Detail, Izin) dan Izin Arsip by User terbuka tanpa error', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const fx0 = ids();
    monitor.note(`DB sesi ${session.dbName}; fixture ${NAME}=${fx0[NAME]}; bahasa QA_USER ${userLang()}`);

    await openArchive(app, page);
    await expect(rowOf(page, NAME), 'folder uji tampil di daftar root').toBeVisible();
    await noToast(page, 'daftar Archive');
    await app.screenshot('AC10a-01-archive-daftar');

    const view = await openDrawer(page);
    monitor.note(`menu baris ${NAME}: ${view.menu.join(' | ')}`);
    expect(view.status, 'GET archives/{id}').toBe(200);
    const texts = await tabTexts(page);
    monitor.note(`tab drawer: ${texts.join(' | ')}`);
    await expect(drawer(page).locator('input#name').first(), 'Detail: kolom Nama terisi nama folder').toHaveValue(NAME);
    await app.screenshot('AC10a-02-drawer-Detail');
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'tab Izin: toggle Izin Folder Off').not.toHaveClass(/ant-switch-checked/);
    await noToast(page, 'drawer folder');
    await app.screenshot('AC10a-03-drawer-Izin');
    await closeDrawerDiscard(page);

    // Izin Arsip by User
    await app.open(PERM_PATH, { waitFor: SEARCH_FOLDER });
    expect(app.redirectOf(PERM_PATH), 'Izin Arsip by User tidak dialihkan').toBeNull();
    await expect(page.locator('.page-container').filter({ has: page.locator(SEARCH_FOLDER) }).first().locator('.ant-select').first(), 'kotak User ada').toBeVisible();
    await noToast(page, 'Izin Arsip by User');
    await app.screenshot('AC10a-04-izin-arsip-by-user');

    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1071 AC-10 b: Tambah Folder "QA71 😀" -> teks GE0748 di bawah Nama, modal tetap, tanpa folder', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const lang = userLang();
    const before = snap();
    monitor.note(`DB sesi ${session.dbName}; bahasa ${lang}; snapshot sebelum: ${JSON.stringify(before)}`);

    await openArchive(app, page);
    const modal = await openAddFolder(page);
    await app.screenshot('AC10b-01-modal-tambah-folder');
    const nameInput = modal.locator('input#name').first();
    await nameInput.fill(BAD_NAME);
    await expect(nameInput, 'Nama terisi dengan emoji').toHaveValue(BAD_NAME);
    const post = page.waitForResponse((r) => r.request().method() === 'POST' && CREATE_RE.test(r.url()), { timeout: 45000 });
    await modal.locator('button.ant-btn-primary', { hasText: /^(Simpan|Save)$/ }).first().click();
    const resp = await post;
    const body = await resp.json().catch(() => null);
    monitor.note(`POST create-folder -> ${resp.status()} errors=${JSON.stringify(body && body.errors)}`);
    expect(resp.status(), 'POST create-folder = 422 (bukan 500)').toBe(422);
    expect(body && body.errors && body.errors.name, 'errors.name = [teks GE0748]').toEqual([GE0748[lang]]);

    // teks di bawah kolom Nama, modal tetap terbuka
    const nameItem = modal.locator('.ant-form-item').filter({ has: page.locator('input#name') }).first();
    const err = nameItem.locator('.ant-form-item-explain-error');
    await expect(err.first(), 'teks galat tampil di bawah Nama').toBeVisible({ timeout: 10000 });
    expect((await err.first().innerText()).trim(), 'teks galat di bawah Nama = GE0748').toBe(GE0748[lang]);
    await expect(modal, 'modal tetap terbuka').toBeVisible();
    await expect(nameInput, 'Nama tetap berisi masukan').toHaveValue(BAD_NAME);
    await page.waitForTimeout(800);
    await app.screenshot('AC10b-02-teks-GE0748-di-bawah-Nama');

    // tanpa folder: DB tidak bertambah, daftar UI tanpa baris itu
    const after = snap();
    monitor.note(`snapshot sesudah: ${JSON.stringify(after)}`);
    expect(after.stray_count, 'DB: tak ada folder "QA71 %"').toBe(0);
    expect(after.archives_total, 'DB: jumlah archives tidak berubah').toBe(before.archives_total);
    expect(after.archive_locations, 'DB: archive_locations tidak berubah').toBe(before.archive_locations);
    expect(after.archive_permissions, 'DB: archive_permissions tidak berubah').toBe(before.archive_permissions);

    // tutup modal (Batal) lalu muat ulang daftar: baris tidak ada
    await modal.locator('button', { hasText: /^(Batal|Cancel)$/ }).first().click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'modal tertutup').toHaveCount(0, { timeout: 10000 });
    await openArchive(app, page);
    await expect(page.locator('.archive-page .ant-table-row').filter({ hasText: /QA71\s/ }), 'daftar UI: tak ada baris "QA71 ..."').toHaveCount(0);
    await expect(rowOf(page, NAME), 'folder uji tetap ada').toBeVisible();
    await app.screenshot('AC10b-03-daftar-tanpa-folder-baru');

    expectRejection(monitor, { method: 'POST', urlRe: CREATE_RE, count: 1 }, 'tambah folder');
    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1071 AC-10 c: Deskripsi "中文" di drawer -> teks GE0748 di bawah Deskripsi, drawer tetap, data tidak berubah', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const lang = userLang();
    const fx0 = ids();
    const row0 = prop();
    monitor.note(`DB sesi ${session.dbName}; bahasa ${lang}; baris sebelum: name=${row0.name} description=${JSON.stringify(row0.description)} updated_at=${row0.updated_at}`);

    await openArchive(app, page);
    const view = await openDrawer(page);
    expect(view.status, 'GET archives/{id}').toBe(200);
    const desc = drawer(page).locator('textarea').first();
    await expect(desc, 'kolom Deskripsi tampil').toBeVisible();
    await expect(desc, 'Deskripsi bisa diubah (Detail tidak read-only)').toBeEnabled();
    await desc.fill(BAD_DESC);
    await expect(desc, 'Deskripsi terisi 中文').toHaveValue(BAD_DESC);
    await app.screenshot('AC10c-01-deskripsi-diisi');

    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && SHOW_RE.test(r.url()) && r.url().includes(fx0[NAME]), { timeout: 45000 });
    await drawer(page).locator('.ant-drawer-extra button.ant-btn-primary').click();
    const resp = await put;
    const body = await resp.json().catch(() => null);
    monitor.note(`PUT archives/{id} -> ${resp.status()} errors=${JSON.stringify(body && body.errors)}`);
    expect(resp.status(), 'PUT archives/{id} = 422 (bukan 500)').toBe(422);
    expect(body && body.errors && body.errors.description, 'errors.description = [teks GE0748]').toEqual([GE0748[lang]]);

    const descItem = drawer(page).locator('.ant-form-item').filter({ has: page.locator('textarea') }).first();
    const err = descItem.locator('.ant-form-item-explain-error');
    await expect(err.first(), 'teks galat tampil di bawah Deskripsi').toBeVisible({ timeout: 10000 });
    expect((await err.first().innerText()).trim(), 'teks galat di bawah Deskripsi = GE0748').toBe(GE0748[lang]);
    await expect(drawer(page), 'drawer tetap terbuka').toBeVisible();
    await expect(desc, 'Deskripsi tetap berisi masukan').toHaveValue(BAD_DESC);
    await page.waitForTimeout(800);
    await app.screenshot('AC10c-02-teks-GE0748-di-bawah-Deskripsi');

    // DB tidak berubah
    const row1 = prop();
    expect(row1.name, 'DB: nama tidak berubah').toBe(row0.name);
    expect(row1.description, 'DB: description tidak berubah').toBe(row0.description);
    expect(row1._hex_description, 'DB: description tidak berisi "?" (3F)').toBe(row0._hex_description);
    expect(row1.updated_at, 'DB: updated_at tidak berubah').toBe(row0.updated_at);
    expect(snap().stray_count, 'DB: tak ada folder "QA71 %"').toBe(0);

    await closeDrawerDiscard(page);
    expectRejection(monitor, { method: 'PUT', urlRe: SHOW_RE, count: 1 }, 'ubah folder');
    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1071 AC-10 d: Search "😀" di Archive -> tabel kosong tanpa error', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    monitor.note(`DB sesi ${session.dbName}`);
    await openArchive(app, page);

    const searchResp = listResponse(page, (u) => (u.searchParams.get('search') || '').includes(EMOJI));
    const input = page.locator('.archive-page .search-bar input').first();
    await input.fill(EMOJI);
    await expect(input, 'Search terisi emoji').toHaveValue(EMOJI);
    await input.press('Enter');
    const sr = await searchResp;
    const result = (sr.body && sr.body.result) || {};
    monitor.note(`GET archives?search=😀 -> ${sr.status} total=${JSON.stringify(result.total)} data=${rowsOf(sr).length} kunci result: ${Object.keys(result).join(',')}`);
    expect(sr.status, 'GET archives?search={"query":"😀"} = 200 (bukan 500)').toBe(200);
    expect(rowsOf(sr), 'data kosong').toHaveLength(0);
    if (result.total !== undefined) expect(Number(result.total), 'total 0').toBe(0);
    await app.settle();
    await expect(page.locator('.archive-page .ant-table-row'), 'tabel tanpa baris data').toHaveCount(0);
    await noToast(page, 'hasil cari 😀');
    await app.screenshot('AC10d-01-search-emoji-tabel-kosong');

    // kembali: reset filter -> daftar root lagi
    const root = listResponse(page, (u) => !folderParam(u) && !u.searchParams.get('search'));
    await page.locator('.archive-page .reset-filter').first().click();
    expect((await root).status, 'GET archives (root) sesudah reset').toBe(200);
    await app.settle();
    await expect(rowOf(page, NAME), 'folder uji tampil lagi sesudah reset').toBeVisible();
    await app.screenshot('AC10d-02-sesudah-reset');

    noServerError(monitor);
    monitor.check();
  });

  test('[default] ED-1071 AC-10 e: select User (tab Izin, dan Izin Arsip by User) diketik "😀" -> tanpa opsi, tanpa error', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const row0 = prop();
    const before = snap();
    monitor.note(`DB sesi ${session.dbName}; baris sebelum: is_folder_permission=${row0.is_folder_permission} updated_at=${row0.updated_at}`);

    await openArchive(app, page);
    const view = await openDrawer(page);
    expect(view.status, 'GET archives/{id}').toBe(200);
    await openPermissionTab(page);
    const sw = drawer(page).locator('.ant-switch').first();
    await sw.click(); // hanya keadaan form (tanpa Save): tombol "+ Add User" baru muncul saat Izin Folder On
    await expect(sw, 'toggle On (lokal)').toHaveClass(/ant-switch-checked/);
    await drawer(page).locator('button.ant-btn-link').click(); // "+ Add User"
    const select = drawer(page).locator('.ant-select').first();
    await select.waitFor({ state: 'visible', timeout: 10000 });

    const res = await typeInUserSelect(page, select, EMOJI);
    const options = (res.body && res.body.result && res.body.result.options) || [];
    monitor.note(`GET select users search=😀 (tab Izin) -> ${res.status} options=${JSON.stringify(options)}`);
    expect(res.status, 'GET select/document-archive/archive/users?search=😀 = 200 (bukan 500)').toBe(200);
    expect(options, 'options kosong').toEqual([]);
    const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)');
    await expect(dd.locator('.ant-select-item-option'), 'dropdown tanpa opsi').toHaveCount(0);
    await app.screenshot('AC10e-01-tab-Izin-select-User-emoji');
    await drawer(page).locator('.ant-drawer-header').click(); // tutup dropdown tanpa Escape (Escape menutup drawer)
    await closeDrawerDiscard(page);

    // DB tidak berubah (toggle hanya lokal)
    const row1 = prop();
    expect(row1.is_folder_permission, 'DB: is_folder_permission tetap').toBe(row0.is_folder_permission);
    expect(row1.updated_at, 'DB: updated_at tetap').toBe(row0.updated_at);
    expect(snap().archive_permissions, 'DB: archive_permissions tetap').toBe(before.archive_permissions);

    // select User di Izin Arsip by User
    await app.open(PERM_PATH, { waitFor: SEARCH_FOLDER });
    expect(app.redirectOf(PERM_PATH), 'Izin Arsip by User tidak dialihkan').toBeNull();
    const root2 = page.locator('.page-container').filter({ has: page.locator(SEARCH_FOLDER) }).first();
    const select2 = root2.locator('.ant-select').first();
    await select2.waitFor({ state: 'visible', timeout: 15000 });
    const res2 = await typeInUserSelect(page, select2, EMOJI, { expectOpen: false });
    const options2 = (res2.body && res2.body.result && res2.body.result.options) || [];
    monitor.note(`GET select users search=😀 (Izin Arsip by User) -> ${res2.status} options=${JSON.stringify(options2)}`);
    expect(res2.status, 'GET select users search=😀 (Izin Arsip by User) = 200').toBe(200);
    expect(options2, 'options kosong (Izin Arsip by User)').toEqual([]);
    await expect(page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option'), 'dropdown tanpa opsi').toHaveCount(0);
    await app.screenshot('AC10e-02-izin-arsip-by-user-select-User-emoji');
    await root2.click({ position: { x: 5, y: 5 } }).catch(() => null);
    await noToast(page, 'Izin Arsip by User sesudah ketik 😀');

    noServerError(monitor);
    monitor.check();
  });
});
