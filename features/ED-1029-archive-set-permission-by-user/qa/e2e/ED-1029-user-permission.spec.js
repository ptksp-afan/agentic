'use strict';

/**
 * ED-1029 - Archive: Set Permission by User (halaman "Archive Permission", item "+", route, simpan/reset/ganti user, jalur error).
 * REVISI review 2026-10-08 (ED-1105): permission item "+", route, guard halaman dan endpoint = `Folder Permissions` (FB-4b, K-15 b);
 * semua folder On bisa dicentang walau A tanpa View/Update di sana (Q, J-JB), hanya folder Off terkunci (K-12 a, FB-9); teks info baru;
 * tanpa modal cabut-akses-sendiri (K-8 c); hak otomatis tab Izin tampil di halaman ini (FB-5).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown. Folder berawalan "0QA29-":
 *   0QA29-L On, di luar lokasi A         0QA29-P On (A penuh, B View) > 0QA29-P-C1 On (A penuh) | 0QA29-P-C2 Off (B View+Store)
 *   0QA29-Q On (A View saja, B View+Update)         0QA29-R Off > 0QA29-R-R1 On (A penuh)
 *   0QA29-J On (A View saja) > J-JA On (A penuh) | J-JB On (tanpa baris A) | J-JC Off (B View+Store)       0QA29-F Off, tanpa baris
 * Profil: [default] = A (QA_USER, role 3 non-bypass = Update Folder + Folder Permissions, employee dibatasi lokasi MGL, bahasa ID),
 *         [user2]   = QA_USER2, bahasa EN: E (preset QA29F-ROLE-E, keadaan awal fixture, tanpa Update Folder dan tanpa Folder Permissions), lalu per test
 *                     `fixture.php uprofile <M|D|E>`: M = Folder Permissions tanpa Update Folder, D = Update Folder tanpa Folder Permissions.
 *                     FE membaca peran dari GET auth/info, jadi setiap tukar peran diikuti muat ulang penuh (app.open) dan dipulihkan di finally.
 * B dan B2 = dua user target hasil pilihan fixture (lihat `fixture.php ids`).
 *
 * REVISI 3 review 2026-10-12 (ED-1130; FE ED-1129, task 024): kolom Permission = saklar Izin Folder (BR-22), kolom Semua sesudahnya (BR-21),
 * body Save `permissions` (selalu) + `folders` (hanya saklar yang berubah), hak otomatis pengaktif (K-9 a: FE mencentang bila target = user login
 * dan folder tersimpan Off; BE menaikkan baris user login bila target lain), K-10 b. Pembantu `tableRows` memisahkan saklar (`flag`), Semua (`all`)
 * dan keempat hak (`boxes`); `box()` melewati kolom Semua. Folder G (Off, A View saja) menambah data fixture (`fixture.php`).
 *
 * Mutasi hanya pada data fixture: tiap test mutasi memulai dengan `fixture.php resetperm` (hak B/A/E kembali ke nilai awal, flag dan
 * riwayat folder uji kembali). Baris archive_permissions folder nyata tidak pernah disentuh. Dijalankan berurutan (E2E_WORKERS=1).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const P = '0QA29-';
const fixture = (...args) => execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), ...args], { encoding: 'utf8', timeout: 120000 });
const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));
const dbState = () => JSON.parse(fixture('state').trim().split('\n').pop());
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const PERM_PATH = '/archives/permissions';
const GET_RE = /\/document-archive\/user-permissions\/([^/?]+)(\?|$)/;
const USERS_RE = /\/select\/document-archive\/archive\/users(\?|$)/;
const SEARCH = "input[placeholder='Cari folder'], input[placeholder='Search folder']";
const RIGHTS = ['View', 'Update', 'Delete', 'Store'];

// ------------------------------------------------------------------------------------------------ pembantu halaman Archive ("+")
const archiveRow = (page, name) =>
  page
    .locator('.archive-page .ant-table-row')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .first();

async function openPlus(page) {
  const plus = page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
  await plus.waitFor({ state: 'visible', timeout: 15000 });
  await plus.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  return menu;
}
const menuTexts = async (menu) => (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());

async function closeMenu(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
}

// ------------------------------------------------------------------------------------------------ pembantu halaman Archive Permission
/** Kartu halaman (tab app lain tetap ter-mount, jadi semua locator dibatasi ke kartu yang memuat kotak Search folder). */
const pageRoot = (page) => page.locator('.page-container').filter({ has: page.locator(SEARCH) }).first();
const resetBtn = (root) => root.locator('button', { hasText: /^Reset$/ });
const saveBtn = (root) => root.locator('button', { hasText: /Simpan Izin|Save Permission/ });
const searchInput = (root) => root.locator(SEARCH).first();

/** Baris pohon dari DOM: kunci baris (= idArchive), nama, level, teks Permission (On/Off), saklar (flag), Semua (all), 4 hak (boxes), status buka. */
const tableRows = (root) =>
  root.locator('.ant-table-tbody > tr.ant-table-row').evaluateAll((trs) =>
    trs.map((tr) => {
      const cells = tr.querySelectorAll(':scope > td');
      const level = Number((/ant-table-row-level-(\d+)/.exec(tr.className) || [])[1] || 0);
      const sw = tr.querySelector('button.ant-switch');
      const inputs = [...tr.querySelectorAll('input[type=checkbox]')];
      const state = (i) => ({ checked: i.checked, disabled: i.disabled, indeterminate: !!(i.closest('.ant-checkbox') && i.closest('.ant-checkbox').classList.contains('ant-checkbox-indeterminate')) });
      return {
        key: tr.getAttribute('data-row-key'),
        name: cells[0].innerText.replace(/\s+/g, ' ').trim(),
        level,
        perm: cells[1].innerText.replace(/\s+/g, ' ').trim(),
        flag: sw ? { checked: sw.getAttribute('aria-checked') === 'true', disabled: sw.disabled, label: sw.getAttribute('aria-label') } : null,
        all: inputs.length ? state(inputs[0]) : null, // kolom Semua = checkbox pertama (rev3)
        boxes: inputs.slice(1).map((i) => ({ checked: i.checked, disabled: i.disabled })),
        nCheckboxes: inputs.length,
        hasToggle: !!tr.querySelector('.expand-icon'),
        open: !!tr.querySelector('.expand-icon') && tr.querySelector('.expand-icon').style.rotate === '90deg',
      };
    }),
  );
const names = async (root) => (await tableRows(root)).map((r) => r.name);
const rowByName = (rows, name) => rows.find((r) => r.name === name);

const tableRowLocator = (root, name) =>
  root
    .locator('.ant-table-tbody > tr.ant-table-row')
    .filter({ has: root.page().locator('td:first-child').filter({ hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) })
    .first();
// rev3: checkbox pertama tiap baris = kolom Semua, keempat hak menyusul
const box = (root, name, right) => tableRowLocator(root, name).locator('input[type=checkbox]').nth(1 + RIGHTS.indexOf(right));
const allBox = (root, name) => tableRowLocator(root, name).locator('input[type=checkbox]').first();
const flagSwitch = (root, name) => tableRowLocator(root, name).locator('button.ant-switch');
const rowOf = async (root, name) => (await tableRows(root)).find((r) => r.name === name);

/** Tabel hasil GET (snake_case dari BE) -> daftar DFS {node, level}. */
function flatten(nodes, level = 0, out = []) {
  (nodes || []).forEach((n) => {
    out.push({ node: n, level });
    flatten(n.children, level + 1, out);
  });
  return out;
}

/** Saring seperti BR-10, dari response GET (nama mengandung kata kunci, tanpa peka huruf + semua induknya). */
function expectedFilter(nodes, keyword) {
  const q = keyword.trim().toLowerCase();
  const walk = (list) =>
    (list || []).reduce((acc, n) => {
      const kids = walk(n.children);
      if (n.name.toLowerCase().includes(q) || kids.length) acc.push({ name: n.name, kids });
      return acc;
    }, []);
  const dfs = (list, out = []) => {
    list.forEach((x) => {
      out.push(x.name);
      dfs(x.kids, out);
    });
    return out;
  };
  return dfs(walk(nodes));
}

/** Bukti isi DOM = isi GET untuk SEMUA baris: hierarki/urutan/level, tag On/Off, nilai tersimpan, terkunci menurut BR-7. */
function expectTreeMatches(domRows, body) {
  const flat = flatten(body.result.data);
  expect(domRows.map((r) => r.key), 'urutan & himpunan baris DOM = DFS response').toEqual(flat.map((f) => f.node.id_archive));
  flat.forEach(({ node, level }, i) => {
    const r = domRows[i];
    const label = `${node.name} (${node.id_archive})`;
    expect(r.name, `${label}: nama`).toBe(node.name);
    expect(r.level, `${label}: level indentasi`).toBe(level);
    expect(r.perm, `${label}: teks saklar Permission`).toBe(node.is_folder_permission === 1 ? 'On' : 'Off');
    // rev3 (AC-14): saklar sesuai is_folder_permission, aktif di semua folder bagi pemegang manage_permission (juga Off dan tanpa View)
    expect(r.flag, `${label}: saklar Izin Folder ada`).not.toBeNull();
    expect(r.flag.checked, `${label}: saklar = is_folder_permission`).toBe(node.is_folder_permission === 1);
    expect(r.flag.disabled, `${label}: saklar aktif bila manage_permission`).toBe(!(node.access && node.access.manage_permission === true));
    expect(r.nCheckboxes, `${label}: Semua + 4 hak`).toBe(5);
    const vals = [node.is_view, node.is_update, node.is_delete, node.is_store].map((v) => v === 1);
    expect(r.all.checked, `${label}: Semua tercentang bila keempat hak`).toBe(vals.every(Boolean));
    expect(r.all.indeterminate, `${label}: Semua indeterminate bila sebagian`).toBe(vals.some(Boolean) && !vals.every(Boolean));
    expect(r.boxes.map((b) => b.checked), `${label}: nilai tersimpan View/Update/Delete/Store`).toEqual([node.is_view, node.is_update, node.is_delete, node.is_store].map((v) => v === 1));
    const editable = node.is_folder_permission === 1 && node.access && node.access.manage_permission === true;
    expect(r.boxes.map((b) => !b.disabled), `${label}: sel bisa diubah hanya bila On dan manage_permission`).toEqual([editable, editable, editable, editable]);
    expect(!r.all.disabled, `${label}: Semua terkunci sama dengan sel`).toBe(editable);
    expect(r.hasToggle, `${label}: ikon buka-tutup hanya pada folder ber-subfolder`).toBe(!!(node.children && node.children.length));
    if (r.hasToggle) expect(r.open, `${label}: semua level terbuka`).toBe(true);
  });
}

const toastTexts = (page) => page.locator('.ant-notification-notice, .ant-message-notice').allInnerTexts();
const toastCount = (page) => page.locator('.ant-notification-notice, .ant-message-notice').count();
async function waitToast(page, regex, timeout = 15000) {
  await expect.poll(async () => (await toastTexts(page)).join(' | '), { timeout, message: `toast ${regex}` }).toMatch(regex);
  return (await toastTexts(page)).join(' | ');
}
async function clearToasts(page) {
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    document.querySelectorAll('.ant-notification-notice-close, .ant-message-notice').forEach((el) => el.remove?.());
  });
}

/** Catat request user-permissions (metode, URL, body) selama test. */
function trackCalls(page, monitor) {
  const calls = [];
  page.on('request', (r) => {
    if (/\/user-permissions\//.test(r.url())) calls.push({ method: r.method(), url: r.url(), body: r.postData() });
  });
  page.on('response', (r) => {
    if (/\/document-archive\/|\/select\/document-archive\//.test(r.url())) {
      monitor.note(`API ${r.status()} ${r.request().method()} ${decodeURIComponent(r.url().replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 200)}`);
      if (r.status() >= 400) {
        r.text()
          .then((t) => monitor.note(`  isi ${r.status()}: ${t.slice(0, 300)}`))
          .catch(() => null);
      }
    }
  });
  return calls;
}

/**
 * Bukti "tidak ada kilasan" (ronde 2, guard lokal AC-3): dipasang SEBELUM navigasi pertama; log disimpan di sessionStorage supaya
 * bertahan lewat reload. App ini berbasis tab (URL browser tetap "/"; path aktif = `appTab[appTabActiveKey]` di sessionStorage),
 * jadi yang dicatat adalah setiap perubahan path tab aktif (setiap tulis `appTab`/`appTabActiveKey` dan setiap mutasi DOM),
 * kemunculan sekejap halaman Unauthorized (`.ant-result-403`) dan kotak Search folder (= halaman Archive Permission ter-render).
 */
async function trackNavigation(page) {
  await page.addInitScript(() => {
    try {
      const KEY = '__qaNav';
      const store = window.sessionStorage;
      const read = () => JSON.parse(store.getItem(KEY) || '{"paths":[],"unauthorized":false,"search":false}');
      const write = (v) => Storage.prototype.setItem.call(store, KEY, JSON.stringify(v));
      const activePath = () => {
        try {
          const tabs = JSON.parse(store.getItem('appTab') || '{}');
          const key = JSON.parse(store.getItem('appTabActiveKey') || '"app-tab-0"');
          return tabs[key] || null;
        } catch (e) {
          return null;
        }
      };
      const addPath = () => {
        const cur = activePath();
        if (!cur) return;
        const v = read();
        if (v.paths[v.paths.length - 1] !== cur) {
          v.paths.push(cur);
          write(v);
        }
      };
      const origSet = Storage.prototype.setItem;
      Storage.prototype.setItem = function patched(k, val) {
        const r = origSet.call(this, k, val);
        if (this === store && (k === 'appTab' || k === 'appTabActiveKey')) addPath();
        return r;
      };
      const scan = () => {
        addPath();
        const unauthorized = !!document.querySelector('.ant-result-403');
        const search = !!document.querySelector("input[placeholder='Search folder'], input[placeholder='Cari folder']");
        if (!unauthorized && !search) return;
        const v = read();
        if ((unauthorized && !v.unauthorized) || (search && !v.search)) {
          v.unauthorized = v.unauthorized || unauthorized;
          v.search = v.search || search;
          write(v);
        }
      };
      new MutationObserver(scan).observe(document, { childList: true, subtree: true });
    } catch (e) {
      // sessionStorage tidak tersedia: log kosong -> asersi gagal (bukan lolos diam-diam)
    }
  });
  return () => page.evaluate(() => JSON.parse(window.sessionStorage.getItem('__qaNav') || 'null'));
}

/** Catat request select user (#11) selama test. */
function trackUsersCalls(page) {
  const calls = [];
  page.on('request', (r) => {
    if (USERS_RE.test(r.url())) calls.push({ method: r.method(), url: r.url() });
  });
  return calls;
}

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
}

async function openPermissionPage(app) {
  await app.open(PERM_PATH, { waitFor: SEARCH });
  expect(app.redirectOf(PERM_PATH), 'halaman tidak dialihkan').toBeNull();
}

/** Pilih user di field User (pencarian server-side lalu klik opsi bernama tepat). Mengembalikan response GET user-permissions bila dipilih. */
async function pickUser(page, root, user, { expectGet = true } = {}) {
  const got = expectGet
    ? page.waitForResponse((r) => r.request().method() === 'GET' && GET_RE.test(r.url()) && r.url().includes(user.id_user), { timeout: 45000 })
    : null;
  await root.locator('.ant-select').first().click();
  await page.keyboard.type(user.username);
  const opt = page
    .locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option')
    .filter({ hasText: new RegExp(`^${esc(user.username)}$`) })
    .first();
  await opt.waitFor({ state: 'visible', timeout: 25000 });
  await opt.click();
  if (!got) return null;
  const r = await got;
  return { status: r.status(), url: r.url(), body: await r.json().catch(() => null) };
}

async function selectAndLoad(app, page, root, user) {
  const r = await pickUser(page, root, user);
  expect(r.status, 'GET user-permissions/{user}').toBe(200);
  await app.settle();
  await expect(tableRowLocator(root, `${P}P`)).toBeVisible();
  return r;
}

const fullName = (n) => `${P}${n}`;

/** Nilai awal hak tersimpan B per folder uji (sama dengan `initialB()` di fixture.php). */
const B0 = { P: [1, 0, 0, 0], 'P-C2': [1, 0, 0, 1], Q: [1, 1, 0, 0], 'J-JC': [1, 0, 0, 1] };

const ITEM_RE = /Set Permission by User|Atur Izin per User/;
const flagsOf = (p) => [p.is_view ?? p.isView, p.is_update ?? p.isUpdate, p.is_delete ?? p.isDelete, p.is_store ?? p.isStore];

/** Jalankan $fn dengan role QA_USER2 = profil D|M; role E (preset QA29F-ROLE-E) selalu dipulihkan sesudahnya. */
async function asProfile(profile, fn) {
  fixture('uprofile', profile);
  try {
    return await fn();
  } finally {
    fixture('uprofile', 'E');
  }
}

/** "+" tidak ada sama sekali (tanpa item apa pun) atau tidak memuat Set Permission by User. Mengembalikan teks item. */
async function plusItemsOrNone(page) {
  const plus = page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
  await page.waitForTimeout(800);
  if ((await plus.count()) === 0 || !(await plus.isVisible())) return null;
  await plus.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = await menuTexts(menu);
  await closeMenu(page);
  return items;
}

/** Masuk ke folder dari daftar Archive (klik sel nama) dan tunggu GET archives?id_archive. */
async function enterFolder(app, page, name) {
  const inFolder = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives\?/.test(r.url()) && /id_?[aA]rchive=/.test(decodeURIComponent(r.url())), { timeout: 45000 });
  await archiveRow(page, name).locator('td.cursor-pointer').first().click();
  const resp = await inFolder;
  expect(resp.status(), `GET archives?id_archive (${name})`).toBe(200);
  await app.settle();
}

/** Halaman Archive Permission ditolak (guard lokal): dialihkan ke /unathorized, 403 ter-render, halaman tidak pernah ter-render, 0 request. */
async function expectPageDenied(app, page, monitor, label) {
  const calls = trackCalls(page, monitor);
  const usersCalls = trackUsersCalls(page);
  const readNav = await trackNavigation(page);
  await app.open(PERM_PATH);
  await app.settle();
  await page.waitForTimeout(800);
  const finalPath = await app.currentPath();
  const nav = await readNav();
  monitor.note(`${label}: path akhir=${finalPath}; riwayat path tab aktif=${JSON.stringify(nav && nav.paths)}; Unauthorized tampil=${nav && nav.unauthorized}; kotak Search folder pernah tampil=${nav && nav.search}; request select user=${usersCalls.length}; request user-permissions=${calls.length}`);
  expect(nav && nav.paths, `${label}: path tab aktif sempat menjadi /unathorized (pengalihan)`).toContain('/unathorized');
  expect(nav && nav.unauthorized, `${label}: halaman Unauthorized (403) ter-render`).toBe(true);
  expect(nav && nav.search, `${label}: halaman Archive Permission tidak pernah ter-render`).toBe(false);
  expect(finalPath, `${label}: path akhir /unathorized`).toBe('/unathorized');
  expect(await page.locator(SEARCH).count(), `${label}: tanpa kotak Search folder`).toBe(0);
  expect(usersCalls, `${label}: tanpa request select user`).toHaveLength(0);
  expect(calls, `${label}: tanpa request user-permissions`).toHaveLength(0);
}
test.describe('ED-1029 set permission by user', () => {
  test.describe.configure({ mode: 'default' }); // berurutan dalam satu worker, tanpa menghentikan sisanya bila satu gagal

  // ------------------------------------------------------------------------------------------------ AC-1, AC-2, AC-14 (A)
  test('[default] ED-1029 AC-1 + AC-2 + AC-14 A (rev): menu "+" (root & di dalam folder), halaman terbuka lewat "+", sel aktif di semua folder On', async ({ app, page, monitor, session }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    monitor.note(`DB sesi ${session.dbName}; A=${fx._userA.username} (role 3 = Update Folder + Folder Permissions, non-superadmin, lokasi MGL); B=${fx._userB.username}`);
    fixture('resetperm');

    // AC-1: root
    const rootList = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives(\?|$)/.test(r.url()), { timeout: 45000 });
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    expect((await rootList).status(), 'GET archives (root)').toBe(200);
    const rootItems = await menuTexts(await openPlus(page));
    monitor.note(`"+" di root (A): ${rootItems.join(' | ')}`);
    await app.screenshot('AC1-01-plus-root-A');
    expect(rootItems[rootItems.length - 1], 'item terakhir di root = Set Permission by User (id-ID: Atur Izin per User)').toBe('Atur Izin per User');
    expect(rootItems[rootItems.length - 2], 'sebelumnya Opname Document (milik ED-1026)').toBe('Opname Dokumen');
    expect(rootItems.filter((t) => t === 'Atur Izin per User'), 'tepat satu').toHaveLength(1);
    await closeMenu(page);

    // AC-1: di dalam folder P
    const inP = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives\?/.test(r.url()) && /id_?[aA]rchive=/.test(decodeURIComponent(r.url())), { timeout: 45000 });
    await archiveRow(page, `${P}P`).locator('td.cursor-pointer').first().click();
    expect((await inP).status(), 'GET archives?id_archive=P').toBe(200);
    await app.settle();
    await expect(archiveRow(page, `${P}P-C1`), 'P memuat 0QA29-P-C1').toBeVisible();
    const menuP = await openPlus(page);
    const pItems = await menuTexts(menuP);
    monitor.note(`"+" di P (A): ${pItems.join(' | ')}`);
    await app.screenshot('AC1-02-plus-di-P-A');
    expect(pItems[pItems.length - 1], 'item terakhir di dalam folder P').toBe('Atur Izin per User');
    expect(pItems[pItems.length - 2], 'sebelumnya Opname Document').toBe('Opname Dokumen');
    expect(pItems, 'di dalam folder: Store Document ada (6 item = 5 milik item lain + 1 baru)').toContain('Letakkan Dokumen');
    expect(pItems, 'Add Folder ada').toContain('Tambah Folder');
    expect(pItems.length, 'jumlah item "+" di dalam folder').toBe(6);

    // AC-2: klik item dari dalam folder -> halaman berdiri sendiri, selalu seluruh pohon dari root
    await menuP.locator('li.ant-dropdown-menu-item', { hasText: /^Atur Izin per User$/ }).click();
    await app.settle();
    const root = pageRoot(page);
    await expect(searchInput(root), 'kotak Search folder tampil').toBeVisible();
    expect(await app.currentPath(), 'navigasi ke /archives/permissions').toBe(PERM_PATH);
    await expect
      .poll(() => page.evaluate(() => [...document.querySelectorAll('*')].filter((el) => !el.children.length && el.textContent.trim() === 'Izin Arsip' && el.offsetParent !== null).length), { message: 'judul header "Izin Arsip" (id-ID untuk Archive Permission) tampil' })
      .toBeGreaterThan(0);
    const selected = await page.locator('.ant-menu-item-selected').allInnerTexts();
    monitor.note(`menu samping terpilih: ${selected.map((t) => t.trim()).join(' | ')}`);
    expect(selected.map((t) => t.trim()).join(' | '), 'menu Archive tetap aktif').toMatch(/Archive|Arsip/i);

    // keadaan sebelum user dipilih
    expect(calls, 'tanpa request user-permissions sebelum user dipilih').toHaveLength(0);
    await expect(root.getByText('Pilih user untuk melihat izin foldernya'), 'teks "pilih user" di tabel kosong').toBeVisible();
    expect((await tableRows(root)).length, 'tabel tanpa baris').toBe(0);
    await expect(resetBtn(root), 'Reset nonaktif').toBeDisabled();
    await expect(saveBtn(root), 'Save Permission nonaktif').toBeDisabled();
    const alert = root.locator('.ant-alert-info');
    await expect(alert, 'kotak info tampil').toBeVisible();
    const alertText = (await alert.innerText()).replace(/\s+/g, ' ');
    monitor.note(`info: ${alertText}`);
    expect(alertText).toContain('Checkbox hanya aktif pada folder yang');
    expect(alertText).toContain('On');
    expect(alertText, 'K-4 a: frasa "termasuk child" dibuang').not.toMatch(/termasuk child|including child/i);
    // BR-9 (rev, FB-9): teks aturan induk baru (id-ID); frasa lama "dominan"/"induk On terdekat" dibuang
    expect(alertText, 'BR-9 id: folder Off tidak membatasi').toContain('Folder Off tidak bisa dicentang dan tidak membatasi akses.');
    expect(alertText, 'BR-9 id: hanya View induk yang berlaku').toContain('Hanya izin Lihat di folder induk yang berlaku: user tanpa izin Lihat di folder induk tidak bisa membuka subfoldernya walaupun dicentang di sini.');
    expect(alertText, 'BR-9 id: U/D/S per folder').toContain('Ubah, Hapus, dan Letakkan dinilai di folder masing-masing.');
    expect(alertText, 'BR-9 id: tanpa "dominan"/"induk On terdekat"').not.toMatch(/dominan|induk On terdekat/i);
    // BR-9 rev3 (AC-30, K-9 a): kalimat tambahan; {setting} dirender (bukan literal)
    expect(alertText, 'BR-9 rev3 id: kalimat saklar').toMatch(/Menyalakan .+ di sini membatasi folder itu bagi semua user tanpa izin Lihat di sana dan memberi Anda semua hak di folder itu\./);
    expect(alertText, 'BR-9 rev3 id: {setting} tidak tersisa literal').not.toContain('{setting}');
    await expect.poll(() => monitor.responses.filter((r) => USERS_RE.test(r.url)).length, { timeout: 15000, message: 'dropdown User memanggil select/document-archive/archive/users' }).toBeGreaterThan(0);
    const usersReqs = monitor.responses.filter((r) => USERS_RE.test(r.url));
    expect(usersReqs.every((r) => !/excepts/.test(decodeURIComponent(r.url))), 'tanpa excepts').toBe(true);
    await app.screenshot('AC2-01-halaman-sebelum-pilih-user');

    // pilih B -> satu GET, pohon tampil
    const loaded = await pickUser(page, root, fx._userB);
    expect(loaded.status, 'GET user-permissions/{B}').toBe(200);
    expect(loaded.body.msg_code, 'msg_code').toBe('ARCHIVE200');
    expect(loaded.body.result.user.id_user, 'result.user = B').toBe(fx._userB.id_user);
    await app.settle();
    await expect(tableRowLocator(root, `${P}P`)).toBeVisible();
    expect(calls.filter((c) => c.method === 'GET'), 'tepat satu GET user-permissions').toHaveLength(1);
    expect(await toastCount(page), 'tanpa toast sesudah memilih user').toBe(0);
    await app.screenshot('AC14-01-pohon-user-B');

    // rev3 (AC-14, AC-29): urutan kolom Folder | Permission | Semua | View | Update | Delete | Store (id-ID)
    const heads = (await root.locator('.ant-table-thead').first().locator('th').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean); // th kosong terakhir = placeholder scrollbar tabel
    monitor.note(`judul kolom: ${heads.join(' | ')}`);
    expect(heads.slice(1), 'kolom Semua tepat sesudah Permission, sebelum Lihat/Ubah/Hapus/Letakkan').toEqual(['Izin', 'Semua', 'Lihat', 'Ubah', 'Hapus', 'Letakkan']);

    // AC-14: isi DOM = isi GET untuk semua baris
    const rows = await tableRows(root);
    expectTreeMatches(rows, loaded.body);
    monitor.note(`pohon: ${rows.length} baris; folder uji: ${rows.filter((r) => r.name.startsWith(P)).map((r) => `${'  '.repeat(r.level)}${r.name}[${r.perm}]`).join(' / ')}`);

    // nilai pasti folder uji (AC-14 rev: P, C1, Q, R1 bisa dicentang - Q walau A hanya View; hanya C2 dan R (Off) terkunci; L tidak ada)
    const pn = (n) => rowByName(rows, fullName(n));
    expect(pn('L'), 'L (di luar lokasi A) tidak tampil').toBeUndefined();
    ['P', 'P-C1', 'P-C2', 'Q', 'R', 'R-R1'].forEach((n) => expect(pn(n), `${fullName(n)} tampil`).toBeTruthy());
    expect(pn('P').level).toBe(0);
    expect(pn('P-C1').level, 'C1 di bawah P').toBe(1);
    expect(pn('P-C2').level, 'C2 di bawah P').toBe(1);
    expect(pn('R-R1').level, 'R1 di bawah R').toBe(1);
    const idx = (n) => rows.indexOf(pn(n));
    expect(idx('P') < idx('P-C1') && idx('P-C1') < idx('P-C2') && idx('P-C2') < idx('Q') && idx('Q') < idx('R') && idx('R') < idx('R-R1'), 'urutan nama: P, C1, C2, Q, R, R1').toBe(true);
    const enabledOf = (n) => pn(n).boxes.map((b) => !b.disabled);
    ['P', 'P-C1', 'Q', 'R-R1'].forEach((n) => expect(enabledOf(n), `${fullName(n)} (On): centang aktif`).toEqual([true, true, true, true]));
    ['P-C2', 'R'].forEach((n) => expect(enabledOf(n), `${fullName(n)} (Off): terkunci`).toEqual([false, false, false, false]));
    // A hanya View di Q: access.view true, update/delete/store false, tetapi manage_permission true (K-15 b)
    const nodeQ = flatten(loaded.body.result.data).find((f) => f.node.name === fullName('Q')).node;
    expect([nodeQ.access.view, nodeQ.access.update, nodeQ.access.delete, nodeQ.access.store, nodeQ.access.manage_permission], 'Q: A View saja, tetap bisa dikelola').toEqual([true, false, false, false, true]);
    expect(['P', 'P-C1', 'P-C2', 'Q', 'R', 'R-R1'].map((n) => pn(n).perm)).toEqual(['On', 'On', 'Off', 'On', 'Off', 'On']);
    // rev3 (AC-14): kolom Permission = saklar (bukan tag), aktif di SEMUA folder (juga Off); sel & Semua aktif hanya di folder On
    expect(['P', 'P-C1', 'P-C2', 'Q', 'R', 'R-R1'].map((n) => pn(n).flag.checked), 'saklar mengikuti is_folder_permission').toEqual([true, true, false, true, false, true]);
    expect(rows.every((r) => r.flag && r.flag.disabled === false), 'saklar aktif di semua folder yang tampil').toBe(true);
    expect(await root.locator('.ant-table-tbody .ant-tag').count(), 'tanpa tag Permission lagi (diganti saklar)').toBe(0);
    ['P', 'P-C1', 'Q', 'R-R1'].forEach((n) => expect(pn(n).all.disabled, `${fullName(n)} (On): Semua aktif`).toBe(false));
    ['P-C2', 'R'].forEach((n) => expect(pn(n).all.disabled, `${fullName(n)} (Off): Semua terkunci`).toBe(true));
    expect(pn('P').all, 'P (B View): Semua indeterminate').toEqual({ checked: false, indeterminate: true, disabled: false });
    expect(pn('P-C2').all, 'C2 (Off, View+Store tersimpan): Semua indeterminate dan terkunci').toEqual({ checked: false, indeterminate: true, disabled: true });
    expect(pn('P-C1').all, 'C1 kosong: Semua kosong').toEqual({ checked: false, indeterminate: false, disabled: false });
    expect(pn('P').flag.label, 'aria-label saklar = <folder> - Izin').toBe(`${fullName('P')} - Izin`);
    const checkedOf = (n) => pn(n).boxes.map((b) => b.checked);
    expect(checkedOf('P'), 'P: nilai tersimpan B = View').toEqual([true, false, false, false]);
    expect(checkedOf('P-C1'), 'C1: kosong').toEqual([false, false, false, false]);
    expect(checkedOf('P-C2'), 'C2 (Off): nilai tersimpan View+Store tetap terlihat walau terkunci').toEqual([true, false, false, true]);
    expect(checkedOf('Q'), 'Q (On, A View saja): nilai tersimpan View+Update terlihat dan bisa diubah').toEqual([true, true, false, false]);
    expect(checkedOf('R-R1')).toEqual([false, false, false, false]);
    expect(await page.locator('.ant-notification-notice-description, .ant-message-error').count(), 'tanpa pesan error').toBe(0);
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-2 lewat URL + F5 (A)
  test('[default] ED-1029 AC-2 A: halaman terbuka lewat URL /archives/permissions dan sesudah reload (F5) tanpa user terpilih', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    await openPermissionPage(app);
    const root = pageRoot(page);
    await expect(root.getByText('Pilih user untuk melihat izin foldernya')).toBeVisible();
    await expect(saveBtn(root)).toBeDisabled();
    await expect(resetBtn(root)).toBeDisabled();
    expect(calls, 'tanpa request user-permissions').toHaveLength(0);
    const sel = await page.locator('.ant-menu-item-selected').allInnerTexts();
    expect(sel.map((t) => t.trim()).join(' | '), 'menu Archive aktif').toMatch(/Archive|Arsip/i);
    await app.screenshot('AC2-02-halaman-lewat-URL');

    const fx = ids();
    await selectAndLoad(app, page, root, fx._userB);
    expect((await tableRows(root)).length, 'pohon tampil sebelum reload').toBeGreaterThan(5);

    // F5: user terpilih tidak diingat (tabel kosong lagi), menu Archive tetap aktif
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator(SEARCH).first().waitFor({ state: 'visible', timeout: 30000 });
    await app.settle();
    const root2 = pageRoot(page);
    expect((await tableRows(root2)).length, 'sesudah F5: tabel kosong').toBe(0);
    await expect(root2.getByText('Pilih user untuk melihat izin foldernya')).toBeVisible();
    await expect(saveBtn(root2)).toBeDisabled();
    const sel2 = await page.locator('.ant-menu-item-selected').allInnerTexts();
    expect(sel2.map((t) => t.trim()).join(' | '), 'menu Archive aktif sesudah F5').toMatch(/Archive|Arsip/i);
    await app.screenshot('AC2-03-sesudah-F5');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-3 sisi A (ronde 2): tanpa pengalihan, tanpa kilasan Unauthorized
  test('[default] ED-1029 AC-3 A: URL langsung, hard reload (F5) dan lewat "+" -> halaman tampil, tidak pernah dialihkan, tanpa kilasan Unauthorized', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const readNav = await trackNavigation(page);

    // (1) buka langsung lewat URL (hard load)
    await openPermissionPage(app);
    await app.screenshot('AC3A-01-URL-langsung');
    const nav1 = await readNav();
    monitor.note(`URL langsung: paths=${JSON.stringify(nav1.paths)} unauthorized=${nav1.unauthorized} search=${nav1.search}`);
    expect(nav1.search, 'halaman Archive Permission ter-render').toBe(true);
    expect(nav1.unauthorized, 'tanpa kilasan Unauthorized (hard load)').toBe(false);
    expect(nav1.paths, 'tidak pernah ke /unathorized (hard load)').not.toContain('/unathorized');

    // (2) hard reload di /archives/permissions
    for (let i = 1; i <= 2; i += 1) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.locator(SEARCH).first().waitFor({ state: 'visible', timeout: 30000 });
      await app.settle();
      await page.waitForTimeout(1200);
      const nav = await readNav();
      monitor.note(`F5 #${i}: paths=${JSON.stringify(nav.paths)} unauthorized=${nav.unauthorized}`);
      expect(nav.unauthorized, `tanpa kilasan Unauthorized (F5 #${i})`).toBe(false);
      expect(nav.paths, `tidak pernah ke /unathorized (F5 #${i})`).not.toContain('/unathorized');
      expect(await page.locator('.ant-result-403').count(), `tanpa Result 403 (F5 #${i})`).toBe(0);
      expect(await app.currentPath(), `path tab tetap (F5 #${i})`).toBe(PERM_PATH);
    }
    await app.screenshot('AC3A-02-sesudah-F5');
    const root = pageRoot(page);
    await expect(root.getByText('Pilih user untuk melihat izin foldernya')).toBeVisible();
    expect(await root.locator('.ant-select').count(), 'kotak User ada').toBeGreaterThan(0);

    // (3) lewat "+" (navigasi client-side) dari Archive
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    const menu = await openPlus(page);
    const item = menu.locator('li.ant-dropdown-menu-item').filter({ hasText: /Set Permission by User|Atur Izin per User/ }).first();
    await expect(item, 'A melihat item Set Permission by User').toBeVisible();
    await item.click();
    await page.locator(SEARCH).first().waitFor({ state: 'visible', timeout: 30000 });
    await app.settle();
    await page.waitForTimeout(800);
    const navFinal = await readNav();
    monitor.note(`lewat "+": paths=${JSON.stringify(navFinal.paths)} unauthorized=${navFinal.unauthorized}`);
    expect(navFinal.unauthorized, 'tanpa kilasan Unauthorized (lewat "+")').toBe(false);
    expect(navFinal.paths, 'tidak pernah ke /unathorized (seluruh test)').not.toContain('/unathorized');
    expect(navFinal.paths, 'path tab aktif memuat /archives/permissions').toContain(PERM_PATH);
    expect(calls.filter((c) => c.method !== 'GET'), 'tanpa request tulis').toHaveLength(0);
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-15 (A)
  test('[default] ED-1029 AC-15 A: centang Update/Delete/Store ikut mencentang View; lepas View melepas semuanya; sel terkunci tidak bisa diubah', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    await expect(saveBtn(root), 'tanpa perubahan: Save nonaktif').toBeDisabled();
    const state = async (n) => (await tableRows(root)).find((r) => r.name === fullName(n)).boxes.map((b) => b.checked);

    const C1 = fullName('P-C1');
    expect(await state('P-C1')).toEqual([false, false, false, false]);

    // Update -> View ikut
    await box(root, C1, 'Update').check();
    expect(await state('P-C1'), 'centang Update: View ikut tercentang').toEqual([true, true, false, false]);
    await expect(resetBtn(root), 'Reset hidup').toBeEnabled();
    await expect(saveBtn(root), 'Save hidup').toBeEnabled();
    await app.screenshot('AC15-01-update-menyalakan-view');
    // Delete dan Store
    await box(root, C1, 'Delete').check();
    expect(await state('P-C1')).toEqual([true, true, true, false]);
    await box(root, C1, 'Store').check();
    expect(await state('P-C1'), 'keempatnya tercentang').toEqual([true, true, true, true]);
    // lepas Update tidak melepas View
    await box(root, C1, 'Update').uncheck();
    expect(await state('P-C1'), 'lepas Update: View tetap').toEqual([true, false, true, true]);
    // lepas View -> semuanya lepas
    await box(root, C1, 'View').uncheck();
    expect(await state('P-C1'), 'lepas View: keempatnya lepas').toEqual([false, false, false, false]);
    await expect(resetBtn(root), 'sama dengan tersimpan: Reset mati lagi').toBeDisabled();
    await expect(saveBtn(root), 'sama dengan tersimpan: Save mati lagi').toBeDisabled();
    await app.screenshot('AC15-02-lepas-view-melepas-semua');

    // Store di P yang tersimpan View -> V+S; lalu centang Delete di R1
    await box(root, fullName('P'), 'Store').check();
    expect(await state('P')).toEqual([true, false, false, true]);

    // (rev) Q (On, A View saja) bisa diubah: Delete ikut mencentang View+Update tersimpan, lepas View melepas semuanya, lalu Reset
    expect(await state('Q'), 'Q: nilai tersimpan B View+Update').toEqual([true, true, false, false]);
    await box(root, fullName('Q'), 'Delete').check();
    expect(await state('Q'), 'Q: centang Delete (A hanya View di Q, tetap bisa)').toEqual([true, true, true, false]);
    await box(root, fullName('Q'), 'View').uncheck();
    expect(await state('Q'), 'Q: lepas View melepas semuanya').toEqual([false, false, false, false]);
    await box(root, fullName('Q'), 'View').check();
    await box(root, fullName('Q'), 'Update').check();
    expect(await state('Q'), 'Q: kembali ke nilai tersimpan').toEqual([true, true, false, false]);

    // sel terkunci (hanya folder Off: C2 dan R) tidak bisa diubah walau diklik paksa
    for (const [n, right] of [['P-C2', 'Update'], ['R', 'View'], ['P-C2', 'Store']]) {
      const before = (await tableRows(root)).find((r) => r.name === fullName(n)).boxes.map((b) => b.checked);
      await expect(box(root, fullName(n), right), `${n}/${right} disabled`).toBeDisabled();
      await box(root, fullName(n), right).click({ force: true, timeout: 5000 }).catch(() => null);
      const after = (await tableRows(root)).find((r) => r.name === fullName(n)).boxes.map((b) => b.checked);
      expect(after, `${n}/${right} tidak berubah`).toEqual(before);
    }
    expect(calls, 'mengubah centang tidak memanggil BE').toHaveLength(1); // hanya GET user B
    expect(calls[0].method).toBe('GET');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-16 (A)
  test('[default] ED-1029 AC-16 A: Search folder menyaring (cocok + induk) tanpa membuang perubahan; kosongkan = pohon penuh', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    const loaded = await selectAndLoad(app, page, root, fx._userB);
    const fullNames = await names(root);
    const nGet = calls.length;

    // ubah P (Store; View ikut sudah ada)
    await box(root, fullName('P'), 'Store').check();
    await box(root, fullName('P-C1'), 'View').check();
    await expect(saveBtn(root)).toBeEnabled();

    // "c1" -> hanya P (induk) dan C1
    await searchInput(root).fill('c1');
    await page.waitForTimeout(500);
    expect(await names(root), 'cari "c1": hanya induk P dan C1').toEqual([fullName('P'), fullName('P-C1')]);
    expect(await names(root)).toEqual(expectedFilter(loaded.body.result.data, 'c1'));
    await app.screenshot('AC16-01-cari-c1');
    // perubahan masih ada walau baris lain tersembunyi, Save tetap hidup
    expect((await tableRows(root)).find((r) => r.name === fullName('P')).boxes.map((b) => b.checked), 'P tetap V+S saat disaring').toEqual([true, false, false, true]);
    expect((await tableRows(root)).find((r) => r.name === fullName('P-C1')).boxes.map((b) => b.checked), 'C1 tetap View saat disaring').toEqual([true, false, false, false]);
    await expect(saveBtn(root)).toBeEnabled();

    // tanpa peka huruf besar, "mengandung" (bukan awalan): "0QA29-P" huruf besar/kecil campur
    await searchInput(root).fill('0qa29-P-c');
    await page.waitForTimeout(400);
    expect(await names(root), 'cari "0qa29-P-c": P, C1, C2').toEqual([fullName('P'), fullName('P-C1'), fullName('P-C2')]);
    await searchInput(root).fill('BRANGKAS');
    await page.waitForTimeout(400);
    const brangkas = await names(root);
    monitor.note(`cari "BRANGKAS": ${brangkas.join(' | ')}`);
    expect(brangkas, 'induk ikut tampil + semua cocok menurut response').toEqual(expectedFilter(loaded.body.result.data, 'BRANGKAS'));
    expect(brangkas.length, 'ada hasil dari folder nyata (induk + cocok)').toBeGreaterThanOrEqual(2);
    await searchInput(root).fill('zzzz-tidak-ada');
    await page.waitForTimeout(400);
    expect(await names(root), 'tanpa hasil').toEqual([]);
    await expect(saveBtn(root), 'tanpa hasil pun perubahan tidak hilang').toBeEnabled();

    // kosongkan: pohon penuh, perubahan masih ada
    await searchInput(root).fill('');
    await page.waitForTimeout(500);
    expect(await names(root), 'dikosongkan: pohon penuh lagi').toEqual(fullNames);
    expectTreeMatchesEdited(await tableRows(root), loaded.body);
    expect((await tableRows(root)).find((r) => r.name === fullName('P')).boxes.map((b) => b.checked), 'P masih V+S sesudah pencarian dikosongkan').toEqual([true, false, false, true]);
    expect((await tableRows(root)).find((r) => r.name === fullName('P-C1')).boxes.map((b) => b.checked)).toEqual([true, false, false, false]);
    await app.screenshot('AC16-02-dikosongkan-perubahan-ada');
    expect(calls.length, 'mencari tidak memanggil BE').toBe(nGet);
    noServerError(monitor);

    function expectTreeMatchesEdited(rows, body) {
      // semua baris selain P dan C1 sama dengan response
      const flat = flatten(body.result.data);
      expect(rows.map((r) => r.key)).toEqual(flat.map((f) => f.node.id_archive));
      flat.forEach(({ node }, i) => {
        if ([fullName('P'), fullName('P-C1')].includes(node.name)) return;
        expect(rows[i].boxes.map((b) => b.checked), `${node.name}: tidak berubah`).toEqual([node.is_view, node.is_update, node.is_delete, node.is_store].map((v) => v === 1));
      });
    }
  });

  // ------------------------------------------------------------------------------------------------ AC-17 (+ BR-12, BR-16) (A)
  test('[default] ED-1029 AC-17 A: Save mengirim hanya P dan C1, toast sukses, tabel dimuat ulang; DB & riwayat sesuai; lalu hapus baris P (semua 0)', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    const before = dbState();
    expect(before.rows.B, 'awal: hak B').toEqual(B0);
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);

    // ubah P (V+S) dan C1 (V); lalu ubah Q? terkunci. Ubah satu lalu kembalikan (R1) = tidak ikut terkirim
    await box(root, fullName('P'), 'Store').check();
    await box(root, fullName('P-C1'), 'View').check();
    await box(root, fullName('R-R1'), 'Delete').check(); // View ikut
    await box(root, fullName('R-R1'), 'View').uncheck(); // semua lepas = sama dengan tersimpan -> R1 keluar dari perubahan
    expect((await tableRows(root)).find((r) => r.name === fullName('R-R1')).boxes.map((b) => b.checked), 'R1 kembali kosong').toEqual([false, false, false, false]);
    await app.screenshot('AC17-01-sebelum-save');

    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    const reload = page.waitForResponse((r) => r.request().method() === 'GET' && GET_RE.test(r.url()) && r.url().includes(fx._userB.id_user) && calls.filter((c) => c.method === 'PUT').length > 0, { timeout: 45000 });
    await saveBtn(root).click();
    const putResp = await put;
    expect(putResp.status(), 'PUT user-permissions/{B}').toBe(200);
    const putBody = await putResp.json();
    expect(putBody.msg_code, 'msg_code').toBe('ARCHIVE207');
    expect(putBody.result.id_user, 'result.id_user').toBe(fx._userB.id_user);
    const putCall = calls.find((c) => c.method === 'PUT');
    expect(putCall.url, 'PUT ke user B').toContain(`/user-permissions/${fx._userB.id_user}`);
    const sent = JSON.parse(putCall.body);
    monitor.note(`PUT body: ${putCall.body}`);
    const sentKeys = Object.keys(sent);
    expect(sentKeys, 'body hanya berisi permissions').toEqual(['permissions']);
    const byId = Object.fromEntries(sent.permissions.map((p) => [p.id_archive || p.idArchive, p]));
    expect(sent.permissions, 'hanya 2 folder yang berubah (P dan C1)').toHaveLength(2);
    expect(Object.keys(byId).sort()).toEqual([fx.P, fx['P-C1']].sort());
    const flags = (p) => [p.is_view ?? p.isView, p.is_update ?? p.isUpdate, p.is_delete ?? p.isDelete, p.is_store ?? p.isStore];
    expect(flags(byId[fx.P]), 'P: V+S sebagai integer 0/1').toEqual([1, 0, 0, 1]);
    expect(flags(byId[fx['P-C1']]), 'C1: View').toEqual([1, 0, 0, 0]);
    flags(byId[fx.P]).concat(flags(byId[fx['P-C1']])).forEach((v) => expect(typeof v, 'flag integer').toBe('number'));
    const toast = await waitToast(page, /.+/);
    monitor.note(`toast sesudah Save: ${toast}`);
    expect(await page.locator('.ant-notification-notice .anticon-check-circle, .ant-message-notice .anticon-check-circle').count(), 'toast sukses (ikon centang)').toBeGreaterThan(0);
    const reloaded = await reload;
    expect(reloaded.status(), 'GET dimuat ulang').toBe(200);
    await app.settle();
    const rb = await reloaded.json();
    expectTreeMatches(await tableRows(root), rb);
    const P_ = rb.result.data.find((n) => n.name === fullName('P'));
    expect([P_.is_view, P_.is_update, P_.is_delete, P_.is_store], 'GET ulang: P = V+S').toEqual([1, 0, 0, 1]);
    await expect(saveBtn(root), 'sesudah muat ulang Save mati').toBeDisabled();
    await expect(resetBtn(root), 'sesudah muat ulang Reset mati').toBeDisabled();
    expect(calls.map((c) => c.method).join(','), 'urutan request: GET, PUT, GET').toBe('GET,PUT,GET');
    await app.screenshot('AC17-02-sesudah-save');

    // DB: baris & riwayat (BR-11, BR-12)
    const after = dbState();
    expect(after.rows.B, 'DB: B -> P V+S, C1 V; C2, Q dan J-JC tidak tersentuh').toEqual({ ...B0, P: [1, 0, 0, 1], 'P-C1': [1, 0, 0, 0] });
    expect(after.rows.B2, 'DB: B2 tidak tersentuh').toEqual({});
    expect(after.history.P.count, 'riwayat permission P +1').toBe(1);
    expect(after.history['P-C1'].count, 'riwayat permission C1 +1').toBe(1);
    expect(after.history.P.last_by, 'pelaku riwayat = A').toBe(fx._userA.username);
    ['P-C2', 'Q', 'R', 'R-R1', 'L', 'J', 'J-JA', 'J-JB', 'J-JC', 'F'].forEach((n) => expect(after.history[n].count, `riwayat ${n} tidak bertambah`).toBe(0));
    expect(after.total_permissions - before.total_permissions, 'baris baru: C1 saja').toBe(1);

    // BR-16: data yang sama terlihat di tab Permission folder P (ED-1025)
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    const view = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives\/[^/?]+(\?|$)/.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
    const btn = archiveRow(page, `${P}P`).locator('button.btn-action');
    await btn.click();
    const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    await menu.waitFor({ state: 'visible', timeout: 10000 });
    await menu.locator('li.ant-dropdown-menu-item', { hasText: /^(Lihat|View)$/ }).first().click();
    const show = await (await view).json();
    const drawer = page.locator('.ant-drawer-open').last();
    await drawer.waitFor({ state: 'visible', timeout: 15000 });
    expect(show.result.folder_permissions.map((r) => [r.username, r.is_view, r.is_update, r.is_delete, r.is_store]).filter((r) => r[0] === fx._userB.username), 'GET archives/{P}: baris B = View+Store').toEqual([[fx._userB.username, 1, 0, 0, 1]]);
    await drawer.locator('.ant-tabs-tab').nth(1).click();
    await drawer.locator('.ant-switch').first().waitFor({ state: 'visible', timeout: 10000 });
    const bRow = drawer.locator('.ant-tabs-tabpane-active .ant-table-row').filter({ hasText: new RegExp(`(^|\\s)${esc(fx._userB.username)}(\\s|$)`) }).first();
    await expect(bRow, 'baris B ada di tab Permission P').toBeVisible();
    // kolom pertama tab Izin = All (ED-1025 AC-32, rev); empat berikutnya View/Update/Delete/Store
    expect((await bRow.locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.checked))).slice(-4), 'tab Permission P: B = View+Store').toEqual([true, false, false, true]);
    await app.screenshot('AC17-03-tab-permission-P-memuat-B');
    await page.keyboard.press('Escape');
    await page.locator('.ant-drawer-open .ant-drawer-close').last().click().catch(() => null);
    await page.waitForTimeout(600);

    // lepas semua hak P lewat halaman: View dilepas -> semuanya 0 -> baris dihapus
    const calls2 = [];
    page.on('request', (r) => {
      if (/\/user-permissions\//.test(r.url())) calls2.push({ method: r.method(), body: r.postData() });
    });
    await openPermissionPage(app);
    const root2 = pageRoot(page);
    await selectAndLoad(app, page, root2, fx._userB);
    await box(root2, fullName('P'), 'View').uncheck();
    expect((await tableRows(root2)).find((r) => r.name === fullName('P')).boxes.map((b) => b.checked), 'P kosong di layar').toEqual([false, false, false, false]);
    const put2 = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    await saveBtn(root2).click();
    expect((await put2).status()).toBe(200);
    const sent2 = JSON.parse(calls2.find((c) => c.method === 'PUT').body);
    monitor.note(`PUT #2 body: ${JSON.stringify(sent2)}`);
    expect(sent2.permissions, 'hanya P').toHaveLength(1);
    expect([sent2.permissions[0].is_view ?? sent2.permissions[0].isView, sent2.permissions[0].is_update ?? sent2.permissions[0].isUpdate, sent2.permissions[0].is_delete ?? sent2.permissions[0].isDelete, sent2.permissions[0].is_store ?? sent2.permissions[0].isStore], 'keempat flag 0').toEqual([0, 0, 0, 0]);
    await app.settle();
    await expect(saveBtn(root2)).toBeDisabled();
    const after2 = dbState();
    expect(after2.rows.B.P, 'DB: baris (P,B) terhapus').toBeUndefined();
    expect(after2.rows.B['P-C1'], 'C1 tetap').toEqual([1, 0, 0, 0]);
    expect(after2.rows.B['J-JC'], 'J-JC tetap').toEqual([1, 0, 0, 1]);
    expect(after2.history.P.count, 'riwayat P +1 lagi').toBe(2);
    expect((await tableRows(root2)).find((r) => r.name === fullName('P')).boxes.map((b) => b.checked), 'sesudah muat ulang P kosong').toEqual([false, false, false, false]);
    await app.screenshot('AC17-04-baris-P-dihapus');
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-18 (A)
  test('[default] ED-1029 AC-18 A: Reset kembali ke nilai tersimpan tanpa request; ganti User saat ada perubahan -> konfirmasi (Tidak = tetap, Ya = pindah)', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const stored = (await tableRows(root)).map((r) => r.boxes.map((b) => b.checked));

    // Reset
    await box(root, fullName('P-C1'), 'Update').check();
    await box(root, fullName('R-R1'), 'Store').check();
    await expect(resetBtn(root)).toBeEnabled();
    const nBefore = calls.length;
    await resetBtn(root).click();
    await page.waitForTimeout(500);
    expect((await tableRows(root)).map((r) => r.boxes.map((b) => b.checked)), 'Reset: semua nilai kembali ke tersimpan').toEqual(stored);
    await expect(resetBtn(root)).toBeDisabled();
    await expect(saveBtn(root)).toBeDisabled();
    expect(calls.length, 'Reset tanpa request').toBe(nBefore);
    expect(await page.locator('.ant-modal-confirm').count(), 'Reset tanpa konfirmasi').toBe(0);
    await app.screenshot('AC18-01-sesudah-reset');

    // ganti user tanpa perubahan: langsung, tanpa konfirmasi
    // (diuji di bagian akhir; sekarang perubahan dulu)
    await box(root, fullName('P-C1'), 'View').check();
    await box(root, fullName('P'), 'Delete').check();
    const dirty = (await tableRows(root)).map((r) => r.boxes.map((b) => b.checked));

    // ganti user -> konfirmasi, Tidak
    await pickUser(page, root, fx._userB2, { expectGet: false });
    const confirm = page.locator('.ant-modal-confirm').last();
    await confirm.waitFor({ state: 'visible', timeout: 10000 });
    const confirmText = (await confirm.innerText()).replace(/\s+/g, ' ');
    monitor.note(`konfirmasi: ${confirmText}`);
    expect(confirmText, 'konfirmasi memuat nama user lama').toContain(fx._userB.username);
    expect(confirmText).toMatch(/belum disimpan akan hilang/);
    await app.screenshot('AC18-02-konfirmasi-ganti-user');
    await confirm.locator('button', { hasText: /^Tidak$/ }).click();
    await page.waitForTimeout(700);
    expect(await page.locator('.ant-modal-confirm').count(), 'konfirmasi tertutup').toBe(0);
    expect((await tableRows(root)).map((r) => r.boxes.map((b) => b.checked)), 'Tidak: perubahan tetap di layar').toEqual(dirty);
    await expect(saveBtn(root), 'Save masih hidup').toBeEnabled();
    expect(calls.filter((c) => c.url.includes(fx._userB2.id_user)), 'Tidak: tanpa request user B2').toHaveLength(0);
    // field User tetap menampilkan B
    await expect(root.locator('.ant-select-selection-item').first(), 'field User tetap B').toHaveText(new RegExp(`^${esc(fx._userB.username)}$`));
    // peta perubahan tidak hilang: Save-nya masih untuk B (cek tanpa menyimpan: Reset lalu kembali)
    await app.screenshot('AC18-03-sesudah-tidak');

    // ganti user lagi -> Ya
    const got = page.waitForResponse((r) => r.request().method() === 'GET' && GET_RE.test(r.url()) && r.url().includes(fx._userB2.id_user), { timeout: 45000 });
    await pickUser(page, root, fx._userB2, { expectGet: false });
    await page.locator('.ant-modal-confirm').last().waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('.ant-modal-confirm').last().locator('button', { hasText: /^Ya$/ }).click();
    const r2 = await got;
    expect(r2.status(), 'Ya: GET user baru').toBe(200);
    await app.settle();
    const rb2 = await r2.json();
    expect(rb2.result.user.id_user).toBe(fx._userB2.id_user);
    expectTreeMatches(await tableRows(root), rb2);
    expect((await tableRows(root)).find((r) => r.name === fullName('P')).boxes.map((b) => b.checked), 'B2 tanpa baris: P kosong, perubahan B hilang').toEqual([false, false, false, false]);
    await expect(root.locator('.ant-select-selection-item').first()).toHaveText(new RegExp(`^${esc(fx._userB2.username)}$`));
    await expect(saveBtn(root), 'Save mati sesudah pindah user').toBeDisabled();
    await app.screenshot('AC18-04-sesudah-ya-user-B2');

    // ganti user TANPA perubahan: tanpa konfirmasi
    const got3 = page.waitForResponse((r) => r.request().method() === 'GET' && GET_RE.test(r.url()) && r.url().includes(fx._userB.id_user), { timeout: 45000 });
    await pickUser(page, root, fx._userB, { expectGet: false });
    expect((await got3).status()).toBe(200);
    expect(await page.locator('.ant-modal-confirm').count(), 'tanpa perubahan: tanpa konfirmasi').toBe(0);
    expect(calls.filter((c) => c.method !== 'GET'), 'tidak ada PUT sama sekali').toHaveLength(0);
    expect(dbState().rows.B, 'DB: hak B tidak berubah').toEqual(B0);
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-19 (A)
  test('[default] ED-1029 AC-19 A: BE menolak (400 ARCHIVE441, C1 dimatikan sesudah halaman dibuka) -> toast error, perubahan tetap di layar', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    await box(root, fullName('P-C1'), 'View').check();
    await box(root, fullName('P'), 'Store').check();
    const dirty = (await tableRows(root)).map((r) => r.boxes.map((b) => b.checked));

    // di antara buka & Save: folder permission C1 dimatikan (seperti di tab Permission folder)
    fixture('flag', 'P-C1', '0');
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    const nGet = calls.filter((c) => c.method === 'GET').length;
    await saveBtn(root).click();
    const resp = await put;
    const body = await resp.json();
    monitor.note(`PUT ditolak: ${resp.status()} ${body.code || body.msg_code} ${JSON.stringify(body.message || '')}`);
    expect(resp.status(), 'PUT ditolak').toBe(400);
    expect(body.code || body.msg_code, 'kode error').toBe('ARCHIVE441');
    const toast = await waitToast(page, /0QA29-P-C1/);
    monitor.note(`toast error: ${toast}`);
    expect(toast, 'pesan error memuat nama folder penolak').toMatch(/belum aktif/);
    expect(await page.locator('.ant-notification-notice .anticon-close-circle, .ant-message-notice .anticon-close-circle').count(), 'toast error (ikon silang)').toBeGreaterThan(0);
    await app.screenshot('AC19-01-toast-error-441');
    expect((await tableRows(root)).map((r) => r.boxes.map((b) => b.checked)), 'centang di layar tidak hilang').toEqual(dirty);
    await expect(saveBtn(root), 'Save tetap hidup').toBeEnabled();
    await expect(resetBtn(root)).toBeEnabled();
    expect(calls.filter((c) => c.method === 'GET').length, 'tanpa GET ulang sesudah gagal').toBe(nGet);
    // atomik: tidak ada yang tersimpan
    const st = dbState();
    expect(st.rows.B, 'DB: tidak ada yang tersimpan (P tetap View saja)').toEqual(B0);
    expect(st.history.P.count, 'tanpa riwayat P').toBe(0);
    expect(st.flags['P-C1'], 'C1 memang Off di DB').toBe(0);

    // penolakan 400 dan log konsol axios/ahooks yang diharapkan dikeluarkan dari pemeriksaan error
    const expected = (e) =>
      (e.kind === 'response' && e.status === 400 && e.method === 'PUT' && GET_RE.test(e.url)) ||
      (e.kind === 'console' && /Request failed with status code 400|ARCHIVE441|AxiosError|status code 400/.test(e.text || ''));
    const dropped = monitor.errors.filter(expected);
    expect(dropped.filter((e) => e.kind === 'response').length, 'respons 400 tercatat (diharapkan)').toBeGreaterThanOrEqual(1);
    monitor.errors = monitor.errors.filter((e) => !expected(e));
    monitor.note(`error yang diharapkan dikeluarkan dari pemeriksaan: ${dropped.length} entri (${dropped.map((e) => e.kind).join(',')})`);
    fixture('flag', 'P-C1', '1');
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ BR-4 (toggle buka-tutup, daftar UI #7)
  test('[default] ED-1029 BR-4 A: folder induk bisa ditutup dan dibuka lagi; ganti kata kunci / user membuka semua level lagi', async ({ app, page, monitor }) => {
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const open = await names(root);
    expect(open, 'awalnya semua level terbuka').toEqual(expect.arrayContaining([fullName('P-C1'), fullName('P-C2'), fullName('R-R1')]));
    await tableRowLocator(root, fullName('P')).locator('.expand-icon').click();
    await page.waitForTimeout(500);
    const closed = await names(root);
    expect(closed, 'P ditutup: anak P hilang').not.toEqual(expect.arrayContaining([fullName('P-C1')]));
    expect(closed).not.toContain(fullName('P-C2'));
    expect(closed, 'folder lain tidak ikut tertutup').toContain(fullName('R-R1'));
    await app.screenshot('BR4-01-P-ditutup');
    // ubah centang saat P tertutup lalu buka lagi: nilai ada
    await tableRowLocator(root, fullName('P')).locator('.expand-icon').click();
    await page.waitForTimeout(500);
    expect(await names(root), 'dibuka lagi: sama dengan awal').toEqual(open);
    // tutup lagi lalu ganti kata kunci -> semua level terbuka lagi
    await tableRowLocator(root, fullName('P')).locator('.expand-icon').click();
    await page.waitForTimeout(400);
    await searchInput(root).fill('0qa29');
    await page.waitForTimeout(500);
    expect(await names(root), 'kata kunci baru membuka semua level lagi').toContain(fullName('P-C1'));
    await searchInput(root).fill('');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ superadmin (BR-15, daftar UI #21)
  test('[default] ED-1029 BR-15 A sebagai superadmin (role 1 sementara): L ikut tampil, semua folder On bisa dicentang, Off tetap terkunci', async ({ app, page, monitor }) => {
    const fx = ids();
    fixture('resetperm');
    fixture('role', '1');
    try {
      await openPermissionPage(app);
      const root = pageRoot(page);
      const r = await selectAndLoad(app, page, root, fx._userB);
      const rows = await tableRows(root);
      expectTreeMatches(rows, r.body);
      const pn = (n) => rowByName(rows, fullName(n));
      expect(pn('L'), 'superadmin: L (di luar lokasi A) ikut tampil').toBeTruthy();
      expect(r.body.result.data.length, 'jumlah folder root lebih banyak dari A biasa').toBeGreaterThan(0);
      const flat = flatten(r.body.result.data);
      expect(flat.every((f) => f.node.access.manage_permission === true), 'access.manage_permission true di semua folder').toBe(true);
      ['P', 'P-C1', 'Q', 'R-R1', 'L'].forEach((n) => expect(pn(n).boxes.map((b) => !b.disabled), `${fullName(n)} (On): bisa dicentang`).toEqual([true, true, true, true]));
      ['P-C2', 'R'].forEach((n) => expect(pn(n).boxes.map((b) => !b.disabled), `${fullName(n)} (Off): tetap terkunci`).toEqual([false, false, false, false]));
      await app.screenshot('BR15-01-superadmin-pohon');
    } finally {
      fixture('role', 'restore');
    }
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-14 + AC-24 (A, data J)
  test('[default] ED-1029 AC-14 + AC-24 A (data J): J, JA, JB bisa dicentang (JB: A tanpa View), JC (Off) terkunci dengan nilai tersimpan; ubah JB + Save', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    const loaded = await selectAndLoad(app, page, root, fx._userB);
    const rows = await tableRows(root);
    expectTreeMatches(rows, loaded.body);
    const pn = (n) => rowByName(rows, fullName(n));
    ['J', 'J-JA', 'J-JB', 'J-JC'].forEach((n) => expect(pn(n), `${fullName(n)} tampil`).toBeTruthy());
    expect(['J', 'J-JA', 'J-JB', 'J-JC'].map((n) => pn(n).level), 'JA, JB, JC di bawah J').toEqual([0, 1, 1, 1]);
    expect(['J', 'J-JA', 'J-JB', 'J-JC'].map((n) => pn(n).perm)).toEqual(['On', 'On', 'On', 'Off']);
    const enabledOf = (n) => pn(n).boxes.map((b) => !b.disabled);
    ['J', 'J-JA', 'J-JB'].forEach((n) => expect(enabledOf(n), `${fullName(n)} (On): centang aktif`).toEqual([true, true, true, true]));
    expect(enabledOf('J-JC'), `${fullName('J-JC')} (Off): terkunci`).toEqual([false, false, false, false]);
    expect(pn('J-JC').boxes.map((b) => b.checked), 'JC: nilai tersimpan B View+Store tetap terlihat').toEqual([true, false, false, true]);
    // access: J A View saja, JB tanpa baris A (view false) tetapi manage_permission true di keempatnya (K-15 b)
    const node = (n) => flatten(loaded.body.result.data).find((f) => f.node.name === fullName(n)).node;
    const acc = (n) => [node(n).access.view, node(n).access.update, node(n).access.delete, node(n).access.store, node(n).access.manage_permission];
    expect(acc('J'), 'J: A View saja').toEqual([true, false, false, false, true]);
    expect(acc('J-JA'), 'JA: A penuh').toEqual([true, true, true, true, true]);
    expect(acc('J-JB'), 'JB: A tanpa View, tetap bisa dikelola').toEqual([false, false, false, false, true]);
    expect(acc('J-JC')[4], 'JC: manage_permission true').toBe(true);
    await app.screenshot('AC24-01-data-J-pohon');

    // info BR-9 id-ID (A): tanpa "dominan"/"induk On terdekat"
    const info = (await root.locator('.ant-alert-info').innerText()).replace(/\s+/g, ' ');
    expect(info, 'AC-24 id: tanpa dominan / induk On terdekat').not.toMatch(/dominan|induk On terdekat/i);
    expect(info).toContain('tidak membatasi akses');

    // ubah JB (View) + Save -> body hanya JB, 200, toast sukses, riwayat JB oleh A
    await box(root, fullName('J-JB'), 'View').check();
    expect((await tableRows(root)).find((r) => r.name === fullName('J-JB')).boxes.map((b) => b.checked)).toEqual([true, false, false, false]);
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    await saveBtn(root).click();
    const pr = await put;
    expect(pr.status(), 'PUT 200').toBe(200);
    const sent = JSON.parse(calls.find((c) => c.method === 'PUT').body);
    monitor.note(`PUT body: ${JSON.stringify(sent)}`);
    expect(sent.permissions, 'body hanya JB').toHaveLength(1);
    expect((sent.permissions[0].id_archive || sent.permissions[0].idArchive), 'body: JB').toBe(fx['J-JB']);
    expect(flagsOf(sent.permissions[0]), 'JB: View').toEqual([1, 0, 0, 0]);
    await waitToast(page, /.+/);
    expect(await page.locator('.ant-notification-notice .anticon-check-circle, .ant-message-notice .anticon-check-circle').count(), 'toast sukses').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC24-02-sesudah-save-JB');
    const st = dbState();
    expect(st.rows.B['J-JB'], 'DB: (JB, B) = View').toEqual([1, 0, 0, 0]);
    expect(st.rows.B['J-JC'], 'DB: JC tidak tersentuh').toEqual([1, 0, 0, 1]);
    expect(st.history['J-JB'].count, 'riwayat JB +1').toBe(1);
    expect(st.history['J-JB'].last_by, 'pelaku riwayat = A').toBe(fx._userA.username);
    ['J', 'J-JA', 'J-JC'].forEach((n) => expect(st.history[n].count, `riwayat ${n} tidak bertambah`).toBe(0));
    await expect(saveBtn(root), 'sesudah muat ulang Save mati').toBeDisabled();
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-26 (A memilih dirinya sendiri)
  test('[default] ED-1029 AC-26 A: User = diri sendiri, lepas View di JA, Save -> tanpa modal, PUT langsung 200; sel JA tetap bisa dicentang', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    expect(dbState().rows.A['J-JA'], 'awal: A punya JA 1111').toEqual([1, 1, 1, 1]);
    await openPermissionPage(app);
    const root = pageRoot(page);
    const self = await pickUser(page, root, fx._userA);
    expect(self.status, 'GET user-permissions/{A}').toBe(200);
    expect(self.body.result.user.id_user, 'result.user = A').toBe(fx._userA.id_user);
    await app.settle();
    await expect(tableRowLocator(root, `${P}P`)).toBeVisible();
    const jaBefore = (await tableRows(root)).find((r) => r.name === fullName('J-JA'));
    expect(jaBefore.boxes.map((b) => b.checked), 'JA: nilai tersimpan A = V/U/D/S').toEqual([true, true, true, true]);
    expect(jaBefore.boxes.map((b) => !b.disabled), 'JA: bisa dicentang').toEqual([true, true, true, true]);
    await app.screenshot('AC26-01-A-memilih-diri-sendiri');

    // lepas View -> semuanya lepas
    await box(root, fullName('J-JA'), 'View').uncheck();
    expect((await tableRows(root)).find((r) => r.name === fullName('J-JA')).boxes.map((b) => b.checked)).toEqual([false, false, false, false]);
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    await saveBtn(root).click();
    // K-8 c: tidak ada modal konfirmasi - PUT langsung
    const pr = await put;
    expect(pr.status(), 'PUT user-permissions/{A} langsung 200').toBe(200);
    expect(await page.locator('.ant-modal-confirm').count(), 'tanpa modal konfirmasi cabut akses').toBe(0);
    expect(await page.locator('.ant-modal-wrap:not([style*="display: none"])').count(), 'tanpa modal apa pun').toBe(0);
    const putCall = calls.find((c) => c.method === 'PUT');
    expect(putCall.url, 'PUT ke A sendiri').toContain(`/user-permissions/${fx._userA.id_user}`);
    const sent = JSON.parse(putCall.body);
    monitor.note(`PUT body: ${JSON.stringify(sent)}`);
    expect(sent.permissions, 'body hanya JA').toHaveLength(1);
    expect(sent.permissions[0].id_archive || sent.permissions[0].idArchive).toBe(fx['J-JA']);
    expect(flagsOf(sent.permissions[0]), 'JA: 0/0/0/0').toEqual([0, 0, 0, 0]);
    await waitToast(page, /.+/);
    expect(await page.locator('.ant-notification-notice .anticon-check-circle, .ant-message-notice .anticon-check-circle').count(), 'toast sukses').toBeGreaterThan(0);

    // sesudah dimuat ulang: JA kosong dan tetap bisa dicentang (manage_permission mengikuti role, bukan View)
    await expect.poll(() => calls.filter((c) => c.method === 'GET').length, { timeout: 20000, message: 'GET dimuat ulang' }).toBeGreaterThanOrEqual(2);
    await app.settle();
    const jaAfter = (await tableRows(root)).find((r) => r.name === fullName('J-JA'));
    expect(jaAfter.boxes.map((b) => b.checked), 'sesudah muat ulang JA kosong').toEqual([false, false, false, false]);
    expect(jaAfter.boxes.map((b) => !b.disabled), 'sesudah muat ulang sel JA tetap bisa dicentang').toEqual([true, true, true, true]);
    await box(root, fullName('J-JA'), 'View').check();
    expect((await tableRows(root)).find((r) => r.name === fullName('J-JA')).boxes.map((b) => b.checked), 'bisa dicentang lagi').toEqual([true, false, false, false]);
    await app.screenshot('AC26-02-sesudah-save-JA-tetap-aktif');
    const st = dbState();
    expect(st.rows.A['J-JA'], 'DB: baris (JA, A) terhapus').toBeUndefined();
    expect(st.history['J-JA'].count, 'riwayat JA +1').toBe(1);
    expect(st.history['J-JA'].last_by, 'pelaku riwayat = A sendiri').toBe(fx._userA.username);
    expect(st.rows.A.J, 'A tidak kehilangan baris lain (J)').toEqual([1, 0, 0, 0]);
    expect(st.rows.B, 'hak B tidak tersentuh').toEqual(B0);
    // kembalikan: centang V/U/D/S JA lagi lewat halaman yang sama, Save (juga tanpa modal) -> DB = nilai awal
    await box(root, fullName('J-JA'), 'Store').check();
    await box(root, fullName('J-JA'), 'Delete').check();
    await box(root, fullName('J-JA'), 'Update').check();
    const put2 = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    await saveBtn(root).click();
    expect((await put2).status(), 'PUT pemulihan 200').toBe(200);
    expect(await page.locator('.ant-modal-confirm').count(), 'pemulihan: tanpa modal').toBe(0);
    await app.settle();
    expect(dbState().rows.A['J-JA'], 'DB: JA kembali 1111').toEqual([1, 1, 1, 1]);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-25 ([BE+FE]) hak otomatis tab Izin -> halaman ini
  test('[default] ED-1029 AC-25 A: Izin dinyalakan di tab Izin folder F (Off, tanpa baris A) -> halaman ini: A di F = V/U/D/S, bisa diubah; lepas Delete + Save -> tab Izin F memuat A View/Update/Store', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    const A = fx._userA.username;
    fixture('resetperm');
    const st0 = dbState();
    expect(st0.flags.F, 'awal: F Off').toBe(0);
    expect(st0.rows.A.F, 'awal: A tanpa baris di F').toBeUndefined();

    const drawerOf = () => page.locator('.ant-drawer-open').last();
    const openDrawerF = async () => {
      await app.open('/archives', { waitFor: '.archive-page .ant-table' });
      const show = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives\/[^/?]+(\?|$)/.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
      await archiveRow(page, fullName('F')).locator('button.btn-action').click();
      const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
      await menu.waitFor({ state: 'visible', timeout: 10000 });
      await menu.locator('li.ant-dropdown-menu-item', { hasText: /^(Lihat|View)$/ }).first().click();
      await show;
      await drawerOf().waitFor({ state: 'visible', timeout: 15000 });
      await drawerOf().locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      const tabs = await drawerOf().locator('.ant-tabs-tab').allInnerTexts();
      const idx = tabs.map((t) => t.trim()).findIndex((t) => /^(Permission|Izin)$/.test(t));
      expect(idx, `tab Izin ada (tab: ${tabs.join(' | ')})`).toBeGreaterThanOrEqual(0);
      await drawerOf().locator('.ant-tabs-tab').nth(idx).click();
      await drawerOf().locator('.ant-switch').first().waitFor({ state: 'visible', timeout: 10000 });
    };
    const aRow = () => drawerOf().locator('.ant-tabs-tabpane-active .ant-table-row').filter({ hasText: new RegExp(`(^|\\s)${esc(A)}(\\s|$)`) }).first();
    const aBoxes = async () => (await aRow().locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.checked))).slice(1); // kolom pertama = All

    // (1) tab Izin F: nyalakan -> baris A otomatis (ED-1025 BR-19, hanya di tab Izin) -> Save
    await openDrawerF();
    await expect(drawerOf().locator('.ant-tabs-tabpane-active .ant-table-row'), 'F: tabel Izin kosong sebelum toggle').toHaveCount(0);
    await drawerOf().locator('.ant-switch').first().click();
    await expect(aRow(), 'baris A muncul otomatis sebelum Save').toBeVisible();
    expect(await aBoxes(), 'baris otomatis A = V/U/D/S').toEqual([true, true, true, true]);
    await app.screenshot('AC25-01-tab-Izin-F-baris-otomatis');
    const putF = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/document-archive\/archives\/[^/?]+(\?|$)/.test(r.url()), { timeout: 30000 });
    await drawerOf().locator('.ant-drawer-extra button.ant-btn-primary').click();
    expect((await putF).status(), 'PUT archives/{F} 200').toBe(200);
    await expect(page.locator('.ant-drawer-open'), 'drawer menutup').toHaveCount(0, { timeout: 15000 });
    const st1 = dbState();
    expect(st1.flags.F, 'DB: F On').toBe(1);
    expect(st1.rows.A.F, 'DB: (F, A) = 1/1/1/1 (baris otomatis disimpan sebagai baris biasa)').toEqual([1, 1, 1, 1]);

    // (2) halaman by User, pilih A: F On, V/U/D/S tercentang dan bisa diubah
    await openPermissionPage(app);
    const root = pageRoot(page);
    const self = await pickUser(page, root, fx._userA);
    expect(self.status, 'GET user-permissions/{A}').toBe(200);
    await app.settle();
    await expect(tableRowLocator(root, fullName('F'))).toBeVisible();
    const nodeF = flatten(self.body.result.data).find((f) => f.node.name === fullName('F')).node;
    expect([nodeF.is_folder_permission, nodeF.is_view, nodeF.is_update, nodeF.is_delete, nodeF.is_store], 'GET user-permissions/{A}: F = On 1/1/1/1').toEqual([1, 1, 1, 1, 1]);
    const rowF = (await tableRows(root)).find((r) => r.name === fullName('F'));
    expect(rowF.perm, 'F: tag On').toBe('On');
    expect(rowF.boxes.map((b) => b.checked), 'F: V/U/D/S tercentang').toEqual([true, true, true, true]);
    expect(rowF.boxes.map((b) => !b.disabled), 'F: bisa diubah').toEqual([true, true, true, true]);
    await app.screenshot('AC25-02-halaman-A-di-F');

    // (3) lepas Delete F + Save
    await box(root, fullName('F'), 'Delete').uncheck();
    expect((await tableRows(root)).find((r) => r.name === fullName('F')).boxes.map((b) => b.checked)).toEqual([true, true, false, true]);
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    await saveBtn(root).click();
    expect((await put).status(), 'PUT user-permissions/{A} 200').toBe(200);
    const sent = JSON.parse(calls.find((c) => c.method === 'PUT').body);
    expect(sent.permissions, 'body hanya F').toHaveLength(1);
    expect(sent.permissions[0].id_archive || sent.permissions[0].idArchive).toBe(fx.F);
    expect(flagsOf(sent.permissions[0]), 'F: V/U/S').toEqual([1, 1, 0, 1]);
    await app.settle();
    expect(dbState().rows.A.F, 'DB: (F, A) = 1/1/0/1').toEqual([1, 1, 0, 1]);

    // (4) tab Izin F memuat A View/Update/Store (tanpa Delete)
    await openDrawerF();
    await expect(aRow(), 'tab Izin F memuat baris A').toBeVisible();
    expect(await aBoxes(), 'tab Izin F: A View/Update/Store, tanpa Delete').toEqual([true, true, false, true]);
    await app.screenshot('AC25-03-tab-Izin-F-sesudah-lepas-Delete');
    await page.keyboard.press('Escape');
    await page.locator('.ant-drawer-open .ant-drawer-close').last().click().catch(() => null);
    await page.waitForTimeout(500);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ================================================================================================ REVISI 3 (ED-1130): kolom Semua + saklar Izin Folder
  const idOf = (o) => (o.id_archive !== undefined ? o.id_archive : o.idArchive);
  const flagOfFolder = (o) => (o.is_folder_permission !== undefined ? o.is_folder_permission : o.isFolderPermission);
  const sortedKeys = (o) => Object.keys(o).sort();
  const checkedOf = (r) => r.boxes.map((b) => b.checked);

  /** Klik Save, tunggu PUT, kembalikan status, isi respons dan body yang dikirim (tanpa modal konfirmasi). */
  async function clickSave(page, root, calls) {
    await clearToasts(page);
    const n = calls.filter((c) => c.method === 'PUT').length;
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
    await saveBtn(root).click();
    const resp = await put;
    const call = calls.filter((c) => c.method === 'PUT')[n];
    expect(await page.locator('.ant-modal-confirm').count(), 'Save tanpa modal konfirmasi (K-8 c)').toBe(0);
    return { status: resp.status(), json: await resp.json().catch(() => null), sent: JSON.parse(call.body), raw: call.body };
  }

  /** Sesudah Save sukses: toast sukses, GET dimuat ulang, Save/Reset mati lagi (peta perubahan dibuang). */
  async function expectSavedAndReloaded(app, page, root, calls, nGetBefore) {
    await waitToast(page, /.+/);
    expect(await page.locator('.ant-notification-notice .anticon-check-circle, .ant-message-notice .anticon-check-circle').count(), 'toast sukses (ikon centang)').toBeGreaterThan(0);
    await expect.poll(() => calls.filter((c) => c.method === 'GET').length, { timeout: 20000, message: 'GET dimuat ulang' }).toBeGreaterThanOrEqual(nGetBefore + 1);
    await app.settle();
    await expect(saveBtn(root), 'sesudah muat ulang Save mati').toBeDisabled();
    await expect(resetBtn(root), 'sesudah muat ulang Reset mati').toBeDisabled();
  }

  const drawerOf = (page) => page.locator('.ant-drawer-open').last();

  /** Buka drawer folder (daftar Archive -> menu baris Lihat) di tab Izin; mengembalikan locator drawer. */
  async function openIzinDrawer(app, page, name) {
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    const show = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives\/[^/?]+(\?|$)/.test(r.url()) && !/history|rename|delete|create/.test(r.url()), { timeout: 30000 });
    await archiveRow(page, name).locator('button.btn-action').click();
    const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    await menu.waitFor({ state: 'visible', timeout: 10000 });
    await menu.locator('li.ant-dropdown-menu-item', { hasText: /^(Lihat|View)$/ }).first().click();
    await show;
    const drawer = drawerOf(page);
    await drawer.waitFor({ state: 'visible', timeout: 15000 });
    await drawer.locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
    const tabs = await drawer.locator('.ant-tabs-tab').allInnerTexts();
    const idx = tabs.map((t) => t.trim()).findIndex((t) => /^(Permission|Izin)$/.test(t));
    expect(idx, `tab Izin ada (tab: ${tabs.join(' | ')})`).toBeGreaterThanOrEqual(0);
    await drawer.locator('.ant-tabs-tab').nth(idx).click();
    await drawer.locator('.ant-tabs-tabpane-active .ant-switch').first().waitFor({ state: 'visible', timeout: 10000 });
    return drawer;
  }
  const izinSwitch = (drawer) => drawer.locator('.ant-tabs-tabpane-active .ant-switch').first();
  const izinRow = (drawer, username) => drawer.locator('.ant-tabs-tabpane-active .ant-table-row').filter({ hasText: new RegExp(`(^|\\s)${esc(username)}(\\s|$)`) }).first();
  const izinBoxes = async (drawer, username) => (await izinRow(drawer, username).locator('input[type="checkbox"]').evaluateAll((els) => els.map((e) => e.checked))).slice(1); // kolom pertama = All
  async function closeDrawer(page) {
    await page.keyboard.press('Escape');
    await page.locator('.ant-drawer-open .ant-drawer-close').last().click().catch(() => null);
    await page.waitForTimeout(600);
  }

  // ------------------------------------------------------------------------------------------------ AC-29 kolom Semua (A)
  test('[default] ED-1029 AC-29 A (rev3): kolom Semua - centang/lepas, indeterminate, terkunci di folder Off; Save Semua di C1 = body hanya C1 1/1/1/1, DB; lepas Semua = baris terhapus', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    const before = dbState();
    expect(before.rows.B, 'awal: hak B').toEqual(B0);
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const PN = fullName('P');
    const C1 = fullName('P-C1');

    // keadaan awal: Semua tepat sesudah Permission; P (B View) indeterminate; C1 kosong; C2 (Off) indeterminate terkunci
    expect((await root.locator('.ant-table-thead').first().locator('th').allInnerTexts()).map((t) => t.trim()).slice(2, 4), 'Semua tepat sesudah Permission').toEqual(['Semua', 'Lihat']);
    expect((await rowOf(root, PN)).all, 'P: B hanya View -> indeterminate').toEqual({ checked: false, indeterminate: true, disabled: false });
    expect((await rowOf(root, C1)).all, 'C1 kosong').toEqual({ checked: false, indeterminate: false, disabled: false });
    expect((await rowOf(root, fullName('P-C2'))).all, 'C2 (Off): indeterminate dan terkunci').toEqual({ checked: false, indeterminate: true, disabled: true });
    expect((await rowOf(root, fullName('R'))).all.disabled, 'R (Off): Semua terkunci').toBe(true);
    await expect(saveBtn(root)).toBeDisabled();

    // P: centang Semua -> V/U/D/S tercentang, Save aktif
    await allBox(root, PN).check();
    let r = await rowOf(root, PN);
    expect(checkedOf(r), 'P: Semua dicentang -> keempat hak').toEqual([true, true, true, true]);
    expect(r.all, 'P: Semua tercentang penuh').toEqual({ checked: true, indeterminate: false, disabled: false });
    await expect(saveBtn(root), 'Save aktif').toBeEnabled();
    await expect(resetBtn(root), 'Reset aktif').toBeEnabled();
    await app.screenshot('AC29-01-P-semua-dicentang');
    // lepas Semua -> keempatnya lepas (berbeda dari tersimpan View -> Save tetap aktif)
    await allBox(root, PN).uncheck();
    r = await rowOf(root, PN);
    expect(checkedOf(r), 'P: Semua dilepas -> keempatnya lepas').toEqual([false, false, false, false]);
    expect(r.all, 'P: Semua kosong').toEqual({ checked: false, indeterminate: false, disabled: false });
    await expect(saveBtn(root), 'P kosong beda dari tersimpan (View): Save tetap aktif').toBeEnabled();
    await box(root, PN, 'View').check();
    expect(checkedOf(await rowOf(root, PN)), 'P kembali ke tersimpan').toEqual([true, false, false, false]);
    await expect(saveBtn(root), 'sama dengan tersimpan: Save mati').toBeDisabled();
    await expect(resetBtn(root), 'sama dengan tersimpan: Reset mati').toBeDisabled();

    // C1 (tersimpan kosong): centang lalu lepas Semua = sama dengan tersimpan -> Save mati
    await allBox(root, C1).check();
    await expect(saveBtn(root)).toBeEnabled();
    await allBox(root, C1).uncheck();
    expect(checkedOf(await rowOf(root, C1)), 'C1 kosong lagi').toEqual([false, false, false, false]);
    await expect(saveBtn(root), 'C1 sama dengan tersimpan: Save mati').toBeDisabled();
    // centang Update saja -> View ikut -> Semua indeterminate
    await box(root, C1, 'Update').check();
    r = await rowOf(root, C1);
    expect(checkedOf(r), 'Update saja (View ikut)').toEqual([true, true, false, false]);
    expect(r.all, 'sebagian: Semua indeterminate').toEqual({ checked: false, indeterminate: true, disabled: false });
    await app.screenshot('AC29-02-C1-indeterminate');
    // lengkapi keempatnya lewat sel -> Semua tercentang penuh
    await box(root, C1, 'Delete').check();
    await box(root, C1, 'Store').check();
    expect((await rowOf(root, C1)).all, 'keempat hak -> Semua tercentang').toEqual({ checked: true, indeterminate: false, disabled: false });
    await resetBtn(root).click();
    await page.waitForTimeout(400);

    // C2 dan R (Off): Semua terkunci, klik paksa tidak mengubah
    for (const n of ['P-C2', 'R']) {
      const b0 = await rowOf(root, fullName(n));
      await expect(allBox(root, fullName(n)), `${n}: Semua disabled`).toBeDisabled();
      await allBox(root, fullName(n)).click({ force: true, timeout: 5000 }).catch(() => null);
      const b1 = await rowOf(root, fullName(n));
      expect({ boxes: checkedOf(b1), all: b1.all }, `${n}: Semua tidak mengubah apa pun`).toEqual({ boxes: checkedOf(b0), all: b0.all });
    }
    await expect(saveBtn(root), 'tanpa perubahan').toBeDisabled();
    expect(calls.map((c) => c.method).join(','), 'sampai di sini hanya GET user B').toBe('GET');

    // Semua di C1 + Save -> body hanya C1 1/1/1/1
    await allBox(root, C1).check();
    const nGet = calls.filter((c) => c.method === 'GET').length;
    const s1 = await clickSave(page, root, calls);
    monitor.note(`PUT Semua C1: ${s1.raw}`);
    expect(s1.status, 'PUT 200').toBe(200);
    expect(s1.json.msg_code, 'ARCHIVE207').toBe('ARCHIVE207');
    expect(sortedKeys(s1.sent), 'body hanya permissions (tanpa folders)').toEqual(['permissions']);
    expect(s1.sent.permissions, 'hanya C1').toHaveLength(1);
    expect(idOf(s1.sent.permissions[0])).toBe(fx['P-C1']);
    expect(flagsOf(s1.sent.permissions[0]), 'C1: 1/1/1/1').toEqual([1, 1, 1, 1]);
    flagsOf(s1.sent.permissions[0]).forEach((v) => expect(typeof v, 'flag integer').toBe('number'));
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    expect((await rowOf(root, C1)).all, 'sesudah muat ulang: C1 Semua tercentang penuh').toEqual({ checked: true, indeterminate: false, disabled: false });
    let st = dbState();
    expect(st.rows.B, 'DB: (C1,B) = 1/1/1/1; lainnya tidak tersentuh').toEqual({ ...B0, 'P-C1': [1, 1, 1, 1] });
    expect(st.history['P-C1'].count, 'riwayat C1 +1').toBe(1);
    expect(st.history['P-C1'].last_by, 'pelaku = A').toBe(fx._userA.username);
    expect(st.history.P.count, 'P tidak tersentuh').toBe(0);
    expect(st.total_permissions - before.total_permissions, 'baris baru: C1 saja').toBe(1);
    await app.screenshot('AC29-03-sesudah-save-C1');

    // lepas Semua di C1 + Save -> baris (C1,B) terhapus
    await allBox(root, C1).uncheck();
    expect(checkedOf(await rowOf(root, C1))).toEqual([false, false, false, false]);
    const nGet2 = calls.filter((c) => c.method === 'GET').length;
    const s2 = await clickSave(page, root, calls);
    monitor.note(`PUT lepas Semua C1: ${s2.raw}`);
    expect(s2.status, 'PUT 200').toBe(200);
    expect(sortedKeys(s2.sent), 'body hanya permissions').toEqual(['permissions']);
    expect(s2.sent.permissions).toHaveLength(1);
    expect(flagsOf(s2.sent.permissions[0]), 'C1: 0/0/0/0').toEqual([0, 0, 0, 0]);
    await expectSavedAndReloaded(app, page, root, calls, nGet2);
    st = dbState();
    expect(st.rows.B['P-C1'], 'DB: baris (C1,B) terhapus').toBeUndefined();
    expect(st.rows.B, 'DB: B kembali nilai awal').toEqual(B0);
    expect(st.history['P-C1'].count, 'riwayat C1 +1 lagi').toBe(2);
    expect(st.total_permissions, 'jumlah baris kembali').toBe(before.total_permissions);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-30 saklar (A, target B)
  test('[default] ED-1029 AC-30 A (rev3, K-10 b): saklar aktif di semua folder; F Off->On (sel aktif, tanpa centang otomatis) lalu centang View dan Off lagi = kembali tersimpan; P centang Update lalu Off = terkunci tetap tampil, Save body folders + permissions', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    const before = dbState();
    expect(before.rows.B, 'awal: hak B').toEqual(B0);
    await openPermissionPage(app);
    const root = pageRoot(page);
    const loaded = await selectAndLoad(app, page, root, fx._userB);
    const PN = fullName('P');
    const F = fullName('F');

    // saklar aktif di semua folder yang tampil (termasuk JB tanpa View dan JC Off); L tidak ada
    const rows0 = await tableRows(root);
    expectTreeMatches(rows0, loaded.body);
    expect(rows0.every((r) => r.flag && r.flag.disabled === false), 'saklar aktif di semua folder').toBe(true);
    ['J', 'J-JA', 'J-JB', 'J-JC'].forEach((n) => expect(rowByName(rows0, fullName(n)), `${n} tampil`).toBeTruthy());
    expect(rowByName(rows0, fullName('J-JB')).flag.checked, 'JB On').toBe(true);
    expect(rowByName(rows0, fullName('J-JC')).flag.checked, 'JC Off').toBe(false);
    expect(rowByName(rows0, fullName('L')), 'L di luar lokasi A tidak tampil').toBeUndefined();
    await app.screenshot('AC30-01-saklar-aktif-semua-folder');

    // F (Off, tanpa baris B): Off -> On = sel aktif dengan nilai tersimpan (kosong, B tidak dicentang otomatis); Save & Reset aktif
    let r = await rowOf(root, F);
    expect([r.flag.checked, r.all.disabled, r.boxes.map((b) => b.disabled)], 'F awal: Off, sel terkunci').toEqual([false, true, [true, true, true, true]]);
    await flagSwitch(root, F).click();
    r = await rowOf(root, F);
    expect(r.flag.checked, 'F: saklar On').toBe(true);
    expect(r.perm, 'F: teks On').toBe('On');
    expect(r.boxes.map((b) => !b.disabled), 'F On: sel aktif').toEqual([true, true, true, true]);
    expect(r.all.disabled, 'F On: Semua aktif').toBe(false);
    expect(checkedOf(r), 'F On: nilai tersimpan B (kosong), tidak dicentang otomatis').toEqual([false, false, false, false]);
    expect(r.all, 'F On: Semua kosong').toEqual({ checked: false, indeterminate: false, disabled: false });
    await expect(saveBtn(root), 'Save aktif').toBeEnabled();
    await expect(resetBtn(root), 'Reset aktif').toBeEnabled();
    expect(calls.map((c) => c.method).join(','), 'saklar tidak memanggil BE').toBe('GET');
    await app.screenshot('AC30-02-F-dinyalakan');
    // centang View F lalu F Off lagi -> sel F kembali kosong dan terkunci; Save mati
    await box(root, F, 'View').check();
    expect(checkedOf(await rowOf(root, F)), 'View F tercentang').toEqual([true, false, false, false]);
    await flagSwitch(root, F).click();
    r = await rowOf(root, F);
    expect(r.flag.checked, 'F Off lagi').toBe(false);
    expect(checkedOf(r), 'F: kembali ke tersimpan (kosong), perubahan dibuang').toEqual([false, false, false, false]);
    expect(r.boxes.map((b) => b.disabled), 'F: terkunci').toEqual([true, true, true, true]);
    await expect(saveBtn(root), 'F Off -> On -> Off = tersimpan: Save mati').toBeDisabled();
    await expect(resetBtn(root), 'Reset mati').toBeDisabled();

    // P (tersimpan On): Off -> On tanpa perubahan lain = tersimpan
    await flagSwitch(root, PN).click();
    await expect(saveBtn(root), 'P Off: Save aktif').toBeEnabled();
    expect(checkedOf(await rowOf(root, PN)), 'P Off: nilai tersimpan tetap (View)').toEqual([true, false, false, false]);
    await flagSwitch(root, PN).click();
    await expect(saveBtn(root), 'P On lagi = tersimpan: Save mati').toBeDisabled();

    // K-10 b: centang Update di P, lalu P Off -> sel terkunci tetap menampilkan View+Update; Save aktif
    await box(root, PN, 'Update').check();
    await flagSwitch(root, PN).click();
    r = await rowOf(root, PN);
    expect(r.flag.checked, 'P: saklar Off').toBe(false);
    expect(checkedOf(r), 'K-10 b: sel terkunci tetap menampilkan View+Update').toEqual([true, true, false, false]);
    expect(r.boxes.map((b) => b.disabled), 'P Off: sel terkunci').toEqual([true, true, true, true]);
    expect(r.all, 'P Off: Semua terkunci, indeterminate').toEqual({ checked: false, indeterminate: true, disabled: true });
    await expect(saveBtn(root), 'Save aktif').toBeEnabled();
    await app.screenshot('AC30-03-P-Off-sel-terkunci-tetap-tampil');
    const nGet = calls.filter((c) => c.method === 'GET').length;
    const s1 = await clickSave(page, root, calls);
    monitor.note(`PUT K-10 b: ${s1.raw}`);
    expect(s1.status, 'PUT 200').toBe(200);
    expect(s1.json.msg_code, 'ARCHIVE207').toBe('ARCHIVE207');
    expect(sortedKeys(s1.sent), 'body: permissions + folders').toEqual(['folders', 'permissions']);
    expect(s1.sent.permissions, 'permissions: hanya P').toHaveLength(1);
    expect(idOf(s1.sent.permissions[0])).toBe(fx.P);
    expect(flagsOf(s1.sent.permissions[0]), 'permissions P: View+Update').toEqual([1, 1, 0, 0]);
    expect(s1.sent.folders, 'folders: hanya P').toHaveLength(1);
    expect([idOf(s1.sent.folders[0]), flagOfFolder(s1.sent.folders[0])], 'folders [P: 0]').toEqual([fx.P, 0]);
    expect(typeof flagOfFolder(s1.sent.folders[0]), 'flag integer').toBe('number');
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    r = await rowOf(root, PN);
    expect([r.flag.checked, checkedOf(r), r.boxes.map((b) => b.disabled)], 'sesudah muat ulang: P Off, sel terkunci View+Update').toEqual([false, [true, true, false, false], [true, true, true, true]]);
    let st = dbState();
    expect(st.flags.P, 'DB: P flag 0').toBe(0);
    expect(st.rows.B.P, 'DB: (P,B) View+Update (hak ikut tersimpan walau dimatikan)').toEqual([1, 1, 0, 0]);
    expect(st.rows.A.P, 'DB: baris A di P tidak tersentuh, tanpa baris otomatis').toEqual([1, 1, 1, 1]);
    expect(st.history.P.count, 'riwayat P +1 (satu entri)').toBe(1);
    expect(st.history.P.last_by, 'pelaku = A').toBe(fx._userA.username);
    expect(st.updated_by.P, 'updated_by folder = user login').toBe(fx._userA.username);
    expect(st.total_permissions, 'tanpa baris baru').toBe(before.total_permissions);
    expect(st.dups, 'tanpa baris ganda').toEqual([]);
    await app.screenshot('AC30-04-sesudah-save-P-Off');

    // pulihkan lewat halaman yang sama: P On, hanya saklar -> body {permissions: [], folders: [P:1]}
    await flagSwitch(root, PN).click();
    r = await rowOf(root, PN);
    expect([r.flag.checked, checkedOf(r), r.boxes.map((b) => b.disabled)], 'P On lagi: nilai tersimpan, sel aktif').toEqual([true, [true, true, false, false], [false, false, false, false]]);
    const nGet2 = calls.filter((c) => c.method === 'GET').length;
    const s2 = await clickSave(page, root, calls);
    monitor.note(`PUT pulihkan P On: ${s2.raw}`);
    expect(s2.status).toBe(200);
    expect(sortedKeys(s2.sent), 'body: permissions + folders').toEqual(['folders', 'permissions']);
    expect(s2.sent.permissions, 'hanya saklar: permissions tetap ada, kosong').toEqual([]);
    expect(s2.sent.folders.map((f) => [idOf(f), flagOfFolder(f)]), 'folders [P: 1]').toEqual([[fx.P, 1]]);
    await expectSavedAndReloaded(app, page, root, calls, nGet2);
    st = dbState();
    expect(st.flags.P, 'DB: P On lagi').toBe(1);
    expect(st.rows.B.P, 'DB: (P,B) tetap View+Update').toEqual([1, 1, 0, 0]);
    expect(st.rows.A.P, 'DB: (P,A) tetap 1/1/1/1 (hak otomatis menaikkan baris yang ada, tanpa baris kedua)').toEqual([1, 1, 1, 1]);
    expect(st.dups, 'tanpa baris ganda').toEqual([]);
    expect(st.history.P.count, 'riwayat P +1 lagi').toBe(2);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ BR-14 (saklar saja): Reset, ganti User
  test('[default] ED-1029 AC-30 A (rev3, BR-14): hanya saklar yang berubah mengaktifkan Save/Reset; Reset mengembalikan saklar tanpa request; ganti User = konfirmasi (Tidak = tetap, Ya = pindah)', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const R = fullName('R');
    await expect(saveBtn(root)).toBeDisabled();

    await flagSwitch(root, R).click();
    expect((await rowOf(root, R)).flag.checked, 'R On').toBe(true);
    await expect(saveBtn(root), 'saklar saja: Save aktif').toBeEnabled();
    await expect(resetBtn(root), 'saklar saja: Reset aktif').toBeEnabled();
    const n0 = calls.length;
    await resetBtn(root).click();
    await page.waitForTimeout(400);
    expect((await rowOf(root, R)).flag.checked, 'Reset: R kembali Off').toBe(false);
    expect((await rowOf(root, R)).boxes.map((b) => b.disabled), 'R terkunci lagi').toEqual([true, true, true, true]);
    await expect(saveBtn(root)).toBeDisabled();
    await expect(resetBtn(root)).toBeDisabled();
    expect(calls.length, 'Reset tanpa request').toBe(n0);
    expect(await page.locator('.ant-modal-confirm').count(), 'Reset tanpa konfirmasi').toBe(0);

    // saklar saja lalu ganti User -> konfirmasi
    await flagSwitch(root, R).click();
    await pickUser(page, root, fx._userB2, { expectGet: false });
    const confirm = page.locator('.ant-modal-confirm').last();
    await confirm.waitFor({ state: 'visible', timeout: 10000 });
    const confirmText = (await confirm.innerText()).replace(/\s+/g, ' ');
    monitor.note(`konfirmasi (hanya saklar berubah): ${confirmText}`);
    expect(confirmText, 'konfirmasi memuat nama user lama').toContain(fx._userB.username);
    expect(confirmText).toMatch(/belum disimpan akan hilang/);
    await app.screenshot('AC30-05-konfirmasi-ganti-user-saklar');
    await confirm.locator('button', { hasText: /^Tidak$/ }).click();
    await page.waitForTimeout(600);
    expect((await rowOf(root, R)).flag.checked, 'Tidak: R tetap On di B').toBe(true);
    await expect(saveBtn(root), 'Save masih aktif').toBeEnabled();
    expect(calls.filter((c) => c.url.includes(fx._userB2.id_user)), 'Tidak: tanpa request B2').toHaveLength(0);
    await expect(root.locator('.ant-select-selection-item').first(), 'field User tetap B').toHaveText(new RegExp(`^${esc(fx._userB.username)}$`));
    // Ya -> pindah, saklar dibuang
    const got = page.waitForResponse((r) => r.request().method() === 'GET' && GET_RE.test(r.url()) && r.url().includes(fx._userB2.id_user), { timeout: 45000 });
    await pickUser(page, root, fx._userB2, { expectGet: false });
    await page.locator('.ant-modal-confirm').last().waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('.ant-modal-confirm').last().locator('button', { hasText: /^Ya$/ }).click();
    expect((await got).status(), 'Ya: GET B2').toBe(200);
    await app.settle();
    expect((await rowOf(root, R)).flag.checked, 'Ya: saklar R kembali tersimpan (Off) di B2').toBe(false);
    await expect(saveBtn(root), 'Save mati sesudah pindah').toBeDisabled();
    expect(calls.filter((c) => c.method !== 'GET'), 'tanpa PUT').toHaveLength(0);
    expect(dbState().rows.B, 'DB utuh').toEqual(B0);
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-30/32/36 (A -> B): hanya saklar, hak otomatis BE (K-9 a), saklar mati selama Save, tab Izin
  test('[default] ED-1029 AC-30/32/36 A (rev3): hanya saklar F On -> Save body {permissions: [], folders}; BE memberi A hak otomatis (satu baris); saklar mati selama Save; tab Izin + perisai; F Off lewat halaman -> tab Izin Off, baris tetap; nyalakan di tab Izin -> halaman On', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    const A = fx._userA.username;
    fixture('resetperm');
    const before = dbState();
    expect(before.flags.F, 'awal: F Off').toBe(0);
    expect(before.rows.A.F, 'awal: A tanpa baris di F').toBeUndefined();
    expect(before.rows.B.F, 'awal: B tanpa baris di F').toBeUndefined();
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const F = fullName('F');

    await flagSwitch(root, F).click();
    expect(checkedOf(await rowOf(root, F)), 'B tidak dicentang otomatis (target bukan user login)').toEqual([false, false, false, false]);

    // tahan PUT supaya keadaan "sedang menyimpan" bisa diamati: saklar mati selama menyimpan
    await page.route(/\/document-archive\/user-permissions\//, async (route) => {
      if (route.request().method() === 'PUT') await new Promise((resolve) => setTimeout(resolve, 2500));
      await route.continue();
    });
    const nGet = calls.filter((c) => c.method === 'GET').length;
    const put = page.waitForResponse((resp) => resp.request().method() === 'PUT' && GET_RE.test(resp.url()), { timeout: 45000 });
    await saveBtn(root).click();
    await expect.poll(async () => (await tableRows(root)).filter((x) => x.flag).every((x) => x.flag.disabled), { timeout: 2000, message: 'selama PUT berjalan semua saklar mati' }).toBe(true);
    await app.screenshot('AC30-06-saklar-mati-selama-save');
    const resp = await put;
    expect(await page.locator('.ant-modal-confirm').count(), 'Save tanpa modal').toBe(0);
    await page.unroute(/\/document-archive\/user-permissions\//);
    expect(resp.status(), 'PUT 200').toBe(200);
    const sent = JSON.parse(calls.find((c) => c.method === 'PUT').body);
    monitor.note(`PUT hanya saklar: ${JSON.stringify(sent)}`);
    expect(sortedKeys(sent), 'body: permissions + folders').toEqual(['folders', 'permissions']);
    expect(sent.permissions, 'permissions selalu ada, kosong bila hanya saklar').toEqual([]);
    expect(sent.folders.map((f) => [idOf(f), flagOfFolder(f)]), 'folders [F: 1]').toEqual([[fx.F, 1]]);
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    const rF = await rowOf(root, F);
    expect([rF.flag.checked, checkedOf(rF), rF.boxes.map((b) => b.disabled), rF.flag.disabled], 'sesudah muat ulang: F On, sel B kosong dan aktif, saklar aktif lagi').toEqual([true, [false, false, false, false], [false, false, false, false], false]);

    // DB: flag, updated_by, hak otomatis BE untuk A (K-9 a), tanpa baris untuk B, satu baris
    let st = dbState();
    expect(st.flags.F, 'DB: F flag 1').toBe(1);
    expect(st.updated_by.F, 'DB: updated_by F = A').toBe(A);
    expect(st.rows.A.F, 'DB: (F,A) = 1/1/1/1 (hak otomatis BE)').toEqual([1, 1, 1, 1]);
    expect(st.rows.B.F, 'DB: tanpa baris (F,B)').toBeUndefined();
    expect(st.dups, 'tanpa baris ganda').toEqual([]);
    expect(st.total_permissions - before.total_permissions, 'baris baru: (F,A) saja').toBe(1);
    expect(st.history.F.count, 'riwayat F +1').toBe(1);
    expect(st.history.F.last_by, 'pelaku = A').toBe(A);
    await app.screenshot('AC30-07-sesudah-save-F-On');

    // tab Izin F: saklar On, baris A V/U/D/S (hak otomatis tidak tampil di halaman ini, terlihat di tab Izin); list: F berperisai
    let drawer = await openIzinDrawer(app, page, F);
    expect(await izinSwitch(drawer).getAttribute('aria-checked'), 'tab Izin F: saklar On').toBe('true');
    await expect(izinRow(drawer, A), 'tab Izin F: baris A').toBeVisible();
    expect(await izinBoxes(drawer, A), 'tab Izin F: A V/U/D/S').toEqual([true, true, true, true]);
    await app.screenshot('AC36-01-tab-Izin-F-On');
    await closeDrawer(page);
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    expect(await archiveRow(page, F).locator('.anticon-safety-certificate').count(), 'list: F berperisai Akses Terbatas').toBe(1);

    // AC-36: F dimatikan dari halaman ini -> tab Izin F Off, baris A tetap
    await openPermissionPage(app);
    const root2 = pageRoot(page);
    await selectAndLoad(app, page, root2, fx._userB);
    await flagSwitch(root2, F).click();
    const nGet2 = calls.filter((c) => c.method === 'GET').length;
    const s2 = await clickSave(page, root2, calls);
    monitor.note(`PUT F Off: ${s2.raw}`);
    expect(s2.status).toBe(200);
    expect(s2.sent.permissions, 'permissions kosong').toEqual([]);
    expect(s2.sent.folders.map((f) => [idOf(f), flagOfFolder(f)]), 'folders [F: 0]').toEqual([[fx.F, 0]]);
    await expectSavedAndReloaded(app, page, root2, calls, nGet2);
    st = dbState();
    expect(st.flags.F, 'DB: F Off').toBe(0);
    expect(st.rows.A.F, 'DB: baris A tetap (mematikan tidak menghapus baris)').toEqual([1, 1, 1, 1]);
    expect(st.history.F.count, 'riwayat F +1 lagi').toBe(2);
    drawer = await openIzinDrawer(app, page, F);
    expect(await izinSwitch(drawer).getAttribute('aria-checked'), 'tab Izin F: saklar Off').toBe('false');
    await expect(izinRow(drawer, A), 'tab Izin F: baris A tetap').toBeVisible();
    expect(await izinBoxes(drawer, A), 'tab Izin F: A tetap V/U/D/S').toEqual([true, true, true, true]);
    await app.screenshot('AC36-02-tab-Izin-F-Off-baris-tetap');

    // F dinyalakan di tab Izin -> halaman ini: F On
    await izinSwitch(drawer).click();
    expect(await izinSwitch(drawer).getAttribute('aria-checked'), 'tab Izin: saklar On').toBe('true');
    const putF = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/document-archive\/archives\/[^/?]+(\?|$)/.test(r.url()), { timeout: 30000 });
    await drawer.locator('.ant-drawer-extra button.ant-btn-primary').click();
    expect((await putF).status(), 'PUT archives/{F} 200').toBe(200);
    await expect(page.locator('.ant-drawer-open'), 'drawer menutup').toHaveCount(0, { timeout: 15000 });
    st = dbState();
    expect(st.flags.F, 'DB: F On (tab Izin)').toBe(1);
    expect(st.dups, 'tab Izin: tanpa baris ganda').toEqual([]);
    await openPermissionPage(app);
    const root3 = pageRoot(page);
    await selectAndLoad(app, page, root3, fx._userB);
    expect((await rowOf(root3, F)).flag.checked, 'halaman ini: F On (dinyalakan di tab Izin)').toBe(true);
    await app.screenshot('AC36-03-halaman-F-On-dari-tab-Izin');
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-30 non-self G: BE menaikkan baris A yang ada
  test('[default] ED-1029 AC-32 A (rev3, K-9 a): B dipilih, G (Off, A View saja) dinyalakan -> body {permissions: [], folders}; BE menaikkan baris A di G ke 1/1/1/1, tetap satu baris, tanpa baris (G,B)', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    const before = dbState();
    expect(before.rows.A.G, 'awal: A View saja di G').toEqual([1, 0, 0, 0]);
    expect(before.flags.G, 'awal: G Off').toBe(0);
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const G = fullName('G');
    await flagSwitch(root, G).click();
    expect(checkedOf(await rowOf(root, G)), 'B tidak dicentang otomatis di G').toEqual([false, false, false, false]);
    const nGet = calls.filter((c) => c.method === 'GET').length;
    const s = await clickSave(page, root, calls);
    monitor.note(`PUT G On (target B): ${s.raw}`);
    expect(s.status).toBe(200);
    expect(s.sent.permissions, 'permissions []').toEqual([]);
    expect(s.sent.folders.map((f) => [idOf(f), flagOfFolder(f)])).toEqual([[fx.G, 1]]);
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    const st = dbState();
    expect(st.flags.G, 'DB: G On').toBe(1);
    expect(st.rows.A.G, 'DB: baris (G,A) dinaikkan 1/1/1/1').toEqual([1, 1, 1, 1]);
    expect(st.rows.B.G, 'DB: tanpa baris (G,B)').toBeUndefined();
    expect(st.dups, 'tanpa baris ganda').toEqual([]);
    expect(st.total_permissions, 'jumlah baris tetap (dinaikkan, bukan ditambah)').toBe(before.total_permissions);
    expect(st.history.G.count, 'riwayat G +1').toBe(1);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-30/32: saklar On + hak sel (Semua) di folder yang sama -> permissions + folders
  test('[default] ED-1029 AC-30/32 A (rev3): B dipilih, F On lalu Semua + lepas Delete/Store di F, Save -> body permissions [F: View+Update] + folders [F:1] (saklar tidak hilang saat hak diubah); BE: F On, (F,B) V+U, (F,A) 1/1/1/1 otomatis satu baris, riwayat +1; tab Izin memuat A dan B', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    const A = fx._userA.username;
    const Bn = fx._userB.username;
    fixture('resetperm');
    const before = dbState();
    expect(before.rows.A.F).toBeUndefined();
    await openPermissionPage(app);
    const root = pageRoot(page);
    await selectAndLoad(app, page, root, fx._userB);
    const F = fullName('F');
    await flagSwitch(root, F).click();
    await allBox(root, F).check();
    let r = await rowOf(root, F);
    expect([r.flag.checked, checkedOf(r), r.all.checked], 'F On + Semua: 1111').toEqual([true, [true, true, true, true], true]);
    await box(root, F, 'Delete').uncheck();
    await box(root, F, 'Store').uncheck();
    r = await rowOf(root, F);
    expect([r.flag.checked, checkedOf(r), r.all.indeterminate], 'F On, View+Update, Semua indeterminate (saklar tetap On)').toEqual([true, [true, true, false, false], true]);
    await app.screenshot('AC30-08-F-On-hak-diubah-B');
    const nGet = calls.filter((c) => c.method === 'GET').length;
    const s = await clickSave(page, root, calls);
    monitor.note(`PUT F On + hak (target B): ${s.raw}`);
    expect(s.status, 'PUT 200').toBe(200);
    expect(sortedKeys(s.sent), 'body: permissions + folders').toEqual(['folders', 'permissions']);
    expect(s.sent.permissions, 'permissions: hanya F').toHaveLength(1);
    expect(idOf(s.sent.permissions[0])).toBe(fx.F);
    expect(flagsOf(s.sent.permissions[0]), 'F: View+Update').toEqual([1, 1, 0, 0]);
    expect(s.sent.folders.map((f) => [idOf(f), flagOfFolder(f)]), 'folders [F: 1]').toEqual([[fx.F, 1]]);
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    r = await rowOf(root, F);
    expect([r.flag.checked, checkedOf(r), r.boxes.map((b) => !b.disabled)], 'sesudah muat ulang: F On, B View+Update, aktif').toEqual([true, [true, true, false, false], [true, true, true, true]]);
    const st = dbState();
    expect(st.flags.F, 'DB: F On').toBe(1);
    expect(st.updated_by.F, 'DB: updated_by F = A').toBe(A);
    expect(st.rows.B.F, 'DB: (F,B) = View+Update').toEqual([1, 1, 0, 0]);
    expect(st.rows.A.F, 'DB: (F,A) = 1/1/1/1 otomatis oleh BE (target bukan user login)').toEqual([1, 1, 1, 1]);
    expect(st.dups, 'satu baris per user').toEqual([]);
    expect(st.total_permissions - before.total_permissions, 'baris baru: (F,A) dan (F,B)').toBe(2);
    expect(st.history.F.count, 'riwayat F +1 (satu entri)').toBe(1);
    expect(st.history.F.last_by).toBe(A);
    const drawer = await openIzinDrawer(app, page, F);
    expect(await izinSwitch(drawer).getAttribute('aria-checked'), 'tab Izin F: saklar On').toBe('true');
    expect(await izinBoxes(drawer, A), 'tab Izin F: A V/U/D/S (otomatis)').toEqual([true, true, true, true]);
    expect(await izinBoxes(drawer, Bn), 'tab Izin F: B View+Update').toEqual([true, true, false, false]);
    await app.screenshot('AC32-01-tab-Izin-F-A-otomatis-B');
    await closeDrawer(page);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-31 (A memilih dirinya sendiri): K-9 a + C-1
  test('[default] ED-1029 AC-31 A (rev3, K-9 a, C-1): A pilih A - F (tersimpan Off) dinyalakan = V/U/D/S dan Semua tercentang sebelum Save; Q (tersimpan On) Off lalu On tidak dicentang otomatis; lepas Delete + Save = body folders [F:1] + permissions [F:1/1/0/1]', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    const A = fx._userA.username;
    fixture('resetperm');
    const before = dbState();
    await openPermissionPage(app);
    const root = pageRoot(page);
    const self = await pickUser(page, root, fx._userA);
    expect(self.status, 'GET user-permissions/{A}').toBe(200);
    await app.settle();
    await expect(tableRowLocator(root, `${P}P`)).toBeVisible();
    const F = fullName('F');
    const Q = fullName('Q');

    // Q (tersimpan On, A View saja): Off lalu On tidak mencentang otomatis (C-1: pemicu = tersimpan Off)
    expect(checkedOf(await rowOf(root, Q)), 'Q: A tersimpan View saja').toEqual([true, false, false, false]);
    await flagSwitch(root, Q).click();
    let r = await rowOf(root, Q);
    expect([r.flag.checked, checkedOf(r), r.boxes.map((b) => b.disabled)], 'Q Off: terkunci, nilai tetap').toEqual([false, [true, false, false, false], [true, true, true, true]]);
    await flagSwitch(root, Q).click();
    r = await rowOf(root, Q);
    expect([r.flag.checked, checkedOf(r)], 'Q On lagi: TIDAK dicentang otomatis (C-1)').toEqual([true, [true, false, false, false]]);
    await expect(saveBtn(root), 'Q kembali tersimpan: Save mati').toBeDisabled();

    // F (tersimpan Off, tanpa baris A): dinyalakan -> V/U/D/S dan Semua tercentang sebelum Save (BR-23), masih bisa diubah
    r = await rowOf(root, F);
    expect([r.flag.checked, checkedOf(r)], 'F awal: Off, kosong').toEqual([false, [false, false, false, false]]);
    await flagSwitch(root, F).click();
    r = await rowOf(root, F);
    expect(r.flag.checked, 'F On').toBe(true);
    expect(checkedOf(r), 'F: V/U/D/S tercentang sebelum Save').toEqual([true, true, true, true]);
    expect(r.all, 'F: Semua tercentang').toEqual({ checked: true, indeterminate: false, disabled: false });
    expect(r.boxes.map((b) => !b.disabled), 'F: masih bisa diubah').toEqual([true, true, true, true]);
    await expect(saveBtn(root), 'Save aktif').toBeEnabled();
    await app.screenshot('AC31-01-F-dinyalakan-A-sendiri');
    // Off lagi sebelum Save -> kembali ke tersimpan (kosong); On lagi -> tetap satu set 1111
    await flagSwitch(root, F).click();
    r = await rowOf(root, F);
    expect([r.flag.checked, checkedOf(r)], 'F Off lagi: kembali tersimpan (kosong)').toEqual([false, [false, false, false, false]]);
    await expect(saveBtn(root), 'tersimpan: Save mati').toBeDisabled();
    await flagSwitch(root, F).click();
    expect(checkedOf(await rowOf(root, F)), 'F On lagi: 1111').toEqual([true, true, true, true]);
    // lepas Delete -> V/U/S, Semua indeterminate
    await box(root, F, 'Delete').uncheck();
    r = await rowOf(root, F);
    expect(checkedOf(r), 'lepas Delete').toEqual([true, true, false, true]);
    expect(r.all, 'Semua indeterminate').toEqual({ checked: false, indeterminate: true, disabled: false });
    await app.screenshot('AC31-02-F-lepas-Delete');

    const nGet = calls.filter((c) => c.method === 'GET').length;
    const s = await clickSave(page, root, calls);
    monitor.note(`PUT AC-31: ${s.raw}`);
    expect(s.status, 'PUT user-permissions/{A} 200').toBe(200);
    expect(s.json.msg_code).toBe('ARCHIVE207');
    expect(s.sent.permissions, 'permissions: hanya F (satu entri, Q tidak ikut)').toHaveLength(1);
    expect(idOf(s.sent.permissions[0])).toBe(fx.F);
    expect(flagsOf(s.sent.permissions[0]), 'permissions F: 1/1/0/1').toEqual([1, 1, 0, 1]);
    expect(sortedKeys(s.sent), 'body: permissions + folders').toEqual(['folders', 'permissions']);
    expect(s.sent.folders.map((f) => [idOf(f), flagOfFolder(f)]), 'folders [F: 1]').toEqual([[fx.F, 1]]);
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    r = await rowOf(root, F);
    expect([r.flag.checked, checkedOf(r)], 'sesudah muat ulang: F On, V/U/S').toEqual([true, [true, true, false, true]]);

    const st = dbState();
    expect(st.flags.F, 'DB: F flag 1').toBe(1);
    expect(st.updated_by.F, 'DB: updated_by F = A').toBe(A);
    expect(st.rows.A.F, 'DB: (F,A) = 1/1/0/1 (tidak dinaikkan BE: target = user login)').toEqual([1, 1, 0, 1]);
    expect(st.dups, 'satu baris (F,A), tanpa baris kedua').toEqual([]);
    expect(st.total_permissions - before.total_permissions, 'baris baru: (F,A) saja').toBe(1);
    expect(st.rows.B, 'hak B tidak tersentuh').toEqual(B0);
    expect(st.flags.Q, 'Q tidak berubah (On)').toBe(1);
    expect(st.history.F.count, 'riwayat F +1').toBe(1);
    expect(st.history.F.last_by).toBe(A);
    expect(st.history.Q.count, 'Q tanpa riwayat').toBe(0);

    // tab Izin F: saklar On, A tanpa Delete; list: F berperisai
    const drawer = await openIzinDrawer(app, page, F);
    expect(await izinSwitch(drawer).getAttribute('aria-checked'), 'tab Izin F: saklar On').toBe('true');
    expect(await izinBoxes(drawer, A), 'tab Izin F: A View/Update/Store tanpa Delete').toEqual([true, true, false, true]);
    await app.screenshot('AC31-03-tab-Izin-F-A-tanpa-Delete');
    await closeDrawer(page);
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    expect(await archiveRow(page, F).locator('.anticon-safety-certificate').count(), 'list: F berperisai').toBe(1);
    await app.screenshot('AC31-04-list-F-berperisai');
    fixture('resetperm');
    noServerError(monitor);
  });

  test('[default] ED-1029 AC-31 A (rev3, K-9 a): A pilih A, G (tersimpan Off, A View saja) dinyalakan = baris G naik ke V/U/D/S (satu entri, bukan kedua); Save body permissions [G 1/1/1/1] + folders [G:1]; DB tetap satu baris', async ({ app, page, monitor }) => {
    const calls = trackCalls(page, monitor);
    const fx = ids();
    fixture('resetperm');
    const before = dbState();
    expect(before.rows.A.G).toEqual([1, 0, 0, 0]);
    await openPermissionPage(app);
    const root = pageRoot(page);
    expect((await pickUser(page, root, fx._userA)).status).toBe(200);
    await app.settle();
    const G = fullName('G');
    let r = await rowOf(root, G);
    expect([r.flag.checked, checkedOf(r), r.boxes.map((b) => b.disabled)], 'G awal: Off, A View tersimpan, terkunci').toEqual([false, [true, false, false, false], [true, true, true, true]]);
    await flagSwitch(root, G).click();
    r = await rowOf(root, G);
    expect([r.flag.checked, checkedOf(r)], 'G On: naik ke V/U/D/S').toEqual([true, [true, true, true, true]]);
    expect(r.all, 'G: Semua tercentang').toEqual({ checked: true, indeterminate: false, disabled: false });
    const nGet = calls.filter((c) => c.method === 'GET').length;
    const s = await clickSave(page, root, calls);
    monitor.note(`PUT G self: ${s.raw}`);
    expect(s.status).toBe(200);
    expect(s.sent.permissions, 'permissions: G satu entri').toHaveLength(1);
    expect(idOf(s.sent.permissions[0])).toBe(fx.G);
    expect(flagsOf(s.sent.permissions[0]), 'G 1/1/1/1').toEqual([1, 1, 1, 1]);
    expect(s.sent.folders.map((f) => [idOf(f), flagOfFolder(f)]), 'folders [G: 1]').toEqual([[fx.G, 1]]);
    await expectSavedAndReloaded(app, page, root, calls, nGet);
    const st = dbState();
    expect(st.flags.G, 'DB: G On').toBe(1);
    expect(st.rows.A.G, 'DB: (G,A) 1/1/1/1').toEqual([1, 1, 1, 1]);
    expect(st.dups, 'satu baris').toEqual([]);
    expect(st.total_permissions, 'jumlah baris tetap (baris A di G dinaikkan, bukan ditambah)').toBe(before.total_permissions);
    expect(st.history.G.count, 'riwayat G +1 (satu entri)').toBe(1);
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-1, AC-3 (M: Folder Permissions tanpa Update Folder)
  test('[user2] ED-1029 AC-1 + AC-3 + AC-14 M (Folder Permissions tanpa Update Folder): "+" memuat Set Permission by User (root & P), halaman terbuka tanpa kilasan Unauthorized, sel aktif termasuk folder tanpa View, Save; info EN', async ({ app, page, monitor, session }) => {
    const fx = ids();
    fixture('resetperm');
    await asProfile('M', async () => {
      monitor.note(`DB sesi ${session.dbName}; QA_USER2 sementara profil M = List Archive + View Folder + Folder Permissions (tanpa Update Folder), bahasa EN`);
      const calls = trackCalls(page, monitor);
      const readNav = await trackNavigation(page);

      // AC-1: "+" di root dan di dalam P (M punya View di P lewat fixture)
      await app.open('/archives', { waitFor: '.archive-page .ant-table' });
      const rootItems = await menuTexts(await openPlus(page));
      monitor.note(`"+" di root (M): ${rootItems.join(' | ')}`);
      await app.screenshot('AC1-05-plus-root-M');
      expect(rootItems, 'M: Set Permission by User ada di root').toContain('Set Permission by User');
      expect(rootItems[rootItems.length - 1], 'paling akhir').toBe('Set Permission by User');
      await closeMenu(page);
      await enterFolder(app, page, `${P}P`);
      const pItems = await menuTexts(await openPlus(page));
      monitor.note(`"+" di P (M): ${pItems.join(' | ')}`);
      await app.screenshot('AC1-06-plus-di-P-M');
      expect(pItems[pItems.length - 1], 'di dalam P: paling akhir').toBe('Set Permission by User');
      expect(pItems.filter((t) => ITEM_RE.test(t)), 'tepat satu').toHaveLength(1);
      // klik item dari dalam folder -> halaman terbuka
      await page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last().locator('li.ant-dropdown-menu-item', { hasText: /^Set Permission by User$/ }).click();
      await page.locator(SEARCH).first().waitFor({ state: 'visible', timeout: 30000 });
      await app.settle();
      expect(await app.currentPath(), 'navigasi ke /archives/permissions').toBe(PERM_PATH);

      // AC-3: URL langsung (hard load) dan F5: tanpa pengalihan, tanpa kilasan Unauthorized
      await openPermissionPage(app);
      for (let i = 1; i <= 2; i += 1) {
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.locator(SEARCH).first().waitFor({ state: 'visible', timeout: 30000 });
        await app.settle();
        await page.waitForTimeout(1000);
      }
      const nav = await readNav();
      monitor.note(`M: paths=${JSON.stringify(nav.paths)} unauthorized=${nav.unauthorized} search=${nav.search}`);
      expect(nav.search, 'halaman Archive Permission ter-render').toBe(true);
      expect(nav.unauthorized, 'M: tanpa kilasan Unauthorized').toBe(false);
      expect(nav.paths, 'M: tidak pernah ke /unathorized').not.toContain('/unathorized');
      expect(await page.locator('.ant-result-403').count(), 'tanpa Result 403').toBe(0);
      expect(await app.currentPath(), 'path tab tetap').toBe(PERM_PATH);

      // bahasa EN: info BR-9 (en) tanpa "dominant" / "nearest On parent"; judul, placeholder
      const root = pageRoot(page);
      await expect(searchInput(root)).toHaveAttribute('placeholder', 'Search folder');
      await expect(root.getByText('Select a user to see their folder permissions')).toBeVisible();
      const info = (await root.locator('.ant-alert-info').innerText()).replace(/\s+/g, ' ');
      monitor.note(`info EN: ${info}`);
      expect(info, 'BR-9 en').toContain('Checkboxes are only active on folders whose Enable Folder Permission is On. Off folders cannot be checked and do not restrict access.');
      expect(info, 'BR-9 en: View induk').toContain('Only View on parent folders applies: a user without View on a parent folder cannot open its subfolders even if checked here.');
      expect(info, 'BR-9 en: U/D/S').toContain('Update, Delete and Store are judged on each folder.');
      expect(info, 'AC-24 en: tanpa dominant / nearest On parent').not.toMatch(/dominant|nearest On parent/i);
      expect(info, 'BR-9 rev3 en: kalimat saklar').toContain('Turning on Enable Folder Permission here restricts that folder for every user without View on it and gives you all rights on it.');
      expect(info, 'BR-9 rev3 en: {setting} tidak tersisa literal').not.toContain('{setting}');

      // AC-14 sisi M: pilih B -> sel aktif di semua folder On termasuk J-JB / Q tanpa View M; Off terkunci
      const loaded = await selectAndLoad(app, page, root, fx._userB);
      const rows = await tableRows(root);
      expectTreeMatches(rows, loaded.body);
      const pn = (n) => rowByName(rows, fullName(n));
      ['P', 'P-C1', 'Q', 'R-R1', 'J', 'J-JA', 'J-JB'].forEach((n) => expect(pn(n).boxes.map((b) => !b.disabled), `${fullName(n)} (On): aktif untuk M`).toEqual([true, true, true, true]));
      ['P-C2', 'R', 'J-JC'].forEach((n) => expect(pn(n).boxes.map((b) => !b.disabled), `${fullName(n)} (Off): terkunci`).toEqual([false, false, false, false]));
      const node = (n) => flatten(loaded.body.result.data).find((f) => f.node.name === fullName(n)).node;
      expect(node('J-JB').access.view, 'M tanpa View di JB').toBe(false);
      expect(node('J-JB').access.manage_permission, 'tetap bisa dikelola').toBe(true);
      // rev3: saklar aktif di semua folder bagi M (juga Off: C2, R, JC; juga JB tanpa View M); judul kolom en
      const heads = (await root.locator('.ant-table-thead').first().locator('th').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
      expect(heads.slice(1), 'M (en): urutan kolom').toEqual(['Permission', 'All', 'View', 'Update', 'Delete', 'Store']);
      expect(rows.every((r) => r.flag && r.flag.disabled === false), 'M: saklar aktif di semua folder').toBe(true);
      expect(pn('J-JB').flag.checked && pn('J-JC').flag.checked === false, 'M: JB On, JC Off').toBe(true);
      await app.screenshot('AC14-02-pohon-user-B-sebagai-M');

      // Save oleh M (tanpa Update Folder): JB View -> 200, riwayat oleh M
      await box(root, fullName('J-JB'), 'View').check();
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
      await saveBtn(root).click();
      expect((await put).status(), 'PUT oleh M: 200').toBe(200);
      await waitToast(page, /.+/);
      expect(await page.locator('.ant-notification-notice .anticon-check-circle, .ant-message-notice .anticon-check-circle').count(), 'toast sukses').toBeGreaterThan(0);
      await app.settle();
      const st = dbState();
      expect(st.rows.B['J-JB'], 'DB: (JB, B) = View').toEqual([1, 0, 0, 0]);
      expect(st.history['J-JB'].count, 'riwayat JB +1').toBe(1);
      expect(st.history['J-JB'].last_by, 'pelaku riwayat = M (QA_USER2)').toBe(fx._userE.username);
      expect(calls.filter((c) => c.method === 'PUT'), 'tepat satu PUT').toHaveLength(1);
    });
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ jalur error M (daftar UI 015 butir 10)
  test('[user2] ED-1029 jalur error M: Folder Permissions dicabut sesudah halaman dibuka -> Save 403 GE0114 (toast), perubahan tetap; muat ulang -> Unauthorized', async ({ app, page, monitor }) => {
    const fx = ids();
    fixture('resetperm');
    await asProfile('M', async () => {
      const calls = trackCalls(page, monitor);
      await openPermissionPage(app);
      const root = pageRoot(page);
      await selectAndLoad(app, page, root, fx._userB);
      await box(root, fullName('P-C1'), 'View').check();
      const dirty = (await tableRows(root)).map((r) => r.boxes.map((b) => b.checked));

      // di antara buka dan Save: role pengguna diganti ke role tanpa Folder Permissions (role E = preset QA29F-ROLE-E)
      fixture('uprofile', 'E');
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && GET_RE.test(r.url()), { timeout: 45000 });
      const nGet = calls.filter((c) => c.method === 'GET').length;
      await saveBtn(root).click();
      const resp = await put;
      const body = await resp.json();
      monitor.note(`PUT ditolak: ${resp.status()} ${body.code || body.msg_code} parameter=${JSON.stringify(body.parameter)}`);
      expect(resp.status(), 'PUT ditolak').toBe(403);
      expect(body.code || body.msg_code, 'kode GE0114').toBe('GE0114');
      expect(JSON.stringify(body.parameter), 'parameter = Folder Permissions').toContain('Folder Permissions');
      const toast = await waitToast(page, /Folder Permissions/);
      monitor.note(`toast error: ${toast}`);
      expect(await page.locator('.ant-notification-notice .anticon-close-circle, .ant-message-notice .anticon-close-circle').count(), 'toast error (ikon silang)').toBeGreaterThan(0);
      await app.screenshot('ERR-01-M-dicabut-save-403');
      expect((await tableRows(root)).map((r) => r.boxes.map((b) => b.checked)), 'centang di layar tidak hilang').toEqual(dirty);
      await expect(saveBtn(root), 'Save tetap hidup').toBeEnabled();
      expect(calls.filter((c) => c.method === 'GET').length, 'tanpa GET ulang sesudah gagal').toBe(nGet);
      const st = dbState();
      expect(st.rows.B, 'DB: tidak ada yang tersimpan').toEqual(B0);
      expect(st.history['P-C1'].count, 'tanpa riwayat C1').toBe(0);

      // 403 PUT dan log konsol axios yang diharapkan dikeluarkan dari pemeriksaan error
      const expected = (e) => (e.kind === 'response' && e.status === 403 && e.method === 'PUT' && GET_RE.test(e.url)) || (e.kind === 'console' && /status code 403|GE0114|AxiosError/.test(e.text || ''));
      const dropped = monitor.errors.filter(expected);
      expect(dropped.filter((e) => e.kind === 'response').length, '403 PUT tercatat (diharapkan)').toBeGreaterThanOrEqual(1);
      monitor.errors = monitor.errors.filter((e) => !expected(e));
      monitor.note(`error yang diharapkan dikeluarkan dari pemeriksaan: ${dropped.length} entri`);

      // muat ulang (role baru terbaca dari auth/info) -> Unauthorized tanpa request
      await expectPageDenied(app, page, monitor, 'M setelah dicabut');
    });
    fixture('resetperm');
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-1, AC-3 (D: Update Folder tanpa Folder Permissions)
  test('[user2] ED-1029 AC-1 + AC-3 D (Update Folder tanpa Folder Permissions): "+" tanpa Set Permission by User (root & P); /archives/permissions -> Unauthorized, tanpa request', async ({ app, page, monitor, session }) => {
    await asProfile('D', async () => {
      monitor.note(`DB sesi ${session.dbName}; QA_USER2 sementara profil D = role A tanpa Folder Permissions (masih Update Folder), bahasa EN`);
      await app.open('/archives', { waitFor: '.archive-page .ant-table' });
      const rootItems = await plusItemsOrNone(page);
      monitor.note(`"+" di root (D): ${rootItems === null ? '(tombol "+" tidak ada)' : rootItems.join(' | ')}`);
      await app.screenshot('AC1-07-plus-root-D');
      expect((rootItems || []).filter((t) => ITEM_RE.test(t)), 'D: tanpa Set Permission by User di root').toHaveLength(0);
      expect(rootItems && rootItems.length, 'D tetap melihat item "+" lain (bukti menu ada, hanya item itu yang hilang)').toBeGreaterThan(0);
      await enterFolder(app, page, `${P}P`);
      const pItems = await plusItemsOrNone(page);
      monitor.note(`"+" di P (D): ${pItems === null ? '(tombol "+" tidak ada)' : pItems.join(' | ')}`);
      await app.screenshot('AC1-08-plus-di-P-D');
      expect((pItems || []).filter((t) => ITEM_RE.test(t)), 'D: tanpa Set Permission by User di dalam P').toHaveLength(0);
      await expectPageDenied(app, page, monitor, 'D');
      await app.screenshot('AC3-03-D-buka-URL');
    });
    noServerError(monitor);
  });

  // ------------------------------------------------------------------------------------------------ AC-1, AC-3 (E)
  test('[user2] ED-1029 AC-1 E: menu "+" tanpa Set Permission by User (di root dan di dalam folder)', async ({ app, page, monitor, session }) => {
    monitor.note(`DB sesi ${session.dbName}; E=QA_USER2 sementara preset QA29F-ROLE-E (List Archive + Handover/Receive/Add Document, tanpa Update Folder dan tanpa Folder Permissions)`);
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    const items = await menuTexts(await openPlus(page));
    monitor.note(`"+" di root (E): ${items.join(' | ')}`);
    await app.screenshot('AC1-03-plus-root-E');
    expect(items.length, 'E tetap melihat item "+" lain').toBeGreaterThan(0);
    expect(items.filter((t) => ITEM_RE.test(t)), 'tanpa Set Permission by User').toHaveLength(0);
    await closeMenu(page);

    // E tidak punya hak folder P (On, tanpa baris): buka folder R (Off) yang terbuka bagi semua
    await enterFolder(app, page, `${P}R`);
    await expect(archiveRow(page, `${P}R-R1`), 'R memuat R1').toBeVisible();
    const inItems = await menuTexts(await openPlus(page));
    monitor.note(`"+" di R (E): ${inItems.join(' | ')}`);
    await app.screenshot('AC1-04-plus-di-R-E');
    expect(inItems.length, 'di dalam folder E tetap melihat item "+" lain').toBeGreaterThan(0);
    expect(inItems.filter((t) => ITEM_RE.test(t)), 'di dalam folder pun tanpa item itu').toHaveLength(0);
    await closeMenu(page);
    noServerError(monitor);
  });

  test('[user2] ED-1029 AC-3 E: /archives/permissions lewat URL -> Unauthorized, tanpa request user-permissions maupun select user', async ({ app, page, monitor }) => {
    await expectPageDenied(app, page, monitor, 'E');
    await app.screenshot('AC3-01-E-buka-URL');
    noServerError(monitor);
  });
});
