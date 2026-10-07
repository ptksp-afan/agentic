'use strict';

/**
 * ED-1025 - Archive: Folder Permission per folder (tab Permission, layar akses ditolak, menu baris / "+" / picker).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA25-":
 *   0QA25-EDIT  nonaktif, pembuat = A          0QA25-P      aktif, A penuh, B View saja (+ anak 0QA25-P-C, dokumen 0QA25-P-DOC)
 *   0QA25-DENY  aktif, B tanpa baris           0QA25-DENY-C aktif, B View (ditolak induk)
 *   0QA25-STORE aktif, B View+Store            0QA25-MOVE   nonaktif bebas
 * Profil: [default] = A (QA_USER, bahasa ID), [user2] = B (QA_USER2, bahasa EN). Keduanya non-superadmin.
 *
 * Mutasi hanya pada data fixture (0QA25-EDIT: AC-17); semua tombol Pindahkan/Hapus tidak pernah dikonfirmasi.
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('e2e-harness');

const PATH = '/archives';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const USERS_RE = /\/select\/document-archive\/archive\/users(\?|$)/;
const SHOW_RE = /\/document-archive\/archives\/[^/?]+(\?|$)/;
const rowName = (row) => (Array.isArray(row.name) ? (row.name[0] || {}).transaction_no : row.name);
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const FULL = { view: true, update: true, delete: true, store: true, manage_permission: true };

const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));

const listResponse = (page, pred) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 })
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];

/** Baris tabel utama (bukan tabel di modal) dengan nama tepat. */
const rowOf = (page, name) =>
  page
    .locator('.archive-page .ant-table-row')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .first();

/** Baris di dalam modal/picker: dicocokkan lewat sel nama (teks sel = nama persis; di picker tidak ada spasi akhir). */
const pickerRow = (page, scope, name) =>
  scope
    .locator('.ant-table-row')
    .filter({ has: page.locator('td').filter({ hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) })
    .first();

async function rowMenuItems(page, name) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  const btn = row.locator('button.btn-action');
  if ((await btn.count()) === 0) return { btn: null, items: [], menu: null };
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());
  return { btn, items, menu };
}

async function pickRowMenu(page, name, itemRegex) {
  const { menu, items } = await rowMenuItems(page, name);
  expect(menu, `menu baris ${name} ada`).not.toBeNull();
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
  return items;
}

/** Tombol "+" (Add Folder / Hand Over / Receive / Store Document): buka dropdown, kembalikan teks item, tutup. */
async function plusItems(page, app, shot) {
  const plus = page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
  await plus.waitFor({ state: 'visible', timeout: 15000 });
  await plus.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());
  if (shot) {
    await page.waitForTimeout(400);
    await app.screenshot(shot); // dropdown terbuka, tampil di galeri
  }
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  return items;
}

const drawer = (page) => page.locator('.ant-drawer-open').last();
const tabs = (page) => drawer(page).locator('.ant-tabs-tab');

async function closeTopLayer(page) {
  const closer = page.locator('.ant-drawer-open .ant-drawer-close, .ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last();
  if (await closer.count()) await closer.click();
  await page.waitForTimeout(700);
}

/** Buka folder dari daftar (klik sel nama) dan tunggu GET archives?id_archive. */
async function openFolder(page, name) {
  const resp = listResponse(page, (u) => !!folderParam(u));
  await rowOf(page, name).locator('td.cursor-pointer').first().click();
  return resp;
}

async function openDrawerView(page, name) {
  const show = page.waitForResponse((r) => r.request().method() === 'GET' && SHOW_RE.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
  await pickRowMenu(page, name, /^(Lihat|View)$/);
  const r = await show;
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  return { status: r.status(), body: await r.json().catch(() => null) };
}

/** Tab Permission = tab ke-2 (label id-ID "Izin", en "Permission"). */
async function openPermissionTab(page) {
  await expect(tabs(page), 'drawer punya 2 tab: Detail & Permission').toHaveCount(2);
  await tabs(page).nth(1).click();
  await drawer(page).locator('.ant-switch').first().waitFor({ state: 'visible', timeout: 10000 });
  await page.waitForTimeout(300);
}

const permRows = (page) => drawer(page).locator('.ant-tabs-tabpane-active .ant-table-row');
const rowBoxes = async (row) => row.locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.checked));
const rowBoxDisabled = async (row) => row.locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.disabled));
const permRow = (page, username) => permRows(page).filter({ hasText: new RegExp(`(^|\\s)${esc(username)}(\\s|$)`) }).first();

const saveBtn = (page) => drawer(page).locator('.ant-drawer-extra button.ant-btn-primary');

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

const noToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-notice, .ant-notification-notice'), `${label}: tanpa toast/notifikasi`).toHaveCount(0);
};

/** Keluarkan dari monitor hanya penolakan 403 ARCHIVE407 yang diharapkan (respons + log konsol axios). */
function expectDenied403(monitor, idArchive) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === 403 && /\/document-archive\/archives\?/.test(e.url) && new RegExp(`id_?[aA]rchive=${idArchive}`).test(decodeURIComponent(e.url))) ||
    (e.kind === 'console' && /Request failed with status code 403/.test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  expect(dropped.filter((e) => e.kind === 'response').length, `403 GET archives?id_archive=${idArchive} tercatat (diharapkan)`).toBeGreaterThanOrEqual(1);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`403 yang diharapkan dikeluarkan dari pemeriksaan error: ${dropped.length} entri (respons + console axios)`);
}

const MENU = {
  info: /^Info$/,
  view: /^(Lihat|View)$/,
  move: /^(Pindahkan|Move)$/,
  del: /^(Hapus|Delete)$/,
};
const hasItem = (items, re) => items.some((t) => re.test(t));

test.describe('ED-1025 folder permission', () => {
  // ------------------------------------------------------------------------------------------------ AC-3 (A)
  test('[default] ED-1025 AC-3 A: Archive, folder, drawer (Detail & Permission), + Add User, Info, modal Pindahkan & pindah massal', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const fx = ids();
    monitor.note(`DB sesi ${session.dbName}; user A (role 3, non-superadmin)`);

    // 1. layar Archive terbuka: baris folder membawa access penuh dan ikon perisai pada folder ber-permission
    const rootResp = listResponse(page, (u) => !folderParam(u));
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    expect(app.redirectOf(PATH), 'layar tidak dialihkan').toBeNull();
    const root = await rootResp;
    expect(root.status, 'GET archives (root)').toBe(200);
    const rootRows = rowsOf(root);
    const byName = (n) => rootRows.find((r) => rowName(r) === n);
    for (const n of ['0QA25-EDIT', '0QA25-P', '0QA25-DENY', '0QA25-STORE', '0QA25-MOVE']) expect(byName(n), `root memuat ${n}`).toBeTruthy();
    expect(byName('0QA25-P').is_folder_permission, 'P: is_folder_permission').toBe(1);
    expect(byName('0QA25-P').access, 'P: access A penuh').toEqual(FULL);
    expect(byName('0QA25-MOVE').is_folder_permission, 'MOVE: nonaktif').toBe(0);
    await expect(rowOf(page, '0QA25-P').locator('.anticon-safety-certificate'), 'ikon perisai di P').toHaveCount(1);
    await expect(rowOf(page, '0QA25-MOVE').locator('.anticon-safety-certificate'), 'tanpa perisai di MOVE (nonaktif)').toHaveCount(0);
    await app.screenshot('AC3-01-root-A-ikon-perisai');

    // 2. masuk folder P: result.access penuh, "+" memuat 4 item (Add Folder, Hand Over, Receive, Store Document)
    const inP = await openFolder(page, '0QA25-P');
    expect(inP.status, 'GET archives?id_archive=P').toBe(200);
    expect(inP.body.result.access, 'result.access di P').toEqual(FULL);
    await app.settle();
    await expect(rowOf(page, '0QA25-P-C'), 'P memuat 0QA25-P-C').toBeVisible();
    await expect(rowOf(page, '0QA25-P-DOC'), 'P memuat 0QA25-P-DOC').toBeVisible();
    const plusA = await plusItems(page, app, 'AC3-02b-plus-di-P-A');
    monitor.note(`"+" di P (A): ${plusA.join(' | ')}`);
    expect(plusA.length, '"+" di P untuk A memuat 4 item').toBe(4);
    expect(plusA.some((t) => /Tambah Folder|Add Folder/.test(t)), 'Add Folder').toBe(true);
    expect(plusA.some((t) => /Letakkan Dokumen|Store Document/.test(t)), 'Store Document').toBe(true);
    await app.screenshot('AC3-02-dalam-folder-P-A');
    // menu baris folder anak (nonaktif, induk memberi A semua): Info, Move, View, Delete
    const itemsC = (await rowMenuItems(page, '0QA25-P-C')).items;
    monitor.note(`menu baris P-C (A): ${itemsC.join(' | ')}`);
    for (const re of [MENU.info, MENU.move, MENU.view, MENU.del]) expect(hasItem(itemsC, re), `menu P-C memuat ${re}`).toBe(true);
    await page.keyboard.press('Escape');
    // kembali ke root lewat breadcrumb
    const back = listResponse(page, (u) => !folderParam(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click();
    await back;
    await app.settle();

    // 3. drawer folder P: tab Detail & Permission, + Add User memanggil select (excepts = user di tabel)
    const view = await openDrawerView(page, '0QA25-P');
    expect(view.status, 'GET archives/{P}').toBe(200);
    expect(view.body.result.is_folder_permission, 'show: is_folder_permission').toBe(1);
    expect(view.body.result.folder_permissions.map((r) => r.username).sort(), 'show: folder_permissions').toEqual([fx._userA.username, fx._userB.username].sort());
    expect(view.body.result.access, 'show: access A').toEqual(FULL);
    await expect(tabs(page)).toHaveCount(2);
    await app.screenshot('AC3-03-drawer-P-tab-Detail');
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'toggle aktif').toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'tabel Permission: 2 baris').toHaveCount(2);
    expect(await rowBoxes(permRow(page, fx._userA.username)), 'baris A: V U D S').toEqual([true, true, true, true]);
    expect(await rowBoxes(permRow(page, fx._userB.username)), 'baris B: View saja').toEqual([true, false, false, false]);
    await expect(drawer(page).locator('.ant-alert'), 'peringatan "parent dominan"').toHaveCount(1);
    const usersP = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
    await drawer(page).locator('button.ant-btn-link').click();
    await drawer(page).locator('.ant-select').first().click();
    const ur = await usersP;
    expect(ur.status(), 'GET select/document-archive/archive/users').toBe(200);
    const uopts = ((await ur.json()).result.options || []).map((o) => o.label);
    monitor.note(`opsi "+ Add User" di P: ${uopts.slice(0, 12).join(' | ')} (total ${uopts.length})`);
    expect(decodeURIComponent(ur.url()), 'excepts memuat id A dan B').toContain(fx._userA.id_user);
    expect(decodeURIComponent(ur.url())).toContain(fx._userB.id_user);
    expect(uopts, 'opsi tanpa A (sudah di tabel)').not.toContain(fx._userA.username);
    expect(uopts, 'opsi tanpa B (sudah di tabel)').not.toContain(fx._userB.username);
    await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
    await app.screenshot('AC3-04-drawer-P-tab-Permission-add-user');
    await page.keyboard.press('Escape');
    await closeTopLayer(page);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0);

    // 4. drawer Info (history)
    const hist = page.waitForResponse((r) => /\/archives\/history\//.test(r.url()), { timeout: 30000 });
    await pickRowMenu(page, '0QA25-P', MENU.info);
    expect((await hist).status(), 'GET archives/history/{P}').toBe(200);
    await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC3-05-drawer-info-P');
    await closeTopLayer(page);

    // 5. modal Pindahkan (folder MOVE): picker memuat root + hak per baris; tidak dikonfirmasi
    const moveList = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, '0QA25-MOVE', MENU.move);
    expect((await moveList).status, 'picker modal Pindahkan: GET archives').toBe(200);
    const modal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(modal.locator('.ant-table-row').filter({ hasText: '0QA25-P' }).first(), 'picker memuat 0QA25-P').toBeVisible();
    await app.screenshot('AC3-06-modal-pindahkan');
    await closeTopLayer(page);

    // 6. pindah massal: mode Pilih -> centang MOVE -> Pindahkan -> Konfirmasi -> Berikutnya -> Pilih Folder
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await rowOf(page, '0QA25-MOVE').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC3-07-bulk-konfirmasi');
    const pickerList = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Next|Lanjut)$/ }).first().click();
    await pickerList.catch(() => null);
    await app.settle();
    await expect(bulk.locator('.ant-table-row').filter({ hasText: '0QA25-STORE' }).first(), 'step Pilih Folder memuat 0QA25-STORE').toBeVisible();
    await app.screenshot('AC3-08-bulk-pilih-folder');
    await closeTopLayer(page);
    await noToast(page, 'AC-3');

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-17 (A)
  test('[default] ED-1025 AC-17 A: tab Permission - nyalakan toggle, + Add User B, centang Update (View ikut), Save, buka ulang', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const fx = ids();
    const B = fx._userB.username;
    monitor.note(`DB sesi ${session.dbName}; A mengubah folder fixture 0QA25-EDIT (pembuat = A); dibuang di teardown`);

    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    await app.settle();
    const view = await openDrawerView(page, '0QA25-EDIT');
    expect(view.status).toBe(200);
    expect(view.body.result.is_folder_permission, 'awal: nonaktif').toBe(0);
    expect(view.body.result.folder_permissions, 'awal: tanpa baris').toEqual([]);
    expect(view.body.result.access, 'A pembuat: access penuh').toEqual(FULL);
    await openPermissionTab(page);
    const sw = drawer(page).locator('.ant-switch').first();
    await expect(sw, 'toggle awal mati').not.toHaveClass(/ant-switch-checked/);
    await expect(drawer(page).locator('button.ant-btn-link'), 'toggle mati: tanpa "+ Add User"').toHaveCount(0);
    await app.screenshot('AC17-01-toggle-mati');

    await sw.click();
    await expect(sw, 'toggle menyala').toHaveClass(/ant-switch-checked/);
    const usersResp = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
    await drawer(page).locator('button.ant-btn-link').click();
    await drawer(page).locator('.ant-select').first().click();
    await usersResp;
    await page.keyboard.type(B);
    await page.waitForTimeout(900);
    const opt = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: new RegExp(`^${esc(B)}$`) }).first();
    await opt.waitFor({ state: 'visible', timeout: 15000 });
    await opt.click();
    await expect(permRows(page), 'satu baris ditambahkan').toHaveCount(1);
    const rowB = permRow(page, B);
    expect(await rowBoxes(rowB), 'user baru default View saja').toEqual([true, false, false, false]);

    // BR-3: lepas View -> semua lepas; centang Store -> View ikut; centang Update -> tetap
    await rowB.locator('input[type="checkbox"]').nth(0).uncheck();
    expect(await rowBoxes(rowB), 'lepas View: semua lepas').toEqual([false, false, false, false]);
    await rowB.locator('input[type="checkbox"]').nth(3).check();
    expect(await rowBoxes(rowB), 'centang Store: View ikut').toEqual([true, false, false, true]);
    await rowB.locator('input[type="checkbox"]').nth(1).check();
    expect(await rowBoxes(rowB), 'centang Update: View tetap, Store tetap').toEqual([true, true, false, true]);
    await rowB.locator('input[type="checkbox"]').nth(0).uncheck();
    expect(await rowBoxes(rowB), 'lepas View lagi: semua lepas').toEqual([false, false, false, false]);
    await rowB.locator('input[type="checkbox"]').nth(1).check();
    expect(await rowBoxes(rowB), 'centang Update: View ikut').toEqual([true, true, false, false]);
    await rowB.locator('input[type="checkbox"]').nth(3).check();
    expect(await rowBoxes(rowB), 'akhir: View + Update + Store').toEqual([true, true, false, true]);
    await app.screenshot('AC17-02-sebelum-save');

    // Save (satu PUT berisi Detail + Permission)
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && SHOW_RE.test(r.url()), { timeout: 30000 });
    await saveBtn(page).click();
    const pr = await put;
    const body = JSON.parse(pr.request().postData() || '{}');
    monitor.note(`PUT body: ${JSON.stringify(body).slice(0, 400)}`);
    expect(pr.status(), 'PUT archives/{id}').toBe(200);
    expect((await pr.json()).msg_code, 'msg_code').toBe('ARCHIVE207');
    expect(body.isFolderPermission ?? body.is_folder_permission, 'PUT: isFolderPermission integer 1').toBe(1);
    const rowsSent = body.folderPermissions || body.folder_permissions;
    expect(rowsSent, 'PUT: tepat 1 baris (B)').toHaveLength(1);
    expect(rowsSent[0].idUser ?? rowsSent[0].id_user, 'PUT: id_user B').toBe(fx._userB.id_user);
    await expect(page.locator('.ant-drawer-open'), 'drawer menutup setelah Save').toHaveCount(0, { timeout: 15000 });

    // buka ulang: data tersimpan, ikon perisai muncul
    await app.settle();
    await expect(rowOf(page, '0QA25-EDIT').locator('.anticon-safety-certificate'), 'perisai muncul di 0QA25-EDIT').toHaveCount(1, { timeout: 15000 });
    const again = await openDrawerView(page, '0QA25-EDIT');
    expect(again.body.result.is_folder_permission, 'buka ulang: aktif').toBe(1);
    expect(again.body.result.folder_permissions.map((r) => [r.username, r.is_view, r.is_update, r.is_delete, r.is_store]), 'buka ulang: baris B tersimpan').toEqual([[B, 1, 1, 0, 1]]);
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'UI: toggle aktif').toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'UI: satu baris').toHaveCount(1);
    expect(await rowBoxes(permRow(page, B)), 'UI: baris B [V,U,D,S]').toEqual([true, true, false, true]);
    await app.screenshot('AC17-03-buka-ulang-tersimpan');
    await closeTopLayer(page);

    // riwayat folder: entri aksi permission oleh A (AC-22 sisi tampilan)
    const hist = page.waitForResponse((r) => /\/archives\/history\//.test(r.url()), { timeout: 30000 });
    await pickRowMenu(page, '0QA25-EDIT', MENU.info);
    const hr = await hist;
    expect(hr.status(), 'GET history').toBe(200);
    await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    const htxt = await drawer(page).innerText();
    monitor.note(`riwayat (UI): ${htxt.replace(/\s+/g, ' ').slice(0, 300)}`);
    expect(htxt, 'riwayat memuat entri Permission diubah/changed').toMatch(/Permission (changed|diubah)/i);
    await app.screenshot('AC17-04-riwayat-permission');
    await closeTopLayer(page);
    await noToast(page, 'AC-17');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-18 + AC-20 (B)
  test('[user2] ED-1025 AC-18/AC-20 B (View saja di P): drawer read-only, Save tidak ada, menu baris & "+" mengikuti hak', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const fx = ids();
    monitor.note(`DB sesi ${session.dbName}; user B (role 5, non-superadmin; bahasa EN)`);

    const rootResp = listResponse(page, (u) => !folderParam(u));
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    const root = await rootResp;
    expect(root.status).toBe(200);
    const p = rowsOf(root).find((r) => rowName(r) === '0QA25-P');
    expect(p.access, 'B di P: access').toEqual({ view: true, update: false, delete: false, store: false, manage_permission: false });
    await app.settle();

    // AC-20: menu baris P (B): Info + View saja
    const pItems = (await rowMenuItems(page, '0QA25-P')).items;
    monitor.note(`menu baris P (B): ${pItems.join(' | ')}`);
    expect(hasItem(pItems, MENU.info), 'P: Info').toBe(true);
    expect(hasItem(pItems, MENU.view), 'P: View').toBe(true);
    expect(hasItem(pItems, MENU.move), 'P: tanpa Move (tanpa Update)').toBe(false);
    expect(hasItem(pItems, MENU.del), 'P: tanpa Delete (tanpa Delete)').toBe(false);
    await app.screenshot('AC20-01-menu-baris-P-B');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    // folder MOVE (nonaktif): B penuh -> semua item
    const mItems = (await rowMenuItems(page, '0QA25-MOVE')).items;
    monitor.note(`menu baris MOVE (B): ${mItems.join(' | ')}`);
    for (const re of [MENU.info, MENU.move, MENU.view, MENU.del]) expect(hasItem(mItems, re), `MOVE memuat ${re}`).toBe(true);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // AC-18: drawer P read-only
    const view = await openDrawerView(page, '0QA25-P');
    expect(view.status).toBe(200);
    expect(view.body.result.access.update, 'B tanpa Update').toBe(false);
    expect(view.body.result.access.manage_permission, 'B tanpa manage_permission').toBe(false);
    await expect(saveBtn(page), 'tombol Save tidak ada').toHaveCount(0);
    const detailDisabled = await drawer(page).locator('.ant-tabs-tabpane-active input:not([type="hidden"]), .ant-tabs-tabpane-active textarea').evaluateAll((els) => els.map((e) => e.disabled));
    monitor.note(`tab Detail: ${detailDisabled.length} isian, disabled=${detailDisabled.filter(Boolean).length}`);
    expect(detailDisabled.length, 'tab Detail punya isian').toBeGreaterThan(0);
    expect(detailDisabled.every(Boolean), 'tab Detail: semua isian disabled').toBe(true);
    await app.screenshot('AC18-01-detail-read-only');
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'toggle tetap aktif').toHaveClass(/ant-switch-checked/);
    await expect(drawer(page).locator('.ant-switch').first(), 'toggle disabled').toBeDisabled();
    await expect(drawer(page).locator('button.ant-btn-link'), 'tanpa "+ Add User"').toHaveCount(0);
    await expect(permRows(page), 'tabel Permission tampil (2 baris)').toHaveCount(2);
    const dis = await permRows(page).evaluateAll((rows) => rows.flatMap((r) => [...r.querySelectorAll('input[type="checkbox"]')].map((e) => e.disabled)));
    expect(dis.length, '8 checkbox').toBe(8);
    expect(dis.every(Boolean), 'semua checkbox disabled').toBe(true);
    await expect(permRows(page).first().locator('button.btn-action'), 'tanpa tombol hapus baris').toHaveCount(0);
    await app.screenshot('AC18-02-permission-read-only');
    await closeTopLayer(page);

    // AC-20: di dalam P (View saja): "+" tanpa Add Folder / Store Document; baris anak: Info + View saja
    const inP = await openFolder(page, '0QA25-P');
    expect(inP.status).toBe(200);
    expect(inP.body.result.access.store, 'B tanpa Store di P').toBe(false);
    await app.settle();
    const plusP = await plusItems(page, app, 'AC20-02b-plus-dropdown-di-P-B');
    monitor.note(`"+" di P (B): ${plusP.join(' | ')}`);
    expect(plusP.some((t) => /Add Folder|Tambah Folder/.test(t)), 'P: tanpa Add Folder').toBe(false);
    expect(plusP.some((t) => /Store Document|Letakkan Dokumen/.test(t)), 'P: tanpa Store Document').toBe(false);
    expect(plusP.some((t) => /Hand Over|Serahkan/.test(t)), 'P: Hand Over tetap (BR-13)').toBe(true);
    expect(plusP.some((t) => /Receive|Terima/.test(t)), 'P: Receive tetap (BR-13)').toBe(true);
    await app.screenshot('AC20-02-plus-di-P-B');
    const cItems = (await rowMenuItems(page, '0QA25-P-C')).items;
    monitor.note(`menu baris P-C (B, hak diwarisi dari P): ${cItems.join(' | ')}`);
    expect(hasItem(cItems, MENU.info) && hasItem(cItems, MENU.view), 'P-C: Info + View').toBe(true);
    expect(hasItem(cItems, MENU.move) || hasItem(cItems, MENU.del), 'P-C: tanpa Move/Delete (induk dominan)').toBe(false);
    await page.keyboard.press('Escape');
    // dokumen: menu tidak dibatasi hak folder (BR-13): Info + Move
    const dItems = (await rowMenuItems(page, '0QA25-P-DOC')).items;
    monitor.note(`menu baris P-DOC (B): ${dItems.join(' | ')}`);
    expect(hasItem(dItems, MENU.info) && hasItem(dItems, MENU.move), 'dokumen: Info + Move (tidak dicek hak folder)').toBe(true);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // di dalam STORE (View+Store): "+" memuat Add Folder & Store Document.
    // Klik tombol aksi baris dokumen ikut memicu klik sel dokumen (pra-ada: breadcrumb diganti breadcrumb dokumen, fixture tanpa
    // kolom breadcrumbs), jadi kembali ke root dengan memuat ulang layar, bukan lewat breadcrumb.
    const backRoot = listResponse(page, (u) => !folderParam(u));
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    await backRoot;
    await app.settle();
    const inS = await openFolder(page, '0QA25-STORE');
    expect(inS.status).toBe(200);
    expect(inS.body.result.access, 'B di STORE').toEqual({ view: true, update: false, delete: false, store: true, manage_permission: false });
    await app.settle();
    const plusS = await plusItems(page, app, 'AC20-03b-plus-dropdown-di-STORE-B');
    monitor.note(`"+" di STORE (B): ${plusS.join(' | ')}`);
    expect(plusS.some((t) => /Add Folder|Tambah Folder/.test(t)), 'STORE: Add Folder').toBe(true);
    expect(plusS.some((t) => /Store Document|Letakkan Dokumen/.test(t)), 'STORE: Store Document').toBe(true);
    await app.screenshot('AC20-03-plus-di-STORE-B');

    await noToast(page, 'AC-18/20');
    noServerError(monitor);
    monitor.check();
    expect(fx['0QA25-P']).toBeTruthy();
  });

  // ------------------------------------------------------------------------------------------------ AC-19 + AC-20 (B)
  test('[user2] ED-1025 AC-19/AC-20 B tanpa View: layar akses ditolak (tanpa toast), ikon perisai, menu baris tanpa tombol, Back to Archive', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const fx = ids();
    monitor.note(`DB sesi ${session.dbName}; user B tanpa baris di 0QA25-DENY`);

    const rootResp = listResponse(page, (u) => !folderParam(u));
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    const root = await rootResp;
    expect(root.status).toBe(200);
    const denyRow = rowsOf(root).find((r) => rowName(r) === '0QA25-DENY');
    expect(denyRow, 'folder ber-permission tetap tampil di daftar (BR-10)').toBeTruthy();
    expect(denyRow.access, 'B di DENY: access semua false').toEqual({ view: false, update: false, delete: false, store: false, manage_permission: false });
    await app.settle();
    await expect(rowOf(page, '0QA25-DENY').locator('.anticon-safety-certificate'), 'ikon perisai di DENY').toHaveCount(1);
    // AC-20: tanpa View dan tanpa Update -> tombol ... disembunyikan
    await expect(rowOf(page, '0QA25-DENY').locator('button.btn-action'), 'DENY: tanpa tombol aksi baris').toHaveCount(0);
    await app.screenshot('AC19-01-root-B-DENY-perisai-tanpa-aksi');

    // klik folder -> 403 ARCHIVE407 -> layar 06
    const denied = listResponse(page, (u) => folderParam(u) === fx['0QA25-DENY']);
    await rowOf(page, '0QA25-DENY').locator('td.cursor-pointer').first().click();
    const dr = await denied;
    expect(dr.status, 'GET archives?id_archive=DENY').toBe(403);
    expect(dr.body.msg_code, 'msg_code').toBe('ARCHIVE407');
    expect(dr.body.result.denied_by, 'denied_by').toEqual({ id_archive: fx['0QA25-DENY'], name: '0QA25-DENY' });
    const screen = page.locator('.archive-access-denied');
    await screen.waitFor({ state: 'visible', timeout: 15000 });
    await noToast(page, 'layar akses ditolak');
    const stxt = await screen.innerText();
    monitor.note(`layar 06 (B, EN): ${stxt.replace(/\s+/g, ' ')}`);
    expect(stxt, 'judul').toMatch(/You do not have access to this folder/);
    expect(stxt, 'teks menyebut nama folder + permission view').toMatch(/Folder 0QA25-DENY has folder permission enabled and your account has not been given view permission/);
    expect(stxt, 'catatan dokumen tetap muncul di pencarian').toMatch(/Documents in this folder can still appear in document search results/);
    expect((await screen.locator('b').first().innerText()).trim(), 'nama folder penolak ditebalkan').toBe('0QA25-DENY');
    await expect(screen.locator('button', { hasText: /Back to Archive/ }), 'tombol Back to Archive').toBeVisible();
    // toolbar dan tabel tersembunyi; breadcrumb tetap
    await expect(page.locator('.archive-page .search-bar'), 'search bar tersembunyi').toHaveCount(0);
    await expect(page.locator('.archive-page .anticon-plus'), 'tombol "+" tersembunyi').toHaveCount(0);
    await expect(page.locator('.archive-page button', { hasText: /^(Select|Pilih)$/ }), 'tombol Select tersembunyi').toHaveCount(0);
    await expect(page.locator('.archive-page .ant-table'), 'tabel tersembunyi').toHaveCount(0);
    const crumbs = (await page.locator('.archive-page .breadcrumbs').innerText()).replace(/\s+/g, ' ').trim();
    monitor.note(`breadcrumb: ${crumbs}`);
    expect(crumbs, 'breadcrumb tetap: Archive > 0QA25-DENY').toMatch(/(Archive|Arsip).*0QA25-DENY/);
    await app.screenshot('AC19-02-layar-akses-ditolak');

    // Back to Archive -> daftar root
    const rootAgain = listResponse(page, (u) => !folderParam(u));
    await screen.locator('button', { hasText: /Back to Archive/ }).click();
    const ra = await rootAgain;
    expect(ra.status, 'GET archives (root) setelah Back to Archive').toBe(200);
    await page.locator('.archive-page .ant-table').first().waitFor({ state: 'visible', timeout: 15000 });
    await expect(page.locator('.archive-access-denied'), 'layar 06 hilang').toHaveCount(0);
    await expect(rowOf(page, '0QA25-MOVE'), 'daftar root tampil lagi').toBeVisible();
    await app.screenshot('AC19-03-setelah-back-to-archive');

    // folder anak yang ditolak oleh INDUK: lewat pencarian -> klik baris folder DENY-C -> denied_by = induk
    const searchResp = listResponse(page, (u) => /0QA25-DENY-C/.test(u.searchParams.get('search') || ''));
    const input = page.locator('.archive-page .search-bar input').first();
    await input.fill('0QA25-DENY-C');
    await input.press('Enter');
    const sr = await searchResp;
    expect(sr.status, 'GET archives?search=0QA25-DENY-C').toBe(200);
    const child = rowsOf(sr).find((r) => rowName(r) === '0QA25-DENY-C');
    expect(child, 'folder anak muncul di hasil cari (BR-12)').toBeTruthy();
    expect(child.access.view, 'B ber-View di DENY-C tetapi induk menolak: access.view false').toBe(false);
    await app.settle();
    const deniedC = listResponse(page, (u) => folderParam(u) === fx['0QA25-DENY-C']);
    await rowOf(page, '0QA25-DENY-C').locator('td.cursor-pointer').first().click();
    const dc = await deniedC;
    expect(dc.status, 'GET archives?id_archive=DENY-C').toBe(403);
    expect(dc.body.result.denied_by.name, 'denied_by = folder induk').toBe('0QA25-DENY');
    await screen.waitFor({ state: 'visible', timeout: 15000 });
    expect((await screen.locator('b').first().innerText()).trim(), 'layar menyebut folder INDUK yang menolak').toBe('0QA25-DENY');
    await noToast(page, 'layar akses ditolak (induk)');
    await app.screenshot('AC19-04-ditolak-oleh-induk');
    const rootThird = listResponse(page, (u) => !folderParam(u));
    await screen.locator('button', { hasText: /Back to Archive/ }).click();
    await rootThird;

    expectDenied403(monitor, fx['0QA25-DENY']);
    expectDenied403(monitor, fx['0QA25-DENY-C']);
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-21 (B)
  test('[user2] ED-1025 AC-21 B: picker modal Pindahkan & pindah massal - folder tanpa Store tidak bisa dipilih, tanpa View tidak bisa dibuka', async ({ app, page, monitor, session }) => {
    traceApi(page, monitor);
    const fx = ids();
    monitor.note(`DB sesi ${session.dbName}; user B`);

    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    await app.settle();

    const radioState = async (scope, name) => {
      const row = pickerRow(page, scope, name);
      await row.waitFor({ state: 'visible', timeout: 20000 });
      return row.locator('input[type="radio"]').evaluate((e) => ({ disabled: e.disabled, checked: e.checked }));
    };

    const checkPicker = async (scope, label, shot) => {
      // P (View saja): bisa dibuka, tidak bisa dipilih; DENY (tanpa View, tanpa Store): tidak bisa dibuka & dipilih; STORE (V+S): bisa dipilih
      const sP = await radioState(scope, '0QA25-P');
      const sD = await radioState(scope, '0QA25-DENY');
      const sS = await radioState(scope, '0QA25-STORE');
      const sM = await radioState(scope, '0QA25-EDIT');
      monitor.note(`${label}: radio P=${JSON.stringify(sP)} DENY=${JSON.stringify(sD)} STORE=${JSON.stringify(sS)} EDIT=${JSON.stringify(sM)}`);
      expect(sP.disabled, `${label}: P (tanpa Store) tidak bisa dipilih`).toBe(true);
      expect(sD.disabled, `${label}: DENY (tanpa Store) tidak bisa dipilih`).toBe(true);
      expect(sS.disabled, `${label}: STORE (Store) bisa dipilih`).toBe(false);
      expect(sM.disabled, `${label}: EDIT (nonaktif) bisa dipilih`).toBe(false);
      await page.waitForTimeout(900); // animasi modal selesai sebelum screenshot
      await app.screenshot(shot);

      // DENY tanpa View: klik nama tidak membuka folder (tidak ada request id_archive=DENY, tidak ada 403)
      let calledDeny = false;
      const watch = (r) => { if (LIST_RE.test(r.url()) && decodeURIComponent(r.url()).includes(fx['0QA25-DENY'])) calledDeny = true; };
      page.on('request', watch);
      const denyRow = pickerRow(page, scope, '0QA25-DENY');
      const nameCell = denyRow.locator('td').nth(1);
      monitor.note(`${label}: sel nama DENY class="${await nameCell.getAttribute('class')}"`);
      await denyRow.locator('td', { hasText: '0QA25-DENY' }).first().click();
      await page.waitForTimeout(1500);
      page.off('request', watch);
      expect(calledDeny, `${label}: klik nama DENY (tanpa View) tidak memanggil GET archives?id_archive=DENY`).toBe(false);

      // P (View): bisa dibuka (klik nama) -> breadcrumb picker berubah dan baris anak tampil
      const inP = listResponse(page, (u) => folderParam(u) === fx['0QA25-P']);
      await pickerRow(page, scope, '0QA25-P').locator('td.cursor-pointer').first().click();
      const pr = await inP;
      expect(pr.status, `${label}: P (View) bisa dibuka`).toBe(200);
      await app.settle();
      await expect(pickerRow(page, scope, '0QA25-P-C'), `${label}: isi P tampil`).toBeVisible();
      // di dalam P (Store false di P) -> anak P-C mewarisi: tidak bisa dipilih
      const sC = await radioState(scope, '0QA25-P-C');
      expect(sC.disabled, `${label}: P-C (hak diwarisi dari P, tanpa Store) tidak bisa dipilih`).toBe(true);
    };

    // (a) modal Pindahkan satu folder (EDIT sebagai yang dipindah)
    await pickRowMenu(page, '0QA25-MOVE', MENU.move);
    const modal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await checkPicker(modal, 'modal Pindahkan', 'AC21-01-modal-pindahkan-picker');
    // memilih STORE (bisa): tombol konfirmasi hidup, tetapi TIDAK dikonfirmasi
    await closeTopLayer(page);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });

    // (b) pindah massal: Pilih -> centang MOVE -> Pindahkan -> Next -> picker
    await page.locator('.archive-page button', { hasText: /^(Select|Pilih)$/ }).first().click();
    await rowOf(page, '0QA25-MOVE').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Move|Pindahkan)$/ }).first().click();
    const bulk = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Next|Lanjut)$/ }).first().click();
    await app.settle();
    await checkPicker(bulk, 'pindah massal', 'AC21-02-pindah-massal-picker');
    // pilih STORE lewat radio: tombol Move hidup
    await bulk.locator('.breadcrumbs button').first().click();
    await app.settle();
    await pickerRow(page, bulk, '0QA25-STORE').locator('input[type="radio"]').check();
    await expect(bulk.locator('button.ant-btn-primary', { hasText: /^(Move|Pindahkan)$/ }).first(), 'STORE dipilih: tombol Move aktif').toBeEnabled();
    await app.screenshot('AC21-03-pindah-massal-STORE-dipilih');
    await closeTopLayer(page);
    await noToast(page, 'AC-21');
    noServerError(monitor);
    monitor.check();
  });
});
