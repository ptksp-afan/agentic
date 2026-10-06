'use strict';

/**
 * ED-1024 - layar Archive (tanpa perubahan kode FE; diverifikasi e2e).
 *
 * Keadaan user QA dipasang fixture (fixtures.json -> state.php) menurut env E2E_STATE:
 *   jog    : AC-16  (QA_USER = role 26 Pusat - Support + employee lokasi JOG saja; is_superadmin 4, tidak di-bypass)
 *   super  : AC-17  (QA_USER = role 1 is_superadmin 1; employee JOG saja; satu dokumen Billing diaktifkan sementara)
 *   noperm : AC-13 sisi FE (role 28 tanpa permission Archive = simulasi tenant tanpa lisensi; lisensi sungguhan = MANUAL Gate 2)
 * Jalankan satu keadaan per run, mis.:  E2E_STATE=jog scripts/e2e/run.sh ED-1024 -- --grep "AC-16|route"
 * Semua flow NON-mutasi: modal/drawer dibuka lalu ditutup tanpa simpan.
 */

const { test, expect } = require('e2e-harness');

const STATE = process.env.E2E_STATE || '';
const PATH = '/archives';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const TYPES_RE = /\/select\/document-archive\/archive\/types(\?|$)/;
const rowName = (row) => (Array.isArray(row.name) ? (row.name[0] || {}).transaction_no : row.name);
const ARCHIVE_MENU = /^(Arsip|Archive)$/;

function needState(expected) {
  test.skip(STATE !== expected, `butuh E2E_STATE=${expected} (sekarang '${STATE}')`);
}

/** Tunggu GET list archive (opsional dengan predikat URL) -> {status, url, body}. */
function listResponse(page, pred) {
  return page
    .waitForResponse(
      (r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))),
      { timeout: 45000 },
    )
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
}

/** id folder yang dibuka menurut query (FE mengirim idArchive camelCase; BE membaca id_archive). */
const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];
const namesOf = (resp) => rowsOf(resp).map(rowName);

/** Teks tabel utama halaman Archive. */
const tableText = (page) => page.locator('.archive-page .ant-table').first().innerText();
const mainRows = (page) => page.locator('.archive-page > .ant-card .ant-table-row, .archive-page .ant-card-body > div .ant-table-row');

async function openFilterPopover(page) {
  await page.locator('.archive-page .search-config').first().click();
  const pop = page.locator('.archive-page .search-bar-overlay').first();
  await pop.locator('.ant-select').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(700); // animasi popover selesai
  return pop;
}

/** Buka dropdown Type di popover filter dan kembalikan teks opsi yang tampil. */
async function openTypeSelect(page, pop) {
  const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
  const option = dd.locator('.ant-select-item-option').first();
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await pop.locator('.ant-select').first().click();
    try {
      await option.waitFor({ state: 'visible', timeout: attempt === 3 ? 15000 : 4000 });
      return dd;
    } catch (e) {
      if (attempt === 3) throw e;
    }
  }
  return dd;
}

async function clickRowMenu(page, rowText, itemRegex) {
  const row = page.locator('.archive-page .ant-table-row', { hasText: rowText }).first();
  await row.waitFor({ state: 'visible', timeout: 15000 });
  await row.locator('button.btn-action').click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
}

async function closeTopLayer(page) {
  // drawer: tombol close; modal: tombol close (X)
  const closer = page.locator('.ant-drawer-open .ant-drawer-close, .ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last();
  if (await closer.count()) await closer.click();
  await page.waitForTimeout(600);
}

/** Catat tiap panggilan API Archive (status + path + query) ke catatan test: bukti di galeri/laporan. */
function traceArchiveApi(page, monitor) {
  page.on('response', (r) => {
    const u = r.url();
    if (/\/document-archive\/|\/select\/document-archive\//.test(u) && r.request().method() === 'GET') {
      monitor.note(`API ${r.status()} GET ${decodeURIComponent(u.replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 200)}`);
    }
  });
}

function noServerError(monitor) {
  // monitor.responses = semua respons browser (apiResponses() membandingkan prefix URL dengan port :80 dan tidak cocok di mesin ini)
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  expect(api.length, 'ada panggilan API v5 yang tercatat (monitor aktif)').toBeGreaterThan(0);
}

test.describe('ED-1024 Archive', () => {
  // ------------------------------------------------------------------------------------------------ AC-16
  test('[default] ED-1024 AC-16 user JOG: layar Archive, scope lokasi, filter Type, drawer & modal', async ({ app, page, monitor, session }) => {
    needState('jog');
    traceArchiveApi(page, monitor);
    monitor.note(`DB sesi ${session.dbName}; state=${STATE}: QA_USER role 26 (is_superadmin 4) + employee lokasi JOG saja`);

    // 1. layar terbuka tanpa error, menu Archive tampil (lisensi ada), root tanpa CABANG - SEMARANG
    const rootResp = listResponse(page, (u) => !folderParam(u));
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    expect(app.redirectOf(PATH), 'layar tidak dialihkan').toBeNull();
    const root = await rootResp;
    expect(root.status, 'GET document-archive/archives (root)').toBe(200);
    const rootNames = namesOf(root);
    monitor.note(`root (halaman 1): ${rootNames.join(' | ')}`);
    for (const n of ['Backup Arsip', 'CABANG - JOGJA', 'PUSAT - MAGELANG']) expect(rootNames, `root memuat ${n}`).toContain(n);
    expect(rootNames, 'root TIDAK memuat CABANG - SEMARANG').not.toContain('CABANG - SEMARANG');
    const txt = await tableText(page);
    expect(txt, 'UI memuat Backup Arsip').toContain('Backup Arsip');
    expect(txt, 'UI memuat CABANG - JOGJA').toContain('CABANG - JOGJA');
    expect(txt, 'UI tanpa CABANG - SEMARANG').not.toContain('CABANG - SEMARANG');
    await expect(page.locator('.ant-menu-item', { hasText: ARCHIVE_MENU }).first(), 'menu Archive tampil di sidebar').toBeVisible();
    await app.screenshot('AC16-01-root-user-JOG');

    // 2. popover filter Type: opsi dari endpoint types (urutan + Billing)
    const typesResp = page.waitForResponse((r) => TYPES_RE.test(r.url()), { timeout: 30000 }).catch(() => null);
    const pop = await openFilterPopover(page);
    const dd = await openTypeSelect(page, pop);
    const tr = await typesResp;
    expect(tr, 'GET select/document-archive/archive/types terpanggil').not.toBeNull();
    expect(tr.status(), 'GET types').toBe(200);
    const typesBody = await tr.json();
    const optionValues = ((typesBody.result && typesBody.result.options) || []).map((o) => String(o.value));
    expect(optionValues, 'urutan opsi dari endpoint types').toEqual(['FOLDER', '6', '7', '8', '9', '29', '31', '222']);
    const ddText = await dd.innerText();
    expect(ddText, 'dropdown Type memuat opsi Billing (Penagihan)').toMatch(/Penagihan|Billing/);
    expect(ddText, 'dropdown Type memuat Pesanan Penjualan').toMatch(/Pesanan Penjualan|Sales Order/);
    monitor.note(`opsi Type (UI): ${ddText.split('\n').filter(Boolean).join(' | ')}`);
    await app.screenshot('AC16-02-filter-type-options');
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);

    // 3. Display Setting terbuka
    await page.locator('.archive-page button', { hasText: /Pengaturan Tampilan|Display Setting/ }).first().click();
    await page.locator('.ant-drawer-open').first().waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC16-03-display-setting');
    await closeTopLayer(page);

    // 4. pencarian "SML" (semua level): SMLHO/SMLSMG tidak muncul untuk user JOG
    const searchResp = listResponse(page, (u) => /SML/.test(u.searchParams.get('search') || ''));
    const input = page.locator('.archive-page .search-bar input').first();
    await input.fill('SML');
    await input.press('Enter');
    const sr = await searchResp;
    expect(sr.status, 'GET archives?search=SML').toBe(200);
    const sNames = namesOf(sr);
    monitor.note(`hasil cari "SML" (halaman 1): ${sNames.join(' | ')}`);
    expect(sNames.some((n) => /SMLHO|SMLSMG/.test(n)), 'cari SML: tanpa SMLHO/SMLSMG').toBe(false);
    expect(sNames, 'cari SML memuat SMLYK').toContain('SMLYK');
    await app.settle();
    await app.screenshot('AC16-04-cari-SML');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await app.settle();

    // 5. buka folder Backup Arsip: hanya SMLYK dan SMLYK - BRANGKAS (tanpa SMLHO/SMLSMG)
    const folderResp = listResponse(page, (u) => !!folderParam(u));
    await page.locator('.archive-page .ant-table-row', { hasText: 'Backup Arsip' }).first().locator('td.cursor-pointer').first().click();
    const fr = await folderResp;
    expect(fr.status, 'GET archives?id_archive=Backup Arsip').toBe(200);
    await app.settle();
    const folders = rowsOf(fr).filter((r) => r.type === 'Folder').map(rowName);
    monitor.note(`isi Backup Arsip, folder: ${folders.join(' | ')}`);
    expect(folders.sort(), 'folder di Backup Arsip untuk user JOG').toEqual(['SMLYK', 'SMLYK - BRANGKAS']);
    const ftxt = await tableText(page);
    expect(ftxt).toContain('SMLYK');
    expect(ftxt, 'UI tanpa SMLHO').not.toContain('SMLHO');
    expect(ftxt, 'UI tanpa SMLSMG').not.toContain('SMLSMG');
    await app.screenshot('AC16-05-isi-Backup-Arsip');

    // 6. drawer Info (history) dan drawer View (show) pada folder SMLYK
    const histResp = page.waitForResponse((r) => /\/archives\/history\//.test(r.url()), { timeout: 30000 });
    await clickRowMenu(page, 'SMLYK - BRANGKAS', /Info/);
    const hr = await histResp;
    expect(hr.status(), 'GET archives/history/{id}').toBe(200);
    await page.locator('.ant-drawer-open').first().waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC16-06-drawer-info-history');
    await closeTopLayer(page);

    const showResp = page.waitForResponse((r) => /\/document-archive\/archives\/[^/?]+(\?|$)/.test(r.url()) && !/history|rename|delete|create/.test(r.url()) && r.request().method() === 'GET', { timeout: 30000 });
    await clickRowMenu(page, 'SMLYK - BRANGKAS', /Lihat|View/);
    const shr = await showResp;
    expect(shr.status(), 'GET archives/{id}').toBe(200);
    const shBody = await shr.json();
    expect(shBody.result && shBody.result.name, 'drawer View memuat nama folder').toBe('SMLYK - BRANGKAS');
    await page.locator('.ant-drawer-open').first().waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC16-07-drawer-view');
    await closeTopLayer(page);

    // 7. modal Pindahkan: tabel pilih folder (endpoint list yang sama) tanpa folder di luar scope
    const moveListResp = listResponse(page, (u) => !folderParam(u));
    await clickRowMenu(page, 'SMLYK - BRANGKAS', /Pindahkan|Move/);
    const mv = await moveListResp;
    expect(mv.status, 'tabel di modal Pindahkan: GET archives').toBe(200);
    const modal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    const mtxt = await modal.innerText();
    expect(mtxt, 'modal Pindahkan memuat folder root').toContain('PUSAT - MAGELANG');
    expect(mtxt, 'modal Pindahkan tanpa CABANG - SEMARANG').not.toContain('CABANG - SEMARANG');
    await app.screenshot('AC16-08-modal-pindahkan');
    await closeTopLayer(page);

    // 8. modal Add Folder (dengan induk Backup Arsip): select lokasi memuat opsi dari endpoint locations
    await page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first().click();
    const addMenu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    await addMenu.waitFor({ state: 'visible', timeout: 10000 });
    const menuText = await addMenu.innerText();
    monitor.note(`menu tombol +: ${menuText.split('\n').filter(Boolean).join(' | ')}`);
    const locResp = page.waitForResponse((r) => /select\/document-archive\/archive\/locations/.test(r.url()), { timeout: 30000 }).catch(() => null);
    // modal Add Folder membaca folder induk (GET archives/{id}); form di-reset begitu datanya tiba, jadi tunggu dulu
    const parentResp = page.waitForResponse((r) => /\/document-archive\/archives\/\d+(\?|$)/.test(r.url()) && r.request().method() === 'GET', { timeout: 30000 });
    await addMenu.locator('li.ant-dropdown-menu-item', { hasText: /Tambah Folder|Add Folder/ }).first().click();
    const pr = await parentResp;
    expect(pr.status(), 'modal Add Folder: GET archives/{induk}').toBe(200);
    const addModal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await addModal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await addModal.locator('label.ant-radio-wrapper, .ant-radio-button-wrapper', { hasText: /Beberapa Lokasi|Several Location/ }).first().click();
    const locSelect = addModal.locator('.ant-select').first();
    await locSelect.waitFor({ state: 'visible', timeout: 10000 });
    await locSelect.click();
    const lr = await locResp;
    expect(lr && lr.status(), 'GET select/document-archive/archive/locations').toBe(200);
    await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
    const locText = await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last().innerText();
    monitor.note(`opsi lokasi di modal Add Folder: ${locText.split('\n').filter(Boolean).join(' | ')}`);
    await app.screenshot('AC16-09-modal-add-folder-lokasi');
    await page.keyboard.press('Escape');
    await closeTopLayer(page);

    // 9. modal Hand Over terbuka tanpa error
    await page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first().click();
    const addMenu2 = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    await addMenu2.waitFor({ state: 'visible', timeout: 10000 });
    await addMenu2.locator('li.ant-dropdown-menu-item', { hasText: /Serahkan Dokumen|Hand Over Document/ }).first().click();
    const hoModal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await hoModal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot('AC16-10-modal-hand-over');
    await closeTopLayer(page);

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-17
  test('[default] ED-1024 AC-17 superadmin (is_superadmin 1): root lengkap, filter Type Billing', async ({ app, page, monitor, session }) => {
    needState('super');
    traceArchiveApi(page, monitor);
    monitor.note(`DB sesi ${session.dbName}; state=${STATE}: QA_USER role 1 (is_superadmin 1) + employee lokasi JOG saja; dokumen Billing diaktifkan sementara`);

    const rootResp = listResponse(page, (u) => !folderParam(u));
    await app.open(PATH, { waitFor: '.archive-page .ant-table' });
    expect(app.redirectOf(PATH), 'layar tidak dialihkan').toBeNull();
    const root = await rootResp;
    expect(root.status, 'GET document-archive/archives (root)').toBe(200);
    const rootNames = namesOf(root);
    monitor.note(`root (halaman 1): ${rootNames.join(' | ')}`);
    for (const n of ['Backup Arsip', 'CABANG - JOGJA', 'CABANG - SEMARANG', 'PUSAT - MAGELANG']) expect(rootNames, `root memuat ${n}`).toContain(n);
    const txt = await tableText(page);
    expect(txt, 'UI memuat CABANG - SEMARANG').toContain('CABANG - SEMARANG');
    expect(txt, 'UI memuat CABANG - JOGJA').toContain('CABANG - JOGJA');
    await app.screenshot('AC17-01-root-superadmin');

    // filter Type = Penagihan (Billing)
    const pop = await openFilterPopover(page);
    const dd = await openTypeSelect(page, pop);
    await dd.locator('.ant-select-item-option', { hasText: /^(Penagihan|Billing)$/ }).first().click();
    const billResp = listResponse(page, (u) => /222/.test(u.searchParams.get('search') || ''));
    await pop.locator('button[type="submit"], button.ant-btn-primary').filter({ hasText: /Cari|Search/ }).first().click();
    const br = await billResp;
    expect(br.status, 'GET archives?search={type:222}').toBe(200);
    const bRows = rowsOf(br);
    monitor.note(`filter Type=Billing: total=${br.body.result.total}, baris halaman 1: ${bRows.map((r) => `${rowName(r)} [${r.type}]`).join(' | ')}`);
    expect(bRows.length, 'filter Type=Billing menampilkan hasil').toBeGreaterThan(0);
    expect(bRows.every((r) => r.type === 'Penagihan' || r.type === 'Billing'), 'semua baris berlabel Billing').toBe(true);
    await app.settle();
    const btxt = await tableText(page);
    expect(btxt, 'UI menampilkan baris Billing').toMatch(/BILL\//);
    expect(btxt, 'UI: label tipe Penagihan').toMatch(/Penagihan|Billing/);
    await app.screenshot('AC17-02-filter-type-billing');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await app.settle();

    // (tambahan) superadmin melihat semua folder di Backup Arsip walau employee hanya JOG
    const folderResp = listResponse(page, (u) => !!folderParam(u));
    await page.locator('.archive-page .ant-table-row', { hasText: 'Backup Arsip' }).first().locator('td.cursor-pointer').first().click();
    const fr = await folderResp;
    expect(fr.status, 'GET archives?id_archive=Backup Arsip').toBe(200);
    await app.settle();
    const folders = rowsOf(fr).filter((r) => r.type === 'Folder').map(rowName).sort();
    monitor.note(`isi Backup Arsip, folder: ${folders.join(' | ')}`);
    for (const n of ['SMLYK', 'SMLYK - BRANGKAS', 'SMLHO - KANTOR ADMIN', 'SMLSMG', 'SMLSMG - BRANGKAS', 'SMLSMG - PROSES KIRIM']) {
      expect(folders, `superadmin melihat ${n}`).toContain(n);
    }
    await app.screenshot('AC17-03-isi-Backup-Arsip-superadmin');

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-13 (FE)
  test('[default] ED-1024 AC-13 FE (simulasi): role tanpa permission Archive - menu hilang, BE menolak, route', async ({ app, page, monitor, session }) => {
    needState('noperm');
    traceArchiveApi(page, monitor);
    monitor.note(`DB sesi ${session.dbName}; state=${STATE}: QA_USER role 28 (tanpa List Archive; lisensi Salesman Activity TETAP aktif) = simulasi akibat lisensi tanpa seed permission Archive`);

    const infoP = page.waitForResponse((r) => /\/auth\/info(\?|$)/.test(r.url()), { timeout: 30000 });
    const listP = page.waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()), { timeout: 30000 }).catch(() => null);
    await app.open(PATH, { waitFor: '.archive-page' });
    await app.settle();

    // prasyarat: auth/info tidak memuat satu pun permission Archive
    const info = (await (await infoP).json()).result;
    const archivePerms = (info.permissions || []).filter((p) => /Archive|Folder|Document/.test(p));
    expect(archivePerms, 'auth/info: tanpa permission Archive').toEqual([]);
    monitor.note(`auth/info: ${(info.permissions || []).length} permission, tanpa Archive; roles: ${(info.roles || []).map((r) => r.role_name).join(', ')}`);

    // (a) menu Archive tidak tampil di sidebar
    await expect(page.locator('.ant-menu-item', { hasText: ARCHIVE_MENU }), 'menu Archive tidak tampil').toHaveCount(0);

    // (b) BE menolak 403 GE0114 (parameter "List Archive"), tanpa data
    const lr = await listP;
    expect(lr, 'layar memanggil GET archives (guard route FE tidak mencegah pemanggilan)').not.toBeNull();
    expect(lr.status(), 'GET archives tanpa permission').toBe(403);
    const lb = await lr.json();
    expect(lb.code, 'kode penolakan').toBe('GE0114');
    expect(lb.parameter, 'parameter = nama permission yang kurang').toBe('List Archive');
    expect(lb.result, 'tanpa data bocor').toBeUndefined();
    await expect(page.locator('.archive-page .ant-table-row'), 'tabel tanpa baris').toHaveCount(0);

    // (c) KARAKTERISASI guard route FE: PrivateRoute tidak pernah mengalihkan (temuan F-1, pra-ada global)
    const finalPath = String(await app.currentPath()).split('?')[0];
    monitor.note(`F-1 (karakterisasi): setelah auth/info lengkap path tetap ${finalPath} (tidak dialihkan ke /unathorized); guard route permission di PrivateRoute tidak efektif karena app.loadingUserData tidak pernah di-set (components/PrivateRoute/index.js:18-24)`);
    await app.screenshot('AC13-01-tanpa-permission-route-tidak-dialihkan');

    // 403 + console error AxiosError di atas adalah penolakan yang DIHARAPKAN: keluarkan dari monitor hanya yang persis itu
    const expected = (e) =>
      (e.kind === 'response' && e.status === 403 && /\/document-archive\/archives(\?|$)/.test(e.url)) ||
      (e.kind === 'console' && /Request failed with status code 403/.test(e.text || ''));
    const dropped = monitor.errors.filter(expected);
    expect(dropped.length, '403 dan console error penolakan tercatat').toBeGreaterThanOrEqual(1);
    monitor.errors = monitor.errors.filter((e) => !expected(e));
    monitor.note(`penolakan 403 yang diharapkan dikeluarkan dari pemeriksaan error: ${dropped.length} entri`);
    noServerError(monitor);
    monitor.check();
  });
});
