'use strict';

/**
 * ED-1250 - Archive: filter Verification Status (Verified / Unverified) di list dan di picker Pindahkan / Pilih Folder.
 * Aturan (spec.md, Revisi Gate 1): filter HANYA menyembunyikan baris dokumen yang statusnya tidak sama; baris folder tidak pernah disembunyikan;
 * struktur folder tidak berubah (tetap di folder / root); nilai tetap aktif saat buka folder, breadcrumb, "Back to Archive"; Reset
 * mengosongkan dan tetap di folder; scan QR mengosongkan semua filter; picker dibuka dengan filter kosong (K-5 a) dan berperilaku sama (K-2 b).
 *
 *   AC-13  : popover memuat select (id "Status Verifikasi" / en "Verification Status") tepat sesudah Type, 2 opsi, kosong; Cari tanpa memilih
 *            = request tanpa key; Display Setting Displayed Filters memuat entri                                  (id + en)
 *   AC-14  : root pilih Unverified -> 1 GET search={"verificationStatus":"UNVERIFIED"} tanpa id_archive; semua folder tetap; V/T & ringkasan sama;
 *            di folder pilih Verified -> id_archive tetap
 *   AC-15  : buka subfolder / breadcrumb / Back to Archive membawa key; Reset tetap di folder; scan QR -> hanya query
 *   AC-16  : filter + Name / Type -> id_archive null (datar); buka folder dari hasil -> hanya verificationStatus
 *   AC-18  : picker Pindahkan (dokumen) dan Pilih Folder pindah massal: kosong saat dibuka, root / folder / navigasi / Reset / + Name, folder
 *            tetap semua & bisa dipilih, folder terpilih / tanpa View tidak bisa dibuka, Pindahkan sukses dengan filter aktif
 *   AC-19  : [user2] tanpa Opname Document: tanpa filter (halaman, picker, Display Setting), simpan Display Setting sukses, tanpa 5xx
 *   AC-20  : pemegang menyembunyikan / menampilkan entri di Displayed Filters -> halaman & picker mengikuti; baris cds dipulihkan
 *   AC-21  : folder tanpa subfolder dan tanpa dokumen verified + Verified -> tabel kosong (halaman & picker), folder induknya tetap tampil
 *   AC-22  : regresi dengan filter aktif: kolom Document Verified, tautan V/T + modal folder, ringkasan + modal All, halaman 2, refresh
 *            (Reload, Letakkan / Serahkan / Terima), Pindahkan folder fixture memindahkan seluruh isinya (SQL)
 *   AC-24  : (sebagian) tabel picker/halaman di-mount ulang saat filter berganti (listKey memuat search), tidak saat refresh yang sama
 *
 * Data: fixtures.json -> fixture.php (berawalan "QA50-", is_verified langsung, tanpa sesi opname). Profil [default] = QA_USER (role 3,
 * Opname Document, bahasa ID); [user2] = QA_USER2 di preset QA50-ROLE-E2E-NOOD (tanpa Opname Document, bahasa EN). Jalankan:
 *   scripts/e2e/run.sh ED-1250 --profile default,user2 --workers 1
 * Mutasi hanya pada data fixture / baris cds documentArchive yang dipulihkan persis (cds-restore) / bahasa QA_USER (lang-restore).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const PATH = '/archives';
const P = 'QA50-';
const LIST_RE = /\/document-archive\/archives(\?|$)/;
const VERIF_RE = /\/document-archive\/verifications\//;
const PUTIN_RE = /\/document-archive\/documents\/put-in/;
const FILTER = 'verificationStatus';
const LBL = { id: 'Status Verifikasi', en: 'Verification Status' };
const OPT = {
  id: { VERIFIED: 'Terverifikasi', UNVERIFIED: 'Belum terverifikasi' },
  en: { VERIFIED: 'Verified', UNVERIFIED: 'Unverified' },
};
const COL = { id: 'Dokumen Terverifikasi', en: 'Document Verified' };
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const trimAll = (arr) => arr.map((t) => t.replace(/\s+/g, ' ').trim());
const fmt = (n) => Number(n).toLocaleString('en-US');
const MENU = { move: /^(Pindahkan|Move)$/ };

// ------------------------------------------------------------------------------------------------------------ fixture.php
const fx = (...args) =>
  execFileSync(config.get('PHP_BIN', 'php'), [path.join(__dirname, 'fixture.php'), ...args], {
    encoding: 'utf8',
    cwd: config.AGENTIC_DIR,
    timeout: 90000,
    env: { ...process.env },
  }).trim();
const fxJson = (...args) => JSON.parse(fx(...args).split('\n').pop());
const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));
const prop = (name) => fxJson('prop', name);
const idOf = (name) => ids()[P + name];

/** baris yang HARUS tampil di satu folder menurut tabel mentah: semua folder + dokumen yang statusnya sama */
function expectedKids(parent, status) {
  const kids = fxJson('children', parent);
  return kids.filter((k) => k.type === 1 || (status === 'VERIFIED' ? k.is_verified === 1 : status === 'UNVERIFIED' ? k.is_verified !== 1 : true)).map((k) => k.name);
}

// ------------------------------------------------------------------------------------------------------------ request / respons
const rowName = (row) => (Array.isArray(row.name) ? (row.name[0] || {}).transaction_no : row.name);
const rowsOf = (resp) => (resp.body && resp.body.result && resp.body.result.data) || [];
const namesOf = (resp) => rowsOf(resp).map(rowName);
const isFolder = (row) => row.type === 'Folder';
const folderParam = (u) => u.searchParams.get('idArchive') || u.searchParams.get('id_archive');
const searchOf = (u) => {
  const raw = u.searchParams.get('search');
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch (e) {
    return { _raw: raw };
  }
};
/** sama menurut isi (urutan key tidak dihitung) */
const canon = (v) => (Array.isArray(v) ? v.map(canon) : v && typeof v === 'object' ? Object.fromEntries(Object.keys(v).sort().map((k) => [k, canon(v[k])])) : v);
const sameJson = (a, b) => JSON.stringify(canon(a)) === JSON.stringify(canon(b));
const vtText = (dv) => (dv === null || dv === undefined ? '-' : `${fmt(dv.verified)}/${fmt(dv.total)}`);

const listResponse = (page, pred, timeout = 45000) =>
  page
    .waitForResponse((r) => r.request().method() === 'GET' && LIST_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout })
    .then(async (r) => ({ status: r.status(), url: r.url(), u: new URL(r.url()), body: await r.json().catch(() => null) }));
/** respons list dengan search PERSIS `search` (null = tanpa search) dan folder `folder` (undefined = abaikan, null = root) */
const listWith = (page, { search, folder }, timeout) =>
  listResponse(
    page,
    (u) => sameJson(searchOf(u), search) && (folder === undefined || (folder === null ? !folderParam(u) : folderParam(u) === folder)),
    timeout,
  );

/** Catat setiap GET list / verifications / POST put-in untuk menghitung request sesudah aksi. */
function trace(page, monitor) {
  const gets = [];
  const verifs = [];
  const puts = [];
  const posts = [];
  page.on('request', (r) => {
    const u = r.url();
    if (r.method() === 'GET' && LIST_RE.test(u)) {
      const url = new URL(u);
      gets.push({ folder: folderParam(url), search: searchOf(url), raw: url.searchParams.get('search'), page: url.searchParams.get('page'), pagination: url.searchParams.get('pagination') });
    }
    if (r.method() === 'GET' && VERIF_RE.test(u)) verifs.push(u);
    if (r.method() === 'PUT' && /display/i.test(u)) puts.push(u);
    if (r.method() === 'POST' && PUTIN_RE.test(u)) posts.push(u);
  });
  page.on('response', (r) => {
    const u = r.url();
    if (/\/document-archive\//.test(u) && monitor) {
      monitor.note(`API ${r.status()} ${r.request().method()} ${decodeURIComponent(u.replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 220)}`);
    }
  });
  return {
    gets,
    verifs,
    puts,
    posts,
    mark: () => ({ g: gets.length, v: verifs.length, p: posts.length }),
    getsSince: (m) => gets.slice(m.g),
    verifsSince: (m) => verifs.slice(m.v),
    postsSince: (m) => posts.slice(m.p),
    last: () => gets[gets.length - 1],
  };
}

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  expect(api.length, 'ada panggilan API v5 tercatat (monitor aktif)').toBeGreaterThan(0);
}

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

const noErrorToast = async (page, label) => {
  await page.waitForTimeout(1000);
  await expect(page.locator('.ant-message-error, .ant-message-warning, .ant-notification-notice-error, .ant-notification-notice-warning'), `${label}: tanpa toast galat/peringatan`).toHaveCount(0);
};

// ------------------------------------------------------------------------------------------------------------ DOM halaman
const pageRows = (page) => page.locator('.archive-page .ant-table-row');
const rowIn = (rows, name) => rows.filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) }).first();
const rowOf = (page, name) => rowIn(pageRows(page), name);
const modalOf = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
const searchBarOf = (scope) => scope.locator('.search-bar').first();
const pageBar = (page) => page.locator('.archive-page .search-bar').first();
const crumbOf = (scope) => scope.locator('.breadcrumbs .ant-breadcrumb:visible').first();
const crumbText = async (scope) => (await crumbOf(scope).innerText()).replace(/\s+/g, ' ').trim();
const reloadBtn = (page) => page.locator('.archive-page button.button-filter-wrapper').first();

/** Buka layar Archive (muat ulang penuh) dan kembalikan respons list root. */
async function openArchive(app, page) {
  const rootResp = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
  await app.open(PATH, { waitFor: '.archive-page .ant-table' });
  const root = await rootResp;
  expect(root.status, 'GET archives (root)').toBe(200);
  await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
  await app.settle();
  return root;
}

/** Buka folder dari daftar (klik sel nama); kembalikan respons. Hasil bisa 403 (layar akses ditolak). */
async function openFolder(page, rows, name, pred) {
  const resp = listResponse(page, pred || ((u) => !!folderParam(u)));
  await rowIn(rows, name).locator('td.cursor-pointer').first().click();
  return resp;
}

/** Klik halaman `n` di pagination `scope`; kembalikan respons. */
async function gotoPage(page, scope, n) {
  const resp = listResponse(page, (u) => u.searchParams.get('page') === String(n));
  await scope.locator(`li.ant-pagination-item-${n}`).first().click();
  return resp;
}

/** Cari baris `name` di halaman-halaman daftar `scope` (pagination); berhenti di halaman berisi baris itu. */
async function findOnPages(page, scope, rows, name, maxPages = 6) {
  for (let n = 1; n <= maxPages; n += 1) {
    if ((await rowIn(rows, name).count()) > 0 && (await rowIn(rows, name).isVisible())) return n;
    const next = scope.locator(`li.ant-pagination-item-${n + 1}`).first();
    if ((await next.count()) === 0) break;
    await gotoPage(page, scope, n + 1);
    await page.waitForTimeout(500);
  }
  throw new Error(`baris ${name} tidak ditemukan di ${maxPages} halaman pertama`);
}

/** Tabel `rows` = isi respons (jumlah baris terlihat dan nama tiap baris). */
async function expectTableIs(page, rows, resp, label) {
  const names = namesOf(resp);
  await expect.poll(async () => rows.filter({ visible: true }).count(), { timeout: 15000, message: `${label}: jumlah baris tabel = respons (${names.length})` }).toBe(names.length);
  for (const n of names) await expect(rowIn(rows, n), `${label}: ${n} tampil`).toBeVisible();
}

/** Indeks kolom (0-based) menurut header tabel dalam `scope`. */
async function colIndex(scope, title) {
  const heads = trimAll(await scope.locator('.ant-table-thead th').allInnerTexts());
  return heads.indexOf(title);
}

/** Teks sel kolom `title` baris `name` (folder: V/T atau "-"). */
async function cellText(scope, rows, name, title) {
  const idx = await colIndex(scope, title);
  expect(idx, `kolom "${title}" ada`).toBeGreaterThanOrEqual(0);
  return trimAll([await rowIn(rows, name).locator('td').nth(idx).innerText()])[0];
}

/** ikon sel dokumen: 'verified' | 'unverified' | null */
async function docIcon(scope, rows, name, title) {
  const idx = await colIndex(scope, title);
  const cell = rowIn(rows, name).locator('td').nth(idx);
  if ((await cell.locator('.anticon-check-circle').count()) > 0) return 'verified';
  if ((await cell.locator('.anticon-close-circle').count()) > 0) return 'unverified';
  return null;
}

/** Semua baris respons: folder tetap, dokumen sesuai status (respons dan ikon di DOM). */
async function expectStatusRows(scope, rows, resp, status, title, label) {
  const want = status === 'VERIFIED' ? 1 : 0;
  for (const row of rowsOf(resp)) {
    if (isFolder(row)) continue;
    expect(row.document_verified.verified, `${label}: dokumen ${rowName(row)} respons verified=${want}`).toBe(want);
    expect(await docIcon(scope, rows, rowName(row), title), `${label}: ikon ${rowName(row)}`).toBe(status === 'VERIFIED' ? 'verified' : 'unverified');
  }
}

// ------------------------------------------------------------------------------------------------------------ popover filter
/** label tiap item popover (placeholder select / isian / rentang tanggal; teks untuk kotak centang), urut seperti di layar */
const popoverLabels = async (overlay) =>
  trimAll(
    await overlay.locator('.filter-item').evaluateAll((els) =>
      els.map((e) => {
        const ph = e.querySelector('.ant-select-selection-placeholder');
        if (ph) return ph.innerText.trim();
        const i = e.querySelector('input[placeholder]');
        if (i && i.getAttribute('placeholder')) return i.getAttribute('placeholder');
        return (e.innerText || '').split('\n')[0].trim();
      }),
    ),
  );

/** halaman-halaman daftar yang masih memuat folder saja (folder tampil lebih dulu): berhenti di halaman pertama yang berisi dokumen */
async function collectFolderPages(page, pager, first) {
  const out = [first];
  let cur = first;
  while (rowsOf(cur).every(isFolder) && cur.body.result.current_page < cur.body.result.last_page) {
    cur = await gotoPage(page, pager, cur.body.result.current_page + 1);
    out.push(cur);
  }
  if (out.length > 1) await gotoPage(page, pager, 1);
  return out;
}

/** tabel Displayed Filters di drawer Display Setting (desktop: dua tabel berdampingan; layar kecil: tab) */
async function filtersTable(page, drawer) {
  const tab = drawer.locator('.ant-tabs-tab', { hasText: /Filter yang Ditampilkan|Displayed Filters/ }).first();
  if (await tab.isVisible().catch(() => false)) {
    await tab.click();
    await page.waitForTimeout(500);
  }
  return drawer.locator('.ant-table').filter({ has: page.locator('th', { hasText: /Nama Filter|Filter Name/ }) }).first();
}
const dropdownOf = (page) => page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();

/** Buka popover filter pada `bar` (.search-bar halaman / picker); kembalikan overlay. */
async function openPopover(page, bar) {
  const overlay = bar.locator('.search-bar-overlay').first();
  if (!(await overlay.isVisible().catch(() => false))) await bar.locator('.search-config').first().click();
  await expect(overlay, 'popover filter terbuka').not.toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
  await overlay.locator('.ant-select').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(600);
  return overlay;
}
async function closePopover(page, bar) {
  const overlay = bar.locator('.search-bar-overlay').first();
  if (await overlay.isVisible().catch(() => false)) {
    await bar.locator('.search-config').first().click();
    await expect(overlay, 'popover filter tertutup').toHaveClass(/ant-popover-hidden/, { timeout: 10000 });
    await page.waitForTimeout(300);
  }
}
const verifSelect = (page, overlay) => overlay.locator('.ant-select').filter({ has: page.locator(`#${FILTER}`) }).first();
const selectedLabel = async (sel) => ((await sel.locator('.ant-select-selection-item').first().innerText().catch(() => '')) || '').trim();
const hasFilterField = async (page, overlay) => (await overlay.locator(`#${FILTER}`).count()) > 0;

/** Pilih opsi `label` di select `sel` (popover). */
async function chooseOption(page, sel, label) {
  await sel.locator('.ant-select-selector').click();
  const dd = dropdownOf(page);
  await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: 10000 });
  await dd.locator('.ant-select-item-option', { hasText: new RegExp(`^${esc(label)}$`) }).first().click();
  await page.waitForTimeout(200);
}
async function clearSelect(page, sel) {
  await sel.hover();
  await sel.locator('.ant-select-clear').click();
  await page.waitForTimeout(200);
}
/** Tombol Cari di popover. */
const cariBtn = (overlay) => overlay.locator('button').filter({ hasText: /^(Cari|Search)$/ }).first();
const filterOn = async (bar) => (await bar.locator('.search-config.text-primary').count()) > 0;
const resetIcon = (bar) => bar.locator('.reset-filter').first();

/**
 * Terapkan filter: buka popover, isi, klik Cari. `status` null = kosongkan select. `fill` = {label: text} untuk input teks popover.
 * Mengembalikan promise respons yang dibuat SEBELUM klik (pemanggil menyediakan predikatnya).
 */
async function applyFilter(page, bar, { status, lang = 'id', name, clear }, wait) {
  const overlay = await openPopover(page, bar);
  const sel = verifSelect(page, overlay);
  if (status) await chooseOption(page, sel, OPT[lang][status]);
  if (clear) await clearSelect(page, sel);
  if (name !== undefined) {
    const nameInput = overlay.locator('input#name').first();
    await nameInput.click();
    await nameInput.fill(name);
  }
  const resp = wait ? wait() : null;
  await cariBtn(overlay).click();
  return resp;
}

/** Nilai select di popover `bar` (membuka lalu menutup popover). */
async function readFilterValue(page, bar) {
  const overlay = await openPopover(page, bar);
  const value = await selectedLabel(verifSelect(page, overlay));
  const nameVal = await overlay.locator('input#name').first().inputValue().catch(() => null);
  await closePopover(page, bar);
  return { value, nameVal };
}

// ------------------------------------------------------------------------------------------------------------ row menu / picker
async function pickRowMenu(page, rows, name, itemRegex) {
  const row = rowIn(rows, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  const btn = row.locator('button.btn-action');
  expect(await btn.count(), `baris ${name} punya tombol ⋯`).toBeGreaterThan(0);
  await btn.click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
}

/** ⋯ Pindahkan <name>: kembalikan {modal, rows, root} sesudah picker memuat root (picker baru = tanpa filter, K-5 a). */
async function openMove(app, page, name) {
  const root = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
  await pickRowMenu(page, pageRows(page), name, MENU.move);
  const modal = modalOf(page);
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  const rr = await root;
  await app.settle();
  await expect(modal.locator('.ant-modal-title'), `judul modal memuat ${name}`).toContainText(name);
  return { modal, rows: modal.locator('.ant-table-row'), root: rr, bar: searchBarOf(modal) };
}

/** Mode Pilih -> centang nama-nama -> Pindahkan -> (Konfirmasi) Lanjut -> Pilih Folder. */
async function openBulkPick(app, page, names) {
  await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
  for (const n of names) await rowIn(pageRows(page), n).locator('input[type="checkbox"]').first().check();
  await page.locator('.archive-page button', { hasText: /^(Pindahkan|Move)$/ }).first().click();
  const bulk = modalOf(page);
  await bulk.waitFor({ state: 'visible', timeout: 15000 });
  await app.settle();
  const root = listResponse(page, (u) => !folderParam(u) && !searchOf(u));
  await bulk.locator('button.ant-btn-primary', { hasText: /^(Lanjut|Next)$/ }).first().click();
  const rr = await root;
  await app.settle();
  await expect(bulk.locator('.search-bar input').first(), 'langkah Pilih Folder: kolom Cari picker tampil').toBeVisible();
  return { modal: bulk, rows: bulk.locator('.ant-table-row:visible'), root: rr, bar: bulk.locator('.search-bar:visible').first() };
}

async function closeModalBatal(page, modal) {
  await modal.locator('.ant-modal-close').first().click();
  await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'modal tertutup').toHaveCount(0, { timeout: 10000 });
  await page.waitForTimeout(500);
}

/** Pilih baris tujuan (radio) lalu klik Pindahkan di `scope`; kembalikan {status, reqBody, json}. */
async function selectTargetAndMove(scope, rows, page, targetName) {
  const row = rowIn(rows, targetName);
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

/** Tanda DOM pada tbody tabel `scope`: hilang bila tabel di-mount ulang (key berganti). */
const markTable = (scope) => scope.locator('.ant-table-body, .ant-table-content').first().evaluate((el) => { el.__ed1250 = 'x'; });
const tableMarked = (scope) => scope.locator('.ant-table-body, .ant-table-content').first().evaluate((el) => el.__ed1250 === 'x');

const scan = async (page, code) => {
  await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur()); // fokus di luar input
  await page.waitForTimeout(300);
  await page.keyboard.type(`${code}\n`, { delay: 0 });
};

// ====================================================================================================================
test.describe('ED-1250 filter Verification Status (e2e)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
  });

  // ---------------------------------------------------------------------------------------------- AC-13 (id)
  test('[default] ED-1250 AC-13 (id): popover memuat "Status Verifikasi" sesudah Tipe, 2 opsi, kosong; Cari tanpa memilih = tanpa key; folder / hasil cari / Display Setting tanpa error', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    const calls = trace(page, monitor);
    const od = fxJson('has-od');
    expect(od.QA_USER, 'prasyarat: QA_USER pemegang Opname Document (role sistem)').toBe(true);
    expect(od.QA_USER2, 'prasyarat: QA_USER2 (preset) TANPA Opname Document').toBe(false);

    const first = await openArchive(app, page);
    const queries = first.body.result.queries.map((q) => q.data_index);
    monitor.note(`queries root: ${JSON.stringify(queries)}`);
    expect(queries.indexOf(FILTER), 'respons: verificationStatus tepat sesudah type').toBe(queries.indexOf('type') + 1);
    expect(queries.indexOf('type'), 'respons: type ada').toBeGreaterThanOrEqual(0);

    const bar = pageBar(page);
    const overlay = await openPopover(page, bar);
    const labels = await popoverLabels(overlay);
    monitor.note(`label popover: ${JSON.stringify(labels)}`);
    const iType = labels.findIndex((l) => /^(Tipe|Type)$/.test(l));
    expect(iType, 'popover memuat Tipe').toBeGreaterThanOrEqual(0);
    expect(labels[iType + 1], 'tepat sesudah Tipe: "Status Verifikasi" (id)').toBe(LBL.id);
    const sel = verifSelect(page, overlay);
    await expect(sel, 'select ada').toHaveCount(1);
    expect(await selectedLabel(sel), 'kosong default').toBe('');
    // 2 opsi sesuai bahasa
    await sel.locator('.ant-select-selector').click();
    const dd = dropdownOf(page);
    await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: 10000 });
    const opts = trimAll(await dd.locator('.ant-select-item-option').allInnerTexts());
    expect(opts, 'tepat 2 opsi (id)').toEqual([OPT.id.VERIFIED, OPT.id.UNVERIFIED]);
    await app.screenshot('ac13-01-popover-opsi-id');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // Cari tanpa memilih: request tanpa key
    const m = calls.mark();
    const resp = listResponse(page, (u) => !folderParam(u));
    await cariBtn(overlay).click();
    const r = await resp;
    expect(r.status, 'GET root 200').toBe(200);
    const g = calls.getsSince(m);
    expect(g.length, 'Cari tanpa memilih: tepat 1 GET').toBe(1);
    expect(g[0].raw === null || !g[0].raw.includes(FILTER), 'tanpa key verificationStatus').toBe(true);
    await app.settle();
    expect(await filterOn(bar), 'indikator filter mati').toBe(false);

    // folder A, subfolder MIX, hasil cari, semuanya tanpa error
    const rootRows = pageRows(page);
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), rootRows, `${P}A`);
    const fa = await openFolder(page, rootRows, `${P}A`);
    expect(fa.status, 'folder A 200').toBe(200);
    await app.settle();
    await expectTableIs(page, pageRows(page), fa, 'folder A (tanpa filter)');
    const fm = await openFolder(page, pageRows(page), `${P}MIX`);
    expect(fm.status, 'subfolder MIX 200').toBe(200);
    await app.settle();
    await app.screenshot('ac13-02-subfolder-tanpa-filter');
    const input = bar.locator('input').first();
    await input.click();
    await page.waitForTimeout(300);
    await page.keyboard.type(`${P}MIX-U`);
    const sr = listWith(page, { search: { query: `${P}MIX-U` } });
    await page.keyboard.press('Enter');
    const srr = await sr;
    expect(srr.status, 'hasil cari 200').toBe(200);
    await app.settle();
    expect(await crumbText(page.locator('.archive-page')), 'hasil cari datar: breadcrumb Archive').toMatch(/^Arsip$/);
    await app.screenshot('ac13-03-hasil-cari');

    // Display Setting: tab Displayed Filters memuat entri
    await resetIcon(bar).click();
    await app.settle();
    await page.locator('.archive-page button', { hasText: /Pengaturan Tampilan|Display Setting/ }).first().click();
    const drawer = page.locator('.ant-drawer-open').last();
    await drawer.waitFor({ state: 'visible', timeout: 15000 });
    await drawer.locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
    await app.settle();
    const ftable = await filtersTable(page, drawer);
    const names = trimAll(await ftable.locator('tr.ant-table-row').allInnerTexts());
    monitor.note(`Displayed Filters (id): ${JSON.stringify(names)}`);
    expect(names, 'Displayed Filters memuat "Status Verifikasi"').toContain(LBL.id);
    expect(names.indexOf(LBL.id), 'urutan: sesudah Tipe').toBe(names.findIndex((n) => /^(Tipe|Type)$/.test(n)) + 1);
    await app.screenshot('ac13-04-display-setting-displayed-filters');
    await drawer.locator('.ant-drawer-close').click();
    await expect(page.locator('.ant-drawer-open'), 'drawer menutup').toHaveCount(0, { timeout: 10000 });

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-13 (en)
  test('[default] ED-1250 AC-13 (en): label "Verification Status", opsi Verified / Unverified, Displayed Filters (bahasa QA_USER diubah sementara, dipulihkan)', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    fx('lang', 'EN');
    try {
      trace(page, monitor);
      await openArchive(app, page);
      const bar = pageBar(page);
      const overlay = await openPopover(page, bar);
      const labels = await popoverLabels(overlay);
      monitor.note(`label popover (en): ${JSON.stringify(labels)}`);
      const iType = labels.findIndex((l) => /^(Tipe|Type)$/.test(l));
      expect(labels[iType + 1], 'tepat sesudah Type: "Verification Status" (en)').toBe(LBL.en);
      const sel = verifSelect(page, overlay);
      expect(await selectedLabel(sel), 'kosong default').toBe('');
      await sel.locator('.ant-select-selector').click();
      const dd = dropdownOf(page);
      await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: 10000 });
      expect(trimAll(await dd.locator('.ant-select-item-option').allInnerTexts()), 'tepat 2 opsi (en)').toEqual([OPT.en.VERIFIED, OPT.en.UNVERIFIED]);
      await app.screenshot('ac13-05-popover-opsi-en');
      await page.keyboard.press('Escape');
      await closePopover(page, bar);

      await page.locator('.archive-page button', { hasText: /Pengaturan Tampilan|Display Setting/ }).first().click();
      const drawer = page.locator('.ant-drawer-open').last();
      await drawer.waitFor({ state: 'visible', timeout: 15000 });
      await drawer.locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      await app.settle();
      const ftable = await filtersTable(page, drawer);
      const names = trimAll(await ftable.locator('tr.ant-table-row').allInnerTexts());
      monitor.note(`Displayed Filters (en): ${JSON.stringify(names)}`);
      expect(names, 'Displayed Filters memuat "Verification Status"').toContain(LBL.en);
      await app.screenshot('ac13-06-display-setting-en');
      await drawer.locator('.ant-drawer-close').click();
      noServerError(monitor);
      monitor.check();
    } finally {
      fx('lang-restore');
    }
  });

  // ---------------------------------------------------------------------------------------------- AC-14 + AC-4/BR-5
  test('[default] ED-1250 AC-14 root: Unverified -> 1 GET search hanya key, tanpa id_archive, folder root tetap semua, dokumen hanya unverified, V/T & ringkasan sama; di folder A: Verified -> id_archive tetap, breadcrumb & tombol tambah tetap', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    const calls = trace(page, monitor);
    const first = await openArchive(app, page);
    const bar = pageBar(page);
    const sumBox = page.locator('.archive-page .ant-space').filter({ hasText: 'seluruh dokumen di sistem' }).first();
    const sumBefore = (await sumBox.innerText()).replace(/\s+/g, ' ');
    const apiSum = first.body.result.document_verified_summary;

    // root tanpa filter: seluruh folder (halaman yang masih berisi folder saja) + total menurut oracle tabel mentah
    // (root QA DB: 15 folder, ribuan dokumen: halaman dokumen tidak dijelajahi satu per satu, `total` yang dicocokkan dengan oracle)
    const rc = fxJson('root-counts');
    monitor.note(`oracle root (tabel mentah): ${JSON.stringify(rc)}`);
    expect(rc.others, 'oracle: tidak ada baris root bertipe lain').toBe(0);
    expect(first.body.result.total, 'tanpa filter: total = folder + semua dokumen root (oracle)').toBe(rc.folders + rc.verified + rc.unverified);
    const pagerSel = page.locator('.archive-page .ant-pagination').first();
    const unfiltered = await collectFolderPages(page, pagerSel, first);
    const allUnf = unfiltered.flatMap(rowsOf);
    const unfFolders = allUnf.filter(isFolder).map(rowName);
    monitor.note(`root tanpa filter: ${unfFolders.length} folder pada ${unfiltered.length} halaman; total ${first.body.result.total}`);
    expect(unfFolders.length, 'folder root = oracle').toBe(rc.folders);
    expect(unfFolders, 'root memuat folder fixture A, B, C').toEqual(expect.arrayContaining([`${P}A`, `${P}B`, `${P}C`]));
    const vtUnf = {};
    allUnf.filter(isFolder).forEach((r) => { vtUnf[rowName(r)] = vtText(r.document_verified); });

    for (const status of ['UNVERIFIED', 'VERIFIED']) {
      const m = calls.mark();
      const search = { [FILTER]: status };
      const resp1 = await applyFilter(page, bar, { status }, () => listWith(page, { search, folder: null }));
      expect(resp1.status, `${status}: GET 200`).toBe(200);
      await app.settle();
      const g = calls.getsSince(m);
      expect(g.length, `${status}: tepat 1 GET sesudah Cari`).toBe(1);
      expect(g[0].search, `${status}: search HANYA {verificationStatus}`).toEqual(search);
      expect(g[0].folder, `${status}: tanpa id_archive`).toBeNull();
      expect(await crumbText(page.locator('.archive-page')), `${status}: breadcrumb "Arsip"`).toBe('Arsip');
      expect(await filterOn(bar), `${status}: indikator filter menyala`).toBe(true);
      await expect(resetIcon(bar), `${status}: ✕ Reset muncul`).toBeVisible();
      await expectTableIs(page, pageRows(page), resp1, `root ${status} hal. 1`);
      expect(await readFilterValue(page, bar).then((v) => v.value), `${status}: popover menampilkan nilainya`).toBe(OPT.id[status]);
      expect(resp1.body.result.total, `${status}: total = folder root + dokumen root ${status} (oracle tabel mentah)`).toBe(rc.folders + (status === 'VERIFIED' ? rc.verified : rc.unverified));

      // semua folder tetap (himpunan dan urutan sama dengan tanpa filter), dokumen hanya status itu
      const pager = page.locator('.archive-page .ant-pagination').first();
      const filtered = await collectFolderPages(page, pager, resp1);
      const lastCollected = filtered[filtered.length - 1];
      const allF = filtered.flatMap(rowsOf);
      expect(allF.filter(isFolder).map(rowName), `${status}: himpunan folder root = tanpa filter (tidak ada folder disembunyikan)`).toEqual(unfFolders);
      const fDocs = allF.filter((r) => !isFolder(r));
      for (const d of fDocs) {
        expect(d.document_verified.verified, `${status}: dokumen ${rowName(d)} berstatus ${status}`).toBe(status === 'VERIFIED' ? 1 : 0);
      }
      if (status === 'VERIFIED') {
        expect(fDocs.map(rowName).sort(), 'VERIFIED: seluruh dokumen verified root (oracle) = QA50-RDV').toEqual(rc.verified === 1 ? [`${P}RDV`] : fDocs.map(rowName).sort());
        expect(fDocs.length, 'VERIFIED: jumlah dokumen = oracle (semua ada di 2 halaman)').toBe(rc.verified);
        expect(fDocs.map(rowName), 'VERIFIED: RDV ada').toContain(`${P}RDV`);
        expect(fDocs.map(rowName), 'VERIFIED: RDU tidak ada').not.toContain(`${P}RDU`);
      }
      // V/T folder dan ringkasan tidak berubah
      allF.filter(isFolder).forEach((r) => expect(vtText(r.document_verified), `${status}: V/T ${rowName(r)} sama dengan tanpa filter`).toBe(vtUnf[rowName(r)]));
      expect(resp1.body.result.document_verified_summary, `${status}: ringkasan (respons) sama`).toEqual(apiSum);
      expect((await sumBox.innerText()).replace(/\s+/g, ' '), `${status}: ringkasan (UI) sama`).toBe(sumBefore);
      await app.settle();
      await app.screenshot(`ac14-01-root-${status.toLowerCase()}`);

      // halaman yang memuat dokumen: ikon sesuai status
      if (filtered.length > 1) {
        await gotoPage(page, pager, lastCollected.body.result.current_page);
        await app.settle();
        await expectStatusRows(page.locator('.archive-page'), pageRows(page), lastCollected, status, COL.id, `root ${status} halaman dokumen`);
        await expectTableIs(page, pageRows(page), lastCollected, `root ${status} halaman ${lastCollected.body.result.current_page}`);
        await app.screenshot(`ac14-01b-root-${status.toLowerCase()}-halaman-dokumen`);
        await gotoPage(page, pager, 1);
        await app.settle();
      }
    }

    // reset ke tanpa filter
    const rr = await (async () => {
      const w = listWith(page, { search: null, folder: null });
      await resetIcon(bar).click();
      return w;
    })();
    expect(rr.status, 'Reset -> root tanpa search').toBe(200);
    await app.settle();

    // di folder A: Verified -> id_archive tetap
    const rows = pageRows(page);
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), rows, `${P}A`);
    const fa = await openFolder(page, rows, `${P}A`);
    const idA = idOf('A');
    expect(fa.u.searchParams.get('page'), 'folder A hal. 1').toBe('1');
    await app.settle();
    expect(await crumbText(page.locator('.archive-page')), 'breadcrumb di folder A').toMatch(/QA50-A$/);
    const plus = page.locator('.archive-page button.ant-btn-primary.btn-icon').first();
    await expect(plus, 'tombol tambah ada di folder').toBeVisible();

    const mA = calls.mark();
    const vSearch = { [FILTER]: 'VERIFIED' };
    const rv = await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: vSearch, folder: idA }));
    expect(rv.status, 'folder A + Verified 200').toBe(200);
    await app.settle();
    const gA = calls.getsSince(mA);
    expect(gA.length, 'folder A: tepat 1 GET').toBe(1);
    expect(gA[0].folder, 'id_archive A tetap').toBe(idA);
    expect(gA[0].search, 'search hanya {verificationStatus}').toEqual(vSearch);
    expect(await crumbText(page.locator('.archive-page')), 'breadcrumb tetap di A').toMatch(/QA50-A$/);
    await expect(plus, 'tombol tambah tetap').toBeVisible();
    const expV = expectedKids('A', 'VERIFIED');
    expect(namesOf(rv).sort(), 'isi A + Verified = semua folder + dokumen verified (oracle DB)').toEqual(expV.sort());
    await expectTableIs(page, pageRows(page), rv, 'folder A + Verified');
    await expectStatusRows(page.locator('.archive-page'), pageRows(page), rv, 'VERIFIED', COL.id, 'A Verified');
    // V/T folder tangan
    const hand = { EMPTY: '0/0', ALLV: '2/2', NOV: '0/2', PRIV: '-' };
    for (const [k, v] of Object.entries(hand)) {
      expect(await cellText(page.locator('.archive-page'), pageRows(page), `${P}${k}`, COL.id), `V/T ${k} saat filter aktif`).toBe(v);
    }
    const mixVt = fxJson('vt', 'MIX');
    expect(await cellText(page.locator('.archive-page'), pageRows(page), `${P}MIX`, COL.id), 'V/T MIX = oracle DB').toBe(`${mixVt.verified}/${mixVt.total}`);
    await app.screenshot('ac14-02-folder-A-verified');

    const rU = await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: { [FILTER]: 'UNVERIFIED' }, folder: idA }));
    expect(namesOf(rU).sort(), 'isi A + Unverified (oracle DB)').toEqual(expectedKids('A', 'UNVERIFIED').sort());
    expect(namesOf(rU), 'Handed Over verified AHO tidak ada di Unverified, Taken unverified ATK dan NULL ANL ada').toEqual(expect.arrayContaining([`${P}ATK`, `${P}ANL`]));
    expect(namesOf(rU)).not.toContain(`${P}AHO`);
    await app.settle();
    await expectTableIs(page, pageRows(page), rU, 'folder A + Unverified');
    await app.screenshot('ac14-03-folder-A-unverified');

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-15
  test('[default] ED-1250 AC-15 navigasi: buka subfolder / breadcrumb / Back to Archive membawa key, popover menampilkan nilai, indikator menyala; Reset tetap di folder; scan QR -> hanya query', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const bar = pageBar(page);
    const U = { [FILTER]: 'UNVERIFIED' };
    const idA = idOf('A');
    const idMix = idOf('MIX');

    await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: null }));
    await app.settle();
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);

    const stepOk = async (label, expectFolder) => {
      await app.settle();
      const g = calls.last();
      expect(g.search, `${label}: search hanya {verificationStatus}`).toEqual(U);
      expect(g.folder, `${label}: id_archive`).toBe(expectFolder);
      expect(await filterOn(bar), `${label}: indikator menyala`).toBe(true);
      const v = await readFilterValue(page, bar);
      expect(v.value, `${label}: popover menampilkan nilai`).toBe(OPT.id.UNVERIFIED);
      expect(v.nameVal, `${label}: filter lain kosong (Name)`).toBe('');
    };

    // buka subfolder
    const ra = await openFolder(page, pageRows(page), `${P}A`);
    expect(ra.status, 'buka A').toBe(200);
    await stepOk('buka A', idA);
    const rm = await openFolder(page, pageRows(page), `${P}MIX`);
    expect(rm.status, 'buka MIX').toBe(200);
    await stepOk('buka MIX', idMix);
    expect(namesOf(rm).sort(), 'isi MIX + Unverified = subfolder + dokumen unverified').toEqual(expectedKids('MIX', 'UNVERIFIED').sort());
    await app.screenshot('ac15-01-di-subfolder-mix');

    // breadcrumb: A lalu "Arsip"
    const bcA = listResponse(page, (u) => folderParam(u) === idA);
    await crumbOf(page.locator('.archive-page')).locator('button', { hasText: `${P}A` }).click();
    expect((await bcA).status, 'breadcrumb A').toBe(200);
    await stepOk('breadcrumb A', idA);
    const bcRoot = listResponse(page, (u) => !folderParam(u));
    await crumbOf(page.locator('.archive-page')).locator('button', { hasText: /^Arsip$/ }).click();
    expect((await bcRoot).status, 'breadcrumb Arsip').toBe(200);
    await stepOk('breadcrumb Arsip', null);
    await app.screenshot('ac15-02-breadcrumb-root');

    // layar akses ditolak (PRIV) -> Kembali ke Arsip
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();
    const idPriv = idOf('PRIV');
    const denied = await openFolder(page, pageRows(page), `${P}PRIV`, (u) => folderParam(u) === idPriv);
    expect(denied.status, 'PRIV ditolak 403').toBe(403);
    expect(denied.body.msg_code, 'msg_code ARCHIVE407').toBe('ARCHIVE407');
    expect(searchOf(denied.u), 'permintaan ke folder tanpa View membawa key').toEqual(U);
    const screen = page.locator('.archive-access-denied');
    await screen.waitFor({ state: 'visible', timeout: 15000 });
    await app.screenshot('ac15-03-layar-akses-ditolak');
    const back = listResponse(page, (u) => !folderParam(u));
    await screen.locator('button', { hasText: /Kembali ke Arsip/ }).click();
    expect((await back).status, 'Kembali ke Arsip -> root 200').toBe(200);
    await expect(page.locator('.archive-access-denied')).toHaveCount(0, { timeout: 10000 });
    await stepOk('Back to Archive', null);
    expectDenied403(monitor, idPriv);

    // Reset di folder: tanpa search, id_archive tetap
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();
    const rst = listWith(page, { search: null, folder: idA });
    await resetIcon(bar).click();
    const rr = await rst;
    expect(rr.status, 'Reset di A -> tanpa search, id_archive A tetap').toBe(200);
    await app.settle();
    expect(await filterOn(bar), 'indikator mati sesudah Reset').toBe(false);
    expect(await crumbText(page.locator('.archive-page')), 'breadcrumb tetap di A').toMatch(/QA50-A$/);
    expect((await readFilterValue(page, bar)).value, 'select kosong sesudah Reset').toBe('');

    // hapus nilai (kosongkan select) lalu Cari: tanpa search, tetap di folder (BR-12)
    await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: { [FILTER]: 'VERIFIED' }, folder: idA }));
    await app.settle();
    const clr = await applyFilter(page, bar, { clear: true }, () => listWith(page, { search: null, folder: idA }));
    expect(clr.status, 'kosongkan select lalu Cari: tanpa search, id_archive A tetap').toBe(200);
    await app.settle();
    expect(await crumbText(page.locator('.archive-page')), 'tetap di A').toMatch(/QA50-A$/);
    expect(await filterOn(bar), 'indikator mati').toBe(false);

    // scan QR: hanya query; field kosong
    await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: idA }));
    await app.settle();
    const sc = listWith(page, { search: { query: `${P}AU1` } });
    await scan(page, `${P}AU1`);
    const sr = await sc;
    expect(sr.status, 'scan -> GET search 200').toBe(200);
    expect(searchOf(sr.u), 'scan: search HANYA {query}').toEqual({ query: `${P}AU1` });
    await app.settle();
    expect((await readFilterValue(page, bar)).value, 'scan: select kosong').toBe('');
    expect(namesOf(sr), 'scan menemukan dokumen AU1').toContain(`${P}AU1`);
    await app.screenshot('ac15-04-scan-qr');

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-16
  test('[default] ED-1250 AC-16 filter + Name / Type -> id_archive null (datar), folder hasil cari tetap, dokumen hanya status itu; buka folder dari hasil -> hanya verificationStatus', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const bar = pageBar(page);
    const idMix = idOf('MIX');

    // dalam folder A dengan Unverified aktif, lalu isi Name
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();
    await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: { [FILTER]: 'UNVERIFIED' }, folder: idOf('A') }));
    await app.settle();

    const both = { name: `${P}MIX`, [FILTER]: 'UNVERIFIED' };
    const m = calls.mark();
    const resp = await applyFilter(page, bar, { status: 'UNVERIFIED', name: `${P}MIX` }, () =>
      listResponse(page, (u) => { const s = searchOf(u); return s && s.name === `${P}MIX` && s[FILTER] === 'UNVERIFIED'; }),
    );
    expect(resp.status, 'Name + filter 200').toBe(200);
    await app.settle();
    const g = calls.getsSince(m);
    expect(g.length, 'tepat 1 GET').toBe(1);
    expect(g[0].search, 'search memuat name DAN verificationStatus').toEqual(both);
    expect(g[0].folder, 'id_archive null (datar)').toBeNull();
    expect(await crumbText(page.locator('.archive-page')), 'breadcrumb kembali "Arsip"').toBe('Arsip');
    const got = namesOf(resp).sort();
    const exp = [`${P}MIX`, `${P}MIX-SUB`, `${P}MIX-U1`, `${P}MIX-U2`, `${P}MIX-SUB-U1`].sort();
    expect(got, 'hasil: folder cocok nama tetap + dokumen unverified cocok nama (semua level)').toEqual(exp);
    expect(got).not.toContain(`${P}MIX-V1`);
    await expectTableIs(page, pageRows(page), resp, 'Name + Unverified');
    await app.screenshot('ac16-01-name-plus-filter');

    // buka folder dari hasil: hirarki, hanya verificationStatus
    const fm = await openFolder(page, pageRows(page), `${P}MIX`, (u) => folderParam(u) === idMix);
    expect(fm.status, 'buka MIX dari hasil').toBe(200);
    expect(searchOf(fm.u), 'hanya {verificationStatus}').toEqual({ [FILTER]: 'UNVERIFIED' });
    await app.settle();
    const v = await readFilterValue(page, bar);
    expect(v.value, 'select tetap').toBe(OPT.id.UNVERIFIED);
    expect(v.nameVal, 'Name kosong').toBe('');
    await app.screenshot('ac16-02-buka-folder-dari-hasil');

    // Type + filter: id_archive null, kedua key
    const m2 = calls.mark();
    const overlay = await openPopover(page, bar);
    const typeSel = overlay.locator('.ant-select').first();
    await typeSel.locator('.ant-select-selector').click();
    const dd = dropdownOf(page);
    await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
    const typeLabel = trimAll(await dd.locator('.ant-select-item-option').allInnerTexts())[0];
    await dd.locator('.ant-select-item-option').first().click();
    await page.waitForTimeout(300);
    const tw = listResponse(page, (u) => { const s = searchOf(u); return s && s.type !== undefined && s[FILTER] === 'UNVERIFIED'; });
    await cariBtn(overlay).click();
    const tr = await tw;
    expect(tr.status, `Type (${typeLabel}) + filter 200`).toBe(200);
    await app.settle();
    const g2 = calls.getsSince(m2);
    expect(g2[g2.length - 1].folder, 'Type + filter: id_archive null').toBeNull();
    expect(Object.keys(g2[g2.length - 1].search).sort(), 'kedua key').toEqual([FILTER, 'type'].sort());
    for (const r of rowsOf(tr)) {
      if (!isFolder(r)) expect(r.document_verified.verified, `hasil Type: dokumen ${rowName(r)} unverified`).toBe(0);
    }
    await expectTableIs(page, pageRows(page), tr, `Type ${typeLabel} + Unverified`);
    await app.screenshot('ac16-03-type-plus-filter');

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- regresi Cari kosong / Related Transaction
  test('[default] ED-1250 regresi: Cari kosong di folder tanpa filter aktif -> root (ED-1125 K-3 a); Show Related Transaction saja -> tetap di folder (preseden); hapus nilai Verification Status dengan Related Transaction aktif tetap di folder', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    const calls = trace(page, monitor);
    await openArchive(app, page);
    const bar = pageBar(page);
    const idA = idOf('A');
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();

    // Cari kosong (Enter di kolom cari kosong) tanpa filter aktif -> root, tanpa search
    const input = bar.locator('input').first();
    await input.click();
    await page.waitForTimeout(350);
    const rootResp = listWith(page, { search: null, folder: null });
    await page.keyboard.press('Enter');
    const rr = await rootResp;
    expect(rr.status, 'Cari kosong di folder tanpa filter aktif -> root 200').toBe(200);
    await app.settle();
    expect(await crumbText(page.locator('.archive-page')), 'breadcrumb kembali "Arsip"').toBe('Arsip');

    // Show Related Transaction saja: tetap di folder (hirarki), tanpa Verification Status
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();
    const overlay = await openPopover(page, bar);
    await overlay.locator('.filter-item', { hasText: /Tampilkan Transaksi Terkait/ }).locator('input[type="checkbox"]').check();
    const rel = listWith(page, { search: { showRelatedTransaction: true }, folder: idA });
    await cariBtn(overlay).click();
    const r1 = await rel;
    expect(r1.status, 'Related Transaction saja: tetap di folder A, search {showRelatedTransaction}').toBe(200);
    await app.settle();
    expect(await crumbText(page.locator('.archive-page')), 'tetap di A').toMatch(/QA50-A$/);

    // Related Transaction + Verification Status: tetap di folder, kedua key
    const both = { showRelatedTransaction: true, [FILTER]: 'VERIFIED' };
    const r2 = await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: both, folder: idA }));
    expect(r2.status, 'Related Transaction + Verified: tetap di A dengan kedua key').toBe(200);
    await app.settle();
    expect(namesOf(r2).sort(), 'isi A + Verified (+ transaksi terkait): semua folder + dokumen verified').toEqual(expectedKids('A', 'VERIFIED').sort());

    // hapus nilai Verification Status saja (Related Transaction tetap aktif): tetap di folder, search = {showRelatedTransaction}
    const overlay2 = await openPopover(page, bar);
    await clearSelect(page, verifSelect(page, overlay2));
    const rc = listWith(page, { search: { showRelatedTransaction: true }, folder: idA });
    await cariBtn(overlay2).click();
    const r3 = await rc;
    expect(r3.status, 'hapus nilai Verification Status: tetap di folder A, search {showRelatedTransaction}').toBe(200);
    await app.settle();
    expect(await crumbText(page.locator('.archive-page')), 'tetap di A').toMatch(/QA50-A$/);

    // dicatat (bukan asersi spec): kotak centang Related Transaction dilepas lalu Cari; nilai false tetap dikirim (deleteEmptyRequest tidak membuang false), tetap di folder
    const overlay3 = await openPopover(page, bar);
    await overlay3.locator('.filter-item', { hasText: /Tampilkan Transaksi Terkait/ }).locator('input[type="checkbox"]').uncheck();
    const m = calls.mark();
    const emptied = listResponse(page, () => true);
    await cariBtn(overlay3).click();
    const er = await emptied;
    await app.settle();
    const g = calls.getsSince(m);
    monitor.note(`Related Transaction dilepas lalu Cari: id_archive=${g[0].folder === idA ? 'A (tetap di folder)' : g[0].folder}, search=${JSON.stringify(g[0].search)}`);
    expect(er.status, 'Cari 200').toBe(200);
    expect(g[0].folder, 'tetap di folder A').toBe(idA);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-21 halaman
  test('[default] ED-1250 AC-21 halaman: folder tanpa subfolder dan tanpa verified (NOV) + Verified -> tabel kosong, paginasi 0, folder induk tetap tampil', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    trace(page, monitor);
    await openArchive(app, page);
    const bar = pageBar(page);
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: { [FILTER]: 'VERIFIED' }, folder: null }));
    await app.settle();
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    expect(await rowIn(pageRows(page), `${P}A`).isVisible(), 'folder induk A tampil saat filter aktif').toBe(true);
    const fa = await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();
    expect(namesOf(fa), 'NOV (tanpa dokumen verified) tetap tampil di A saat filter aktif').toContain(`${P}NOV`);
    expect(namesOf(fa), 'EMPTY (0/0) tetap tampil').toContain(`${P}EMPTY`);
    const fn = await openFolder(page, pageRows(page), `${P}NOV`);
    expect(fn.status, 'NOV + Verified 200').toBe(200);
    expect(fn.body.result.total, 'total 0').toBe(0);
    expect(rowsOf(fn), 'tanpa baris').toEqual([]);
    await app.settle();
    await expect(page.locator('.archive-page .ant-empty').first(), 'tabel kosong (Empty)').toBeVisible();
    await expect(pageRows(page).filter({ visible: true }), 'tanpa baris di DOM').toHaveCount(0);
    const pag = page.locator('.archive-page .ant-pagination').first();
    const pagText = (await pag.count()) ? (await pag.innerText()).replace(/\s+/g, ' ') : '';
    monitor.note(`paginasi (NOV + Verified): "${pagText}"`);
    await noErrorToast(page, 'folder kosong');
    await app.screenshot('ac21-01-nov-verified-kosong');

    // EMPTY juga kosong; breadcrumb dan filter tetap
    const crumb = listResponse(page, (u) => folderParam(u) === idOf('A'));
    await crumbOf(page.locator('.archive-page')).locator('button', { hasText: `${P}A` }).click();
    await crumb;
    await app.settle();
    const fe = await openFolder(page, pageRows(page), `${P}EMPTY`);
    expect(fe.body.result.total, 'EMPTY total 0').toBe(0);
    await expect(page.locator('.archive-page .ant-empty').first(), 'EMPTY kosong').toBeVisible();

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-18 picker Pindahkan (dokumen)
  test('[default] ED-1250 AC-18 picker Pindahkan (⋯ dokumen): dibuka kosong walau filter halaman aktif; root / folder / navigasi / breadcrumb / Reset / + Name; folder tetap semua; terpilih & tanpa View tidak bisa dibuka; Pindahkan sukses dengan filter aktif; request halaman tidak berubah', async ({ app, page, monitor }) => {
    test.setTimeout(480000);
    const calls = trace(page, monitor);
    const U = { [FILTER]: 'UNVERIFIED' };
    const idC = idOf('C');
    const idDest = idOf('DEST');
    const idMvdU = idOf('MVD-U');
    try {
      await openArchive(app, page);
      const pbar = pageBar(page);
      await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}C`);
      await openFolder(page, pageRows(page), `${P}C`);
      await app.settle();
      await applyFilter(page, pbar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: idC }));
      await app.settle();
      const pageCrumb = await crumbText(page.locator('.archive-page'));

      // ---- picker dibuka (K-5 a): kosong
      const { modal, rows, root, bar } = await openMove(app, page, `${P}MVD-U`);
      expect(searchOf(root.u), 'picker dibuka: GET root tanpa search (filter halaman tidak diwariskan)').toBeNull();
      expect(folderParam(root.u), 'picker dibuka di root').toBeNull();
      expect(await filterOn(bar), 'indikator picker mati').toBe(false);
      const v0 = await readFilterValue(page, bar);
      expect(v0.value, 'select picker kosong saat dibuka').toBe('');
      const ov0 = await openPopover(page, bar);
      expect(await hasFilterField(page, ov0), 'popover picker memuat filter Status Verifikasi').toBe(true);
      const labels = await popoverLabels(ov0);
      expect(labels[labels.findIndex((l) => /^(Tipe|Type)$/.test(l)) + 1], 'picker: tepat sesudah Tipe').toBe(LBL.id);
      await closePopover(page, bar);
      await app.screenshot('ac18-01-picker-dibuka-kosong');

      // folder picker tanpa filter (halaman yang masih berisi folder saja) = pembanding
      const pager = modal.locator('.ant-pagination').first();
      const unf = await collectFolderPages(page, pager, root);
      const unfFolders = unf.flatMap(rowsOf).filter(isFolder).map(rowName);
      expect(unfFolders, 'picker root memuat folder fixture A, B, C').toEqual(expect.arrayContaining([`${P}A`, `${P}B`, `${P}C`]));

      // ---- root picker: Unverified
      await markTable(modal);
      const m1 = calls.mark();
      const r1 = await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: null }));
      await app.settle();
      const g1 = calls.getsSince(m1);
      expect(g1.length, 'picker root: tepat 1 GET').toBe(1);
      expect(g1[0].folder, 'picker root: tanpa id_archive').toBeNull();
      expect(g1[0].search, 'picker root: search hanya {verificationStatus}').toEqual(U);
      expect(await crumbText(modal), 'breadcrumb picker "Arsip"').toBe('Arsip');
      expect(await filterOn(bar), 'indikator picker menyala').toBe(true);
      await expectTableIs(page, rows, r1, 'picker root Unverified');
      const fil = await collectFolderPages(page, pager, r1);
      await app.settle();
      expect(fil.flatMap(rowsOf).filter(isFolder).map(rowName), 'picker root: semua folder tetap (himpunan sama dengan tanpa filter)').toEqual(unfFolders);
      fil.flatMap(rowsOf).filter((r) => !isFolder(r)).forEach((d) => expect(d.document_verified.verified, `picker: dokumen ${rowName(d)} unverified`).toBe(0));
      expect(await tableMarked(modal), 'AC-24: tabel picker di-mount ulang saat filter berganti (listKey memuat search)').toBe(false);
      await app.screenshot('ac18-02-picker-root-unverified');

      // refresh dengan parameter sama: tabel TIDAK di-mount ulang
      await markTable(modal);
      const rl = listWith(page, { search: U, folder: null });
      await modal.locator('button.button-filter-wrapper').first().click();
      await rl;
      await app.settle();
      expect(await tableMarked(modal), 'AC-24: refresh dengan parameter sama tidak me-mount ulang tabel').toBe(true);

      // ---- buka folder C di picker: key ikut, nilai tetap
      await findOnPages(page, pager, rows, `${P}C`);
      const rc = await openFolder(page, rows, `${P}C`);
      await app.settle();
      expect(searchOf(rc.u), 'buka C di picker: key ikut').toEqual(U);
      expect(folderParam(rc.u), 'id_archive C').toBe(idC);
      expect(namesOf(rc).sort(), 'isi C di picker + Unverified (oracle DB)').toEqual(expectedKids('C', 'UNVERIFIED').sort());
      expect(namesOf(rc), 'folder DEST (tanpa dokumen cocok) dan MVF tetap tampil').toEqual(expect.arrayContaining([`${P}DEST`, `${P}MVF`]));
      expect((await readFilterValue(page, bar)).value, 'nilai tetap di picker').toBe(OPT.id.UNVERIFIED);
      expect(await filterOn(bar), 'indikator picker menyala di C').toBe(true);
      expect(await crumbText(modal), 'breadcrumb picker "Arsip > QA50-C"').toMatch(/QA50-C$/);
      await expectTableIs(page, rows, rc, 'picker C Unverified');
      await app.screenshot('ac18-03-picker-folder-C');

      // klik breadcrumb picker "Arsip" -> key ikut
      const bc = listWith(page, { search: U, folder: null });
      await crumbOf(modal).locator('button', { hasText: /^Arsip$/ }).click();
      await bc;
      await app.settle();
      expect((await readFilterValue(page, bar)).value, 'sesudah breadcrumb: nilai tetap').toBe(OPT.id.UNVERIFIED);

      // C lagi, Reset -> tanpa search, tetap di C
      await findOnPages(page, pager, rows, `${P}C`);
      await openFolder(page, rows, `${P}C`);
      await app.settle();
      const rs = listWith(page, { search: null, folder: idC });
      await resetIcon(bar).click();
      const rsr = await rs;
      expect(rsr.status, 'Reset picker: tanpa search, id_archive C tetap').toBe(200);
      await app.settle();
      expect(await filterOn(bar), 'picker: indikator mati sesudah Reset').toBe(false);
      expect(await crumbText(modal), 'picker: tetap di C').toMatch(/QA50-C$/);
      expect((await readFilterValue(page, bar)).value, 'picker: select kosong').toBe('');

      // + Name -> datar dari root
      const m2 = calls.mark();
      const rn = await applyFilter(page, bar, { status: 'UNVERIFIED', name: `${P}MV` }, () =>
        listResponse(page, (u) => { const s = searchOf(u); return s && s.name === `${P}MV` && s[FILTER] === 'UNVERIFIED'; }),
      );
      await app.settle();
      const g2 = calls.getsSince(m2);
      expect(g2[g2.length - 1].folder, 'picker + Name: id_archive null (datar dari root)').toBeNull();
      expect(await crumbText(modal), 'picker + Name: breadcrumb "Arsip"').toBe('Arsip');
      expect(namesOf(rn).sort(), 'picker Name+Unverified: folder cocok nama tetap + dokumen unverified cocok nama').toEqual([`${P}MVD-U`, `${P}MVF`, `${P}MVF-U`].sort());
      await app.screenshot('ac18-04-picker-name-plus-filter');
      const rreset = listWith(page, { search: null, folder: null });
      await resetIcon(bar).click();
      await rreset;
      await app.settle();

      // ---- folder tanpa View & folder terpilih tidak bisa dibuka (filter aktif)
      await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: null }));
      await app.settle();
      await findOnPages(page, pager, rows, `${P}A`);
      await openFolder(page, rows, `${P}A`);
      await app.settle();
      const mLock = calls.mark();
      await rowIn(rows, `${P}PRIV`).getByText(`${P}PRIV`, { exact: false }).first().click();
      await page.waitForTimeout(1500);
      expect(calls.getsSince(mLock).length, 'folder tanpa View (PRIV) di picker: tidak bisa dibuka, 0 request').toBe(0);
      await expect(rowIn(rows, `${P}PRIV`), 'PRIV tetap tampil').toBeVisible();
      await app.screenshot('ac18-05-picker-folder-tanpa-view-terkunci');

      // ---- folder kosong (DEST) tetap bisa dipilih sebagai tujuan; Pindahkan sukses dengan filter VERIFIED aktif
      await crumbOf(modal).locator('button', { hasText: /^Arsip$/ }).click();
      await app.settle();
      await findOnPages(page, pager, rows, `${P}C`);
      await openFolder(page, rows, `${P}C`);
      await app.settle();
      const rv = await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: { [FILTER]: 'VERIFIED' }, folder: idC }));
      await app.settle();
      expect(namesOf(rv).sort(), 'C + Verified (oracle DB): DEST kosong tetap ada').toEqual(expectedKids('C', 'VERIFIED').sort());
      expect(namesOf(rv)).toContain(`${P}DEST`);
      const refreshPage = listWith(page, { search: U, folder: idC });
      const mv = await selectTargetAndMove(modal, rows, page, `${P}DEST`);
      expect(mv.status, 'POST put-in 200').toBe(200);
      expect(idsOfBody(mv.reqBody), 'body: idArchives = [MVD-U]').toEqual([idMvdU]);
      expect(parentOfBody(mv.reqBody), 'body: tujuan DEST').toBe(idDest);
      expect(prop('MVD-U').id_archive_parent, 'DB: MVD-U sekarang di bawah DEST').toBe(idDest);
      const pr = await refreshPage;
      expect(pr.status, 'refresh halaman sesudah Pindahkan').toBe(200);
      expect(searchOf(pr.u), 'refresh halaman membawa filter HALAMAN (Unverified), bukan filter picker (Verified)').toEqual(U);
      expect(folderParam(pr.u), 'refresh halaman di folder C').toBe(idC);
      await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'modal tertutup sesudah sukses').toHaveCount(0, { timeout: 10000 });
      await app.settle();
      expect(namesOf(pr), 'MVD-U tidak lagi di C').not.toContain(`${P}MVD-U`);
      expect(await crumbText(page.locator('.archive-page')), 'breadcrumb halaman tidak berubah').toBe(pageCrumb);
      expect((await readFilterValue(page, pbar)).value, 'filter halaman tetap Unverified').toBe(OPT.id.UNVERIFIED);
      await expectTableIs(page, pageRows(page), pr, 'halaman sesudah Pindahkan');
      await app.screenshot('ac18-06-sesudah-pindah-halaman-berfilter');
      await noErrorToast(page, 'Pindahkan sukses');

      noServerError(monitor);
      monitor.check();
    } finally {
      fx('restore');
    }
    expect(prop('MVD-U').id_archive_parent, 'MVD-U dipulihkan ke C').toBe(idC);
  });

  // ---------------------------------------------------------------------------------------------- AC-18 Pilih Folder (pindah massal) + AC-21 picker
  test('[default] ED-1250 AC-18 Pilih Folder (pindah massal): kosong saat dibuka, filter berlaku, pilihan tujuan tidak di-reset oleh filter / navigasi; AC-21 picker: NOV + Verified kosong, induknya tetap', async ({ app, page, monitor }) => {
    test.setTimeout(420000);
    const calls = trace(page, monitor);
    const idC = idOf('C');
    const V = { [FILTER]: 'VERIFIED' };
    const U = { [FILTER]: 'UNVERIFIED' };
    await openArchive(app, page);
    const pbar = pageBar(page);
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}C`);
    await openFolder(page, pageRows(page), `${P}C`);
    await app.settle();
    // filter halaman aktif (tidak boleh diwariskan)
    await applyFilter(page, pbar, { status: 'VERIFIED' }, () => listWith(page, { search: V, folder: idC }));
    await app.settle();
    const { modal, rows, root, bar } = await openBulkPick(app, page, [`${P}MVD-V`]);
    expect(searchOf(root.u), 'Pilih Folder dibuka: tanpa search (K-5 a)').toBeNull();
    expect(folderParam(root.u), 'Pilih Folder dibuka di root').toBeNull();
    expect((await readFilterValue(page, bar)).value, 'select kosong saat dibuka').toBe('');
    const pager = modal.locator('.ant-pagination:visible').first();
    await app.screenshot('ac18-07-pilih-folder-dibuka');

    // root + Unverified: tanpa id_archive, semua folder tetap
    const unf = await collectFolderPages(page, pager, root);
    const unfFolders = unf.flatMap(rowsOf).filter(isFolder).map(rowName);
    const m1 = calls.mark();
    const r1 = await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: null }));
    await app.settle();
    expect(calls.getsSince(m1).length, 'Pilih Folder root: 1 GET').toBe(1);
    expect(calls.getsSince(m1)[0].folder, 'tanpa id_archive').toBeNull();
    expect(await crumbText(modal), 'breadcrumb "Arsip"').toBe('Arsip');
    const fil = await collectFolderPages(page, pager, r1);
    expect(fil.flatMap(rowsOf).filter(isFolder).map(rowName), 'semua folder tetap').toEqual(unfFolders);
    await expectTableIs(page, rows, r1, 'Pilih Folder root Unverified');
    await app.screenshot('ac18-08-pilih-folder-root-unverified');

    // C: pilih tujuan DEST, lalu ganti filter / navigasi: pilihan tidak di-reset
    await findOnPages(page, pager, rows, `${P}C`);
    const rc = await openFolder(page, rows, `${P}C`);
    await app.settle();
    expect(searchOf(rc.u), 'buka C: key ikut').toEqual(U);
    await rowIn(rows, `${P}DEST`).locator('input[type="radio"]').check();
    const nextBtn = modal.locator('button.ant-btn-primary:visible', { hasText: MENU.move }).first();
    await expect(nextBtn, 'Pindahkan aktif setelah DEST dipilih').toBeEnabled();
    const rv = await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: V, folder: idC }));
    await app.settle();
    expect(namesOf(rv)).toContain(`${P}DEST`);
    await expect(rowIn(rows, `${P}DEST`).locator('input[type="radio"]'), 'DEST tetap terpilih sesudah filter berganti').toBeChecked();
    await expect(nextBtn, 'Pindahkan tetap aktif').toBeEnabled();
    await openFolder(page, rows, `${P}MVF`);
    await app.settle();
    await expect(nextBtn, 'Pindahkan tetap aktif sesudah buka subfolder').toBeEnabled();
    const bcC = listWith(page, { search: V, folder: idC });
    await crumbOf(modal).locator('button', { hasText: `${P}C` }).click();
    await bcC;
    await app.settle();
    await expect(rowIn(rows, `${P}DEST`).locator('input[type="radio"]'), 'DEST tetap terpilih sesudah breadcrumb').toBeChecked();
    expect(prop('MVD-V').id_archive_parent, 'tanpa mutasi: MVD-V tetap di C').toBe(idC);
    await app.screenshot('ac18-09-pilih-folder-pilihan-bertahan');

    // AC-21 di picker: A -> NOV + Verified = kosong; induk A tetap
    const root2 = listWith(page, { search: V, folder: null });
    await crumbOf(modal).locator('button', { hasText: /^Arsip$/ }).click();
    await root2;
    await app.settle();
    await findOnPages(page, pager, rows, `${P}A`);
    expect(await rowIn(rows, `${P}A`).isVisible(), 'picker: folder A tampil saat Verified aktif').toBe(true);
    const ra = await openFolder(page, rows, `${P}A`);
    await app.settle();
    expect(namesOf(ra), 'picker: NOV tetap tampil di A').toContain(`${P}NOV`);
    const rn = await openFolder(page, rows, `${P}NOV`);
    expect(rn.status, 'picker NOV + Verified 200').toBe(200);
    expect(rn.body.result.total, 'picker NOV total 0').toBe(0);
    await app.settle();
    await expect(modal.locator('.ant-empty:visible').first(), 'picker: tabel kosong (Empty)').toBeVisible();
    await noErrorToast(page, 'picker kosong');
    await app.screenshot('ac21-02-picker-nov-verified-kosong');

    // tutup tanpa memindahkan
    await closeModalBatal(page, modal);
    expect(prop('MVD-V').id_archive_parent, 'MVD-V tetap di C').toBe(idC);
    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-22 kolom / V/T / ringkasan / halaman 2 / refresh
  test('[default] ED-1250 AC-22 dengan filter aktif: kolom Document Verified, tautan V/T + modal folder, ringkasan + modal All, halaman 2 tetap berfilter, ganti filter = halaman 1, Reload tetap berfilter, tabel di-mount ulang hanya saat filter berganti', async ({ app, page, monitor }) => {
    test.setTimeout(420000);
    const calls = trace(page, monitor);
    const U = { [FILTER]: 'UNVERIFIED' };
    const V = { [FILTER]: 'VERIFIED' };
    const first = await openArchive(app, page);
    const bar = pageBar(page);
    const scope = page.locator('.archive-page');
    const sumBox = page.locator('.archive-page .ant-space').filter({ hasText: 'seluruh dokumen di sistem' }).first();
    const sumBefore = (await sumBox.innerText()).replace(/\s+/g, ' ');
    const apiSum = first.body.result.document_verified_summary;

    await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: null }));
    await app.settle();
    expect(await colIndex(scope, COL.id), 'kolom Dokumen Terverifikasi ada saat filter aktif').toBeGreaterThanOrEqual(0);
    expect((await sumBox.innerText()).replace(/\s+/g, ' '), 'ringkasan sama dengan sebelum filter').toBe(sumBefore);

    // modal All dari ringkasan
    const vResp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
    await page.locator('.archive-page').getByText('Lihat detail per tipe transaksi', { exact: false }).first().click();
    const vr = await vResp;
    expect(vr.status(), 'GET verifications (All) 200').toBe(200);
    expect(vr.url(), 'GET verifications tanpa verificationStatus (modal tidak ikut filter)').not.toContain(FILTER);
    const body = (await vr.json()).result;
    expect({ verified: body.verified, total: body.total }, 'modal All = ringkasan list').toEqual(apiSum);
    const modalAll = modalOf(page);
    await modalAll.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    const cardAll = (await modalAll.locator('.ant-card').first().innerText()).replace(/\s+/g, ' ');
    expect(cardAll, 'kartu modal All memuat V / T ringkasan').toContain(`${fmt(apiSum.verified)}`);
    await app.screenshot('ac22-01-modal-all-filter-aktif');
    await modalAll.locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });

    // folder A: V/T tautan -> modal folder
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
    await openFolder(page, pageRows(page), `${P}A`);
    await app.settle();
    const idx = await colIndex(scope, COL.id);
    const mixVt = fxJson('vt', 'MIX');
    const link = pageRows(page).filter({ hasText: new RegExp(`(^|\\s)${esc(`${P}MIX`)}(?![\\w-])`) }).first().locator('td').nth(idx).locator('button');
    await expect(link, 'sel folder MIX: tepat satu tombol tautan').toHaveCount(1);
    expect(trimAll([await link.innerText()])[0], 'V/T MIX = oracle DB saat filter aktif').toBe(`${mixVt.verified}/${mixVt.total}`);
    const mf = page.waitForResponse((r) => r.request().method() === 'GET' && VERIF_RE.test(r.url()), { timeout: 30000 });
    await link.click();
    const mr = await mf;
    expect(mr.status(), 'GET verifications/{id} 200').toBe(200);
    expect(mr.url(), 'verifications/{id} tanpa verificationStatus').not.toContain(FILTER);
    const mb = (await mr.json()).result;
    expect({ verified: mb.verified, total: mb.total }, 'modal folder MIX = oracle DB (tidak ikut filter)').toEqual(mixVt);
    const modalF = modalOf(page);
    await modalF.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await app.screenshot('ac22-02-modal-folder-mix-filter-aktif');
    await modalF.locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });

    // B: halaman 2 tetap berfilter; ganti filter = halaman 1
    const idB = idOf('B');
    const bcRoot = listWith(page, { search: U, folder: null });
    await crumbOf(scope).locator('button', { hasText: /^Arsip$/ }).click();
    await bcRoot;
    await app.settle();
    await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}B`);
    const rb = await openFolder(page, pageRows(page), `${P}B`);
    await app.settle();
    expect(rb.body.result.total, 'B + Unverified: 1 subfolder + 12 dokumen = 13').toBe(13);
    expect(rb.body.result.last_page, 'B: 2 halaman').toBe(2);
    const pagerB = page.locator('.archive-page .ant-pagination').first();
    await markTable(scope);
    const mP = calls.mark();
    const r2 = await gotoPage(page, pagerB, 2);
    await app.settle();
    const g2 = calls.getsSince(mP);
    expect(g2[g2.length - 1].search, 'halaman 2 membawa key').toEqual(U);
    expect(g2[g2.length - 1].folder, 'halaman 2 di folder B').toBe(idB);
    expect(g2[g2.length - 1].page, 'halaman 2').toBe('2');
    expect(rowsOf(r2).length, 'halaman 2: 3 baris tersisa').toBe(3);
    rowsOf(r2).forEach((d) => expect(d.document_verified.verified, `hal. 2: ${rowName(d)} unverified`).toBe(0));
    await expectTableIs(page, pageRows(page), r2, 'B halaman 2');
    await app.screenshot('ac22-03-halaman-2-berfilter');
    // ganti nilai filter -> halaman 1
    const mC = calls.mark();
    const rvv = await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: V, folder: idB }));
    await app.settle();
    const gC = calls.getsSince(mC);
    expect(gC[gC.length - 1].page, 'ganti filter = halaman 1').toBe('1');
    expect(rvv.body.result.current_page, 'respons halaman 1').toBe(1);
    await expectTableIs(page, pageRows(page), rvv, 'B + Verified halaman 1');
    expect(await tableMarked(scope), 'tabel di-mount ulang saat filter berganti').toBe(false);

    // Reload (refresh) membawa key yang sama dan tidak me-mount ulang tabel
    await markTable(scope);
    const mR = calls.mark();
    const rl = listWith(page, { search: V, folder: idB });
    await reloadBtn(page).click();
    const rlr = await rl;
    await app.settle();
    const gR = calls.getsSince(mR);
    expect(gR.length, 'Reload: 1 GET').toBe(1);
    expect(gR[0].search, 'Reload membawa key').toEqual(V);
    expect(gR[0].folder, 'Reload di folder B').toBe(idB);
    expect(rlr.status).toBe(200);
    expect(await tableMarked(scope), 'Reload (parameter sama) tidak me-mount ulang tabel').toBe(true);

    noServerError(monitor);
    monitor.check();
  });

  // ---------------------------------------------------------------------------------------------- AC-22 Pindahkan folder saat filter aktif
  test('[default] ED-1250 AC-22 Pindahkan folder fixture (MVF) saat filter Verified aktif: seluruh isi ikut pindah (SQL), refresh tetap berfilter', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    const calls = trace(page, monitor);
    const V = { [FILTER]: 'VERIFIED' };
    const idC = idOf('C');
    const idDest = idOf('DEST');
    const idMvf = idOf('MVF');
    try {
      await openArchive(app, page);
      const bar = pageBar(page);
      await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}C`);
      await openFolder(page, pageRows(page), `${P}C`);
      await app.settle();
      await applyFilter(page, bar, { status: 'VERIFIED' }, () => listWith(page, { search: V, folder: idC }));
      await app.settle();
      const before = { v: prop('MVF-V').id_archive_parent, u: prop('MVF-U').id_archive_parent };
      expect(before, 'sebelum: kedua dokumen di MVF').toEqual({ v: idMvf, u: idMvf });
      expect(fxJson('children', 'MVF').map((k) => k.name).sort(), 'isi MVF (SQL): satu verified + satu unverified').toEqual([`${P}MVF-U`, `${P}MVF-V`]);

      const { modal, rows } = await openMove(app, page, `${P}MVF`);
      await findOnPages(page, modal.locator('.ant-pagination').first(), rows, `${P}C`);
      await openFolder(page, rows, `${P}C`);
      await app.settle();
      // folder yang sedang dipindahkan (terpilih di halaman) tidak bisa dibuka di picker, juga saat filter ikut aktif
      const rv0 = await applyFilter(page, searchBarOf(modal), { status: 'VERIFIED' }, () => listWith(page, { search: V, folder: idC }));
      await app.settle();
      expect(namesOf(rv0), 'picker C + Verified: folder MVF yang dipindahkan tetap tampil').toContain(`${P}MVF`);
      const mLock = calls.mark();
      await rowIn(rows, `${P}MVF`).getByText(`${P}MVF`, { exact: false }).first().click();
      await page.waitForTimeout(1500);
      expect(calls.getsSince(mLock).length, 'folder terpilih (MVF yang dipindahkan) tidak bisa dibuka di picker, 0 request').toBe(0);
      await app.screenshot('ac18-10-picker-folder-terpilih-terkunci');
      const refresh = listWith(page, { search: V, folder: idC });
      const mv = await selectTargetAndMove(modal, rows, page, `${P}DEST`);
      expect(mv.status, 'POST put-in folder 200').toBe(200);
      expect(idsOfBody(mv.reqBody), 'body: idArchives = [MVF]').toEqual([idMvf]);
      expect(parentOfBody(mv.reqBody), 'body: tujuan DEST').toBe(idDest);
      const pr = await refresh;
      expect(pr.status, 'refresh sesudah Pindahkan').toBe(200);
      expect(searchOf(pr.u), 'refresh membawa filter halaman').toEqual(V);
      expect(folderParam(pr.u), 'refresh di folder C').toBe(idC);
      await app.settle();
      expect(namesOf(pr), 'MVF tidak lagi di C').not.toContain(`${P}MVF`);

      // SQL: folder pindah, isinya (termasuk dokumen yang tersembunyi filter) ikut
      const mvf = prop('MVF');
      expect(mvf.id_archive_parent, 'DB: MVF di bawah DEST').toBe(idDest);
      expect(mvf.is_active, 'DB: MVF aktif').toBe(1);
      expect(prop('MVF-V').id_archive_parent, 'DB: MVF-V tetap di MVF (ikut pindah)').toBe(idMvf);
      expect(prop('MVF-U').id_archive_parent, 'DB: MVF-U (tersembunyi saat Verified) tetap di MVF (ikut pindah)').toBe(idMvf);
      expect(prop('MVF-U').is_active, 'DB: MVF-U aktif').toBe(1);
      expect(fxJson('children', 'DEST').map((k) => k.name), 'DB: DEST berisi MVF').toEqual([`${P}MVF`]);
      expect(fxJson('children', 'MVF').map((k) => k.name).sort(), 'DB: isi MVF utuh').toEqual([`${P}MVF-U`, `${P}MVF-V`]);
      await app.screenshot('ac22-04-sesudah-pindah-folder');
      noServerError(monitor);
      monitor.check();
    } finally {
      fx('restore');
    }
    expect(prop('MVF').id_archive_parent, 'MVF dipulihkan ke C').toBe(idC);
  });

  // ---------------------------------------------------------------------------------------------- AC-22 Letakkan / Serahkan / Terima
  test('[default] ED-1250 AC-22 refresh sesudah Letakkan / Serahkan / Terima dokumen (D6) tetap berfilter (Unverified); D6 dipulihkan', async ({ app, page, monitor }) => {
    test.setTimeout(420000);
    const calls = trace(page, monitor);
    const U = { [FILTER]: 'UNVERIFIED' };
    const idA = idOf('A');
    const SAVE = { store: /^(Simpan|Save)$/, handover: /^(Serahkan|Hand Over)$/, receive: /^(Terima|Receive)$/ };
    const ITEM = { store: /^(Letakkan Dokumen|Store Document)$/, handover: /^(Serahkan Dokumen|Hand Over Document)$/, receive: /^(Terima Dokumen|Receive Document)$/ };
    const POSTRE = { store: PUTIN_RE, handover: /hand-over|handover|give/i, receive: /receive/i };
    const snap = prop('D6');
    try {
      await openArchive(app, page);
      const bar = pageBar(page);
      await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
      await openFolder(page, pageRows(page), `${P}A`);
      await app.settle();
      await applyFilter(page, bar, { status: 'UNVERIFIED' }, () => listWith(page, { search: U, folder: idA }));
      await app.settle();

      const flow = async (kind) => {
        const plus = page.locator('.archive-page button.ant-btn-primary.btn-icon').first();
        await plus.waitFor({ state: 'visible', timeout: 15000 });
        await plus.click();
        const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
        await menu.waitFor({ state: 'visible', timeout: 10000 });
        await menu.locator('li.ant-dropdown-menu-item', { hasText: ITEM[kind] }).first().click();
        const modal = modalOf(page);
        await modal.waitFor({ state: 'visible', timeout: 15000 });
        await app.settle();
        await modal.locator('.ant-modal-title').click();
        await page.waitForTimeout(300);
        await page.keyboard.type(`${P}D6\n`, { delay: 0 });
        await expect(modal.locator('.ant-tabs-tabpane-active').first(), `${kind}: review memuat D6`).toContainText(`${P}D6`, { timeout: 15000 });
        const post = page.waitForResponse((r) => r.request().method() === 'POST' && POSTRE[kind].test(r.url()), { timeout: 45000 });
        const refresh = listWith(page, { search: U, folder: idA });
        await modal.locator('button.ant-btn-primary', { hasText: SAVE[kind] }).first().click();
        const pr = await post;
        expect(pr.status(), `POST ${kind} D6 200`).toBe(200);
        const rr = await refresh;
        expect(rr.status, `${kind}: refresh 200`).toBe(200);
        expect(searchOf(rr.u), `${kind}: refresh membawa filter Unverified`).toEqual(U);
        expect(folderParam(rr.u), `${kind}: refresh di folder A`).toBe(idA);
        await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), `${kind}: modal tertutup`).toHaveCount(0, { timeout: 15000 });
        await app.settle();
        expect(namesOf(rr), `${kind}: D6 (unverified) ada di daftar berfilter`).toContain(`${P}D6`);
        expect(namesOf(rr).sort(), `${kind}: daftar = folder + dokumen unverified (oracle DB)`).toEqual(expectedKids('A', 'UNVERIFIED').sort());
        await expectTableIs(page, pageRows(page), rr, `${kind}: tabel halaman`);
        expect(await filterOn(bar), `${kind}: indikator filter tetap menyala`).toBe(true);
        return rr;
      };

      await flow('store');
      expect(prop('D6').id_archive_parent, 'DB: D6 di A').toBe(idA);
      await app.screenshot('ac22-05-sesudah-letakkan');
      await flow('handover');
      expect(prop('D6').status, 'DB: D6 diserahkan (2)').toBe(2);
      await app.screenshot('ac22-06-sesudah-serahkan');
      await flow('receive');
      expect(prop('D6').status, 'DB: D6 diterima (3)').toBe(3);
      await app.screenshot('ac22-07-sesudah-terima');
      await noErrorToast(page, 'Letakkan/Serahkan/Terima');
      noServerError(monitor);
      monitor.check();
    } finally {
      fx('restore');
    }
    expect(prop('D6'), 'D6 dipulihkan').toEqual(snap);
  });

  // ---------------------------------------------------------------------------------------------- AC-20 Display Setting
  test('[default] ED-1250 AC-20 Displayed Filters: sembunyikan entri -> tidak ada di popover halaman & picker; tampilkan lagi -> kembali; baris cds dipulihkan persis', async ({ app, page, monitor }) => {
    test.setTimeout(420000);
    const calls = trace(page, monitor);
    const drawer = () => page.locator('.ant-drawer-open').last();
    const openSetting = async () => {
      await page.locator('.archive-page button', { hasText: /Pengaturan Tampilan|Display Setting/ }).first().click();
      await drawer().waitFor({ state: 'visible', timeout: 15000 });
      await drawer().locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      await app.settle();
      const ft = await filtersTable(page, drawer());
      return ft.locator('tr.ant-table-row').filter({ hasText: LBL.id }).first();
    };
    const save = async () => {
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /display/i.test(r.url()), { timeout: 30000 });
      await drawer().locator('.ant-drawer-extra button.ant-btn-primary').click();
      const r = await put;
      expect(r.status(), 'PUT display setting 200').toBe(200);
      await expect(page.locator('.ant-drawer-open'), 'drawer menutup sesudah Save').toHaveCount(0, { timeout: 15000 });
      await page.waitForTimeout(1500);
      await app.settle();
    };
    const pickerHasField = async () => {
      const { modal, bar } = await openMove(app, page, `${P}AU1`);
      const ov = await openPopover(page, bar);
      const has = await hasFilterField(page, ov);
      await closePopover(page, bar);
      await closeModalBatal(page, modal);
      return has;
    };
    try {
      const before = fxJson('cds');
      monitor.note(`cds sebelum: current_queries=${JSON.stringify(before.current_queries)} available_queries=${JSON.stringify(before.available_queries)}`);
      expect(before.current_queries, 'DB sebelum: verificationStatus di current_queries').toContain(FILTER);
      expect(before.available_queries, 'DB sebelum: verificationStatus di available_queries').toContain(FILTER);

      await openArchive(app, page);
      const bar = pageBar(page);
      await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
      await openFolder(page, pageRows(page), `${P}A`);
      await app.settle();
      const ov0 = await openPopover(page, bar);
      expect(await hasFilterField(page, ov0), 'sebelum: popover halaman memuat filter').toBe(true);
      await closePopover(page, bar);

      // sembunyikan
      const row = await openSetting();
      await expect(row, 'baris Status Verifikasi di Displayed Filters').toBeVisible();
      await expect(row.locator('input[type="checkbox"]'), 'tercentang').toBeChecked();
      await row.locator('input[type="checkbox"]').uncheck();
      await save();
      const hidden = fxJson('cds');
      expect(hidden.current_queries, 'DB: dikeluarkan dari current_queries').not.toContain(FILTER);
      expect(hidden.available_queries, 'DB: tetap di available_queries').toContain(FILTER);
      const ov1 = await openPopover(page, bar);
      expect(await hasFilterField(page, ov1), 'sesudah disembunyikan: popover halaman tanpa filter').toBe(false);
      await closePopover(page, bar);
      expect(await pickerHasField(), 'sesudah disembunyikan: popover picker tanpa filter').toBe(false);
      await app.screenshot('ac20-01-disembunyikan');

      // tampilkan lagi
      const row2 = await openSetting();
      await expect(row2.locator('input[type="checkbox"]'), 'tidak tercentang').not.toBeChecked();
      await row2.locator('input[type="checkbox"]').check();
      await save();
      const shown = fxJson('cds');
      expect(shown.current_queries, 'DB: kembali di current_queries').toContain(FILTER);
      const ov2 = await openPopover(page, bar);
      expect(await hasFilterField(page, ov2), 'sesudah ditampilkan: popover halaman memuat filter').toBe(true);
      await closePopover(page, bar);
      expect(await pickerHasField(), 'sesudah ditampilkan: popover picker memuat filter').toBe(true);
      await app.screenshot('ac20-02-ditampilkan-lagi');
      noServerError(monitor);
      monitor.check();
    } finally {
      monitor.note(`cds-restore: ${fx('cds-restore')}`);
    }
    const after = fxJson('cds');
    expect(after.current_queries, 'C-8: current_queries memuat verificationStatus').toContain(FILTER);
    expect(after.available_queries, 'C-8: available_queries memuat verificationStatus').toContain(FILTER);
    expect(after.current_columns, 'C-8: current_columns memuat documentVerified').toContain('documentVerified');
  });

  // ---------------------------------------------------------------------------------------------- AC-19 [user2]
  test('[user2] ED-1250 AC-19 tanpa Opname Document: Archive (root, folder, cari) dan picker Pindahkan / Pilih Folder tanpa filter Verification Status; Display Setting tanpa entri, simpan sukses; tanpa 5xx', async ({ app, page, monitor }) => {
    test.setTimeout(420000);
    trace(page, monitor);
    const queriesOf = (resp) => (resp.body.result.queries || []).map((q) => q.data_index);
    const drawer = () => page.locator('.ant-drawer-open').last();
    try {
      const od = fxJson('has-od');
      expect(od.QA_USER2, 'prasyarat: QA_USER2 (preset) tanpa Opname Document').toBe(false);

      const first = await openArchive(app, page);
      expect(queriesOf(first), 'root: queries tanpa verificationStatus').not.toContain(FILTER);
      expect(queriesOf(first), 'root: queries memuat filter lain (type)').toContain('type');
      const bar = pageBar(page);
      const ov = await openPopover(page, bar);
      expect(await hasFilterField(page, ov), 'popover halaman tanpa filter Verification Status').toBe(false);
      const labels = await popoverLabels(ov);
      monitor.note(`label popover [user2]: ${JSON.stringify(labels)}`);
      expect(labels.join('|'), 'tidak ada label Verification Status / Status Verifikasi').not.toMatch(/Verification Status|Status Verifikasi/);
      await app.screenshot('ac19-01-popover-tanpa-filter');
      await closePopover(page, bar);

      // folder A + cari: tetap tanpa filter
      await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
      const fa = await openFolder(page, pageRows(page), `${P}A`);
      expect(fa.status, 'folder A 200').toBe(200);
      expect(queriesOf(fa), 'folder: queries tanpa verificationStatus').not.toContain(FILTER);
      await app.settle();
      const input = bar.locator('input').first();
      await input.click();
      await page.waitForTimeout(300);
      await page.keyboard.type(`${P}MIX`);
      const sr = listWith(page, { search: { query: `${P}MIX` } });
      await page.keyboard.press('Enter');
      const srr = await sr;
      expect(srr.status, 'cari 200').toBe(200);
      expect(queriesOf(srr), 'hasil cari: queries tanpa verificationStatus').not.toContain(FILTER);
      await app.settle();
      await resetIcon(bar).click();
      await app.settle();

      // picker Pindahkan (dokumen) dan Pilih Folder (massal)
      await findOnPages(page, page.locator('.archive-page .ant-pagination').first(), pageRows(page), `${P}A`);
      await openFolder(page, pageRows(page), `${P}A`);
      await app.settle();
      const one = await openMove(app, page, `${P}AU1`);
      expect(queriesOf(one.root), 'picker Pindahkan: queries tanpa verificationStatus').not.toContain(FILTER);
      const ovp = await openPopover(page, one.bar);
      expect(await hasFilterField(page, ovp), 'popover picker Pindahkan tanpa filter').toBe(false);
      await closePopover(page, one.bar);
      await app.screenshot('ac19-02-picker-pindahkan');
      await closeModalBatal(page, one.modal);

      const bulk = await openBulkPick(app, page, [`${P}AU1`, `${P}AU2`]);
      expect(queriesOf(bulk.root), 'Pilih Folder: queries tanpa verificationStatus').not.toContain(FILTER);
      const ovb = await openPopover(page, bulk.bar);
      expect(await hasFilterField(page, ovb), 'popover Pilih Folder tanpa filter').toBe(false);
      await closePopover(page, bulk.bar);
      await app.screenshot('ac19-03-pilih-folder');
      await closeModalBatal(page, bulk.modal);

      // Display Setting: tanpa entri; simpan sukses (sembunyikan filter lain) dan entri tersimpan dipertahankan
      await page.locator('.archive-page button', { hasText: /Display Setting|Pengaturan Tampilan/ }).first().click();
      await drawer().waitFor({ state: 'visible', timeout: 15000 });
      await drawer().locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      await app.settle();
      const ft2 = await filtersTable(page, drawer());
      const names = trimAll(await ft2.locator('tr.ant-table-row').allInnerTexts());
      monitor.note(`Displayed Filters [user2]: ${JSON.stringify(names)}`);
      expect(names.length, 'Displayed Filters memuat daftar filter').toBeGreaterThan(0);
      expect(names.filter((n) => n === LBL.en || n === LBL.id).length, 'Displayed Filters tanpa entri Verification Status').toBe(0);
      const rel = ft2.locator('tr.ant-table-row').filter({ hasText: /Show Related Transaction|Tampilkan Transaksi Terkait/ }).first();
      await expect(rel, 'baris Show Related Transaction ada').toBeVisible();
      await rel.locator('input[type="checkbox"]').uncheck();
      await app.screenshot('ac19-04-display-setting-tanpa-entri');
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /display/i.test(r.url()), { timeout: 30000 });
      await drawer().locator('.ant-drawer-extra button.ant-btn-primary').click();
      const pr = await put;
      expect(pr.status(), '[user2] PUT display setting 200').toBe(200);
      await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 15000 });
      await app.settle();
      const cds = fxJson('cds');
      monitor.note(`cds sesudah simpan [user2]: current_queries=${JSON.stringify(cds.current_queries)}`);
      expect(cds.current_queries, 'DB: perubahan user2 tersimpan (Show Related Transaction keluar)').not.toContain('showRelatedTransaction');
      expect(cds.current_queries, 'DB: verificationStatus tetap di current_queries (dipertahankan)').toContain(FILTER);
      expect(cds.available_queries, 'DB: verificationStatus tetap di available_queries').toContain(FILTER);
      expect(cds.current_columns, 'DB: documentVerified tetap di current_columns').toContain('documentVerified');
      await noErrorToast(page, '[user2] simpan Display Setting');
      noServerError(monitor);
      monitor.check();
    } finally {
      monitor.note(`cds-restore: ${fx('cds-restore')}`);
    }
  });
});
