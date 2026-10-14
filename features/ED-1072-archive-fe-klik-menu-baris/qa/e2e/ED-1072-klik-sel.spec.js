'use strict';

/**
 * ED-1072 - Archive FE: klik elemen interaktif di sel (⋯, item menunya, tautan nomor transaksi + menu konteksnya, V/T folder,
 * isi popover "+N") bukan klik sel/baris (aturan AT-1, `isCellActionClick`).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php), berawalan "0QA72-", dan dibuang persis di teardown. Peta:
 *   F  (root) > D1 (DO, status 1, breadcrumbs=[F]), D2 (DO, status 2 = Tag Diserahkan, breadcrumbs=[F]), S (subfolder)
 *   FP (root, ber-perisai, hak penuh QA_USER)    FE (root, kosong)
 * AC-9 memakai SATU dokumen DO NYATA (fixture.php real-doc, baca-saja) karena "Open in new tab" memasang tab halaman DO yang
 * memanggil GET sales/delivery-orders/{id}: id transaksi palsu = 404 (ditolak harness). Tidak ada baris dibuat/diubah untuk itu.
 *
 * Profil: [default] = QA_USER (bahasa ID, pemegang Opname Document). Label UI yang dipakai: "Pilih", "Info", "Pindahkan", "Batal",
 * "Lihat", "Opname", "Buka ditab baru" (id_ID) atau padanan en_US.
 *
 * Semua test NON-mutasi: tidak ada Pindahkan/Hapus/Konfirmasi yang dikirim (modal selalu ditutup lewat Batal/X), tidak ada simpan.
 * Jalankan dengan --workers 1: scripts/e2e/run.sh ED-1072 --workers 1
 *
 * Bukti "gejala hilang" bergantung pada breadcrumbs dokumen yang terisi (fixture): sebelum perbaikan klik ⋯ menulis ulang
 * breadcrumb dari D.breadcrumbs dan men-set `ant-table-row-selected`.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const P = '0QA72-';

const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));

/** fixture.php <perintah>: keluaran JSON tanpa rahasia (inspect / real-doc: baca-saja). */
function fx(...args) {
  const out = execFileSync(config.get('PHP_BIN', 'php'), [path.join(__dirname, 'fixture.php'), ...args], {
    encoding: 'utf8',
    cwd: config.AGENTIC_DIR,
    timeout: 90000,
    env: { ...process.env },
  });
  return JSON.parse(out.trim().split('\n').pop());
}

/** screenshot viewport (bukan fullPage: fullPage menutup menu/popover yang sedang terbuka), masuk galeri index.html. */
async function shot(app, page, name) {
  const slug = String(name).replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 120);
  const rel = path.join('screenshots', app.profile || 'default', `${slug}.png`);
  const abs = path.join(app.outDir || process.env.E2E_OUT_DIR, rel);
  fs.mkdirSync(path.dirname(abs), { recursive: true });
  await page.screenshot({ path: abs, fullPage: false });
  app.screenshots.push(rel.replace(/\\/g, '/'));
}

/** catat request GET document-archive/archives (untuk membuktikan folder "dibuka" atau tidak). */
function trace(page) {
  const calls = [];
  page.on('request', (r) => {
    if (r.method() !== 'GET') return;
    const u = new URL(r.url());
    if (!/\/document-archive\/archives$/.test(u.pathname)) return;
    calls.push({ idArchive: u.searchParams.get('idArchive') || u.searchParams.get('id_archive'), search: u.searchParams.get('search'), url: r.url() });
  });
  return calls;
}
const folderCalls = (calls) => calls.filter((c) => c.idArchive);

const listResponse = (page, pred) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 })
    .then(async (r) => ({ status: r.status(), url: r.url(), body: await r.json().catch(() => null) }));
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];

/** baris tabel HALAMAN (bukan tabel picker di dalam modal Pindahkan, yang juga berisi `.archive-page`-like markup). */
const rowOf = (page, name) =>
  page
    .locator('.archive-page .ant-table-row:not(.ant-modal-wrap *)')
    .filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) })
    .first();

const crumbs = async (page) =>
  (await page.locator('.archive-page .breadcrumbs .ant-breadcrumb-link:not(.ant-modal-wrap *)').allInnerTexts()).map((t) => t.trim());
const selectedRows = async (page) =>
  (await page.locator('.archive-page .ant-table-row-selected:not(.ant-modal-wrap *)').evaluateAll((els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').trim()))).sort();
/** "N dipilih" di toolbar mode Pilih; null = bukan mode Pilih. */
async function pickedCount(page) {
  const txt = await page.locator('.archive-page').first().innerText();
  const m = /(\d+)\s+(dipilih|selected)/i.exec(txt);
  return m ? Number(m[1]) : null;
}
const isChecked = (row) => row.locator('input[type="checkbox"]').isChecked();
/** keadaan yang tidak boleh berubah oleh klik ⋯/menu/tautan/V/T: breadcrumb, baris terpilih, jumlah "N dipilih". */
const snap = async (page) => ({ crumbs: await crumbs(page), selected: await selectedRows(page), picked: await pickedCount(page) });

async function openRoot(page, app) {
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  await app.settle();
  expect(await crumbs(page), 'mulai di root').toEqual(['Arsip']);
}

/** Buka folder (klik sel nama) dan tunggu GET archives?idArchive. */
async function openFolder(page, app, name) {
  const resp = listResponse(page, (u) => !!(u.searchParams.get('idArchive') || u.searchParams.get('id_archive')));
  await rowOf(page, name).locator('td.cursor-pointer').first().click();
  const r = await resp;
  expect(r.status, `buka folder ${name}`).toBe(200);
  await app.settle();
  return r;
}

/** Cari di root (query bebas, lintas folder) dan tunggu hasilnya. */
async function search(page, app, q) {
  const input = page.locator('.archive-page #query').first();
  await input.fill(q);
  const resp = listResponse(page, (u) => decodeURIComponent(u.searchParams.get('search') || '').includes(q));
  await input.press('Enter');
  const r = await resp;
  expect(r.status, `cari ${q}`).toBe(200);
  await app.settle();
  return r;
}

const dropdown = (page) => page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();

/** Klik ⋯ baris; kembalikan teks item menu (menu dibiarkan terbuka). */
async function openRowMenu(page, row) {
  await row.waitFor({ state: 'visible', timeout: 20000 });
  await row.locator('button.btn-action').click();
  const menu = dropdown(page);
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  return (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());
}
const pickMenuItem = (page, re) => dropdown(page).locator('li.ant-dropdown-menu-item', { hasText: re }).first().click();

const drawer = (page) => page.locator('.ant-drawer-open').last();
const visibleModal = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"])').last();

async function closeDrawer(page) {
  await drawer(page).locator('.ant-drawer-close').click();
  await page.locator('.ant-drawer-open').waitFor({ state: 'detached', timeout: 10000 });
}
async function closeModalX(page) {
  await visibleModal(page).locator('.ant-modal-close').click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  await page.waitForTimeout(400);
}

/** Item tombol "+" (primary dengan ikon): kunci FE, tidak bergantung bahasa. */
const PLUS_KEYS = [
  ['add-folder', /Add Folder|Tambah Folder/],
  ['hand-over', /Hand ?Over|Serah/],
  ['receive', /Receive|Terima/],
  ['add-document', /Store Document|Letakkan Dokumen/],
  ['opname', /Opname/],
  ['set-permission', /Set Permission|Atur Izin/],
];
async function plusKeys(page) {
  const plus = page.locator('.archive-page button.ant-btn-primary').filter({ has: page.locator('.anticon') }).first();
  await plus.waitFor({ state: 'visible', timeout: 15000 });
  await plus.click();
  const menu = dropdown(page);
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  const items = (await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim());
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  return items.map((t) => (PLUS_KEYS.find(([, re]) => re.test(t)) || ['?:' + t])[0]);
}

const noToast = async (page, label) => {
  const n = await page.locator('.ant-message-error, .ant-notification-notice-error, .ant-message-notice .anticon-close-circle').count();
  expect(n, `tanpa toast error (${label})`).toBe(0);
};

/** Klik titik pusat elemen (tanpa menunggu actionability tooltip): untuk ikon/Tag/sel. */
const click = (loc, opts) => loc.first().click({ timeout: 15000, ...(opts || {}) });

// ------------------------------------------------------------------------------------------------------------------------------

test('[default] ED-1072 AC-1: root, di dalam F, dan hasil pencarian terbuka tanpa error konsol/toast', async ({ app, page }) => {
  await openRoot(page, app);
  await noToast(page, 'root');
  await expect(rowOf(page, `${P}F`)).toBeVisible();
  await shot(app, page, 'AC1-root');

  await openFolder(page, app, `${P}F`);
  expect(await crumbs(page)).toEqual(['Arsip', `${P}F`]);
  await expect(rowOf(page, `${P}D1`)).toBeVisible();
  await noToast(page, 'di dalam F');
  await shot(app, page, 'AC1-dalam-F');

  await page.locator('.archive-page .breadcrumbs .ant-breadcrumb-link', { hasText: /^Arsip$/ }).click();
  await app.settle();
  const r = await search(page, app, `${P}D`);
  const rows = rowsOf(r).map((x) => (Array.isArray(x.name) ? x.name[0].transaction_no : x.name));
  expect(rows.sort(), 'pencarian root menemukan D1 dan D2 (lintas folder)').toEqual([`${P}D1`, `${P}D2`]);
  expect(await crumbs(page)).toEqual(['Arsip']);
  await noToast(page, 'hasil pencarian');
  await shot(app, page, 'AC1-hasil-cari');
});

test('[default] ED-1072 AC-2: klik ⋯ dokumen D di dalam F (klik dan fokus+Enter): menu saja, D tidak terpilih, breadcrumb tetap', async ({ app, page, monitor }) => {
  const calls = trace(page);
  await openRoot(page, app);
  await openFolder(page, app, `${P}F`);
  const d1 = rowOf(page, `${P}D1`);

  const before = await snap(page);
  expect(before.selected, 'tidak ada baris terpilih sebelum ⋯').toEqual([]);
  expect(before.crumbs).toEqual(['Arsip', `${P}F`]);
  const nBefore = calls.length;

  // (a) klik
  const items = await openRowMenu(page, d1);
  expect(items, 'menu ⋯ dokumen').toEqual(['Info', 'Pindahkan']);
  await shot(app, page, 'AC2-menu-terbuka-klik');
  expect(await snap(page), 'sesudah klik ⋯: tidak ada perubahan').toEqual(before);
  await expect(d1, 'tr D tanpa ant-table-row-selected').not.toHaveClass(/ant-table-row-selected/);
  await page.keyboard.press('Escape');
  await dropdown(page).waitFor({ state: 'hidden', timeout: 5000 }).catch(() => null);
  await page.waitForTimeout(300);

  // (b) fokus + Enter. Halaman Archive memasang `useBarcodeScanner` (scanner USB) yang memanggil preventDefault pada SETIAP keydown
  // di luar input (appHook.js), sehingga Enter/Spasi pada tombol mana pun di halaman ini tidak menjadi klik (juga di build HEAD
  // sebelum perbaikan, dan pada tombol "Pilih" / "+"): bukan akibat ED-1072. Aktivasi papan tombol yang setara (klik `detail=0`
  // pada tombol terfokus, `HTMLElement.click()`) dipakai sebagai gantinya bila keystroke asli tidak membuka menu.
  const btn = d1.locator('button.btn-action');
  await btn.focus();
  await page.keyboard.press('Enter');
  await page.waitForTimeout(500);
  const menu = dropdown(page);
  if (!(await menu.isVisible().catch(() => false))) {
    monitor.note('keystroke Enter asli tidak membuka menu (useBarcodeScanner mem-preventDefault keydown, pra-ada, juga di build HEAD); dipakai aktivasi setara btn.click() pada tombol terfokus');
    await btn.focus();
    await btn.evaluate((b) => b.click());
  }
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  expect((await menu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map((t) => t.trim()), 'menu terbuka lewat Enter').toEqual(['Info', 'Pindahkan']);
  expect(await snap(page), 'sesudah fokus+Enter: tidak ada perubahan').toEqual(before);
  await expect(d1).not.toHaveClass(/ant-table-row-selected/);
  await shot(app, page, 'AC2-menu-terbuka-enter');
  await page.keyboard.press('Escape');

  expect(calls.length, 'klik ⋯ tidak memicu muat ulang daftar').toBe(nBefore);
});

test('[default] ED-1072 AC-3: hasil pencarian root, ⋯ D1 → Info: drawer saja, breadcrumb tetap "Arsip", D1 tidak terpilih, "+" tetap item root', async ({ app, page, monitor }) => {
  await openRoot(page, app);
  const plusRoot = await plusKeys(page);
  expect(plusRoot, 'root tanpa Letakkan Dokumen').not.toContain('add-document');

  await search(page, app, `${P}D`);
  const before = await snap(page);
  expect(before.crumbs).toEqual(['Arsip']);
  expect(before.selected).toEqual([]);
  const plusBefore = await plusKeys(page);
  expect(plusBefore, '"+" di hasil pencarian = item root').toEqual(plusRoot);

  const d1 = rowOf(page, `${P}D1`);
  const info = infoResponse(page, ids()[`${P}D1`]);
  await openRowMenu(page, d1);
  expect(await snap(page), 'sesudah klik ⋯').toEqual(before);
  await pickMenuItem(page, /^Info$/);
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  // (ED-1149) usang: FE 41480e37c (penyesuaian UI developer 2026-10-12) menambah subjudul drawer Info = nomor transaksi / nama folder baris;
  // judul "Info" tetap, dan sekarang subjudul harus = baris yang diklik (lebih ketat dari sebelumnya)
  await expect(drawerTitle(page), 'drawer Info: judul "Info" + subjudul = nomor dokumen D1').toHaveText(new RegExp(`^Info\\s*${P}D1$`));
  expect((await info).status(), 'drawer Info memuat riwayat D1 (GET archives/history/{id D1})').toBe(200);
  await app.settle();
  await shot(app, page, 'AC3-drawer-info');
  expect(await snap(page), 'drawer Info terbuka: breadcrumb/pilihan tetap').toEqual(before);
  await expect(d1).not.toHaveClass(/ant-table-row-selected/);
  await closeDrawer(page);
  expect(await snap(page), 'sesudah drawer ditutup').toEqual(before);

  expect(await plusKeys(page), '"+" tetap item root sesudah Info').toEqual(plusRoot);

  // kontrol positif: di dalam F "+" memang berisi Letakkan Dokumen (kalau role-nya punya Add Document)
  await page.locator('.archive-page #query').first().fill('');
  await page.goto(page.url());
  await openRoot(page, app);
  await openFolder(page, app, `${P}F`);
  const plusF = await plusKeys(page);
  expect(plusF, '"+" di dalam F memuat semua item root (kontrol)').toEqual(expect.arrayContaining(plusRoot));
  monitor.note(`item "+" root/hasil pencarian: ${plusRoot.join(', ')} | di dalam F: ${plusF.join(', ')}`);
  // Kontrol: bila "+" di F berbeda dari root (mis. Letakkan Dokumen), perbedaan itu adalah yang akan bocor bila ⋯ mengganti breadcrumb.
  monitor.note(plusF.includes('add-document') ? 'kontrol positif ada: "+" di F memuat add-document, root/hasil pencarian tidak' : 'kontrol positif tidak tersedia: role QA_USER tanpa item add-document di F');
});

/** Drawer Info = timeline riwayat: GET archives/history/{id} membuktikan drawer memuat baris yang benar (fixture tanpa riwayat = timeline kosong). */
function infoResponse(page, idArchive) {
  return page.waitForResponse((r) => r.request().method() === 'GET' && new RegExp(`/document-archive/archives/history/${idArchive}(\\?|$)`).test(r.url()), { timeout: 30000 });
}
const drawerTitle = (page) => drawer(page).locator('.ant-drawer-title');

test('[default] ED-1072 AC-4: ⋯ D1 → Pindahkan (di F dan di hasil pencarian): modal Pindahkan D1 terbuka; breadcrumb dan pilihan tetap, juga sesudah Batal', async ({ app, page }) => {
  const dbBefore = fx('inspect');
  const calls = trace(page);
  await openRoot(page, app);

  // --- hasil pencarian root
  await search(page, app, `${P}D`);
  let before = await snap(page);
  let d1 = rowOf(page, `${P}D1`);
  await openRowMenu(page, d1);
  await pickMenuItem(page, /^Pindahkan$/);
  let modal = visibleModal(page);
  await expect(modal.locator('.ant-modal-title')).toContainText(`Pindahkan - ${P}D1`, { timeout: 15000 });
  await app.settle();
  await shot(app, page, 'AC4-modal-pindahkan-hasil-cari');
  expect(await snap(page), 'modal terbuka (hasil pencarian): breadcrumb/pilihan tetap').toEqual(before);
  await modal.locator('button', { hasText: /^Batal$/ }).click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  await page.waitForTimeout(500);
  expect(await snap(page), 'sesudah Batal (hasil pencarian)').toEqual(before);
  await expect(d1).not.toHaveClass(/ant-table-row-selected/);

  // --- di dalam F
  await page.goto(page.url());
  await openRoot(page, app);
  await openFolder(page, app, `${P}F`);
  before = await snap(page);
  expect(before.crumbs).toEqual(['Arsip', `${P}F`]);
  d1 = rowOf(page, `${P}D1`);
  await openRowMenu(page, d1);
  await pickMenuItem(page, /^Pindahkan$/);
  modal = visibleModal(page);
  await expect(modal.locator('.ant-modal-title')).toContainText(`Pindahkan - ${P}D1`, { timeout: 15000 });
  await app.settle();
  expect(await snap(page), 'modal terbuka (di F): breadcrumb/pilihan tetap').toEqual(before);
  await modal.locator('button', { hasText: /^Batal$/ }).click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  await page.waitForTimeout(500);
  expect(await snap(page), 'sesudah Batal (di F)').toEqual(before);
  await expect(d1).not.toHaveClass(/ant-table-row-selected/);

  // tidak ada permintaan tulis ke archives (hanya GET); DB fixture tidak berubah
  expect(calls.every((c) => c), 'jejak GET dicatat').toBe(true);
  expect(fx('inspect'), 'baris fixture tidak berubah (Batal, bukan Pindahkan)').toEqual(dbBefore);
});

test('[default] ED-1072 AC-5: mode Pilih dengan 0 terpilih: ⋯ D1 lalu Info (dan Pindahkan/Batal): jumlah terpilih tetap 0', async ({ app, page }) => {
  await openRoot(page, app);
  await search(page, app, `${P}D`);
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).click();
  await page.waitForTimeout(400);
  const d1 = rowOf(page, `${P}D1`);
  const before = await snap(page);
  expect(before.picked, 'mode Pilih, 0 dipilih').toBe(0);
  expect(await isChecked(d1)).toBe(false);

  await openRowMenu(page, d1);
  expect(await pickedCount(page), 'sesudah klik ⋯').toBe(0);
  expect(await isChecked(d1), 'checkbox D1 kosong sesudah ⋯').toBe(false);
  await shot(app, page, 'AC5-select-menu-terbuka');
  expect(await snap(page)).toEqual(before);

  await pickMenuItem(page, /^Info$/);
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  await app.settle();
  expect(await pickedCount(page), 'sesudah klik item Info').toBe(0);
  expect(await isChecked(d1), 'checkbox D1 kosong sesudah Info').toBe(false);
  expect(await snap(page)).toEqual(before);
  await closeDrawer(page);
  expect(await snap(page), 'sesudah drawer ditutup').toEqual(before);

  // Pindahkan lalu Batal di mode Pilih
  await openRowMenu(page, d1);
  await pickMenuItem(page, /^Pindahkan$/);
  const modal = visibleModal(page);
  await expect(modal.locator('.ant-modal-title')).toContainText(`Pindahkan - ${P}D1`, { timeout: 15000 });
  expect(await pickedCount(page), 'modal Pindahkan terbuka').toBe(0);
  await modal.locator('button', { hasText: /^Batal$/ }).click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  await page.waitForTimeout(400);
  expect(await snap(page), 'sesudah Batal').toEqual(before);
  expect(await isChecked(d1)).toBe(false);

  // keluar mode Pilih (0 terpilih = tanpa konfirmasi)
  await page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first().click();
  await page.waitForTimeout(400);
  expect(await pickedCount(page), 'keluar mode Pilih').toBeNull();
});

test('[default] ED-1072 AC-6 (regresi): klik teks/ikon Nama, Tipe, ikon Document Verified, Tag status → D terpilih + breadcrumb = lokasi D; mode Pilih tetap men-toggle', async ({ app, page }) => {
  await openRoot(page, app);

  const targets = [
    ['ikon file di sel Nama (di luar tautan)', 'D1', (row) => row.locator('td').nth(1).locator('.anticon-file')],
    ['sel Tipe', 'D1', (row) => row.locator('td').filter({ hasText: /^Pengantaran Pesanan$/ })],
    ['ikon Document Verified (belum terverifikasi)', 'D1', (row) => row.locator('td .anticon-close-circle')],
    ['Tag status "Diserahkan" di sel Nama', 'D2', (row) => row.locator('.ant-tag')],
  ];
  for (const [label, who, pick] of targets) {
    await page.locator('.archive-page #query').first().fill('');
    await search(page, app, `${P}D`); // pencarian baru = daftar baru, pilihan + breadcrumb direset
    const before = await snap(page);
    expect(before.crumbs, `${label}: mulai di root`).toEqual(['Arsip']);
    expect(before.selected).toEqual([]);
    const row = rowOf(page, `${P}${who}`);
    await click(pick(row));
    await page.waitForTimeout(400);
    await expect(row, `${label}: ${who} terpilih`).toHaveClass(/ant-table-row-selected/);
    const sel = await selectedRows(page);
    expect(sel.length, `${label}: hanya satu baris terpilih`).toBe(1);
    expect(sel[0]).toContain(`${P}${who}`);
    expect(await crumbs(page), `${label}: breadcrumb = lokasi D`).toEqual(['Arsip', `${P}F`]);
    await shot(app, page, `AC6-klik-${who}-${label.replace(/\W+/g, '-').slice(0, 24)}`);
  }

  // mode Pilih: klik sel D1 men-toggle
  await page.locator('.archive-page #query').first().fill('');
  await search(page, app, `${P}D`);
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).click();
  await page.waitForTimeout(400);
  const d1 = rowOf(page, `${P}D1`);
  const tipe = d1.locator('td').filter({ hasText: /^Pengantaran Pesanan$/ });
  expect(await pickedCount(page)).toBe(0);
  await click(tipe);
  await page.waitForTimeout(300);
  expect(await pickedCount(page), 'klik pertama: 1 dipilih').toBe(1);
  expect(await isChecked(d1)).toBe(true);
  expect(await crumbs(page), 'mode Pilih: breadcrumb tidak diganti').toEqual(['Arsip']);
  await shot(app, page, 'AC6-select-toggle-1');
  await click(tipe);
  await page.waitForTimeout(300);
  expect(await pickedCount(page), 'klik kedua: kembali 0').toBe(0);
  expect(await isChecked(d1)).toBe(false);
});

test('[default] ED-1072 AC-7 (regresi folder): Nama folder + ikon perisai membuka folder; ⋯ folder hanya menu; Info/Lihat/Opname bekerja; mode Pilih terkunci', async ({ app, page }) => {
  const dbBefore = fx('inspect');
  const calls = trace(page);
  await openRoot(page, app);

  // Nama folder F membuka F
  const r1 = await openFolder(page, app, `${P}F`);
  expect(rowsOf(r1).length).toBeGreaterThan(0);
  expect(await crumbs(page)).toEqual(['Arsip', `${P}F`]);
  await page.locator('.archive-page .breadcrumbs .ant-breadcrumb-link', { hasText: /^Arsip$/ }).click();
  await app.settle();
  expect(await crumbs(page)).toEqual(['Arsip']);

  // ikon perisai FP membuka FP (folder ber-permission aktif, QA_USER berhak)
  const idFP = ids()[`${P}FP`];
  const respFP = listResponse(page, (u) => (u.searchParams.get('idArchive') || u.searchParams.get('id_archive')) === idFP);
  const shield = rowOf(page, `${P}FP`).locator('.anticon-safety-certificate');
  await expect(shield, 'ikon perisai FP tampil').toBeVisible();
  await click(shield);
  expect((await respFP).status, 'klik perisai membuka FP').toBe(200);
  await app.settle();
  expect(await crumbs(page)).toEqual(['Arsip', `${P}FP`]);
  await page.locator('.archive-page .breadcrumbs .ant-breadcrumb-link', { hasText: /^Arsip$/ }).click();
  await app.settle();

  // ⋯ folder F: menu saja, folder tidak dibuka
  const f = rowOf(page, `${P}F`);
  const nBefore = folderCalls(calls).length;
  const before = await snap(page);
  const items = await openRowMenu(page, f);
  expect(items, 'menu ⋯ folder F (QA_USER)').toEqual(expect.arrayContaining(['Info', 'Pindahkan', 'Lihat', 'Opname']));
  await shot(app, page, 'AC7-menu-folder');
  expect(await snap(page), '⋯ folder: tidak ada perubahan').toEqual(before);
  expect(folderCalls(calls).length, '⋯ folder tidak membuka folder (tanpa GET archives?idArchive)').toBe(nBefore);

  // Info
  const infoF = infoResponse(page, ids()[`${P}F`]);
  await pickMenuItem(page, /^Info$/);
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  await expect(drawerTitle(page), 'drawer Info: judul "Info" + subjudul = nama folder F (FE 41480e37c)').toHaveText(new RegExp(`^Info\\s*${P}F$`));
  expect((await infoF).status(), 'Info folder F: GET archives/history/{id F}').toBe(200);
  expect(folderCalls(calls).length, 'Info tidak membuka folder').toBe(nBefore);
  expect(await crumbs(page)).toEqual(['Arsip']);
  await closeDrawer(page);

  // Lihat (drawer ubah folder; ditutup tanpa simpan)
  const showF = page.waitForResponse((r) => r.request().method() === 'GET' && new RegExp(`/document-archive/archives/${ids()[`${P}F`]}(\\?|$)`).test(r.url()), { timeout: 30000 });
  await openRowMenu(page, f);
  await pickMenuItem(page, /^(Lihat|View)$/);
  await drawer(page).waitFor({ state: 'visible', timeout: 15000 });
  expect((await showF).status(), 'Lihat folder F: GET archives/{id F}').toBe(200);
  await drawer(page).locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  await expect
    .poll(() => drawer(page).locator('input').evaluateAll((els) => els.map((e) => e.value)), { timeout: 15000, message: 'drawer Lihat memuat nama F di salah satu isian' })
    .toContain(`${P}F`);
  expect(folderCalls(calls).length, 'Lihat tidak membuka folder').toBe(nBefore);
  await closeDrawer(page);

  // Opname (modal pilih folder; ditutup tanpa Lanjut)
  await openRowMenu(page, f);
  await pickMenuItem(page, /^Opname$/);
  const modal = visibleModal(page);
  await expect(modal, 'modal Opname F').toContainText(`${P}F`, { timeout: 15000 });
  await app.settle();
  await shot(app, page, 'AC7-modal-opname');
  expect(folderCalls(calls).length, 'Opname tidak membuka folder').toBe(nBefore);
  await modal.locator('button', { hasText: /^Batal$/ }).click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  await page.waitForTimeout(400);
  expect(await snap(page)).toEqual(before);

  // mode Pilih: folder terpilih tetap terkunci (klik Nama tidak membuka)
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).click();
  await page.waitForTimeout(400);
  const fe = rowOf(page, `${P}FE`);
  await fe.locator('input[type="checkbox"]').check();
  await page.waitForTimeout(300);
  expect(await pickedCount(page), 'FE dicentang').toBe(1);
  const n2 = folderCalls(calls).length;
  await fe.locator('td.ant-table-cell-ellipsis').first().click({ timeout: 5000 }).catch(() => null);
  await page.waitForTimeout(600);
  expect(folderCalls(calls).length, 'klik Nama folder terpilih (terkunci): tidak membuka').toBe(n2);
  expect(await crumbs(page)).toEqual(['Arsip']);
  expect(await pickedCount(page)).toBe(1);
  await shot(app, page, 'AC7-select-terkunci');

  // keluar mode Pilih: hapus centang dulu (0 terpilih = tanpa konfirmasi)
  await fe.locator('input[type="checkbox"]').uncheck();
  await page.waitForTimeout(300);
  expect(await pickedCount(page)).toBe(0);
  await page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first().click();
  await page.waitForTimeout(400);

  expect(fx('inspect'), 'baris fixture tidak berubah').toEqual(dbBefore);
});

test('[default] ED-1072 AC-8 (ED-1027 AC-27): klik V/T folder → modal verifikasi folder; folder tidak dibuka; pilihan + breadcrumb tetap', async ({ app, page }) => {
  const calls = trace(page);
  await openRoot(page, app);

  // --- mode biasa di root: V/T F ("0/2")
  const f = rowOf(page, `${P}F`);
  const vt = f.locator('button.ant-btn-link');
  await expect(vt, 'V/T folder F = 0/2').toHaveText(/^0\s*\/\s*2$/);
  const nBefore = folderCalls(calls).length;
  const before = await snap(page);
  await vt.click();
  const modal = visibleModal(page);
  await expect(modal, 'modal verifikasi folder F').toContainText(`${P}F`, { timeout: 15000 });
  await expect(modal).toContainText(/2 dokumen/);
  await app.settle();
  await shot(app, page, 'AC8-modal-verifikasi-F');
  expect(folderCalls(calls).length, 'V/T tidak membuka folder').toBe(nBefore);
  expect(await snap(page), 'V/T (mode biasa): breadcrumb + pilihan tetap').toEqual(before);
  await expect(f).not.toHaveClass(/ant-table-row-selected/);
  await closeModalX(page);
  expect(await snap(page), 'sesudah modal ditutup').toEqual(before);

  // --- mode Pilih di root: FE dicentang (1 dipilih), klik V/T F
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).click();
  await page.waitForTimeout(400);
  const fe = rowOf(page, `${P}FE`);
  await fe.locator('input[type="checkbox"]').check();
  await page.waitForTimeout(300);
  const beforeSel = await snap(page);
  expect(beforeSel.picked).toBe(1);
  const n2 = folderCalls(calls).length;
  await rowOf(page, `${P}F`).locator('button.ant-btn-link').click();
  await expect(visibleModal(page)).toContainText(`${P}F`, { timeout: 15000 });
  await app.settle();
  await shot(app, page, 'AC8-modal-verifikasi-mode-pilih');
  expect(folderCalls(calls).length, 'V/T mode Pilih tidak membuka folder').toBe(n2);
  expect(await snap(page), 'V/T (mode Pilih): "1 dipilih" tetap, F tidak ikut terpilih').toEqual(beforeSel);
  await closeModalX(page);
  await fe.locator('input[type="checkbox"]').uncheck();
  await page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first().click();
  await page.waitForTimeout(400);

  // --- di dalam F, mode Pilih dengan D1 terpilih: V/T subfolder S
  await openFolder(page, app, `${P}F`);
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).click();
  await page.waitForTimeout(400);
  const d1 = rowOf(page, `${P}D1`);
  await d1.locator('input[type="checkbox"]').check();
  await page.waitForTimeout(300);
  const beforeF = await snap(page);
  expect(beforeF.picked).toBe(1);
  expect(beforeF.crumbs).toEqual(['Arsip', `${P}F`]);
  const n3 = folderCalls(calls).length;
  await rowOf(page, `${P}S`).locator('button.ant-btn-link').click();
  await expect(visibleModal(page)).toContainText(`${P}S`, { timeout: 15000 });
  await app.settle();
  expect(folderCalls(calls).length, 'V/T subfolder tidak membuka subfolder').toBe(n3);
  expect(await snap(page), 'V/T subfolder di mode Pilih (1 dipilih = D1): tetap').toEqual(beforeF);
  expect(await isChecked(d1)).toBe(true);
  await closeModalX(page);
  await d1.locator('input[type="checkbox"]').uncheck();
  await page.locator('.archive-page button', { hasText: /^(Batal Pilih|Exit Select)$/ }).first().click();
  await page.waitForTimeout(400);
});

test('[default] ED-1072 AC-9 (K-2 a): klik kanan nomor transaksi D → "Open in new tab": tab baru ditambahkan; D tidak terpilih, breadcrumb tetap', async ({ app, page, monitor }) => {
  const real = fx('real-doc');
  expect(real, 'ada dokumen DO nyata di dalam folder (baca-saja) untuk AC-9').not.toBeNull();
  monitor.note(`AC-9 memakai dokumen DO nyata (baca-saja) ${real.transaction_no} di folder ${JSON.parse(real.breadcrumbs).map((b) => b.label).join(' > ')}`);

  await openRoot(page, app);
  const r = await search(page, app, real.transaction_no);
  const row = rowsOf(r).find((x) => x.id_archive === real.id_archive);
  expect(row, 'dokumen nyata ditemukan pencarian root').toBeTruthy();
  expect((row.breadcrumbs || []).length, 'baris list membawa breadcrumbs (gejala terlihat)').toBeGreaterThan(0);

  const tabs = page.locator('.app-tab .ant-tabs-tab');
  const nTabs = await tabs.count();
  const before = await snap(page);
  expect(before.crumbs).toEqual(['Arsip']);
  const doc = rowOf(page, real.transaction_no);
  const link = doc.locator('a[href]').first();
  await expect(link, 'nomor transaksi berupa tautan').toHaveText(real.transaction_no);
  await link.click({ button: 'right' });
  const ctx = page.locator('.context-menu li', { hasText: /ditab baru|new tab/i });
  await ctx.waitFor({ state: 'visible', timeout: 10000 });
  await shot(app, page, 'AC9-menu-konteks');
  expect(await snap(page), 'klik kanan: breadcrumb/pilihan tetap').toEqual(before);
  await ctx.click();
  await page.waitForTimeout(800);
  expect(await tabs.count(), 'tab aplikasi baru ditambahkan').toBe(nTabs + 1);
  const titles = (await tabs.allInnerTexts()).map((t) => t.trim());
  monitor.note(`tab sesudah Open in new tab: ${titles.join(' | ')}`);
  expect(await snap(page), 'sesudah Open in new tab: D tidak terpilih, breadcrumb tetap').toEqual(before);
  await expect(doc).not.toHaveClass(/ant-table-row-selected/);
  await app.settle();
  await shot(app, page, 'AC9-tab-baru-ditambahkan');

  // pulihkan: tutup tab yang ditambahkan
  await page.locator('.app-tab .ant-tabs-tab-remove').last().click();
  await page.waitForTimeout(500);
  expect(await tabs.count(), 'tab tambahan ditutup lagi').toBe(nTabs);
});

test('[default] ED-1072 K-2 a (sintetis): isi popover "+N" di sel Nama dokumen tidak memilih baris / mengganti breadcrumb', async ({ app, page, monitor }) => {
  // BE selalu mengirim SATU referensi per baris; popover "+N" tidak muncul dari data nyata. Respons list diubah di jalur jaringan
  // (HANYA di test ini, tanpa menyentuh BE/DB): D1 mendapat dua referensi tambahan supaya popover bisa diuji.
  monitor.note('respons GET archives diubah di jaringan (page.route): D1 mendapat 2 referensi tambahan agar tag "+2" muncul');
  await page.route(LIST_RE, async (route) => {
    const resp = await route.fetch();
    let json;
    try {
      json = await resp.json();
    } catch (e) {
      return route.fulfill({ response: resp });
    }
    const data = (json.result && json.result.data) || [];
    data.forEach((x) => {
      if (Array.isArray(x.name) && x.name[0] && x.name[0].transaction_no === `${P}D1`) {
        x.name.push({ id_transaction: 'QA72TXMOCK0001', transaction_no: `${P}MOCK-1`, transaction_type: 7 });
        x.name.push({ id_transaction: 'QA72TXMOCK0002', transaction_no: `${P}MOCK-2`, transaction_type: 7 });
      }
    });
    return route.fulfill({ response: resp, json });
  });

  await openRoot(page, app);
  await search(page, app, `${P}D`);
  const d1 = rowOf(page, `${P}D1`);
  const before = await snap(page);
  const tag = d1.locator('.ant-tag', { hasText: /^\+2$/ });
  await expect(tag, 'tag "+2" muncul').toBeVisible({ timeout: 15000 });
  await tag.click();
  const pop = page.locator('.ant-popover:not(.context-menu):not(.ant-popover-hidden)').last();
  await pop.waitFor({ state: 'visible', timeout: 10000 });
  await expect(pop).toContainText(`${P}MOCK-1`);
  await shot(app, page, 'K2-popover-terbuka');
  expect(await snap(page), 'klik tag +2 membuka popover saja').toEqual(before);

  // klik bagian popover yang bukan tautan (judul + padding isi; portal ke body): bukan klik sel
  await pop.locator('.ant-popover-title').click();
  await page.waitForTimeout(300);
  expect(await snap(page), 'klik judul popover (portal): D1 tidak terpilih, breadcrumb tetap').toEqual(before);
  await pop.locator('.ant-popover-inner-content').click({ position: { x: 4, y: 4 } });
  await page.waitForTimeout(300);
  expect(await snap(page), 'klik padding isi popover (portal): D1 tidak terpilih, breadcrumb tetap').toEqual(before);
  await expect(d1).not.toHaveClass(/ant-table-row-selected/);
  await expect(pop, 'popover masih terbuka (tidak ada navigasi)').toBeVisible();

  // klik kanan tautan di popover: menu konteks portal; Escape menutup. Tidak ada perubahan.
  await pop.locator('a[href]').first().click({ button: 'right' });
  await page.waitForTimeout(400);
  expect(await snap(page), 'klik kanan tautan di popover').toEqual(before);
  await page.keyboard.press('Escape');
});
