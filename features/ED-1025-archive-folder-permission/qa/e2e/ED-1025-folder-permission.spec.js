'use strict';

/**
 * ED-1025 - Archive: Folder Permission per folder (tab Izin, layar akses ditolak, menu baris / "+" / picker) + revisi review 2026-10-08.
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA25-" (peta di fixture.php).
 * Profil: [default] = A (QA_USER, bahasa ID), [user2] = B (QA_USER2, bahasa EN). Keduanya non-superadmin pada keadaan asli.
 *
 * Peran uji (revisi): tiap test yang butuh peran tertentu memanggil `fixture.php role <who> <preset>` (A = punya Folder Permissions,
 * C = A tanpa Folder Permissions, D = List Archive + View Folder + Folder Permissions, SUPER = role 1) lalu membuka layar ulang
 * (FE membaca peran dari GET auth/info). Peran asli dan bahasa dipulihkan oleh teardown fixture (`down`, diverifikasi).
 * Jalankan dengan `--workers 1` (tukar peran satu user tidak boleh berebut antar test): scripts/e2e/run.sh ED-1025 --profile default,user2 --workers 1
 *
 * Mutasi hanya pada data fixture (dibuang di teardown). Tombol Pindahkan/Hapus tidak pernah dikonfirmasi.
 *
 * Revisi ronde 3 (ED-1133): AC-39 (simpan lewat kolom Semua saja, W > SEM), AC-40 (perisai di picker Pindahkan & pindah massal, id dan en),
 * AC-41 (oracle `expectedPlus`: Set Permission by User = `Folder Permissions`), AC-42 (satu baris per user, W > F42).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const USERS_RE = /\/select\/document-archive\/archive\/users(\?|$)/;
const SHOW_RE = /\/document-archive\/archives\/[^/?]+(\?|$)/;
const rowName = (row) => (Array.isArray(row.name) ? (row.name[0] || {}).transaction_no : row.name);
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const FULL = { view: true, update: true, delete: true, store: true, manage_permission: true };

const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));

/** fixture.php <perintah...>: tukar peran/bahasa, baca baris izin. Keluaran hanya JSON/teks tanpa rahasia. */
function fx(...args) {
  const out = execFileSync(config.get('PHP_BIN', 'php'), [path.join(__dirname, 'fixture.php'), ...args], {
    encoding: 'utf8',
    cwd: config.AGENTIC_DIR,
    timeout: 90000,
    env: { ...process.env },
  });
  return out.trim();
}
const fxJson = (...args) => JSON.parse(fx(...args));
const setRole = (who, preset) => fx('role', who, preset);

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

/** Tombol "+" (Add Folder / Hand Over / Receive / Store Document / ...): buka dropdown, kembalikan teks item, tutup. */
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

/** Item "+" -> kunci FE (`getArchiveAddKeys`), tidak bergantung bahasa. */
const PLUS_KEYS = [
  ['add-folder', /Add Folder|Tambah Folder/],
  ['hand-over', /Hand ?Over|Serah/],
  ['receive', /Receive|Terima/],
  ['add-document', /Store Document|Letakkan Dokumen/],
  ['opname', /Opname/],
  ['set-permission', /Set Permission|Atur Izin/],
];
const plusKeysOf = (texts) => texts.map((t) => (PLUS_KEYS.find(([, re]) => re.test(t)) || ['?:' + t])[0]);

/** Item "+" yang diharapkan menurut permission role dan hak folder aktif (archive.function.js `getArchiveAddKeys`). */
function expectedPlus(perms, { inFolder, access }) {
  const has = (n) => perms.includes(n);
  const store = !inFolder || !access || access.store !== false;
  const view = !inFolder || !access || access.view !== false;
  const keys = [];
  if (has('Add Folder') && store) keys.push('add-folder');
  if (has('Handover Document')) keys.push('hand-over');
  if (has('Receive Document')) keys.push('receive');
  if (has('Add Document') && inFolder && store) keys.push('add-document');
  if (has('Opname Document') && view) keys.push('opname');
  // AC-41 (rev3, P6): Set Permission by User digerbangi `Folder Permissions` (`getArchiveAddKeys` canSetPermission), bukan `Update Folder`
  if (has('Folder Permissions')) keys.push('set-permission');
  return keys;
}

const drawer = (page) => page.locator('.ant-drawer-open').last();
const tabs = (page) => drawer(page).locator('.ant-tabs-tab');
const tabTexts = async (page) => (await tabs(page).allInnerTexts()).map((t) => t.trim());
const TAB_PERMISSION = /^(Permission|Izin)$/;
const TAB_DETAIL = /^(Detail|Details)$/;

async function openTab(page, re) {
  const texts = await tabTexts(page);
  const idx = texts.findIndex((t) => re.test(t));
  expect(idx, `tab ${re} ada (tab: ${texts.join(' | ')})`).toBeGreaterThanOrEqual(0);
  await tabs(page).nth(idx).click();
  await page.waitForTimeout(300);
}

async function openPermissionTab(page) {
  await openTab(page, TAB_PERMISSION);
  await drawer(page).locator('.ant-switch').first().waitFor({ state: 'visible', timeout: 10000 });
}

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

/** Masuk berturut-turut ke jalur folder (nama), mulai dari daftar saat ini. Kembalikan respons folder terakhir. */
async function enter(page, app, names) {
  let last = null;
  for (const n of names) {
    last = await openFolder(page, n);
    expect(last.status, `buka folder ${n}`).toBe(200);
    await app.settle();
  }
  return last;
}

async function openDrawerView(page, name) {
  const show = page.waitForResponse((r) => r.request().method() === 'GET' && SHOW_RE.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
  await pickRowMenu(page, name, /^(Lihat|View)$/);
  const r = await show;
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  return { status: r.status(), body: await r.json().catch(() => null) };
}

const permRows = (page) => drawer(page).locator('.ant-tabs-tabpane-active .ant-table-row');
/** Semua checkbox satu baris tabel Izin: urutan kolom = All, View, Update, Delete, Store. */
const rowAllBoxes = async (row) => row.locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.checked));
/** Empat hak [View, Update, Delete, Store] (tanpa kolom All). */
const rowBoxes = async (row) => (await rowAllBoxes(row)).slice(1);
const rowBoxDisabled = async (row) => row.locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.disabled));
const IDX = { all: 0, view: 1, update: 2, delete: 3, store: 4 };
const box = (row, right) => row.locator('input[type="checkbox"]').nth(IDX[right]);
/** Keadaan checkbox kolom All satu baris. */
const allState = async (row) => ({
  checked: await box(row, 'all').isChecked(),
  indeterminate: await row.locator('label.ant-checkbox-wrapper').first().locator('.ant-checkbox-indeterminate').count().then((n) => n > 0),
});
const permRow = (page, username) => permRows(page).filter({ hasText: new RegExp(`(^|\\s)${esc(username)}(\\s|$)`) }).first();

const saveBtn = (page) => drawer(page).locator('.ant-drawer-extra button.ant-btn-primary');
const confirmModal = (page) => page.locator('.ant-modal-confirm').last();

/** Semua isian tab aktif (Detail) disabled? */
const detailDisabled = (page) =>
  drawer(page).locator('.ant-tabs-tabpane-active input:not([type="hidden"]), .ant-tabs-tabpane-active textarea').evaluateAll((els) => els.map((e) => e.disabled));

/** Catat PUT archives/{id} (body JSON) dan statusnya. */
function trackPuts(page) {
  const puts = [];
  page.on('request', (r) => {
    if (r.method() === 'PUT' && SHOW_RE.test(r.url())) {
      let body = null;
      try {
        body = JSON.parse(r.postData() || '{}');
      } catch (e) {
        body = { _raw: r.postData() };
      }
      puts.push({ url: r.url(), body, status: null });
    }
  });
  page.on('response', (r) => {
    if (r.request().method() === 'PUT' && SHOW_RE.test(r.url())) {
      const hit = [...puts].reverse().find((p) => p.url === r.url() && p.status === null);
      if (hit) hit.status = r.status();
    }
  });
  return puts;
}

const PERM_KEYS = ['isFolderPermission', 'folderPermissions'];
const DETAIL_KEYS = ['name', 'description', 'idArchiveParent', 'isAllLocation', 'idLocations'];
const keysOf = (body) => Object.keys(body || {}).map((k) => k.replace(/_([a-z])/g, (_, c) => c.toUpperCase()));

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

/** Tanpa toast/notifikasi apa pun (alur non-simpan). */
const noToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-notice, .ant-notification-notice'), `${label}: tanpa toast/notifikasi`).toHaveCount(0);
};

/** Alur yang menyimpan: toast sukses ("Perubahan data berhasil") sah, tetapi tidak boleh ada toast galat/peringatan. */
const noErrorToast = async (page, label) => {
  await page.waitForTimeout(1200);
  await expect(page.locator('.ant-message-error, .ant-message-warning, .ant-notification-notice-error, .ant-notification-notice-warning'), `${label}: tanpa toast galat/peringatan`).toHaveCount(0);
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

/** Buka layar Archive (muat ulang penuh: auth/info dibaca ulang, jadi peran/bahasa baru terpakai). */
async function openArchive(app, page) {
  const rootResp = listResponse(page, (u) => !folderParam(u));
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  const root = await rootResp;
  expect(root.status, 'GET archives (root)').toBe(200);
  await app.settle();
  return root;
}

/** Klik Simpan, tunggu PUT archives/{id}; kembalikan {status, body (request), json}. */
async function saveDrawer(page) {
  const put = page.waitForResponse((r) => r.request().method() === 'PUT' && SHOW_RE.test(r.url()), { timeout: 30000 });
  await saveBtn(page).click();
  const pr = await put;
  let body = {};
  try {
    body = JSON.parse(pr.request().postData() || '{}');
  } catch (e) {
    body = {};
  }
  return { status: pr.status(), body, json: await pr.json().catch(() => null) };
}

// ====================================================================================================================
test.describe('ED-1025 folder permission', () => {
  // ------------------------------------------------------------------------------------------------ AC-3 (A, C)
  /** Alur AC-3 (rev) untuk satu peran: root, folder, drawer (tab menurut permission), "+" menurut permission, Info, Pindahkan, pindah massal. */
  async function ac3Flow({ app, page, monitor, session, preset, perms, tag }) {
    traceApi(page, monitor);
    const fx0 = ids();
    const hasFP = perms.includes('Folder Permissions');
    monitor.note(`DB sesi ${session.dbName}; ${tag}; Folder Permissions=${hasFP}; permission Archive: ${perms.filter((p) => /Archive|Folder|Document|Opname/.test(p)).join(', ')}`);

    // 1. layar Archive terbuka: baris folder membawa access, perisai pada folder ber-permission
    const root = await openArchive(app, page);
    expect(app.redirectOf(PATH), 'layar tidak dialihkan').toBeNull();
    const rootRows = rowsOf(root);
    const byName = (n) => rootRows.find((r) => rowName(r) === n);
    for (const n of ['0QA25-EDIT', '0QA25-P', '0QA25-DENY', '0QA25-STORE', '0QA25-MOVE']) expect(byName(n), `root memuat ${n}`).toBeTruthy();
    expect(byName('0QA25-P').is_folder_permission, 'P: is_folder_permission').toBe(1);
    expect(byName('0QA25-P').access, `P: access ${tag} (manage_permission = Folder Permissions)`).toEqual({ ...FULL, manage_permission: hasFP });
    expect(byName('0QA25-MOVE').is_folder_permission, 'MOVE: nonaktif').toBe(0);
    await expect(rowOf(page, '0QA25-P').locator('.anticon-safety-certificate'), 'ikon perisai di P').toHaveCount(1);
    await expect(rowOf(page, '0QA25-MOVE').locator('.anticon-safety-certificate'), 'tanpa perisai di MOVE (nonaktif)').toHaveCount(0);
    await app.screenshot(`AC3-01-root-${preset}-ikon-perisai`);

    // 2. masuk folder P: "+" sesuai permission role
    const inP = await openFolder(page, '0QA25-P');
    expect(inP.status, 'GET archives?id_archive=P').toBe(200);
    expect(inP.body.result.access, 'result.access di P').toEqual({ ...FULL, manage_permission: hasFP });
    await app.settle();
    await expect(rowOf(page, '0QA25-P-C'), 'P memuat 0QA25-P-C').toBeVisible();
    await expect(rowOf(page, '0QA25-P-DOC'), 'P memuat 0QA25-P-DOC').toBeVisible();
    const plus = await plusItems(page, app, `AC3-02b-plus-di-P-${preset}`);
    monitor.note(`"+" di P (${tag}): ${plus.join(' | ')}`);
    const expectedKeys = expectedPlus(perms, { inFolder: true, access: inP.body.result.access });
    expect(plusKeysOf(plus).sort(), `"+" di P untuk ${tag} = item menurut permission role`).toEqual(expectedKeys.sort());
    // AC-41 (rev3): gerbang Set Permission by User/Atur Izin per User = `Folder Permissions`; C ber-`Update Folder` tanpa itu -> tidak ada
    expect(plusKeysOf(plus).includes('set-permission'), `AC-41: "+" ${tag} memuat Set Permission by User/Atur Izin per User = ${hasFP}`).toBe(hasFP);
    expect(perms.includes('Update Folder'), `${tag} ber-Update Folder (bukan penentu menu Set Permission)`).toBe(true);
    await app.screenshot(`AC3-02-dalam-folder-P-${preset}`);
    // menu baris folder anak P-C (Off, K-12 a): Info, Move, View, Delete
    const itemsC = (await rowMenuItems(page, '0QA25-P-C')).items;
    monitor.note(`menu baris P-C (${tag}): ${itemsC.join(' | ')}`);
    for (const re of [MENU.info, MENU.move, MENU.view, MENU.del]) expect(hasItem(itemsC, re), `menu P-C memuat ${re}`).toBe(true);
    await page.keyboard.press('Escape');
    // kembali ke root lewat breadcrumb
    const back = listResponse(page, (u) => !folderParam(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click();
    await back;
    await app.settle();

    // 3. drawer folder P: jumlah tab menurut permission role
    const view = await openDrawerView(page, '0QA25-P');
    expect(view.status, 'GET archives/{P}').toBe(200);
    expect(view.body.result.is_folder_permission, 'show: is_folder_permission').toBe(1);
    expect(view.body.result.access.manage_permission, 'show: access.manage_permission = Folder Permissions').toBe(hasFP);
    const texts = await tabTexts(page);
    monitor.note(`tab drawer P (${tag}): ${texts.join(' | ')}`);
    expect(texts.length, `jumlah tab ${tag}: Detail + Verification${hasFP ? ' + Izin' : ''}`).toBe(hasFP ? 3 : 2);
    expect(texts.some((t) => TAB_PERMISSION.test(t)), `tab Izin ${hasFP ? 'ada' : 'tidak ada'} untuk ${tag}`).toBe(hasFP);
    await app.screenshot(`AC3-03-drawer-P-tab-Detail-${preset}`);

    if (hasFP) {
      expect(view.body.result.folder_permissions.map((r) => r.username).sort(), 'show: folder_permissions').toEqual([fx0._userA.username, fx0._userB.username].sort());
      await openPermissionTab(page);
      await expect(drawer(page).locator('.ant-switch').first(), 'toggle aktif').toHaveClass(/ant-switch-checked/);
      await expect(permRows(page), 'tabel Izin: 2 baris').toHaveCount(2);
      expect(await rowAllBoxes(permRow(page, fx0._userA.username)), 'baris A: All + V U D S').toEqual([true, true, true, true, true]);
      expect(await rowAllBoxes(permRow(page, fx0._userB.username)), 'baris B: All kosong, View saja').toEqual([false, true, false, false, false]);
      await expect(drawer(page).locator('.ant-alert'), 'peringatan induk').toHaveCount(1);
      const usersP = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
      await drawer(page).locator('button.ant-btn-link').click();
      await drawer(page).locator('.ant-select').first().click();
      const ur = await usersP;
      expect(ur.status(), 'GET select/document-archive/archive/users').toBe(200);
      const uopts = ((await ur.json()).result.options || []).map((o) => o.label);
      monitor.note(`opsi "+ Add User" di P: ${uopts.slice(0, 12).join(' | ')} (total ${uopts.length})`);
      expect(decodeURIComponent(ur.url()), 'excepts memuat id A dan B').toContain(fx0._userA.id_user);
      expect(decodeURIComponent(ur.url())).toContain(fx0._userB.id_user);
      expect(uopts, 'opsi tanpa A (sudah di tabel)').not.toContain(fx0._userA.username);
      expect(uopts, 'opsi tanpa B (sudah di tabel)').not.toContain(fx0._userB.username);
      await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
      await app.screenshot(`AC3-04-drawer-P-tab-Izin-add-user-${preset}`);
      await page.keyboard.press('Escape');
    } else {
      expect(view.body.result.folder_permissions, 'show tanpa Folder Permissions: folder_permissions kosong').toEqual([]);
    }
    await closeTopLayer(page);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0);

    // 4. drawer Info (history)
    const hist = page.waitForResponse((r) => /\/archives\/history\//.test(r.url()), { timeout: 30000 });
    await pickRowMenu(page, '0QA25-P', MENU.info);
    expect((await hist).status(), 'GET archives/history/{P}').toBe(200);
    await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot(`AC3-05-drawer-info-P-${preset}`);
    await closeTopLayer(page);

    // 5. modal Pindahkan (folder MOVE); tidak dikonfirmasi
    const moveList = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, '0QA25-MOVE', MENU.move);
    expect((await moveList).status, 'picker modal Pindahkan: GET archives').toBe(200);
    const modal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await expect(modal.locator('.ant-table-row').filter({ hasText: '0QA25-P' }).first(), 'picker memuat 0QA25-P').toBeVisible();
    await app.screenshot(`AC3-06-modal-pindahkan-${preset}`);
    await closeTopLayer(page);

    // 6. pindah massal: Pilih -> centang MOVE -> Pindahkan -> Lanjut -> Pilih Folder
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await rowOf(page, '0QA25-MOVE').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
    const bulk = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await app.screenshot(`AC3-07-bulk-konfirmasi-${preset}`);
    const pickerList = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Next|Lanjut)$/ }).first().click();
    await pickerList.catch(() => null);
    await app.settle();
    await expect(bulk.locator('.ant-table-row').filter({ hasText: '0QA25-STORE' }).first(), 'step Pilih Folder memuat 0QA25-STORE').toBeVisible();
    await app.screenshot(`AC3-08-bulk-pilih-folder-${preset}`);
    await closeTopLayer(page);
    await noToast(page, `AC-3 ${tag}`);

    noServerError(monitor);
    monitor.check();
  }

  test('[default] ED-1025 AC-3 A (role asli QA_USER): Archive, folder, drawer (tab per permission), + Add User, Info, modal Pindahkan & pindah massal', async ({ app, page, monitor, session }) => {
    setRole('default', 'orig');
    const perms = ids()._perms.orig_default;
    await ac3Flow({ app, page, monitor, session, preset: 'A', perms, tag: 'A (role asli)' });
  });

  test('[default] ED-1025 AC-3 C (A tanpa Folder Permissions): Archive, folder, drawer tanpa tab Izin, "+" menurut permission, Info, Pindahkan, pindah massal', async ({ app, page, monitor, session }) => {
    setRole('default', 'C');
    await ac3Flow({ app, page, monitor, session, preset: 'C', perms: ids()._perms.C, tag: 'C (tanpa Folder Permissions)' });
  });

  // ------------------------------------------------------------------------------------------------ AC-17 (A) + AC-31 (baris otomatis) + AC-32 (kolom All)
  test('[default] ED-1025 AC-17/31/32 A: tab Izin - toggle menyala = baris A otomatis, + Add User B, kolom All, centang Update (View ikut), Save, buka ulang', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    const B = fx0._userB.username;
    monitor.note(`DB sesi ${session.dbName}; A (peran A) mengubah folder fixture 0QA25-EDIT (pembuat = A); dibuang di teardown`);

    await openArchive(app, page);
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

    // AC-32: urutan kolom Pengguna, Semua/All, lalu empat hak (sesudah toggle menyala ada baris untuk dilihat)
    await sw.click();
    await expect(sw, 'toggle menyala').toHaveClass(/ant-switch-checked/);
    // AC-31/BR-19: baris A langsung muncul (sebelum Save) dengan keempat hak + All tercentang
    await expect(permRows(page), 'toggle menyala: baris A otomatis').toHaveCount(1);
    const rowA = permRow(page, A);
    expect(await rowAllBoxes(rowA), 'baris A otomatis: All + V U D S tercentang').toEqual([true, true, true, true, true]);
    const heads = (await drawer(page).locator('.ant-tabs-tabpane-active .ant-table-thead th').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
    monitor.note(`kolom tabel Izin: ${heads.join(' | ')}`);
    expect(heads[0], 'kolom 1 = Pengguna/User').toMatch(/^(Pengguna|User)$/i);
    expect(heads[1], 'kolom 2 (tepat sesudah Pengguna) = Semua/All').toMatch(/^(Semua|All)$/i);
    expect(heads.length, 'kolom: User, All, V, U, D, S').toBeGreaterThanOrEqual(6);
    await app.screenshot('AC17-01b-toggle-menyala-baris-A-otomatis');

    // On -> Off tidak mengubah baris; checkbox dan tombol tambah user dikunci; On lagi
    await sw.click();
    await expect(sw).not.toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'toggle mati: baris A tetap').toHaveCount(1);
    expect(await rowBoxDisabled(rowA), 'toggle mati: semua checkbox (termasuk All) disabled').toEqual([true, true, true, true, true]);
    await expect(drawer(page).locator('button.ant-btn-link'), 'toggle mati: tanpa "+ Add User"').toHaveCount(0);
    await sw.click();
    await expect(sw).toHaveClass(/ant-switch-checked/);
    expect(await rowAllBoxes(rowA), 'toggle menyala lagi: baris A keempat hak').toEqual([true, true, true, true, true]);

    // + Add User B (default View saja)
    const usersResp = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
    await drawer(page).locator('button.ant-btn-link').click();
    await drawer(page).locator('.ant-select').first().click();
    await usersResp;
    await page.keyboard.type(B);
    await page.waitForTimeout(900);
    const opt = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: new RegExp(`^${esc(B)}$`) }).first();
    await opt.waitFor({ state: 'visible', timeout: 15000 });
    await opt.click();
    await expect(permRows(page), 'dua baris: A (otomatis) + B').toHaveCount(2);
    const rowB = permRow(page, B);
    expect(await rowBoxes(rowB), 'user baru default View saja').toEqual([true, false, false, false]);
    expect(await allState(rowB), 'B View saja: All indeterminate').toEqual({ checked: false, indeterminate: true });

    // AC-32: centang All -> keempat; lepas All -> keempatnya lepas (baris tetap); centang satu hak; lepas satu hak -> All lepas
    await box(rowB, 'all').check();
    expect(await rowBoxes(rowB), 'centang All: keempat hak').toEqual([true, true, true, true]);
    expect(await allState(rowB), 'keempat hak: All tercentang').toEqual({ checked: true, indeterminate: false });
    await box(rowB, 'all').uncheck();
    expect(await rowBoxes(rowB), 'lepas All: keempatnya lepas').toEqual([false, false, false, false]);
    expect(await allState(rowB), 'tanpa hak: All kosong, bukan indeterminate').toEqual({ checked: false, indeterminate: false });
    await expect(permRows(page), 'lepas All: baris B tetap ada').toHaveCount(2);
    await box(rowB, 'all').check();
    await box(rowB, 'delete').uncheck();
    expect(await rowBoxes(rowB), 'lepas Delete dari keempat').toEqual([true, true, false, true]);
    expect(await allState(rowB), 'lepas satu hak: All lepas (indeterminate)').toEqual({ checked: false, indeterminate: true });
    await app.screenshot('AC32-01-kolom-all-indeterminate');

    // BR-3: lepas View -> semua lepas; centang Store -> View ikut; centang Update -> tetap
    await box(rowB, 'view').uncheck();
    expect(await rowBoxes(rowB), 'lepas View: semua lepas').toEqual([false, false, false, false]);
    await box(rowB, 'store').check();
    expect(await rowBoxes(rowB), 'centang Store: View ikut').toEqual([true, false, false, true]);
    await box(rowB, 'update').check();
    expect(await rowBoxes(rowB), 'akhir: View + Update + Store').toEqual([true, true, false, true]);
    await app.screenshot('AC17-02-sebelum-save');

    // Save (satu PUT berisi Detail + Izin; A pembuat dan View-nya tetap -> tanpa modal)
    const sv = await saveDrawer(page);
    monitor.note(`PUT body: ${JSON.stringify(sv.body).slice(0, 500)}`);
    expect(sv.status, 'PUT archives/{id}').toBe(200);
    expect(sv.json.msg_code, 'msg_code').toBe('ARCHIVE207');
    expect(sv.body.isFolderPermission ?? sv.body.is_folder_permission, 'PUT: isFolderPermission integer 1').toBe(1);
    const rowsSent = sv.body.folderPermissions || sv.body.folder_permissions;
    expect(rowsSent, 'PUT: tepat 2 baris (A otomatis + B)').toHaveLength(2);
    const sent = (id) => rowsSent.find((r) => (r.idUser ?? r.id_user) === id);
    expect(sent(fx0._userB.id_user), 'PUT: baris B ada').toBeTruthy();
    expect(sent(fx0._userA.id_user), 'PUT: baris A ada (otomatis)').toBeTruthy();
    await expect(page.locator('.ant-drawer-open'), 'drawer menutup setelah Save').toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-EDIT'), 'DB: baris A = 1/1/1/1 dan B = 1/1/0/1').toEqual({
      is_folder_permission: 1,
      rows: [[A, 1, 1, 1, 1], [B, 1, 1, 0, 1]].sort((x, y) => (x[0] < y[0] ? -1 : 1)),
    });

    // buka ulang: data tersimpan, ikon perisai muncul
    await app.settle();
    await expect(rowOf(page, '0QA25-EDIT').locator('.anticon-safety-certificate'), 'perisai muncul di 0QA25-EDIT').toHaveCount(1, { timeout: 15000 });
    const again = await openDrawerView(page, '0QA25-EDIT');
    expect(again.body.result.is_folder_permission, 'buka ulang: aktif').toBe(1);
    expect(again.body.result.folder_permissions.map((r) => [r.username, r.is_view, r.is_update, r.is_delete, r.is_store]).sort((x, y) => (x[0] < y[0] ? -1 : 1)), 'buka ulang: baris tersimpan').toEqual(
      [[A, 1, 1, 1, 1], [B, 1, 1, 0, 1]].sort((x, y) => (x[0] < y[0] ? -1 : 1)),
    );
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'UI: toggle aktif').toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'UI: dua baris').toHaveCount(2);
    expect(await rowBoxes(permRow(page, B)), 'UI: baris B [V,U,D,S]').toEqual([true, true, false, true]);
    expect(await rowBoxes(permRow(page, A)), 'UI: baris A [V,U,D,S]').toEqual([true, true, true, true]);
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
    await noErrorToast(page, 'AC-17');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-31 (A): folder Off tanpa baris / dengan baris View saja
  test('[default] ED-1025 AC-31 A: Izin Folder dinyalakan di folder Off -> baris A empat hak (tanpa baris dan View saja), Save, DB 1/1/1/1', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    monitor.note(`DB sesi ${session.dbName}; A (peran A) di Z > AUTO dan Z > AUTO2`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Z']);

    // (a) AUTO: Off, tanpa baris A
    expect(fxJson('perm', '0QA25-AUTO'), 'awal AUTO: Off, tanpa baris').toEqual({ is_folder_permission: 0, rows: [] });
    const v1 = await openDrawerView(page, '0QA25-AUTO');
    expect(v1.body.result.folder_permissions, 'AUTO awal: tanpa baris').toEqual([]);
    await openPermissionTab(page);
    const sw = drawer(page).locator('.ant-switch').first();
    await expect(permRows(page), 'AUTO: tabel kosong sebelum toggle').toHaveCount(0);
    await sw.click();
    await expect(permRows(page), 'AUTO: baris A muncul sebelum Save').toHaveCount(1);
    expect(await rowAllBoxes(permRow(page, A)), 'AUTO: baris A = All + V U D S').toEqual([true, true, true, true, true]);
    await app.screenshot('AC31-01-AUTO-baris-A-otomatis');
    const s1 = await saveDrawer(page);
    expect(s1.status, 'AUTO: PUT 200').toBe(200);
    for (const k of PERM_KEYS) expect(keysOf(s1.body), `AUTO: body memuat ${k}`).toContain(k);
    expect(keysOf(s1.body), 'AUTO: A punya Update Folder + Folder Permissions -> body juga memuat kelompok detail').toContain('description');
    await expect(page.locator('.ant-drawer-open'), 'AUTO: drawer menutup').toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-AUTO'), 'DB AUTO: (AUTO, A) = 1/1/1/1').toEqual({ is_folder_permission: 1, rows: [[A, 1, 1, 1, 1]] });

    // (b) AUTO2: Off, baris A View saja -> dinaikkan ke empat hak
    await app.settle();
    expect(fxJson('perm', '0QA25-AUTO2'), 'awal AUTO2: Off, A View saja').toEqual({ is_folder_permission: 0, rows: [[A, 1, 0, 0, 0]] });
    await openDrawerView(page, '0QA25-AUTO2');
    await openPermissionTab(page);
    await expect(permRows(page), 'AUTO2: satu baris (A)').toHaveCount(1);
    expect(await rowBoxes(permRow(page, A)), 'AUTO2 awal: A View saja').toEqual([true, false, false, false]);
    await drawer(page).locator('.ant-switch').first().click();
    await expect(permRows(page), 'AUTO2: tetap satu baris (dinaikkan, bukan ditambah)').toHaveCount(1);
    expect(await rowAllBoxes(permRow(page, A)), 'AUTO2: baris A naik ke keempat hak').toEqual([true, true, true, true, true]);
    await app.screenshot('AC31-02-AUTO2-baris-A-naik');
    const s2 = await saveDrawer(page);
    expect(s2.status, 'AUTO2: PUT 200').toBe(200);
    await expect(page.locator('.ant-drawer-open'), 'AUTO2: drawer menutup').toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-AUTO2'), 'DB AUTO2: (AUTO2, A) = 1/1/1/1').toEqual({ is_folder_permission: 1, rows: [[A, 1, 1, 1, 1]] });

    await noErrorToast(page, 'AC-31');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-33 (A)
  test('[default] ED-1025 AC-33 A: Save yang mencabut View sendiri -> modal (Tidak = tanpa PUT, Ya = PUT); tanpa modal untuk U/D/S saja, toggle Off, pembuat', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    const B = fx0._userB.username;
    const puts = trackPuts(page);
    monitor.note(`DB sesi ${session.dbName}; A (peran A, bukan superadmin, bukan pembuat) di Y > OWN, OWN2, OWN3, OWNC`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Y']);

    // (1) OWN: lepas Update A saja -> Simpan -> tanpa modal, PUT 200
    await openDrawerView(page, '0QA25-OWN');
    await openPermissionTab(page);
    let rowA = permRow(page, A);
    expect(await rowBoxes(rowA), 'OWN awal: A V U D S').toEqual([true, true, true, true]);
    await box(rowA, 'update').uncheck();
    expect(await rowBoxes(rowA), 'lepas Update saja').toEqual([true, false, true, true]);
    const n0 = puts.length;
    const s1 = await saveDrawer(page);
    await expect(confirmModal(page), 'lepas U/D/S saja: tanpa modal').toHaveCount(0);
    expect(s1.status, 'OWN (1): PUT 200 tanpa modal').toBe(200);
    expect(puts.length - n0, 'OWN (1): tepat satu PUT').toBe(1);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-OWN').rows.find((r) => r[0] === A), 'DB OWN: A = 1/0/1/1').toEqual([A, 1, 0, 1, 1]);

    // (2) OWN: lepas View A -> Simpan -> modal; Tidak = tanpa PUT, drawer tetap dengan perubahan; Ya = PUT 200
    await app.settle();
    await openDrawerView(page, '0QA25-OWN');
    await openPermissionTab(page);
    rowA = permRow(page, A);
    await box(rowA, 'view').uncheck();
    expect(await rowBoxes(rowA), 'lepas View: semua lepas (BR-3)').toEqual([false, false, false, false]);
    const nBefore = puts.length;
    await saveBtn(page).click();
    const modal = confirmModal(page);
    await modal.waitFor({ state: 'visible', timeout: 10000 });
    const mtxt = (await modal.innerText()).replace(/\s+/g, ' ');
    monitor.note(`modal (ID): ${mtxt}`);
    expect(mtxt, 'isi modal id-ID').toContain('Sesudah disimpan, Anda tidak lagi bisa melihat folder ini. Lanjutkan?');
    expect(mtxt, 'judul modal').toMatch(/Perhatian|Warning/);
    const okBtn = modal.locator('.ant-modal-confirm-btns button.ant-btn-primary');
    const noBtn = modal.locator('.ant-modal-confirm-btns button:not(.ant-btn-primary)');
    expect((await okBtn.innerText()).trim(), 'tombol Ya').toMatch(/^(Ya|Yes)$/);
    expect((await noBtn.innerText()).trim(), 'tombol Tidak').toMatch(/^(Tidak|No)$/);
    await app.screenshot('AC33-01-modal-cabut-view-sendiri');
    await noBtn.click();
    await page.waitForTimeout(1500);
    await expect(confirmModal(page), 'Tidak: modal menutup').toHaveCount(0);
    expect(puts.length - nBefore, 'Tidak: tanpa PUT archives/{id} (monitor jaringan)').toBe(0);
    await expect(drawer(page), 'Tidak: drawer tetap terbuka').toBeVisible();
    expect(await rowBoxes(permRow(page, A)), 'Tidak: perubahan (View A lepas) tetap di form').toEqual([false, false, false, false]);
    await app.screenshot('AC33-02-tidak-drawer-tetap');
    await saveBtn(page).click();
    await confirmModal(page).waitFor({ state: 'visible', timeout: 10000 });
    await confirmModal(page).locator('.ant-modal-confirm-btns button.ant-btn-primary').click();
    const ya = await page.waitForResponse((r) => r.request().method() === 'PUT' && SHOW_RE.test(r.url()), { timeout: 30000 });
    expect(ya.status(), 'Ya: PUT 200').toBe(200);
    expect(puts.length - nBefore, 'Ya: tepat satu PUT').toBe(1);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    const own = fxJson('perm', '0QA25-OWN');
    expect(own.rows.find((r) => r[0] === A), 'DB OWN: baris A hilang (semua 0 tidak disimpan)').toBeUndefined();
    expect(own.rows.find((r) => r[0] === B), 'DB OWN: baris B utuh').toEqual([B, 1, 0, 0, 0]);

    // (3) OWN2: hapus baris A (tombol hapus) -> Simpan -> modal -> Ya -> PUT 200
    await app.settle();
    await openDrawerView(page, '0QA25-OWN2');
    await openPermissionTab(page);
    await expect(permRows(page)).toHaveCount(2);
    await permRow(page, A).locator('button.btn-action, button.ant-btn-dangerous, button').last().click();
    await expect(permRows(page), 'baris A dihapus dari tabel').toHaveCount(1);
    const n3 = puts.length;
    await saveBtn(page).click();
    await confirmModal(page).waitFor({ state: 'visible', timeout: 10000 });
    expect(puts.length - n3, 'hapus baris A: modal dulu, belum ada PUT').toBe(0);
    await confirmModal(page).locator('.ant-modal-confirm-btns button.ant-btn-primary').click();
    const y3 = await page.waitForResponse((r) => r.request().method() === 'PUT' && SHOW_RE.test(r.url()), { timeout: 30000 });
    expect(y3.status(), 'hapus baris A + Ya: PUT 200').toBe(200);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-OWN2').rows.find((r) => r[0] === A), 'DB OWN2: baris A terhapus').toBeUndefined();

    // (4) OWN3: lepas View A + matikan toggle -> Simpan -> tanpa modal (folder Off tidak membatasi)
    await app.settle();
    await openDrawerView(page, '0QA25-OWN3');
    await openPermissionTab(page);
    await box(permRow(page, A), 'view').uncheck();
    await drawer(page).locator('.ant-switch').first().click();
    await expect(drawer(page).locator('.ant-switch').first(), 'toggle dimatikan').not.toHaveClass(/ant-switch-checked/);
    const s4 = await saveDrawer(page);
    await expect(confirmModal(page), 'toggle Off: tanpa modal').toHaveCount(0);
    expect(s4.status, 'OWN3: PUT 200 tanpa modal').toBe(200);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-OWN3').is_folder_permission, 'DB OWN3: nonaktif').toBe(0);

    // (5) OWNC (pembuat = A): hapus baris A -> Simpan -> tanpa modal
    await app.settle();
    await openDrawerView(page, '0QA25-OWNC');
    await openPermissionTab(page);
    await permRow(page, A).locator('button').last().click();
    await expect(permRows(page), 'OWNC: baris A dihapus dari tabel').toHaveCount(1);
    const s5 = await saveDrawer(page);
    await expect(confirmModal(page), 'pembuat folder: tanpa modal').toHaveCount(0);
    expect(s5.status, 'OWNC: PUT 200 tanpa modal').toBe(200);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-OWNC').rows.find((r) => r[0] === A), 'DB OWNC: baris A terhapus').toBeUndefined();

    await noErrorToast(page, 'AC-33');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-33 + AC-31 (superadmin)
  test('[default] ED-1025 AC-33/31 SUPER (role 1): Save tanpa modal walau tanpa baris; toggle menyala menambah baris sendiri', async ({ app, page, monitor, session }) => {
    setRole('default', 'SUPER');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    const B = fx0._userB.username;
    monitor.note(`DB sesi ${session.dbName}; QA_USER sementara role 1 (Super Admin EQUAL, is_superadmin 1) di Y > OWNS dan Y > SUPAUTO`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Y']);

    // OWNS: On, baris B View; superadmin tanpa baris -> ubah baris B -> Simpan -> tanpa modal
    const v = await openDrawerView(page, '0QA25-OWNS');
    expect(v.body.result.access.manage_permission, 'superadmin ber-Folder Permissions (role 1): manage_permission true').toBe(true);
    await openPermissionTab(page);
    await expect(permRows(page), 'OWNS: satu baris (B)').toHaveCount(1);
    await box(permRow(page, B), 'update').check();
    const s1 = await saveDrawer(page);
    await expect(confirmModal(page), 'superadmin: tanpa modal').toHaveCount(0);
    expect(s1.status, 'OWNS: PUT 200 tanpa modal').toBe(200);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-OWNS').rows, 'DB OWNS: B = 1/1/0/0').toEqual([[B, 1, 1, 0, 0]]);

    // SUPAUTO: Off; toggle menyala -> baris superadmin sendiri muncul (gate1 C-6); tidak disimpan
    await app.settle();
    await openDrawerView(page, '0QA25-SUPAUTO');
    await openPermissionTab(page);
    await expect(permRows(page), 'SUPAUTO: tabel kosong').toHaveCount(0);
    await drawer(page).locator('.ant-switch').first().click();
    await expect(permRows(page), 'SUPAUTO: baris sendiri muncul walau superadmin').toHaveCount(1);
    expect(await rowAllBoxes(permRow(page, A)), 'SUPAUTO: baris sendiri empat hak').toEqual([true, true, true, true, true]);
    await app.screenshot('AC31-03-superadmin-baris-sendiri');
    await closeTopLayer(page);

    await noErrorToast(page, 'AC-33 SUPER');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-30 (C): tanpa Folder Permissions
  test('[default] ED-1025 AC-30 C (A tanpa Folder Permissions): drawer tanpa tab Izin, Detail bisa diubah, body Save tanpa field izin', async ({ app, page, monitor, session }) => {
    setRole('default', 'C');
    traceApi(page, monitor);
    monitor.note(`DB sesi ${session.dbName}; C = peran A tanpa Folder Permissions, folder Z > CED (Off)`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Z']);
    const v = await openDrawerView(page, '0QA25-CED');
    expect(v.body.result.access.manage_permission, 'C: manage_permission false').toBe(false);
    expect(v.body.result.access.update, 'C: Update efektif di CED').toBe(true);
    expect(v.body.result.folder_permissions, 'C: folder_permissions []').toEqual([]);
    const texts = await tabTexts(page);
    monitor.note(`tab drawer CED (C): ${texts.join(' | ')}`);
    expect(texts.some((t) => TAB_PERMISSION.test(t)), 'C: tanpa tab Izin').toBe(false);
    expect(texts.length, 'C: Detail + Verification').toBe(2);
    const dis = await detailDisabled(page);
    expect(dis.length, 'tab Detail punya isian').toBeGreaterThan(0);
    expect(dis.some(Boolean), 'C (Update efektif): Detail bisa diubah').toBe(false);
    await expect(saveBtn(page), 'C: tombol Save ada').toHaveCount(1);
    const desc = drawer(page).locator('.ant-tabs-tabpane-active textarea').first();
    await desc.fill('QA25 C deskripsi diubah');
    await app.screenshot('AC30-01-C-tanpa-tab-izin');
    const sv = await saveDrawer(page);
    monitor.note(`PUT body C: ${JSON.stringify(sv.body).slice(0, 400)}`);
    expect(sv.status, 'C: PUT 200').toBe(200);
    expect(keysOf(sv.body).filter((k) => PERM_KEYS.includes(k)), 'C: body tanpa isFolderPermission/folderPermissions').toEqual([]);
    expect(keysOf(sv.body), 'C: body memuat kelompok detail').toContain('description');
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('prop', '0QA25-CED').description, 'DB CED: deskripsi tersimpan').toBe('QA25 C deskripsi diubah');
    await noErrorToast(page, 'AC-30 C');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-30 (A di J, View saja)
  test('[default] ED-1025 AC-30 A di J (View saja): Detail read-only, tab Izin bisa diubah, body Save tanpa field detail', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    const B = fx0._userB.username;
    monitor.note(`DB sesi ${session.dbName}; A di Z > JV (On; A View saja)`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Z']);
    const v = await openDrawerView(page, '0QA25-JV');
    expect(v.body.result.access, 'A di JV: View saja, manage_permission true').toEqual({ view: true, update: false, delete: false, store: false, manage_permission: true });
    const dis = await detailDisabled(page);
    expect(dis.length, 'tab Detail punya isian').toBeGreaterThan(0);
    expect(dis.every(Boolean), 'A di JV: Detail read-only (semua isian disabled)').toBe(true);
    await expect(saveBtn(page), 'A di JV: Save tetap ada (untuk tab Izin)').toHaveCount(1);
    await app.screenshot('AC30-02-A-di-JV-detail-read-only');
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'toggle bisa diubah').toBeEnabled();
    await expect(drawer(page).locator('button.ant-btn-link'), '+ Add User ada').toHaveCount(1);
    const rowA = permRow(page, A);
    expect(await rowBoxDisabled(rowA), 'checkbox baris A bisa diubah').toEqual([false, false, false, false, false]);

    // + Add User B (View) -> Save
    const usersResp = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
    await drawer(page).locator('button.ant-btn-link').click();
    await drawer(page).locator('.ant-select').first().click();
    await usersResp;
    await page.keyboard.type(B);
    await page.waitForTimeout(900);
    const opt = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: new RegExp(`^${esc(B)}$`) }).first();
    await opt.waitFor({ state: 'visible', timeout: 15000 });
    await opt.click();
    await expect(permRows(page)).toHaveCount(2);
    await app.screenshot('AC30-03-A-di-JV-tab-izin-diubah');
    const sv = await saveDrawer(page);
    monitor.note(`PUT body A di JV: ${JSON.stringify(sv.body).slice(0, 400)}`);
    expect(sv.status, 'A di JV: PUT 200').toBe(200);
    expect(keysOf(sv.body).sort(), 'body HANYA kelompok izin (tanpa name/description/idArchiveParent/isAllLocation/idLocations)').toEqual([...PERM_KEYS].sort());
    for (const k of DETAIL_KEYS) expect(keysOf(sv.body), `body tanpa ${k}`).not.toContain(k);
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    expect(fxJson('perm', '0QA25-JV').rows, 'DB JV: A View saja dan B View').toEqual([[A, 1, 0, 0, 0], [B, 1, 0, 0, 0]].sort((x, y) => (x[0] < y[0] ? -1 : 1)));
    await noErrorToast(page, 'AC-30 A');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-18 (D)
  test('[default] ED-1025 AC-18 D (tanpa Update Folder, ber-Folder Permissions): Detail read-only, tab Izin bisa diubah, Save ada, body hanya kelompok izin', async ({ app, page, monitor, session }) => {
    setRole('default', 'D');
    traceApi(page, monitor);
    const fx0 = ids();
    const B = fx0._userB.username;
    monitor.note(`DB sesi ${session.dbName}; D = List Archive + View Folder + Folder Permissions saja, folder Z > DP (On; D/A View saja, B View)`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Z']);
    const v = await openDrawerView(page, '0QA25-DP');
    expect(v.body.result.access, 'D di DP: View saja, manage_permission true').toEqual({ view: true, update: false, delete: false, store: false, manage_permission: true });
    const texts = await tabTexts(page);
    monitor.note(`tab drawer DP (D): ${texts.join(' | ')}`);
    expect(texts.some((t) => TAB_PERMISSION.test(t)), 'D: tab Izin ada').toBe(true);
    const dis = await detailDisabled(page);
    expect(dis.length).toBeGreaterThan(0);
    expect(dis.every(Boolean), 'D: Detail read-only').toBe(true);
    await expect(saveBtn(page), 'D: Save ada').toHaveCount(1);
    await app.screenshot('AC18-01-D-detail-read-only-save-ada');
    await openPermissionTab(page);
    await expect(drawer(page).locator('.ant-switch').first(), 'D: toggle bisa diubah').toBeEnabled();
    await expect(drawer(page).locator('button.ant-btn-link'), 'D: + Add User ada').toHaveCount(1);
    const rowB = permRow(page, B);
    expect(await rowBoxes(rowB), 'B awal: View saja').toEqual([true, false, false, false]);
    await box(rowB, 'update').check();
    expect(await rowBoxes(rowB), 'centang Update B (View tetap)').toEqual([true, true, false, false]);
    await app.screenshot('AC18-02-D-tab-izin-diubah');
    const sv = await saveDrawer(page);
    monitor.note(`PUT body D: ${JSON.stringify(sv.body).slice(0, 400)}`);
    expect(sv.status, 'D: PUT 200').toBe(200);
    expect(keysOf(sv.body).sort(), 'D: body hanya isFolderPermission + folderPermissions').toEqual([...PERM_KEYS].sort());
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
    // buka ulang = tersimpan
    await app.settle();
    const again = await openDrawerView(page, '0QA25-DP');
    expect(again.body.result.folder_permissions.find((r) => r.username === B), 'buka ulang: B Update tersimpan').toMatchObject({ is_view: 1, is_update: 1, is_delete: 0, is_store: 0 });
    await openPermissionTab(page);
    expect(await rowBoxes(permRow(page, B)), 'UI buka ulang: B [V,U,D,S]').toEqual([true, true, false, false]);
    await closeTopLayer(page);
    await noErrorToast(page, 'AC-18 D');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-35 (A): pohon FB-9
  test('[default] ED-1025 AC-35 A: pohon J (View saja) > JA/JC - menu baris, "+" di dalam JA & JC, drawer JA & JC bisa diubah dan Save sukses', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    traceApi(page, monitor);
    const perms = ids()._perms.A;
    monitor.note(`DB sesi ${session.dbName}; A: Z > J (On, A View saja) > JA (On, A VUDS), JB (On, tanpa baris A), JC (Off)`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-Z']);
    const inJ = await openFolder(page, '0QA25-J');
    expect(inJ.status, 'GET archives?id_archive=J').toBe(200);
    await app.settle();
    expect(inJ.body.result.access, 'A di J: View saja').toEqual({ view: true, update: false, delete: false, store: false, manage_permission: true });
    const rows = rowsOf(inJ);
    const by = (n) => rows.find((r) => rowName(r) === n);
    expect(by('0QA25-JA').access, 'JA access semua true').toEqual(FULL);
    expect(by('0QA25-JC').access, 'JC (Off) access semua true (K-12 a)').toEqual(FULL);
    expect(by('0QA25-JB').access.view, 'JB (tanpa baris A) view false').toBe(false);

    // menu baris
    const jaItems = (await rowMenuItems(page, '0QA25-JA')).items;
    monitor.note(`menu baris JA: ${jaItems.join(' | ')}`);
    for (const re of [MENU.info, MENU.move, MENU.view, MENU.del]) expect(hasItem(jaItems, re), `JA memuat ${re}`).toBe(true);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    const jcItems = (await rowMenuItems(page, '0QA25-JC')).items;
    monitor.note(`menu baris JC: ${jcItems.join(' | ')}`);
    for (const re of [MENU.info, MENU.move, MENU.view, MENU.del]) expect(hasItem(jcItems, re), `JC memuat ${re}`).toBe(true);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    await expect(rowOf(page, '0QA25-JB').locator('button.btn-action'), 'JB (tanpa hak): tanpa tombol aksi baris').toHaveCount(0);
    await app.screenshot('AC35-01-J-menu-baris');

    // "+" di J: tanpa Add Folder / Store Document
    const plusJ = await plusItems(page, app, 'AC35-02-plus-di-J');
    monitor.note(`"+" di J: ${plusJ.join(' | ')}`);
    expect(plusKeysOf(plusJ).sort(), '"+" di J (View saja) = menurut permission & hak').toEqual(expectedPlus(perms, { inFolder: true, access: inJ.body.result.access }).sort());
    expect(plusKeysOf(plusJ), 'J: tanpa add-folder').not.toContain('add-folder');
    expect(plusKeysOf(plusJ), 'J: tanpa add-document').not.toContain('add-document');

    // drawer JA dan JC: Detail bisa diubah, Save sukses
    for (const [name, desc] of [['0QA25-JA', 'QA25 JA ubah'], ['0QA25-JC', 'QA25 JC ubah']]) {
      const dv = await openDrawerView(page, name);
      expect(dv.body.result.access, `${name}: access`).toEqual(FULL);
      const dis = await detailDisabled(page);
      expect(dis.length).toBeGreaterThan(0);
      expect(dis.some(Boolean), `drawer ${name}: Detail bisa diubah`).toBe(false);
      await drawer(page).locator('.ant-tabs-tabpane-active textarea').first().fill(desc);
      await app.screenshot(`AC35-03-drawer-${name.replace('0QA25-', '')}`);
      const sv = await saveDrawer(page);
      expect(sv.status, `${name}: Save 200`).toBe(200);
      await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
      expect(fxJson('prop', name).description, `DB ${name}: deskripsi`).toBe(desc);
      await app.settle();
    }

    // "+" di dalam JA dan JC: Add Folder & Store Document
    for (const name of ['0QA25-JA', '0QA25-JC']) {
      const resp = await openFolder(page, name);
      expect(resp.status, `buka ${name}`).toBe(200);
      expect(resp.body.result.access, `${name}: result.access`).toEqual(FULL);
      await app.settle();
      const plus = await plusItems(page, app, `AC35-04-plus-di-${name.replace('0QA25-', '')}`);
      monitor.note(`"+" di ${name}: ${plus.join(' | ')}`);
      expect(plusKeysOf(plus), `${name}: Add Folder`).toContain('add-folder');
      expect(plusKeysOf(plus), `${name}: Store Document`).toContain('add-document');
      expect(plusKeysOf(plus).sort(), `${name}: "+" menurut permission`).toEqual(expectedPlus(perms, { inFolder: true, access: resp.body.result.access }).sort());
      // kembali ke J lewat breadcrumb (item ke-3: Archive > Z > J)
      const back = listResponse(page, (u) => folderParam(u) === ids()['0QA25-J']);
      await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs button').filter({ hasText: '0QA25-J' }).first().click();
      await back;
      await app.settle();
    }

    await noErrorToast(page, 'AC-35');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-18/30 (B tanpa Folder Permissions) + AC-20 (B, EN)
  test('[user2] ED-1025 AC-18/20/30 B (tanpa Folder Permissions, View saja di P): Detail read-only, tanpa tab Izin, Save tidak ada, menu baris & "+" mengikuti hak, tooltip EN', async ({ app, page, monitor, session }) => {
    setRole('user2', 'C'); // role asli QA_USER2 (role 5) sudah ber-Folder Permissions/Opname Document (diubah developer di DB bersama); C = peran uji tanpa Folder Permissions
    traceApi(page, monitor);
    const fx0 = ids();
    monitor.note(`DB sesi ${session.dbName}; user B (preset C tanpa Folder Permissions, non-superadmin; bahasa EN)`);

    const root = await openArchive(app, page);
    const p = rowsOf(root).find((r) => rowName(r) === '0QA25-P');
    expect(p.access, 'B di P: access').toEqual({ view: true, update: false, delete: false, store: false, manage_permission: false });
    await app.settle();

    // AC-20 (rev): tooltip perisai EN
    await rowOf(page, '0QA25-P').locator('.anticon-safety-certificate').hover();
    const tip = page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last();
    await tip.waitFor({ state: 'visible', timeout: 10000 });
    expect((await tip.innerText()).trim(), 'tooltip perisai (en)').toBe('Restricted Access');
    await app.screenshot('AC20-00-tooltip-perisai-EN');
    await page.mouse.move(5, 5);

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

    // AC-18/30 (rev): drawer P tanpa Folder Permissions: Detail read-only, tanpa tab Izin, tanpa Save
    const view = await openDrawerView(page, '0QA25-P');
    expect(view.status).toBe(200);
    expect(view.body.result.access.update, 'B tanpa Update').toBe(false);
    expect(view.body.result.access.manage_permission, 'B tanpa manage_permission').toBe(false);
    expect(view.body.result.folder_permissions, 'B: folder_permissions []').toEqual([]);
    await expect(saveBtn(page), 'tombol Save tidak ada (tanpa Update dan tanpa Folder Permissions)').toHaveCount(0);
    const dis = await detailDisabled(page);
    monitor.note(`tab Detail: ${dis.length} isian, disabled=${dis.filter(Boolean).length}`);
    expect(dis.length, 'tab Detail punya isian').toBeGreaterThan(0);
    expect(dis.every(Boolean), 'tab Detail: semua isian disabled').toBe(true);
    const texts = await tabTexts(page);
    monitor.note(`tab drawer P (B): ${texts.join(' | ')}`);
    expect(texts.some((t) => TAB_PERMISSION.test(t)), 'B tanpa Folder Permissions: tanpa tab Permission').toBe(false);
    await app.screenshot('AC18-01-B-detail-read-only-tanpa-tab-izin');
    await closeTopLayer(page);

    // AC-20: di dalam P (View saja): "+" tanpa Add Folder / Store Document; anak P-C (Off, K-12 a): menu penuh
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
    const cRow = inP.body.result.data.find((r) => rowName(r) === '0QA25-P-C');
    expect(cRow.access, 'P-C (Off, B View lewat P): access penuh (K-12 a)').toEqual({ view: true, update: true, delete: true, store: true, manage_permission: false });
    const cItems = (await rowMenuItems(page, '0QA25-P-C')).items;
    monitor.note(`menu baris P-C (B, folder Off, View diwarisi dari P): ${cItems.join(' | ')}`);
    for (const re of [MENU.info, MENU.view, MENU.move, MENU.del]) expect(hasItem(cItems, re), `P-C (Off) memuat ${re}`).toBe(true);
    await page.keyboard.press('Escape');
    // dokumen: menu tidak dibatasi hak folder (BR-13): Info + Move
    const dItems = (await rowMenuItems(page, '0QA25-P-DOC')).items;
    monitor.note(`menu baris P-DOC (B): ${dItems.join(' | ')}`);
    expect(hasItem(dItems, MENU.info) && hasItem(dItems, MENU.move), 'dokumen: Info + Move (tidak dicek hak folder)').toBe(true);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // di dalam STORE (View+Store): "+" memuat Add Folder & Store Document (kembali ke root dengan muat ulang layar)
    await openArchive(app, page);
    const inS = await openFolder(page, '0QA25-STORE');
    expect(inS.status).toBe(200);
    expect(inS.body.result.access, 'B di STORE').toEqual({ view: true, update: false, delete: false, store: true, manage_permission: false });
    await app.settle();
    const plusS = await plusItems(page, app, 'AC20-03b-plus-dropdown-di-STORE-B');
    monitor.note(`"+" di STORE (B): ${plusS.join(' | ')}`);
    expect(plusS.some((t) => /Add Folder|Tambah Folder/.test(t)), 'STORE: Add Folder').toBe(true);
    expect(plusS.some((t) => /Store Document|Letakkan Dokumen/.test(t)), 'STORE: Store Document').toBe(true);
    await app.screenshot('AC20-03-plus-di-STORE-B');

    await noToast(page, 'AC-18/20/30 B');
    noServerError(monitor);
    monitor.check();
    expect(fx0['0QA25-P']).toBeTruthy();
  });

  // ------------------------------------------------------------------------------------------------ AC-19 + AC-20 (B)
  test('[user2] ED-1025 AC-19/AC-20 B tanpa View: layar akses ditolak (tanpa toast), ikon perisai, menu baris tanpa tombol, Back to Archive', async ({ app, page, monitor, session }) => {
    setRole('user2', 'C'); // role asli QA_USER2 (role 5) sudah ber-Folder Permissions/Opname Document (diubah developer di DB bersama); C = peran uji tanpa Folder Permissions
    traceApi(page, monitor);
    const fx0 = ids();
    monitor.note(`DB sesi ${session.dbName}; user B tanpa baris di 0QA25-DENY`);

    const root = await openArchive(app, page);
    const denyRow = rowsOf(root).find((r) => rowName(r) === '0QA25-DENY');
    expect(denyRow, 'folder ber-permission tetap tampil di daftar (BR-10)').toBeTruthy();
    expect(denyRow.access, 'B di DENY: access semua false').toEqual({ view: false, update: false, delete: false, store: false, manage_permission: false });
    await expect(rowOf(page, '0QA25-DENY').locator('.anticon-safety-certificate'), 'ikon perisai di DENY (juga bagi user tanpa View)').toHaveCount(1);
    // AC-20: tanpa View dan tanpa Update -> tombol ... disembunyikan
    await expect(rowOf(page, '0QA25-DENY').locator('button.btn-action'), 'DENY: tanpa tombol aksi baris').toHaveCount(0);
    await app.screenshot('AC19-01-root-B-DENY-perisai-tanpa-aksi');

    // klik folder -> 403 ARCHIVE407 -> layar 06
    const denied = listResponse(page, (u) => folderParam(u) === fx0['0QA25-DENY']);
    await rowOf(page, '0QA25-DENY').locator('td.cursor-pointer').first().click();
    const dr = await denied;
    expect(dr.status, 'GET archives?id_archive=DENY').toBe(403);
    expect(dr.body.msg_code, 'msg_code').toBe('ARCHIVE407');
    expect(dr.body.result.denied_by, 'denied_by').toEqual({ id_archive: fx0['0QA25-DENY'], name: '0QA25-DENY' });
    const screen = page.locator('.archive-access-denied');
    await screen.waitFor({ state: 'visible', timeout: 15000 });
    await noToast(page, 'layar akses ditolak');
    const stxt = await screen.innerText();
    monitor.note(`layar 06 (B, EN): ${stxt.replace(/\s+/g, ' ')}`);
    expect(stxt, 'judul').toMatch(/You do not have access to this folder/);
    expect(stxt, 'teks menyebut nama folder + permission view').toMatch(/Folder 0QA25-DENY has folder permission enabled and your account has not been given view permission/);
    expect(stxt, 'catatan dokumen tetap muncul di pencarian').toMatch(/Documents in this folder can still appear in document search results/);
    expect((await screen.locator('b').first().innerText()).trim(), 'nama folder penolak ditebalkan').toBe('0QA25-DENY');
    await expect(screen.locator('button', { hasText: /^Back to Archive$/ }), 'tombol Back to Archive (en)').toBeVisible();
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
    const deniedC = listResponse(page, (u) => folderParam(u) === fx0['0QA25-DENY-C']);
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

    expectDenied403(monitor, fx0['0QA25-DENY']);
    expectDenied403(monitor, fx0['0QA25-DENY-C']);
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-19 + AC-34 (A, id-ID)
  test('[default] ED-1025 AC-34 A (id-ID): tooltip "Akses Terbatas", layar akses ditolak "Kembali ke Arsip", teks bantuan & peringatan tab Izin', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    fx('lang', 'default', 'ID');
    traceApi(page, monitor);
    const fx0 = ids();
    monitor.note(`DB sesi ${session.dbName}; A (peran A, bahasa ID)`);

    await openArchive(app, page);
    await rowOf(page, '0QA25-P').locator('.anticon-safety-certificate').hover();
    const tip = page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last();
    await tip.waitFor({ state: 'visible', timeout: 10000 });
    expect((await tip.innerText()).trim(), 'tooltip perisai (id)').toBe('Akses Terbatas');
    await app.screenshot('AC34-01-tooltip-akses-terbatas');
    await page.mouse.move(5, 5);

    // teks bantuan + peringatan di tab Izin
    await openDrawerView(page, '0QA25-P');
    await openPermissionTab(page);
    const ptxt = (await drawer(page).locator('.ant-tabs-tabpane-active').innerText()).replace(/\s+/g, ' ');
    monitor.note(`tab Izin (id): ${ptxt.slice(0, 700)}`);
    expect(ptxt, 'bantuan toggle (id)').toContain('Default nonaktif. Saat aktif, akses folder ini dibatasi sesuai izin di bawah. Bisa diubah oleh user dengan izin Perizinan Folder.');
    expect(ptxt, 'peringatan induk (id)').toContain(
      'Hanya izin Lihat di folder induk yang berlaku: user tanpa izin Lihat di folder induk tetap tidak bisa membuka folder ini walaupun dicentang di sini. Ubah, Hapus, dan Letakkan dinilai di folder ini. Superadmin EQUAL selalu bypass.',
    );
    expect(ptxt, 'teks lama "Izin folder induk lebih dominan" hilang').not.toMatch(/lebih dominan/);
    const heads = (await drawer(page).locator('.ant-tabs-tabpane-active .ant-table-thead th').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
    monitor.note(`kolom tabel Izin (id): ${heads.join(' | ')}`);
    expect(heads[1], 'kolom All (id) = Semua').toBe('Semua');
    await app.screenshot('AC34-02-tab-izin-teks-id');
    await closeTopLayer(page);

    // layar 06 (A ditolak di DENYA)
    const denied = listResponse(page, (u) => folderParam(u) === fx0['0QA25-DENYA']);
    await rowOf(page, '0QA25-DENYA').locator('td.cursor-pointer').first().click();
    const dr = await denied;
    expect(dr.status, 'GET archives?id_archive=DENYA').toBe(403);
    expect(dr.body.msg_code).toBe('ARCHIVE407');
    const screen = page.locator('.archive-access-denied');
    await screen.waitFor({ state: 'visible', timeout: 15000 });
    await noToast(page, 'layar akses ditolak id');
    const stxt = (await screen.innerText()).replace(/\s+/g, ' ');
    monitor.note(`layar 06 (A, ID): ${stxt}`);
    expect(stxt, 'judul id').toMatch(/Anda tidak punya akses ke folder ini/);
    const btn = screen.locator('button').filter({ hasText: /Kembali ke Arsip/ });
    await expect(btn, 'tombol "Kembali ke Arsip" (id)').toBeVisible();
    await expect(screen.locator('button', { hasText: /Back to Archive/ }), 'tanpa teks Inggris "Back to Archive"').toHaveCount(0);
    await app.screenshot('AC34-03-layar-06-id');
    const rootAgain = listResponse(page, (u) => !folderParam(u));
    await btn.click();
    expect((await rootAgain).status, 'Kembali ke Arsip -> root 200').toBe(200);
    await page.locator('.archive-page .ant-table').first().waitFor({ state: 'visible', timeout: 15000 });
    await expect(page.locator('.archive-access-denied')).toHaveCount(0);

    expectDenied403(monitor, fx0['0QA25-DENYA']);
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-34 (B, en)
  test('[user2] ED-1025 AC-34 B (en, peran A): teks bantuan & peringatan tab Permission, kolom All, modal cabut View sendiri (en), tooltip', async ({ app, page, monitor, session }) => {
    setRole('user2', 'A');
    traceApi(page, monitor);
    const fx0 = ids();
    const B = fx0._userB.username;
    const puts = trackPuts(page);
    monitor.note(`DB sesi ${session.dbName}; B sementara peran A (bahasa EN), baris B di P = View saja`);

    await openArchive(app, page);
    await openDrawerView(page, '0QA25-P');
    await openPermissionTab(page);
    const ptxt = (await drawer(page).locator('.ant-tabs-tabpane-active').innerText()).replace(/\s+/g, ' ');
    monitor.note(`tab Permission (en): ${ptxt.slice(0, 700)}`);
    expect(ptxt, 'bantuan toggle (en)').toContain('Inactive by default. When active, access to this folder is limited to the permissions below. Can be changed by a user with the Folder Permissions permission.');
    expect(ptxt, 'peringatan induk (en)').toContain(
      'Only View permission on a parent folder applies: a user without View on a parent folder still cannot open this folder even if checked here. Update, Delete, and Store are evaluated on this folder. EQUAL superadmin always bypasses.',
    );
    expect(ptxt, 'teks lama "Parent folder permission is dominant" hilang').not.toMatch(/is dominant/);
    const heads = (await drawer(page).locator('.ant-tabs-tabpane-active .ant-table-thead th').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
    expect(heads[1], 'kolom All (en)').toBe('All');
    await app.screenshot('AC34-04-tab-permission-teks-en');

    // B (bukan superadmin/pembuat) hapus baris sendiri -> modal (en); No = tanpa PUT
    await permRow(page, B).locator('button').last().click();
    await expect(permRows(page)).toHaveCount(1);
    const n0 = puts.length;
    await saveBtn(page).click();
    const modal = confirmModal(page);
    await modal.waitFor({ state: 'visible', timeout: 10000 });
    const mtxt = (await modal.innerText()).replace(/\s+/g, ' ');
    monitor.note(`modal (en): ${mtxt}`);
    expect(mtxt, 'isi modal (en)').toContain('After saving, you will no longer be able to view this folder. Continue?');
    await app.screenshot('AC34-05-modal-en');
    await modal.locator('.ant-modal-confirm-btns button:not(.ant-btn-primary)').click();
    await page.waitForTimeout(1200);
    expect(puts.length - n0, 'No: tanpa PUT').toBe(0);
    await expect(drawer(page), 'No: drawer tetap terbuka').toBeVisible();
    await closeTopLayer(page);
    expect(fxJson('perm', '0QA25-P').rows.find((r) => r[0] === B), 'DB P: baris B utuh').toEqual([B, 1, 0, 0, 0]);
    await noToast(page, 'AC-34 B');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-21 (B)
  test('[user2] ED-1025 AC-21 B: picker modal Pindahkan & pindah massal - folder tanpa Store tidak bisa dipilih, tanpa View tidak bisa dibuka', async ({ app, page, monitor, session }) => {
    setRole('user2', 'C'); // role asli QA_USER2 (role 5) sudah ber-Folder Permissions/Opname Document (diubah developer di DB bersama); C = peran uji tanpa Folder Permissions
    traceApi(page, monitor);
    const fx0 = ids();
    monitor.note(`DB sesi ${session.dbName}; user B`);

    await openArchive(app, page);

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
      const watch = (r) => { if (LIST_RE.test(r.url()) && decodeURIComponent(r.url()).includes(fx0['0QA25-DENY'])) calledDeny = true; };
      page.on('request', watch);
      const denyRow = pickerRow(page, scope, '0QA25-DENY');
      const nameCell = denyRow.locator('td').nth(1);
      monitor.note(`${label}: sel nama DENY class="${await nameCell.getAttribute('class')}"`);
      await denyRow.locator('td', { hasText: '0QA25-DENY' }).first().click();
      await page.waitForTimeout(1500);
      page.off('request', watch);
      expect(calledDeny, `${label}: klik nama DENY (tanpa View) tidak memanggil GET archives?id_archive=DENY`).toBe(false);

      // P (View): bisa dibuka (klik nama) -> baris anak tampil
      const inP = listResponse(page, (u) => folderParam(u) === fx0['0QA25-P']);
      await pickerRow(page, scope, '0QA25-P').locator('td.cursor-pointer').first().click();
      const pr = await inP;
      expect(pr.status, `${label}: P (View) bisa dibuka`).toBe(200);
      await app.settle();
      await expect(pickerRow(page, scope, '0QA25-P-C'), `${label}: isi P tampil`).toBeVisible();
      // di dalam P (Store false di P): anak P-C nonaktif -> tidak dibatasi induk (K-12 a), B ber-Store -> bisa dipilih
      const sC = await radioState(scope, '0QA25-P-C');
      expect(sC.disabled, `${label}: P-C (Off, K-12 a: hak P tidak membatasi) bisa dipilih`).toBe(false);
    };

    // (a) modal Pindahkan satu folder (MOVE sebagai yang dipindah)
    await pickRowMenu(page, '0QA25-MOVE', MENU.move);
    const modal = page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await checkPicker(modal, 'modal Pindahkan', 'AC21-01-modal-pindahkan-picker');
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

  // ====================================================================================================================
  // REVISI RONDE 3 (ED-1133): AC-39 (kolom Semua), AC-42 (satu baris per user), AC-40 (perisai di picker). AC-41 = `expectedPlus` di atas.
  // ====================================================================================================================
  const byUser = (...rows) => rows.sort((x, y) => (x[0] < y[0] ? -1 : 1));
  const NO_CHANGES_RE = /No changes to save|Tidak ada perubahan untuk disimpan/;
  const sentRow = (rows, id) => (rows || []).filter((r) => (r.idUser ?? r.id_user) === id);
  const rightsOf = (r) => [r.isView ?? r.is_view, r.isUpdate ?? r.is_update, r.isDelete ?? r.is_delete, r.isStore ?? r.is_store];

  // ------------------------------------------------------------------------------------------------ AC-39 (A)
  test('[default] ED-1025 AC-39 A: tab Izin - HANYA kolom Semua (centang / lepas) lalu Save = PUT 200 tanpa "No changes to save", DB 1/1/1/1 lalu baris hilang; lepas Semua sendiri = modal BR-21', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    fx('lang', 'default', 'ID');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    const B = fx0._userB.username;
    const puts = trackPuts(page);
    const noChanges = page.locator('.ant-message-notice').filter({ hasText: NO_CHANGES_RE });
    monitor.note(`DB sesi ${session.dbName}; A (peran A, bukan pembuat) di W > SEM (On, A VUDS, B View saja)`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-W']);
    expect(fxJson('perm', '0QA25-SEM'), 'awal SEM: On, A 1/1/1/1, B View saja').toEqual({ is_folder_permission: 1, rows: byUser([A, 1, 1, 1, 1], [B, 1, 0, 0, 0]) });

    // (a) HANYA lepas Semua baris sendiri (A bukan pembuat/superadmin) -> Save -> modal BR-21; Tidak = tanpa PUT, drawer tetap
    await openDrawerView(page, '0QA25-SEM');
    await openPermissionTab(page);
    await expect(permRows(page), 'SEM: dua baris (A, B)').toHaveCount(2);
    let rowA = permRow(page, A);
    expect(await rowAllBoxes(rowA), 'SEM awal: baris A All + V U D S').toEqual([true, true, true, true, true]);
    await box(rowA, 'all').uncheck();
    expect(await rowAllBoxes(rowA), 'lepas Semua A: keempatnya lepas').toEqual([false, false, false, false, false]);
    const n0 = puts.length;
    await saveBtn(page).click();
    const modal = confirmModal(page);
    await modal.waitFor({ state: 'visible', timeout: 10000 });
    const mtxt = (await modal.innerText()).replace(/\s+/g, ' ');
    monitor.note(`modal (ID) dari lepas Semua A: ${mtxt}`);
    expect(mtxt, 'modal BR-21 muncul dari lepas Semua').toContain('Sesudah disimpan, Anda tidak lagi bisa melihat folder ini. Lanjutkan?');
    await app.screenshot('AC39-01-lepas-semua-sendiri-modal');
    await modal.locator('.ant-modal-confirm-btns button:not(.ant-btn-primary)').click();
    await page.waitForTimeout(1500);
    await expect(confirmModal(page), 'Tidak: modal menutup').toHaveCount(0);
    expect(puts.length - n0, 'Tidak: tanpa PUT archives/{id} (monitor jaringan)').toBe(0);
    await expect(drawer(page), 'Tidak: drawer tetap terbuka').toBeVisible();
    expect(await rowAllBoxes(permRow(page, A)), 'Tidak: perubahan Semua A tetap di form').toEqual([false, false, false, false, false]);
    await expect(noChanges, 'Tidak: tanpa pesan "No changes to save"').toHaveCount(0);
    await closeTopLayer(page);
    expect(fxJson('perm', '0QA25-SEM').rows, 'DB SEM utuh (belum ada tulisan)').toEqual(byUser([A, 1, 1, 1, 1], [B, 1, 0, 0, 0]));
    await app.settle();

    // (b) HANYA centang Semua baris B -> Save -> PUT 200 ARCHIVE207, tanpa "No changes to save"
    await openDrawerView(page, '0QA25-SEM');
    await openPermissionTab(page);
    let rowB = permRow(page, B);
    expect(await allState(rowB), 'B View saja: Semua indeterminate').toEqual({ checked: false, indeterminate: true });
    await box(rowB, 'all').check();
    expect(await rowAllBoxes(rowB), 'centang Semua B: keempat hak + Semua').toEqual([true, true, true, true, true]);
    await app.screenshot('AC39-02-centang-semua-B');
    const nB = puts.length;
    const s1 = await saveDrawer(page);
    monitor.note(`PUT (centang Semua B saja): ${JSON.stringify(s1.body).slice(0, 400)}`);
    expect(s1.status, 'Semua saja + Save: PUT 200').toBe(200);
    expect(s1.json.msg_code, 'msg_code').toBe('ARCHIVE207');
    expect(puts.length - nB, 'tepat satu PUT terkirim (monitor jaringan)').toBe(1);
    const rowsSent = s1.body.folderPermissions || s1.body.folder_permissions;
    expect(sentRow(rowsSent, fx0._userB.id_user).map(rightsOf), 'body: baris B 1/1/1/1').toEqual([[1, 1, 1, 1]]);
    expect(sentRow(rowsSent, fx0._userA.id_user).map(rightsOf), 'body: baris A tetap 1/1/1/1').toEqual([[1, 1, 1, 1]]);
    await expect(page.locator('.ant-drawer-open'), 'sukses: drawer menutup').toHaveCount(0, { timeout: 15000 });
    await expect(noChanges, 'tanpa pesan "No changes to save"/"Tidak ada perubahan untuk disimpan"').toHaveCount(0);
    expect(fxJson('perm', '0QA25-SEM').rows, 'DB (SEM, B) = 1/1/1/1').toEqual(byUser([A, 1, 1, 1, 1], [B, 1, 1, 1, 1]));
    await app.settle();
    // buka ulang: Semua B tercentang
    await openDrawerView(page, '0QA25-SEM');
    await openPermissionTab(page);
    rowB = permRow(page, B);
    expect(await rowAllBoxes(rowB), 'buka ulang: baris B All + V U D S tercentang').toEqual([true, true, true, true, true]);
    expect(await allState(rowB), 'buka ulang: Semua B tercentang (bukan indeterminate)').toEqual({ checked: true, indeterminate: false });
    await app.screenshot('AC39-03-buka-ulang-semua-B');
    await closeTopLayer(page);
    await app.settle();

    // (c) HANYA lepas Semua baris B -> Save -> PUT 200, baris (SEM, B) hilang
    await openDrawerView(page, '0QA25-SEM');
    await openPermissionTab(page);
    rowB = permRow(page, B);
    await box(rowB, 'all').uncheck();
    expect(await rowAllBoxes(rowB), 'lepas Semua B: keempatnya lepas, baris tetap tampil').toEqual([false, false, false, false, false]);
    await expect(permRows(page), 'baris B masih di tabel sebelum Save').toHaveCount(2);
    const nC = puts.length;
    const s2 = await saveDrawer(page);
    monitor.note(`PUT (lepas Semua B saja): ${JSON.stringify(s2.body).slice(0, 400)}`);
    expect(s2.status, 'lepas Semua saja + Save: PUT 200').toBe(200);
    expect(s2.json.msg_code, 'msg_code').toBe('ARCHIVE207');
    expect(puts.length - nC, 'tepat satu PUT').toBe(1);
    expect(sentRow(s2.body.folderPermissions || s2.body.folder_permissions, fx0._userB.id_user).map(rightsOf), 'body: baris B 0/0/0/0').toEqual([[0, 0, 0, 0]]);
    await expect(page.locator('.ant-drawer-open'), 'sukses: drawer menutup').toHaveCount(0, { timeout: 15000 });
    await expect(noChanges, 'tanpa pesan "No changes to save"').toHaveCount(0);
    expect(fxJson('perm', '0QA25-SEM').rows, 'DB: baris (SEM, B) hilang (baris tanpa hak tidak disimpan, BR-16); A utuh').toEqual([[A, 1, 1, 1, 1]]);
    await app.settle();
    await openDrawerView(page, '0QA25-SEM');
    await openPermissionTab(page);
    await expect(permRows(page), 'buka ulang: hanya baris A').toHaveCount(1);
    await expect(permRow(page, B), 'buka ulang: baris B tidak ada').toHaveCount(0);
    await app.screenshot('AC39-04-buka-ulang-B-hilang');

    // (d) kontrol negatif: tanpa perubahan apa pun -> Save = "Tidak ada perubahan untuk disimpan", tanpa PUT (perilaku DrawerUpdate lama)
    const nD = puts.length;
    await saveBtn(page).click();
    await expect(noChanges, 'tanpa perubahan: pesan "Tidak ada perubahan untuk disimpan" tampil (kontrol)').toHaveCount(1, { timeout: 10000 });
    await page.waitForTimeout(1000);
    expect(puts.length - nD, 'tanpa perubahan: tanpa PUT').toBe(0);
    await app.screenshot('AC39-05-kontrol-tanpa-perubahan');
    await page.waitForTimeout(3500); // toast hilang sebelum akhir test
    await page.locator('.ant-drawer-open').count().then(async (n) => { if (n) await closeTopLayer(page); });
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-42 (A)
  test('[default] ED-1025 AC-42 A: satu baris per user - "+ Add User" dirinya, toggle Off lalu On = tetap satu baris naik ke V/U/D/S, Save = A sekali, DB satu baris; "+ Add User" tidak menawarkan A lagi', async ({ app, page, monitor, session }) => {
    setRole('default', 'A');
    fx('lang', 'default', 'ID');
    traceApi(page, monitor);
    const fx0 = ids();
    const A = fx0._userA.username;
    const B = fx0._userB.username;
    const puts = trackPuts(page);
    monitor.note(`DB sesi ${session.dbName}; A (pembuat) di W > F42 (On, tanpa baris A, B View saja)`);

    await openArchive(app, page);
    await enter(page, app, ['0QA25-W']);
    expect(fxJson('perm', '0QA25-F42'), 'awal F42: On, hanya baris B').toEqual({ is_folder_permission: 1, rows: [[B, 1, 0, 0, 0]] });

    const view = await openDrawerView(page, '0QA25-F42');
    expect(view.body.result.access, 'A pembuat: access penuh').toEqual(FULL);
    await openPermissionTab(page);
    const sw = drawer(page).locator('.ant-switch').first();
    await expect(sw, 'F42 On').toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'F42: satu baris (B)').toHaveCount(1);

    // "+ Add User": A BELUM di tabel -> ditawarkan (excepts hanya B); pilih A -> baris A View saja
    const addUser = async (typed) => {
      const usersResp = page.waitForResponse((r) => USERS_RE.test(r.url()), { timeout: 30000 });
      await drawer(page).locator('button.ant-btn-link').click();
      await drawer(page).locator('.ant-select').first().click();
      const ur = await usersResp;
      // tunggu respons pencarian dengan teks ketikan LENGKAP (pencarian di-debounce): opsi sebelum itu masih daftar tanpa saringan
      const searched = page.waitForResponse((r) => USERS_RE.test(r.url()) && decodeURIComponent(r.url()).includes(typed), { timeout: 30000 });
      await page.keyboard.type(typed);
      await searched;
      await page.waitForTimeout(800);
      const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)');
      const options = (await dd.locator('.ant-select-item-option').allInnerTexts()).map((t) => t.trim());
      return { ur, dd, options };
    };
    const first = await addUser(A);
    const exc1 = decodeURIComponent(first.ur.url());
    expect(first.ur.status(), 'GET select users').toBe(200);
    expect(exc1, 'excepts memuat B').toContain(fx0._userB.id_user);
    expect(exc1, 'excepts belum memuat A (belum di tabel)').not.toContain(fx0._userA.id_user);
    expect(first.options, '"+ Add User" menawarkan A (belum ada barisnya)').toContain(A);
    await first.dd.locator('.ant-select-item-option').filter({ hasText: new RegExp(`^${esc(A)}$`) }).first().click();
    await expect(permRows(page), 'sesudah + Add User A: dua baris (A, B)').toHaveCount(2);
    let rowA = permRow(page, A);
    expect(await rowBoxes(rowA), 'baris A baru: View saja').toEqual([true, false, false, false]);
    expect(await allState(rowA), 'A View saja: Semua indeterminate').toEqual({ checked: false, indeterminate: true });
    await app.screenshot('AC42-01-add-user-dirinya-View-saja');

    // toggle Off lalu On -> tetap SATU baris A (dinaikkan), tidak ada baris kedua
    await sw.click();
    await expect(sw, 'toggle Off').not.toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'Off: tetap dua baris').toHaveCount(2);
    expect(await rowBoxes(permRow(page, A)), 'Off: baris A tidak berubah (View saja)').toEqual([true, false, false, false]);
    await sw.click();
    await expect(sw, 'toggle On lagi').toHaveClass(/ant-switch-checked/);
    await expect(permRows(page), 'Off -> On: TETAP dua baris (A dinaikkan, bukan ditambah)').toHaveCount(2);
    await expect(permRow(page, A), 'tepat satu baris A').toHaveCount(1);
    rowA = permRow(page, A);
    expect(await rowAllBoxes(rowA), 'baris A naik ke Semua + V U D S').toEqual([true, true, true, true, true]);
    expect(await rowBoxes(permRow(page, B)), 'baris B tidak berubah').toEqual([true, false, false, false]);
    await app.screenshot('AC42-02-toggle-off-on-satu-baris-A');

    // "+ Add User" tidak menawarkan A lagi (excepts memuat A dan B)
    const second = await addUser(A);
    const exc2 = decodeURIComponent(second.ur.url());
    monitor.note(`opsi "+ Add User" setelah A di tabel (ketik "${A}"): [${second.options.join(' | ')}]; excepts memuat A dan B`);
    expect(exc2, 'excepts memuat A').toContain(fx0._userA.id_user);
    expect(exc2, 'excepts memuat B').toContain(fx0._userB.id_user);
    expect(second.options, '"+ Add User" tidak menawarkan A (sudah di tabel)').not.toContain(A);
    await app.screenshot('AC42-03-add-user-tidak-menawarkan-A');
    await drawer(page).locator('.ant-drawer-header').click(); // tutup dropdown tanpa Escape (Escape menutup drawer)
    await expect(permRows(page), 'tanpa pilihan: tetap dua baris').toHaveCount(2);

    // Save: body memuat A SEKALI, PUT 200, tanpa modal (A pembuat), DB satu baris (F42, A) = 1/1/1/1
    const n0 = puts.length;
    const sv = await saveDrawer(page);
    monitor.note(`PUT body: ${JSON.stringify(sv.body).slice(0, 500)}`);
    await expect(confirmModal(page), 'A pembuat dan View ada: tanpa modal').toHaveCount(0);
    expect(sv.status, 'PUT 200').toBe(200);
    expect(sv.json.msg_code, 'msg_code').toBe('ARCHIVE207');
    expect(puts.length - n0, 'tepat satu PUT').toBe(1);
    const rowsSent = sv.body.folderPermissions || sv.body.folder_permissions;
    expect(rowsSent, 'body: 2 baris (A sekali + B)').toHaveLength(2);
    expect(sentRow(rowsSent, fx0._userA.id_user).map(rightsOf), 'body: A tepat sekali, 1/1/1/1').toEqual([[1, 1, 1, 1]]);
    expect(sentRow(rowsSent, fx0._userB.id_user).map(rightsOf), 'body: B sekali, View saja').toEqual([[1, 0, 0, 0]]);
    await expect(page.locator('.ant-drawer-open'), 'sukses: drawer menutup').toHaveCount(0, { timeout: 15000 });
    const db = fxJson('perm', '0QA25-F42');
    expect(db, 'DB: (F42, A) = 1/1/1/1 satu baris, B utuh').toEqual({ is_folder_permission: 1, rows: byUser([A, 1, 1, 1, 1], [B, 1, 0, 0, 0]) });
    expect(db.rows.filter((r) => r[0] === A), 'DB: tepat satu baris A').toHaveLength(1);

    await app.settle();
    await openDrawerView(page, '0QA25-F42');
    await openPermissionTab(page);
    await expect(permRows(page), 'buka ulang: dua baris').toHaveCount(2);
    expect(await rowAllBoxes(permRow(page, A)), 'buka ulang: baris A V/U/D/S').toEqual([true, true, true, true, true]);
    await app.screenshot('AC42-04-buka-ulang-satu-baris-A');
    await closeTopLayer(page);
    await noErrorToast(page, 'AC-42');
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ AC-40 (A, id dan en)
  /**
   * Perisai Akses Terbatas di picker folder tujuan: modal Pindahkan (⋯ dokumen: root, di dalam folder, folder tanpa View/Store, hasil pencarian)
   * dan pindah massal (Konfirmasi + Pilih Folder). Tidak ada Pindahkan yang dikonfirmasi. Search di dalam picker tidak bisa dipakai (ED-1125,
   * bug FE pra-ada: submit form HTML bersarang): hasil pencarian dimuat dengan pengalih jaringan, respons BE asli (pola QA ED-1069).
   */
  async function ac40Flow({ app, page, monitor, session, lang }) {
    setRole('default', 'A');
    fx('lang', 'default', lang);
    traceApi(page, monitor);
    const fx0 = ids();
    const TIP = lang === 'EN' ? 'Restricted Access' : 'Akses Terbatas';
    monitor.note(`DB sesi ${session.dbName}; A (peran A, bahasa ${lang}); tooltip yang diharapkan "${TIP}"`);

    /** Baris YANG TAMPIL di modal (langkah Konfirmasi pindah massal tetap di DOM, tersembunyi, saat langkah Pilih Folder aktif). */
    const vrow = (scope, name) =>
      scope
        .locator('.ant-table-row:visible')
        .filter({ has: page.locator('td').filter({ hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) })
        .first();
    const shieldOf = (row) => row.locator('.anticon-safety-certificate');
    const expectShield = async (scope, name, want, label) => {
      const row = vrow(scope, name);
      await row.waitFor({ state: 'visible', timeout: 20000 });
      await expect(shieldOf(row), `${label}: ${name} ${want ? 'berikon perisai' : 'tanpa ikon perisai'}`).toHaveCount(want ? 1 : 0);
      return row;
    };
    const hoverTip = async (row, label, shot) => {
      await shieldOf(row).first().hover();
      const tip = page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last();
      await tip.waitFor({ state: 'visible', timeout: 10000 });
      expect((await tip.innerText()).trim(), `${label}: tooltip perisai`).toBe(TIP);
      if (shot) await app.screenshot(shot); // tooltip terlihat di galeri
      await page.mouse.move(5, 5);
      await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)'), `${label}: tooltip menutup`).toHaveCount(0, { timeout: 10000 });
    };
    const modalOf = () => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
    const radioOf = (row) => row.locator('input[type="radio"]').evaluate((e) => e.disabled);
    const backToRoot = async (scope) => {
      const back = listResponse(page, (u) => !folderParam(u));
      await scope.locator('.breadcrumbs button').first().click();
      await back;
      await app.settle();
    };
    const sleep = (ms) => page.waitForTimeout(ms);

    // pantau semua request API selama picker dipakai: hanya GET document-archive/archives (daftar) yang boleh (AC-40: tanpa request tambahan)
    const apiCalls = [];
    let denyCalls = 0;
    page.on('request', (r) => {
      if (!/\/api\/v5\//.test(r.url())) return;
      const p = decodeURIComponent(r.url().replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).split('?')[0];
      apiCalls.push(`${r.method()} ${p}`);
      if (decodeURIComponent(r.url()).includes(fx0['0QA25-DENYA'])) denyCalls += 1;
    });

    // ---- list: perisai tetap + klik ikon = klik nama (EDIT tidak dipakai: AC-17 menyalakan Izin-nya lebih dulu di run penuh; Off tanpa ikon = MOVE, Z, Y, W)
    await openArchive(app, page);
    for (const [n, want] of [['0QA25-P', 1], ['0QA25-DENY', 1], ['0QA25-DENYA', 1], ['0QA25-STORE', 1], ['0QA25-MOVE', 0]]) {
      await expect(rowOf(page, n).locator('.anticon-safety-certificate'), `list: ${n} perisai=${want}`).toHaveCount(want);
    }
    const openByIcon = listResponse(page, (u) => folderParam(u) === fx0['0QA25-P']);
    await rowOf(page, '0QA25-P').locator('.anticon-safety-certificate').first().click();
    expect((await openByIcon).status, 'list: klik IKON perisai P = membuka P (sama dengan klik nama)').toBe(200);
    await app.settle();
    await expect(rowOf(page, '0QA25-P-DOC'), 'di dalam P: dokumen P-DOC').toBeVisible();
    await expect(rowOf(page, '0QA25-P-DOC').locator('.anticon-safety-certificate'), 'dokumen tanpa perisai').toHaveCount(0);
    await app.screenshot(`AC40-01-list-${lang}`);

    // ---- (1) modal Pindahkan dari ⋯ dokumen P-DOC: picker mulai dari root
    const startCalls = apiCalls.length;
    const pr1 = listResponse(page, (u) => !folderParam(u));
    await pickRowMenu(page, '0QA25-P-DOC', MENU.move);
    expect((await pr1).status, 'picker Pindahkan: GET archives (root)').toBe(200);
    let modal = modalOf();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await sleep(900);
    for (const [n, want] of [['0QA25-P', 1], ['0QA25-DENY', 1], ['0QA25-DENYA', 1], ['0QA25-STORE', 1], ['0QA25-MOVE', 0], ['0QA25-Z', 0], ['0QA25-W', 0]]) {
      await expectShield(modal, n, want, 'picker Pindahkan (root)');
    }
    const rowDenya = vrow(modal, '0QA25-DENYA');
    expect(await radioOf(rowDenya), 'DENYA (A tanpa View & Store): tidak bisa dipilih').toBe(true);
    expect(await radioOf(vrow(modal, '0QA25-STORE')), 'STORE (A Store): bisa dipilih').toBe(false);
    await app.screenshot(`AC40-02-picker-pindahkan-root-${lang}`);
    await hoverTip(vrow(modal, '0QA25-P'), 'picker Pindahkan, P', `AC40-03-tooltip-P-${lang}`);
    await hoverTip(rowDenya, 'picker Pindahkan, DENYA (tanpa View/Store)', `AC40-03b-tooltip-DENYA-${lang}`);

    // klik IKON DENYA (tanpa View) = klik nama: tidak membuka folder, tanpa request ke DENYA
    const denyBefore = denyCalls;
    await shieldOf(rowDenya).first().click();
    await sleep(1500);
    expect(denyCalls - denyBefore, 'klik ikon DENYA (tanpa View): tanpa request ke folder itu').toBe(0);
    await expect(modal.locator('.breadcrumbs'), 'breadcrumb picker tetap di root').not.toContainText('0QA25-DENYA');
    await expect(vrow(modal, '0QA25-DENYA'), 'DENYA masih di daftar root').toBeVisible();

    // klik IKON P (A ber-View) = membuka P: P-C (Off) dan P-DOC (dokumen) tanpa perisai
    const openP = listResponse(page, (u) => folderParam(u) === fx0['0QA25-P']);
    await shieldOf(vrow(modal, '0QA25-P')).first().click();
    expect((await openP).status, 'picker: klik IKON P = membuka P').toBe(200);
    await app.settle();
    await expect(modal.locator('.breadcrumbs'), 'breadcrumb picker memuat P').toContainText('0QA25-P');
    await expectShield(modal, '0QA25-P-C', 0, 'di dalam P (folder Off)');
    await expectShield(modal, '0QA25-P-DOC', 0, 'di dalam P (dokumen)');
    await expect(vrow(modal, '0QA25-P-DOC').locator('.anticon-file'), 'P-DOC = dokumen').toHaveCount(1);
    await app.screenshot(`AC40-04-picker-di-dalam-P-${lang}`);
    await backToRoot(modal);

    // di dalam DENY (On): anak DENY-C (On) berperisai
    const openDeny = listResponse(page, (u) => folderParam(u) === fx0['0QA25-DENY']);
    await vrow(modal, '0QA25-DENY').locator('td.cursor-pointer').first().click();
    expect((await openDeny).status, 'picker: klik nama DENY = membuka').toBe(200);
    await app.settle();
    await expectShield(modal, '0QA25-DENY-C', 1, 'di dalam DENY');
    await backToRoot(modal);

    // di dalam folder wadah Z (Off): J/JV/ALL/DP On berperisai (J, JV, DP: A tanpa Store -> tidak bisa dipilih), CED Off tanpa
    const openZ = listResponse(page, (u) => folderParam(u) === fx0['0QA25-Z']);
    await vrow(modal, '0QA25-Z').locator('td.cursor-pointer').first().click();
    expect((await openZ).status, 'picker: Z dibuka').toBe(200);
    await app.settle();
    for (const [n, want] of [['0QA25-J', 1], ['0QA25-JV', 1], ['0QA25-ALL', 1], ['0QA25-DP', 1], ['0QA25-CED', 0]]) {
      await expectShield(modal, n, want, 'di dalam wadah Z');
    }
    expect(await radioOf(vrow(modal, '0QA25-J')), 'J (A View saja, tanpa Store): tidak bisa dipilih').toBe(true);
    expect(await radioOf(vrow(modal, '0QA25-ALL')), 'ALL (A Store): bisa dipilih').toBe(false);
    await app.screenshot(`AC40-05-picker-di-dalam-wadah-${lang}`);
    await closeTopLayer(page);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });

    // ---- (2) hasil pencarian di picker (respons BE asli lewat pengalih jaringan, ED-1125)
    const searchPicker = async (query, shot, checks) => {
      const arm = await armPickerSearch(page, query);
      await pickRowMenu(page, '0QA25-P-DOC', MENU.move);
      const m = modalOf();
      await m.waitFor({ state: 'visible', timeout: 15000 });
      await app.settle();
      await sleep(900);
      expect(await arm.done(), `pengalih pencarian "${query}" terpakai`).toBe(true);
      await checks(m);
      await app.screenshot(shot);
      await closeTopLayer(page);
      await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });
    };
    await searchPicker('0QA25-DENY', `AC40-06-picker-hasil-pencarian-DENY-${lang}`, async (m) => {
      await expectShield(m, '0QA25-DENY', 1, 'hasil pencarian "0QA25-DENY"');
      await expectShield(m, '0QA25-DENY-C', 1, 'hasil pencarian "0QA25-DENY"');
      const row = await expectShield(m, '0QA25-DENYA', 1, 'hasil pencarian "0QA25-DENY"');
      expect(await radioOf(row), 'hasil pencarian: DENYA (tanpa View/Store) tetap tidak bisa dipilih').toBe(true);
      await hoverTip(row, 'hasil pencarian, DENYA');
    });
    await searchPicker('0QA25-P', `AC40-07-picker-hasil-pencarian-P-${lang}`, async (m) => {
      await expectShield(m, '0QA25-P', 1, 'hasil pencarian "0QA25-P"');
      await expectShield(m, '0QA25-P-C', 0, 'hasil pencarian "0QA25-P" (folder Off)');
      await expectShield(m, '0QA25-P-DOC', 0, 'hasil pencarian "0QA25-P" (dokumen)');
    });

    // ---- (3) pindah massal: Pilih -> centang MOVE dan P -> Pindahkan -> Konfirmasi -> Lanjut -> Pilih Folder
    const crumb = listResponse(page, (u) => !folderParam(u));
    await page.locator('.archive-page .breadcrumbs a, .archive-page .breadcrumbs .ant-breadcrumb-link').first().click();
    await crumb;
    await app.settle();
    await page.locator('.archive-page button', { hasText: /^(Select|Pilih)$/ }).first().click();
    await rowOf(page, '0QA25-MOVE').locator('input[type="checkbox"]').first().check();
    await rowOf(page, '0QA25-P').locator('input[type="checkbox"]').first().check();
    await page.locator('.archive-page button', { hasText: /^(Move|Pindahkan)$/ }).first().click();
    const bulk = modalOf();
    await bulk.waitFor({ state: 'visible', timeout: 15000 });
    await app.settle();
    await sleep(900);
    // Konfirmasi: kolom list -> P berperisai, MOVE (Off) tanpa
    await expectShield(bulk, '0QA25-P', 1, 'pindah massal, langkah Konfirmasi');
    await expectShield(bulk, '0QA25-MOVE', 0, 'pindah massal, langkah Konfirmasi');
    await hoverTip(vrow(bulk, '0QA25-P'), 'Konfirmasi, P', `AC40-08-bulk-konfirmasi-tooltip-${lang}`);
    const pickerList = listResponse(page, (u) => !folderParam(u));
    await bulk.locator('button.ant-btn-primary', { hasText: /^(Next|Lanjut)$/ }).first().click();
    await pickerList.catch(() => null);
    await app.settle();
    await sleep(900);
    // Pilih Folder
    for (const [n, want] of [['0QA25-P', 1], ['0QA25-DENY', 1], ['0QA25-DENYA', 1], ['0QA25-STORE', 1], ['0QA25-MOVE', 0], ['0QA25-Z', 0]]) {
      await expectShield(bulk, n, want, 'pindah massal, langkah Pilih Folder');
    }
    expect(await radioOf(vrow(bulk, '0QA25-DENYA')), 'Pilih Folder: DENYA tidak bisa dipilih').toBe(true);
    expect(await radioOf(vrow(bulk, '0QA25-P')), 'Pilih Folder: P (ikut dipindah) tidak bisa dipilih').toBe(true);
    await app.screenshot(`AC40-09-bulk-pilih-folder-${lang}`);
    await hoverTip(vrow(bulk, '0QA25-DENYA'), 'Pilih Folder, DENYA', `AC40-09b-bulk-tooltip-DENYA-${lang}`);
    // klik IKON STORE (Store + View) = membuka STORE; klik IKON P (sedang dipindah, terkunci) = tanpa membuka
    const pBefore = apiCalls.length;
    await shieldOf(vrow(bulk, '0QA25-P')).first().click();
    await sleep(1500);
    expect(apiCalls.slice(pBefore), 'klik ikon folder yang sedang dipindah (terkunci): tanpa request').toEqual([]);
    const openStore = listResponse(page, (u) => folderParam(u) === fx0['0QA25-STORE']);
    await shieldOf(vrow(bulk, '0QA25-STORE')).first().click();
    expect((await openStore).status, 'Pilih Folder: klik IKON STORE = membuka STORE').toBe(200);
    await app.settle();
    await expect(bulk.locator('.breadcrumbs'), 'breadcrumb memuat STORE').toContainText('0QA25-STORE');
    await app.screenshot(`AC40-10-bulk-di-dalam-STORE-${lang}`);
    await closeTopLayer(page);
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });

    // ---- tanpa request tambahan: hanya GET document-archive/archives (daftar) selama picker dipakai
    const used = apiCalls.slice(startCalls);
    const kinds = [...new Set(used)];
    monitor.note(`request API selama picker/pindah massal (${used.length}): ${kinds.join(' ; ')}`);
    const extra = used.filter((c) => c !== 'GET document-archive/archives');
    expect(extra, 'picker/pindah massal: tanpa request selain GET document-archive/archives (daftar)').toEqual([]);

    // tidak ada yang dipindah
    expect(fxJson('prop', '0QA25-MOVE').id_archive_parent, 'MOVE tidak dipindah').toBeNull();
    expect(fxJson('prop', '0QA25-P-DOC').id_archive_parent, 'P-DOC tidak dipindah').toBe(fx0['0QA25-P']);
    await noToast(page, `AC-40 ${lang}`);
    noServerError(monitor);
    monitor.check();
  }

  /** Pengalih pencarian picker (pola QA ED-1069, `armPickerSearch`): GET archives pertama tanpa id_archive/search sesudahnya dialihkan ke ?search=. */
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

  test('[default] ED-1025 AC-40 A (id): perisai Akses Terbatas di picker Pindahkan (⋯ dokumen, di dalam folder, pencarian) dan pindah massal (Konfirmasi + Pilih Folder); tooltip "Akses Terbatas", klik ikon = klik nama', async ({ app, page, monitor, session }) => {
    await ac40Flow({ app, page, monitor, session, lang: 'ID' });
  });

  test('[default] ED-1025 AC-40 A (en): perisai Akses Terbatas di picker Pindahkan dan pindah massal; tooltip "Restricted Access"', async ({ app, page, monitor, session }) => {
    try {
      await ac40Flow({ app, page, monitor, session, lang: 'EN' });
    } finally {
      fx('lang', 'default', 'ID'); // pulih ke ID (teardown fixture juga memulihkan dari baseline)
    }
  });
});
