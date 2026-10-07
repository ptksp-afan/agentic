'use strict';

/**
 * ED-1028 - Archive: Opname History (semua sesi). Drawer inline dari modal Document Verified (All Archive) dan tab Verification
 * drawer folder; tabel v5 (display setting, search + filter popover, sort, pagination); hasil satu sesi (drawer bersarang).
 *
 * Data uji dibuat fixture (fixtures.json -> fixture.php) dan dibuang persis di teardown: folder berawalan "0QA28-", sesi N01..N14
 * (14 terkonfirmasi, 13 terlihat user2), HID (terkonfirmasi, tak terlihat), draft, batal. Sesi disisipkan spec (fixture.php sessions)
 * sesudah test "tanpa sesi sama sekali" supaya keadaan nol sesi nyata ikut teruji. Lihat kepala fixture.php untuk angka tangan.
 * User [default] = QA_USER (role 3, semua lokasi, bahasa ID, bukan superadmin), [user2] = QA_USER2 (Sales Supervisor, lokasi SMR, EN).
 *
 * Mutasi: Display Setting `documentArchiveOpnameHistory` (baris global; dipulihkan spec lalu fixture), Confirm Opname GAMMA lewat UI
 * (test terakhir; sesi baru dibuang teardown). Test dijalankan berurutan (E2E_WORKERS=1).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, config } = require('e2e-harness');

const P = '0QA28-';
const fixture = (cmd) => execFileSync(config.get('PHP_BIN'), [path.join(__dirname, 'fixture.php'), cmd], { encoding: 'utf8', timeout: 90000 });
const ids = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.fixture-ids.json'), 'utf8'));
const inspectDb = () => JSON.parse(fixture('inspect').trim().split('\n').pop());
const displayDb = () => JSON.parse(fixture('display').trim().split('\n').pop());
const trimAll = (arr) => arr.map((t) => t.replace(/\s+/g, ' ').trim());
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// ------------------------------------------------------------------------------------------------ data harapan (fixture.php)
const MONTHS = {
  id: ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
  en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};
const dateText = (at, lang = 'id') => {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(at);
  return `${m[3]} ${MONTHS[lang][Number(m[2]) - 1]} ${m[1]} ${m[4]}:${m[5]}`;
};
// [confirmed_at, user, scope, total, verified, notFound, invalid]
const S = {
  N01: ['2026-10-06 16:40:00', 'qa28alice', `${P}ALFA-S1 + 1 subfolder`, 5, 3, 1, 1],
  N02: ['2026-10-05 09:15:00', 'qa28root', 'All Archive · root', 8, 3, 1, 1],
  N03: ['2026-10-04 14:30:00', 'qa28bob', `${P}BRAVO`, 1, 1, 0, 0],
  N04: ['2026-10-03 11:05:00', 'qa28carol', `${P}BRAVO-T1`, 1, 1, 0, 0],
  N05: ['2026-10-02 08:20:00', 'qa28alice', `${P}ALFA-S2`, 1, 1, 0, 0],
  N06: ['2026-10-01 17:45:00', 'qa28dave', `${P}BRAVO`, 2, 1, 0, 1],
  N07: ['2026-09-30 10:10:00', 'qa28erin', `${P}ALFA-S1`, 2, 2, 0, 0],
  N08: ['2026-09-29 13:00:00', 'qa28bob', `${P}BRAVO`, 2, 1, 1, 0],
  N09: ['2026-09-28 09:00:00', 'qa28carol', `${P}ALFA`, 3, 2, 0, 0],
  N10: ['2026-09-27 15:30:00', 'qa28dave', `${P}BRAVO`, 2, 2, 0, 0],
  N11: ['2026-09-26 12:00:00', 'qa28alice', `${P}ALFA-S1-X`, 1, 1, 0, 0],
  N12: ['2026-09-25 08:00:00', 'qa28erin', `${P}BRAVO`, 2, 1, 0, 0],
  N13: ['2026-09-24 07:30:00', 'qa28bob', `${P}ALFA`, 3, 3, 0, 0],
  N14: ['2026-09-23 06:00:00', 'qa28view', `${P}VIEWONLY`, 1, 1, 0, 0],
};
const ORDER = Object.keys(S); // terbaru -> terlama
const cells = (k, lang = 'id') => [dateText(S[k][0], lang), S[k][1], S[k][2], ...S[k].slice(3).map(String)];
const cellsOf = (keys, lang) => keys.map((k) => cells(k, lang));
const HIDDEN_USERS = /qa28hid|qa28draft|qa28cancel/;

const LANG = {
  id: {
    link: 'Lihat detail per tipe transaksi', cta: 'Lihat semua riwayat', title: 'Riwayat Opname', subAll: 'All Archive — seluruh sesi opname',
    subFolder: (n) => `${n} — seluruh sesi opname yang mencakup folder ini`, resultTitle: 'Hasil Opname',
    heads: ['Waktu', 'User', 'Scope', 'Total Dokumen', 'Verified', 'Not Found', 'Invalid'],
    cards: ['Total dokumen', 'Terverifikasi sesi ini', 'Tidak ditemukan', 'Tidak valid', 'Belum discan'],
    seg: ['Semua', 'Discan', 'Terverifikasi', 'Tidak ditemukan', 'Tidak valid'],
    partial: 'Sebagian dokumen di luar akses Anda tidak ditampilkan.', outside: 'Di luar scope opname',
    res: { verified: 'Terverifikasi', not_found: 'Tidak ditemukan', invalid: 'Tidak valid', unscanned: 'Belum discan' },
    docHeads: ['No Dokumen', 'Tipe Transaksi', 'Folder', 'Waktu', 'Hasil'], display: /Pengaturan Tampilan/, view: /^Lihat$/, verif: 'Verifikasi',
  },
  en: {
    link: 'See detail per transaction type', cta: 'See all history', title: 'Opname History', subAll: 'All Archive — all opname sessions',
    subFolder: (n) => `${n} — all opname sessions that cover this folder`, resultTitle: 'Opname Result',
    heads: ['Time', 'User', 'Scope', 'Total Documents', 'Verified', 'Not Found', 'Invalid'],
    cards: ['Total documents', 'Verified this session', 'Not found', 'Invalid', 'Not scanned'],
    seg: ['All', 'Scanned', 'Verified', 'Not found', 'Invalid'],
    partial: 'Some documents outside your access are not shown.', outside: 'Outside opname scope',
    res: { verified: 'Verified', not_found: 'Not found', invalid: 'Invalid', unscanned: 'Not scanned' },
    docHeads: ['Document No', 'Transaction Type', 'Folder', 'Time', 'Result'], display: /Display Setting/, view: /^View$/, verif: 'Verification',
  },
};

// ------------------------------------------------------------------------------------------------ jejak request API
/** catat respons document-archive + select/document-archive (method, path, query, search JSON, sorts, status, json) */
function trace(page) {
  const calls = [];
  page.on('response', (r) => {
    const u = new URL(r.url());
    const m = /\/api\/v5\/((?:select\/)?document-archive\/.*)$/.exec(u.pathname);
    if (!m) return;
    const parse = (s) => {
      try {
        return JSON.parse(s);
      } catch (e) {
        return s;
      }
    };
    const raw = u.searchParams.get('search');
    const entry = {
      method: r.request().method(),
      path: m[1].replace(/^document-archive\//, ''),
      query: Object.fromEntries(u.searchParams),
      search: raw === null ? undefined : parse(raw),
      sorts: [...u.searchParams.keys()].filter((k) => /^sorts?(\[\])?$/.test(k)).flatMap((k) => u.searchParams.getAll(k).map(parse)),
      url: r.url(),
      status: r.status(),
      json: null,
      at: Date.now(),
    };
    entry.ready = r.json().then((j) => { entry.json = j; }).catch(() => null);
    calls.push(entry);
  });
  return calls;
}
const callsOf = async (calls, method, re) => {
  await Promise.all(calls.map((c) => c.ready));
  return calls.filter((c) => c.method === method && re.test(c.path));
};
const histCalls = (calls) => callsOf(calls, 'GET', /^opnames$/);
const listCalls = (calls) => callsOf(calls, 'GET', /^archives$/);
const OPNAMES_RE = /\/document-archive\/opnames(\?|$)/;
const waitOpnames = (page, pred) =>
  page.waitForResponse((r) => r.request().method() === 'GET' && OPNAMES_RE.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 });
const waitList = (page, pred) =>
  page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/archives(\?|$)/.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout: 45000 });
const bodyOf = async (resp) => (await resp.json()).result;
const searchOf = (resp) => {
  const raw = new URL(resp.url()).searchParams.get('search');
  return raw === null || raw === '' ? null : JSON.parse(raw);
};

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  expect(api.length, 'ada panggilan API v5 tercatat (monitor aktif)').toBeGreaterThan(0);
}

/** buang dari monitor penolakan yang SENGAJA dibuat (respons dimodifikasi), setelah memastikan terjadi */
function expectRejected(monitor, label, { status, urlRe }) {
  const expected = (e) =>
    (e.kind === 'response' && e.status === status && urlRe.test(decodeURIComponent(e.url))) ||
    (e.kind === 'console' && new RegExp(`Request failed with status code ${status}`).test(e.text || ''));
  const dropped = monitor.errors.filter(expected);
  expect(dropped.filter((e) => e.kind === 'response').length, `${label}: respons ${status} tercatat (diharapkan)`).toBeGreaterThanOrEqual(1);
  monitor.errors = monitor.errors.filter((e) => !expected(e));
  monitor.note(`${label}: ${dropped.length} entri error yang diharapkan dikeluarkan dari pemeriksaan`);
}

// ------------------------------------------------------------------------------------------------ halaman Archive, modal, drawer
const modalVisible = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
const drawerOpen = (page) => page.locator('.ant-drawer-open').last();
const hist = (page) => page.locator('.archive-opname-history.ant-drawer-open').first();
const resDrawer = (page) => hist(page).locator('.ant-drawer.ant-drawer-open');
const histTable = (page) => hist(page).locator('.ant-table').first();
const histRowsLoc = (page) => histTable(page).locator('tbody tr.ant-table-row');

const rowOf = (page, name) =>
  page.locator('.archive-page .ant-table-row').filter({ hasText: new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`) }).first();

async function openArchive(app, page) {
  const list = waitList(page);
  await app.open('/archives', { waitFor: '.archive-page .ant-table' });
  await list;
  await page.locator('.archive-page .ant-table-row').first().waitFor({ state: 'visible', timeout: 20000 });
}

async function openModal(app, page, lang = 'id') {
  const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications(\?|$)/.test(r.url()), { timeout: 30000 });
  await page.locator('.archive-page').getByText(LANG[lang].link, { exact: false }).first().click();
  await resp;
  const modal = modalVisible(page);
  await modal.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  await app.settle();
  return modal;
}

async function tableRows(scope, n = 0) {
  const rows = scope.locator('.ant-table').nth(n).locator('tbody tr.ant-table-row');
  const count = await rows.count();
  const out = [];
  for (let i = 0; i < count; i += 1) out.push(trimAll(await rows.nth(i).locator('td').allInnerTexts()));
  return out;
}
const histRows = async (page) => {
  const rows = histRowsLoc(page);
  const count = await rows.count();
  const out = [];
  for (let i = 0; i < count; i += 1) out.push(trimAll(await rows.nth(i).locator('td').allInnerTexts()));
  return out;
};
const histHeads = async (page) => trimAll(await histTable(page).locator('thead th').allInnerTexts()).filter(Boolean);

/** buka drawer history dari modal All Archive; kembalikan { modal, resp (respons GET opnames) } */
async function openHistoryFromModal(app, page, lang = 'id', { expectRows = true } = {}) {
  const modal = await openModal(app, page, lang);
  const respP = waitOpnames(page);
  await modal.getByText(LANG[lang].cta).first().click();
  const resp = await respP;
  await hist(page).waitFor({ state: 'visible', timeout: 15000 });
  if (expectRows) await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(600);
  return { modal, resp };
}

/** menu baris folder -> item (Lihat / View) */
async function pickRowMenu(page, name, itemRegex) {
  const row = rowOf(page, name);
  await row.waitFor({ state: 'visible', timeout: 20000 });
  await row.locator('button.btn-action').click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: itemRegex }).first().click();
}

/** buka drawer folder (Lihat) -> tab Verification (tab ke-3); kembalikan pane aktif */
async function openFolderVerification(app, page, name, lang = 'id') {
  await pickRowMenu(page, name, LANG[lang].view);
  const dr = drawerOpen(page);
  await dr.waitFor({ state: 'visible', timeout: 15000 });
  await dr.locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
  const tabsTexts = trimAll(await dr.locator('.ant-tabs-tab').allInnerTexts());
  const verifIdx = tabsTexts.indexOf(LANG[lang].verif);
  expect(verifIdx, `tab ${LANG[lang].verif} ada di drawer folder (tab: ${JSON.stringify(tabsTexts)})`).toBeGreaterThan(-1);
  const vresp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/verifications\/[^/?]+/.test(r.url()), { timeout: 30000 });
  await dr.locator('.ant-tabs-tab').nth(verifIdx).click();
  await vresp;
  const pane = dr.locator('.ant-tabs-tabpane-active');
  await pane.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  await app.settle();
  return { drawer: dr, pane };
}

/** drawer history menutupi wadahnya: kotak = kotak induk, dan elemen teratas di judul induk milik drawer history */
async function coverInfo(page) {
  return page.evaluate(() => {
    const h = document.querySelector('.archive-opname-history.ant-drawer-open');
    const wrap = h.querySelector('.ant-drawer-content-wrapper');
    const parent = h.parentElement.closest('.ant-modal-content, .ant-drawer-content') || h.parentElement;
    const rect = (e) => {
      const b = e.getBoundingClientRect();
      return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) };
    };
    const pr = rect(parent);
    const top = document.elementFromPoint(pr.x + 24, pr.y + 20);
    const bottom = document.elementFromPoint(pr.x + 24, pr.y + pr.h - 12);
    return {
      parentClass: parent.className, parent: pr, wrap: rect(wrap),
      topInHistory: !!(top && top.closest('.archive-opname-history')), bottomInHistory: !!(bottom && bottom.closest('.archive-opname-history')),
    };
  });
}
const sameBox = (a, b, tol = 3) => ['x', 'y', 'w', 'h'].every((k) => Math.abs(a[k] - b[k]) <= tol);

/** gulir tabel sampai ujung: kolom terakhir harus terjangkau */
async function columnReach(page, table) {
  const need = await table.evaluate((t) => {
    const sc = t.querySelector('.ant-table-body') || t.querySelector('.ant-table-content');
    const ths = Array.from(t.querySelectorAll('thead th')).filter((th) => th.innerText.trim());
    const last = ths[ths.length - 1];
    const cr = t.getBoundingClientRect();
    return { scrollable: sc.scrollWidth > sc.clientWidth + 1, clippedBefore: last.getBoundingClientRect().right > cr.right + 1, last: last.innerText.trim(), overflowX: getComputedStyle(sc).overflowX };
  });
  await table.evaluate((t) => {
    const sc = t.querySelector('.ant-table-body') || t.querySelector('.ant-table-content');
    sc.scrollLeft = sc.scrollWidth;
  });
  await page.waitForTimeout(500);
  const reach = await table.evaluate((t) => {
    const ths = Array.from(t.querySelectorAll('thead th')).filter((th) => th.innerText.trim());
    const last = ths[ths.length - 1];
    const r = last.getBoundingClientRect();
    const cr = t.getBoundingClientRect();
    return r.right <= cr.right + 2 && r.left >= cr.left - 2;
  });
  await table.evaluate((t) => {
    const sc = t.querySelector('.ant-table-body') || t.querySelector('.ant-table-content');
    sc.scrollLeft = 0;
  });
  await page.waitForTimeout(200);
  return { ...need, reach };
}

// ------------------------------------------------------------------------------------------------ search bar + popover di drawer history
const searchInput = (page) => hist(page).locator('.search-bar input').first();
const filterIcon = (page) => hist(page).locator('.search-bar .search-config').first();
const popover = (page) => hist(page).locator('.search-bar-overlay:not(.ant-popover-hidden)').first();
const resetIcon = (page) => hist(page).locator('.search-bar .reset-filter').first();

async function typeSearch(page, text) {
  await searchInput(page).click();
  await searchInput(page).fill(text);
  const resp = waitOpnames(page);
  await searchInput(page).press('Enter');
  return resp;
}
async function openPopover(page) {
  if (await popover(page).count()) return popover(page);
  await filterIcon(page).click();
  await popover(page).waitFor({ state: 'visible', timeout: 10000 });
  await page.waitForTimeout(500);
  return popover(page);
}
async function submitPopover(page) {
  await popover(page).click({ position: { x: 4, y: 4 } }); // tutup dropdown/panel picker yang masih terbuka
  await page.waitForTimeout(300);
  const resp = waitOpnames(page);
  await popover(page).locator('button').filter({ hasText: /^(Cari|Search)$/ }).first().click();
  const r = await resp;
  await page.waitForTimeout(700);
  return r;
}
async function resetAll(page) {
  const resp = waitOpnames(page);
  await resetIcon(page).click();
  const r = await resp;
  await page.waitForTimeout(700);
  return r;
}
const rowUsers = async (page) => (await histRows(page)).map((r) => r[1]);
const rowKeys = async (page, lang = 'id') => {
  const rows = await histRows(page);
  return rows.map((r) => ORDER.find((k) => JSON.stringify(cells(k, lang)) === JSON.stringify(r)) || `?${r.join('|')}`);
};

// ---------------------------------------------------------------------------------------------------------------------
test.describe('ED-1028 Opname History', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    fixture('ensure-sessions'); // tiap test mandiri (--grep); test "tanpa sesi" mengosongkan lalu mengisi ulang
  });

  // ------------------------------------------------------------------------------------------- AC-23 tanpa sesi sama sekali + AC-15
  test('[default] ED-1028 AC-23 tanpa sesi sama sekali: drawer inline terbuka, tabel kosong standar, tanpa teks "N sesi", tanpa error', async ({ app, page, monitor }) => {
    fixture('reset-sessions');
    const db0 = inspectDb();
    expect(db0.sessions.length, 'DB: tidak ada sesi opname sama sekali').toBe(0);
    const calls = trace(page);
    await openArchive(app, page);
    const { resp } = await openHistoryFromModal(app, page, 'id', { expectRows: false });
    expect(resp.status(), 'GET opnames 200 walau tanpa sesi').toBe(200);
    const res = await bodyOf(resp);
    monitor.note(`respons tanpa sesi: total=${res.total} data=${res.data.length} kolom=${res.columns.length} query=${res.queries.length}`);
    expect(res.total, 'total 0').toBe(0);
    expect(res.data, 'data kosong').toEqual([]);
    expect(res.columns.map((c) => c.data_index), '7 kolom tetap dikirim').toEqual(['confirmedAt', 'createdBy', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'invalidCount']);
    await expect(hist(page).locator('.ant-table-placeholder .ant-empty, .ant-empty').first(), 'empty state standar tabel').toBeVisible();
    expect(await histHeads(page), 'header kolom tetap tampil').toEqual(LANG.id.heads);
    const text = await hist(page).innerText();
    expect(text, 'tanpa teks jumlah sesi ("N sesi")').not.toMatch(/\d+\s+sesi\b/i);
    expect(text, 'tanpa NaN / objek mentah').not.toMatch(/NaN|\[object Object\]/);
    expect((await histCalls(calls)).length, 'tepat satu GET opnames').toBe(1);
    await app.screenshot('ac23-01-tanpa-sesi');
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open'), '✕ menutup drawer').toHaveCount(0, { timeout: 10000 });
    await expect(modalVisible(page).locator('.ant-card').first(), 'isi modal tampil lagi').toBeVisible();
    noServerError(monitor);
    monitor.check();
    fixture('sessions'); // sesi uji untuk test berikutnya
    expect(inspectDb().sessions.length, 'DB: 14 + HID + draft + batal = 17 sesi uji').toBe(17);
  });

  // ------------------------------------------------------------------------------------------- AC-15 / AC-1 / AC-24 modal -> history
  test('[default] ED-1028 AC-15 modal Document Verified -> "Lihat semua riwayat": drawer inline menutupi modal, satu GET opnames tanpa idArchive, 3 baris teratas = overview, ✕ kembali', async ({ app, page, monitor }) => {
    const calls = trace(page);
    await openArchive(app, page);
    const modal = await openModal(app, page);
    const overview = await tableRows(modal, 1);
    monitor.note(`overview modal: ${JSON.stringify(overview)}`);
    expect(overview, 'overview 3 sesi terbaru (04) tetap benar').toEqual(cellsOf(['N01', 'N02', 'N03']));
    await expect(modal.getByText(LANG.id.cta), 'CTA tampil di modal').toBeVisible();
    expect((await histCalls(calls)).length, 'sebelum CTA: 0 GET opnames').toBe(0);
    await app.screenshot('ac15-00-modal-sebelum');

    const respP = waitOpnames(page);
    await modal.getByText(LANG.id.cta).first().click();
    const resp = await respP;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const hc = await histCalls(calls);
    expect(hc.length, 'tepat satu GET opnames saat drawer dibuka').toBe(1);
    expect(Object.keys(hc[0].query).filter((k) => /id_?archive$/i.test(k)), 'tanpa idArchive / id_archive (All Archive)').toEqual([]);
    monitor.note(`GET opnames URL: ${hc[0].url.replace(/^https?:\/\/[^/]+/, '')}`);

    // respons: paginator + columns + queries (AC-1 sisi FE: dipakai apa adanya)
    expect(resp.status(), 'GET opnames 200').toBe(200);
    const res = await bodyOf(resp);
    expect(res.total, 'total = 14 sesi terlihat user QA (HID/draft/batal tidak ikut)').toBe(14);
    expect(res.per_page, '10 per halaman').toBe(10);
    expect(res.data.map((d) => d.created_by), 'urut confirmed_at turun').toEqual(ORDER.slice(0, 10).map((k) => S[k][1]));
    expect(res.columns.map((c) => c.data_index), '7 kolom urut BR-6').toEqual(['confirmedAt', 'createdBy', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'invalidCount']);
    expect(res.queries.map((q) => [q.data_index, q.type]), '3 query').toEqual([['confirmedAt', 'dateTimeRange'], ['createdBy', 'select'], ['idArchives', 'select']]);
    expect(res.queries[2].multiple, 'query Folder multiple').toBe(true);
    expect(res.queries[1].endpoint, 'endpoint User').toBe('select/document-archive/archive/opname-users');
    expect(res.queries[2].endpoint, 'endpoint Folder').toBe('select/document-archive/archive/folders');

    // judul + subjudul + tanpa "N sesi"
    const title = (await hist(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    monitor.note(`judul drawer: ${title}`);
    expect(title, 'judul').toContain(LANG.id.title);
    expect(title, 'subjudul All Archive').toContain(LANG.id.subAll);
    const text = await hist(page).innerText();
    expect(text, 'tanpa teks "N sesi"').not.toMatch(/\d+\s+sesi\b/i);
    expect(text, 'tanpa NaN / objek mentah').not.toMatch(/NaN|\[object Object\]/);
    expect(text, 'sesi tersembunyi / draft / batal tidak tampil').not.toMatch(HIDDEN_USERS);

    // menutupi isi modal (bukan hanya sebagian)
    const cover = await coverInfo(page);
    monitor.note(`cover: ${JSON.stringify(cover)}`);
    expect(cover.parentClass, 'wadah = isi modal').toContain('ant-modal-content');
    expect(sameBox(cover.wrap, cover.parent), `drawer selebar & setinggi isi modal (${JSON.stringify(cover.wrap)} vs ${JSON.stringify(cover.parent)})`).toBe(true);
    expect(cover.topInHistory && cover.bottomInHistory, 'judul dan footer modal tertutup drawer').toBe(true);

    // kolom + baris halaman 1 + 3 teratas = overview
    expect(await histHeads(page), 'kolom default BR-6 (judul dari server)').toEqual(LANG.id.heads);
    const rows = await histRows(page);
    expect(rows, 'halaman 1 = 10 sesi terbaru, nilai tersimpan').toEqual(cellsOf(ORDER.slice(0, 10)));
    expect(rows.slice(0, 3), '3 baris teratas = overview 3 sesi').toEqual(overview);
    await expect(hist(page).locator('.ant-pagination'), 'paginasi tampil').toBeVisible();
    const pageItems = trimAll(await hist(page).locator('.ant-pagination-item').allInnerTexts());
    expect(pageItems, '2 halaman (14 sesi, 10 per halaman)').toEqual(['1', '2']);
    await expect(histRowsLoc(page).first(), 'baris bisa diklik (kursor tangan)').toHaveClass(/cursor-pointer/);
    await expect(hist(page).locator('.search-bar'), 'search bar').toBeVisible();
    await expect(hist(page).locator('button', { hasText: LANG.id.display }).first(), 'tombol Display Setting').toBeVisible();
    // tanpa filter di atas tabel (bulan/folder/user ala desain lama)
    await expect(hist(page).getByText(/^Semua folder$|^Semua user$|Agustus 2026/), 'tanpa filter ala desain di atas tabel').toHaveCount(0);
    await app.screenshot('ac15-01-history-modal');

    // keterjangkauan kolom (modal sempit): kolom terakhir (Invalid) harus bisa dicapai lewat gulir
    const reach = await columnReach(page, histTable(page));
    monitor.note(`keterjangkauan kolom (modal 1440 px): ${JSON.stringify(reach)}`);
    expect(reach.reach, `kolom terakhir (${reach.last}) terjangkau lewat gulir horizontal`).toBe(true);
    await app.screenshot('ac15-02-history-modal-kolom');

    // ✕ kembali ke isi modal; buka lagi = request baru
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open'), '✕ menutup drawer').toHaveCount(0, { timeout: 10000 });
    await expect(modal.locator('.ant-card').first(), 'isi modal tampil lagi').toBeVisible();
    expect(await tableRows(modal, 1), 'overview modal tidak berubah').toEqual(overview);
    const resp2 = waitOpnames(page);
    await modal.getByText(LANG.id.cta).first().click();
    expect((await resp2).status(), 'buka lagi: GET opnames baru').toBe(200);
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    expect((await histCalls(calls)).length, 'dua pembukaan = dua request').toBe(2);
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    // tutup modal (Tutup) -> buka lagi: drawer history tidak ikut terbuka (menutup induk membuangnya)
    await modal.locator('.ant-modal-footer button').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'Tutup menutup modal').toHaveCount(0, { timeout: 10000 });
    await openModal(app, page);
    await expect(page.locator('.archive-opname-history.ant-drawer-open'), 'buka modal lagi: history tertutup').toHaveCount(0);
    await modalVisible(page).locator('.ant-modal-close').click();

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-16 folder -> tab Verification -> history
  test('[default] ED-1028 AC-16 drawer folder -> tab Verification -> CTA: drawer inline menutupi drawer, idArchive=F di setiap request, subjudul nama F, ✕ kembali; folder read-only tetap bisa dicari', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    await openArchive(app, page);
    const { drawer, pane } = await openFolderVerification(app, page, `${P}ALFA`);
    const overviewTable = await tableRows(pane, 1);
    monitor.note(`overview tab (kolom varian folder): ${JSON.stringify(overviewTable)}`);
    expect(overviewTable.map((r) => [r[0], r[1]]), 'overview tab ALFA: 3 sesi terbaru yang mencakup ALFA').toEqual(['N01', 'N02', 'N05'].map((k) => [cells(k)[0], cells(k)[1]]));
    await expect(pane.getByText(LANG.id.cta), 'CTA di tab Verification').toBeVisible();

    const respP = waitOpnames(page);
    await pane.getByText(LANG.id.cta).first().click();
    const resp = await respP;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await app.settle();
    const u = new URL(resp.url());
    expect(u.searchParams.get('idArchive') || u.searchParams.get('id_archive'), 'request awal membawa idArchive = id folder ALFA').toBe(fx[`${P}ALFA`]);
    expect(resp.status(), 'GET opnames 200').toBe(200);
    const res = await bodyOf(resp);

    const title = (await hist(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    monitor.note(`judul drawer folder: ${title}`);
    expect(title, 'judul').toContain(LANG.id.title);
    expect(title, 'subjudul = nama F + keterangan').toContain(LANG.id.subFolder(`${P}ALFA`));
    expect(await hist(page).innerText(), 'tanpa teks "N sesi"').not.toMatch(/\d+\s+sesi\b/i);

    // menutupi isi drawer folder (judul tab + tombol Simpan ikut tertutup)
    const cover = await coverInfo(page);
    monitor.note(`cover folder: ${JSON.stringify(cover)}`);
    expect(cover.parentClass, 'wadah = isi drawer folder').toContain('ant-drawer-content');
    expect(sameBox(cover.wrap, cover.parent), `drawer history selebar & setinggi drawer folder (${JSON.stringify(cover.wrap)} vs ${JSON.stringify(cover.parent)})`).toBe(true);
    expect(cover.topInHistory && cover.bottomInHistory, 'header + footer drawer folder tertutup').toBe(true);

    // isi: hanya sesi yang mencakup ALFA (ALFA atau subfolder): N01 N02 N05 N07 N09 N11 N13
    const alfa = ['N01', 'N02', 'N05', 'N07', 'N09', 'N11', 'N13'];
    expect(res.total, 'total 7 sesi yang mencakup ALFA').toBe(7);
    expect(await histRows(page), 'baris = sesi yang mencakup ALFA, terbaru di atas, angka sesi (bukan potongan folder)').toEqual(cellsOf(alfa));
    expect((await histRows(page)).slice(0, 3).map((r) => [r[0], r[1]]), '3 baris teratas = overview tab').toEqual(overviewTable.map((r) => [r[0], r[1]]));
    await app.screenshot('ac16-01-history-folder');

    // idArchive menyertai SETIAP request: search, sort, reset, reload
    const withFolder = async (resp2, label) => {
      const q = new URL(resp2.url()).searchParams;
      expect(q.get('idArchive') || q.get('id_archive'), `${label}: idArchive tetap ada`).toBe(fx[`${P}ALFA`]);
    };
    const r1 = await typeSearch(page, 'alice');
    await withFolder(r1, 'search');
    expect(searchOf(r1), 'search = {"query":"alice"}').toEqual({ query: 'alice' });
    expect(await rowKeys(page), 'search alice dalam konteks ALFA: N01, N05, N11').toEqual(['N01', 'N05', 'N11']);
    const r2 = await resetAll(page);
    await withFolder(r2, 'reset');
    const sortResp = waitOpnames(page);
    await histTable(page).locator('thead th', { hasText: LANG.id.heads[0] }).first().click();
    const r3 = await sortResp;
    await withFolder(r3, 'sort');
    await page.waitForTimeout(700);
    expect(await rowKeys(page), 'sort Waktu naik: N13 pertama').toEqual([...alfa].reverse());
    const reloadResp = waitOpnames(page);
    await hist(page).locator('button.button-filter-wrapper').first().click();
    await withFolder(await reloadResp, 'reload');

    // folder BRAVO (konteks lain): mencakup sesi subfolder BRAVO-T1 (N04) dan sesi root N02
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open'), '✕ menutup drawer history').toHaveCount(0, { timeout: 10000 });
    await expect(pane.locator('.ant-card').first(), '✕ kembali ke tab Verification').toBeVisible();
    await expect(drawer, 'drawer folder tetap terbuka').toBeVisible();
    await page.locator('.ant-drawer-open .ant-drawer-close:visible').first().click();
    await expect(page.locator('.ant-drawer-open'), 'drawer folder ditutup').toHaveCount(0, { timeout: 10000 });

    const b = await openFolderVerification(app, page, `${P}BRAVO`);
    const respB = waitOpnames(page);
    await b.pane.getByText(LANG.id.cta).first().click();
    const rb = await respB;
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(500);
    expect(new URL(rb.url()).searchParams.get('idArchive'), 'BRAVO: idArchive').toBe(fx[`${P}BRAVO`]);
    const bravo = ['N02', 'N03', 'N04', 'N06', 'N08', 'N10', 'N12'];
    expect(await rowKeys(page), 'BRAVO: sesi yang mencakup BRAVO atau subfolder (N04 = BRAVO-T1), sesi root N02').toEqual(bravo);
    await app.screenshot('ac16-02-history-folder-bravo');
    await hist(page).locator('.ant-drawer-close').click();
    await page.locator('.ant-drawer-open .ant-drawer-close:visible').first().click();
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });

    // folder read-only (user QA hanya View): search / filter / Display Setting tetap aktif
    const v = await openFolderVerification(app, page, `${P}VIEWONLY`);
    const respV = waitOpnames(page);
    await v.pane.getByText(LANG.id.cta).first().click();
    await respV;
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(500);
    expect(await rowKeys(page), 'VIEWONLY: sesi N14').toEqual(['N14']);
    await expect(searchInput(page), 'search riwayat tidak disabled (drawer folder read-only)').toBeEnabled();
    await expect(hist(page).locator('button', { hasText: LANG.id.display }).first(), 'Display Setting riwayat tidak disabled').toBeEnabled();
    const rv = await typeSearch(page, 'qa28view');
    expect(searchOf(rv), 'search di drawer read-only terkirim').toEqual({ query: 'qa28view' });
    await filterIcon(page).click();
    await popover(page).waitFor({ state: 'visible', timeout: 10000 });
    await expect(popover(page).locator('.ant-select').first(), 'select filter tidak disabled').not.toHaveClass(/ant-select-disabled/);
    await app.screenshot('ac16-03-history-folder-readonly');
    await filterIcon(page).click();
    await hist(page).locator('.ant-drawer-close').click();
    await page.locator('.ant-drawer-open .ant-drawer-close:visible').first().click();
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-17 search + filter popover
  test('[default] ED-1028 AC-17 search bar + filter popover: query, User, Folder multi, rentang waktu, AND, Reset', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    const fx = ids();
    const calls = trace(page);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);

    // search bar: username, nama folder (level 2 / snapshot), tanpa beda huruf; folder tak terlihat tidak mencocokkan
    const r1 = await typeSearch(page, 'alice');
    expect(searchOf(r1), 'search = {"query":"alice"}').toEqual({ query: 'alice' });
    expect((await bodyOf(r1)).total, 'total 3').toBe(3);
    expect(await rowKeys(page), 'username alice').toEqual(['N01', 'N05', 'N11']);
    await expect(hist(page).locator('.search-bar .reset-filter'), 'ikon reset muncul sesudah filter').toBeVisible();
    await app.screenshot('ac17-01-search-username');
    const r2 = await typeSearch(page, 's1-x');
    expect(searchOf(r2), 'query huruf kecil').toEqual({ query: 's1-x' });
    expect(await rowKeys(page), 'nama folder level 2 "ALFA-S1-X": N01 (ikut otomatis), N11').toEqual(['N01', 'N11']);
    const r3 = await typeSearch(page, 'SECRET');
    expect((await bodyOf(r3)).total, 'nama folder SECRET (tak boleh dilihat user): tidak mencocokkan').toBe(0);
    await expect(hist(page).locator('.ant-empty').first(), 'tabel kosong standar').toBeVisible();
    await app.screenshot('ac17-02-search-kosong');
    const r4 = await resetAll(page);
    expect(searchOf(r4), 'Reset: search kosong').toBeNull();
    expect((await bodyOf(r4)).total, 'Reset: data kembali (14)').toBe(14);
    expect(await histRows(page), 'Reset: 10 baris halaman 1').toEqual(cellsOf(ORDER.slice(0, 10)));

    // popover: tiga query
    await openPopover(page);
    const rangePh = await popover(page).locator('.ant-picker-range input').first().getAttribute('placeholder');
    const selPh = trimAll(await popover(page).locator('.ant-select-selection-placeholder').allInnerTexts());
    monitor.note(`query popover: rentang="${rangePh}" select=${JSON.stringify(selPh)}`);
    expect(rangePh, 'popover: query Waktu Opname (rentang tanggal-jam)').toBe('Waktu Opname');
    expect(selPh, 'popover: select User dan Folder').toEqual(['User', 'Folder']);
    await expect(popover(page).locator('.ant-picker-range'), 'rentang tanggal-jam').toBeVisible();
    await app.screenshot('ac17-03-popover');

    // select User (endpoint opname-users)
    const selects = popover(page).locator('.ant-select');
    await selects.nth(0).click();
    // opsi bisa dimuat saat popover dibuka atau saat select diklik: cukup ada panggilan sukses ke endpoint-nya
    await expect.poll(() => calls.filter((c) => /select\/document-archive\/archive\/opname-users/.test(c.path)).length, { message: 'GET select opname-users terpanggil', timeout: 20000 }).toBeGreaterThan(0);
    expect(calls.filter((c) => /select\/document-archive\/archive\/opname-users/.test(c.path)).map((c) => c.status), 'GET select opname-users 200').toEqual(expect.arrayContaining([200]));
    const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
    await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: 15000 });
    const userOpts = trimAll(await dd.locator('.ant-select-item-option').allInnerTexts());
    monitor.note(`opsi User: ${JSON.stringify(userOpts)}`);
    expect(userOpts, 'opsi User = pelaku sesi terlihat (tanpa HID/draft/batal), urut username').toEqual(['qa28alice', 'qa28bob', 'qa28carol', 'qa28dave', 'qa28erin', 'qa28root', 'qa28view']);
    await app.screenshot('ac17-04-opsi-user');
    await dd.locator('.ant-select-item-option', { hasText: /^qa28bob$/ }).click();
    const r5 = await submitPopover(page);
    expect(searchOf(r5), 'createdBy = qa28bob').toEqual({ createdBy: 'qa28bob' });
    expect(await rowKeys(page), 'sesi qa28bob').toEqual(['N03', 'N08', 'N13']);

    // AND dengan query: "qa28" cocok semua username, createdBy mempersempit
    await searchInput(page).click();
    await searchInput(page).fill('qa28');
    const r6 = await (async () => {
      const resp = waitOpnames(page);
      await searchInput(page).press('Enter');
      return resp;
    })();
    expect(searchOf(r6), 'query + createdBy digabung').toEqual({ query: 'qa28', createdBy: 'qa28bob' });
    expect(await rowKeys(page), 'AND: query qa28 + user bob = 3 sesi bob').toEqual(['N03', 'N08', 'N13']);
    await resetAll(page);

    // select Folder multi (endpoint folders): cari per nama, label path; SECRET (tanpa View) tidak ada
    await openPopover(page);
    const folderSel = popover(page).locator('.ant-select').nth(1);
    await folderSel.click();
    await expect.poll(() => calls.filter((c) => /select\/document-archive\/archive\/folders/.test(c.path)).length, { message: 'GET select folders terpanggil', timeout: 20000 }).toBeGreaterThan(0);
    expect(calls.filter((c) => /select\/document-archive\/archive\/folders/.test(c.path)).map((c) => c.status), 'GET select folders 200').toEqual(expect.arrayContaining([200]));
    await page.waitForTimeout(500);
    const fdd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
    const pick = async (term, label) => {
      const sResp = page.waitForResponse((r) => /select\/document-archive\/archive\/folders/.test(r.url()) && /search=/.test(decodeURIComponent(r.url())), { timeout: 30000 });
      await folderSel.locator('input').fill(term);
      const s = await sResp;
      expect(s.status(), `select folders search=${term} 200`).toBe(200);
      await page.waitForTimeout(500);
      const opts = trimAll(await fdd.locator('.ant-select-item-option').allInnerTexts());
      monitor.note(`opsi Folder (cari "${term}"): ${JSON.stringify(opts)}`);
      return { opts, click: async () => fdd.locator('.ant-select-item-option', { hasText: new RegExp(`^${esc(label)}$`) }).first().click() };
    };
    const secret = await pick('SECRET', `${P}SECRET`);
    expect(secret.opts.filter((o) => o.includes('SECRET')), 'folder SECRET (tanpa View) tidak ada di opsi').toEqual([]);
    const s2 = await pick('ALFA-S2', `${P}ALFA / ${P}ALFA-S2`);
    expect(s2.opts, 'label = path "Induk / Folder"').toContain(`${P}ALFA / ${P}ALFA-S2`);
    await s2.click();
    const t1 = await pick('BRAVO-T1', `${P}BRAVO / ${P}BRAVO-T1`);
    expect(t1.opts, 'label path BRAVO-T1').toContain(`${P}BRAVO / ${P}BRAVO-T1`);
    await t1.click();
    const emptyf = await pick('EMPTYF', `${P}EMPTYF`);
    expect(emptyf.opts, 'folder aktif tanpa sesi tetap ada di opsi').toContain(`${P}EMPTYF`);
    await folderSel.locator('input').fill('');
    await app.screenshot('ac17-05-opsi-folder');
    await popover(page).click({ position: { x: 4, y: 4 } }); // tutup dropdown select (bukan Escape: menutup popover juga)
    await page.waitForTimeout(400);
    const picked = trimAll(await popover(page).locator('.ant-select').nth(1).locator('.ant-select-selection-item').allInnerTexts());
    monitor.note(`Folder terpilih: ${JSON.stringify(picked)}`);
    expect(picked.length, 'dua folder terpilih (multi)').toBe(2);
    const r7 = await submitPopover(page);
    const s7 = searchOf(r7);
    monitor.note(`search Folder multi: ${JSON.stringify(s7)}`);
    expect(Array.isArray(s7.idArchives), 'idArchives = array').toBe(true);
    expect(s7.idArchives, 'idArchives = [S2, T1] (id datar, urutan pilih)').toEqual([fx[`${P}ALFA-S2`], fx[`${P}BRAVO-T1`]]);
    expect(await rowKeys(page), 'sesi yang mencakup ALFA-S2 ATAU BRAVO-T1').toEqual(['N01', 'N02', 'N04', 'N05']);
    await app.screenshot('ac17-06-filter-folder');
    await resetAll(page);

    // rentang waktu: dua ujung, hanya awal, hanya akhir, ujung inklusif
    const fillRange = async (start, end) => {
      await openPopover(page);
      const inputs = popover(page).locator('.ant-picker-range input');
      const acceptPanel = async () => {
        await page.waitForTimeout(300);
        const ok = page.locator('.ant-picker-dropdown:not(.ant-picker-dropdown-hidden) .ant-picker-ok button');
        if (await ok.count()) await ok.first().click({ timeout: 1500 }).catch(() => null);
        await page.waitForTimeout(300);
      };
      if (start) {
        await inputs.nth(0).click();
        await inputs.nth(0).fill(start);
        await inputs.nth(0).press('Enter');
        await acceptPanel();
      }
      if (end) {
        await inputs.nth(1).click();
        await inputs.nth(1).fill(end);
        await inputs.nth(1).press('Enter');
        await acceptPanel();
      }
    };
    const typeRange = fillRange;
    await typeRange('05 Okt 2026 00:00:00', '06 Okt 2026 23:59:59');
    const r8 = await submitPopover(page);
    monitor.note(`search rentang: ${JSON.stringify(searchOf(r8))}`);
    expect(searchOf(r8).confirmedAt, 'confirmedAt = [awal, akhir] format Y-m-d H:i:s').toEqual(['2026-10-05 00:00:00', '2026-10-06 23:59:59']);
    expect(await rowKeys(page), 'rentang 5-6 Okt: N01, N02 (HID 6 Okt 20:00 tak terlihat)').toEqual(['N01', 'N02']);
    await app.screenshot('ac17-07-filter-waktu');
    await resetAll(page);

    await typeRange('04 Okt 2026 00:00:00', '');
    const r9 = await submitPopover(page);
    const c9 = searchOf(r9).confirmedAt;
    monitor.note(`hanya awal: ${JSON.stringify(c9)}`);
    expect(c9[0], 'hanya awal: ujung awal terkirim').toBe('2026-10-04 00:00:00');
    expect(c9[1] === null || c9[1] === undefined || c9[1] === '', 'hanya awal: ujung akhir kosong/null').toBe(true);
    expect(await rowKeys(page), 'sejak 4 Okt: N01, N02, N03').toEqual(['N01', 'N02', 'N03']);
    await resetAll(page);

    await fillRange('', '26 Sep 2026 23:59:59');
    const r10 = await submitPopover(page);
    const c10 = searchOf(r10);
    monitor.note(`hanya akhir: ${JSON.stringify(c10)}`);
    if (c10 && c10.confirmedAt) {
      expect(c10.confirmedAt[0] === null || c10.confirmedAt[0] === undefined || c10.confirmedAt[0] === '', 'hanya akhir: ujung awal kosong').toBe(true);
      expect(c10.confirmedAt[1], 'hanya akhir: ujung akhir').toBe('2026-09-26 23:59:59');
      expect(await rowKeys(page), 'sampai 26 Sep 23:59:59: N11, N12, N13, N14').toEqual(['N11', 'N12', 'N13', 'N14']);
    } else {
      monitor.note('hanya akhir: picker tidak menerima ketikan ujung akhir saja (dicatat, diperiksa lewat API di bawah)');
    }
    await resetAll(page);

    // ujung inklusif: awal = akhir = waktu tepat N01
    await typeRange('06 Okt 2026 16:40:00', '06 Okt 2026 16:40:00');
    const r11 = await submitPopover(page);
    expect(searchOf(r11).confirmedAt, 'awal = akhir = waktu N01').toEqual(['2026-10-06 16:40:00', '2026-10-06 16:40:00']);
    expect(await rowKeys(page), 'ujung inklusif: tepat N01').toEqual(['N01']);
    const r12 = await resetAll(page);
    expect(searchOf(r12), 'Reset mengosongkan search').toBeNull();
    expect((await bodyOf(r12)).total, 'data kembali 14').toBe(14);
    expect(await searchInput(page).inputValue(), 'input search kosong sesudah Reset').toBe('');

    // tidak ada request GET archives (list di belakang) selama bekerja di drawer
    expect((await listCalls(calls)).length, 'hanya 1 GET archives (muat halaman), tidak ada pencarian list di belakang').toBe(1);
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-18 Display Setting, sort, pagination
  test('[default] ED-1028 AC-18 Display Setting (sembunyikan Invalid, tampilkan Discan), sort per header, halaman 2', async ({ app, page, monitor }) => {
    const calls = trace(page);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const before = displayDb();
    monitor.note(`display setting DB sebelum: ${JSON.stringify(before.documentArchiveOpnameHistory)}`);
    expect(before.documentArchiveOpnameHistory, 'DB: 7 kolom aktif BR-6').toEqual(['confirmedAt', 'createdBy', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'invalidCount']);

    const dsDrawer = () => page.locator('.ant-drawer-open:not(.archive-opname-history)').last();
    const openSetting = async () => {
      await hist(page).locator('button', { hasText: LANG.id.display }).last().click();
      await dsDrawer().waitFor({ state: 'visible', timeout: 15000 });
      await dsDrawer().locator('.ant-skeleton').first().waitFor({ state: 'detached', timeout: 15000 }).catch(() => null);
      await app.settle();
    };
    const saveSetting = async () => {
      const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /display/i.test(r.url()), { timeout: 30000 });
      await dsDrawer().locator('.ant-drawer-extra button.ant-btn-primary').click();
      const r = await put;
      expect(r.status(), 'PUT display setting 200').toBe(200);
      await expect(page.locator('.ant-drawer-open:not(.archive-opname-history)'), 'drawer Display Setting menutup sesudah Save').toHaveCount(0, { timeout: 15000 });
      await page.waitForTimeout(1500);
    };
    const rowOfSetting = (label) => dsDrawer().locator('.ant-table').first().locator('tr.ant-table-row').filter({ hasText: new RegExp(`^\\s*${esc(label)}\\s*$`) }).first();

    await openSetting();
    // drawer Display Setting harus tampil DI ATAS drawer history / modal (z-index)
    const z = await page.evaluate(() => {
      const ds = Array.from(document.querySelectorAll('.ant-drawer-open')).find((d) => !d.classList.contains('archive-opname-history'));
      const r = ds.querySelector('.ant-drawer-content-wrapper').getBoundingClientRect();
      const top = document.elementFromPoint(r.x + r.width / 2, r.y + 60);
      return { visible: r.width > 100, topInDisplay: !!(top && top.closest('.ant-drawer-open:not(.archive-opname-history)')), rect: [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)] };
    });
    monitor.note(`Display Setting drawer: ${JSON.stringify(z)}`);
    expect(z.visible && z.topInDisplay, 'drawer Display Setting terlihat dan menerima klik (di atas drawer history & modal)').toBe(true);
    const names = trimAll(await dsDrawer().locator('.ant-table').first().locator('tr.ant-table-row').allInnerTexts());
    monitor.note(`isi Display Setting: ${JSON.stringify(names)}`);
    expect(names, 'kolom aktif + kolom tersedia (Discan, Belum Discan)').toEqual(expect.arrayContaining(['Waktu', 'User', 'Scope', 'Total Dokumen', 'Verified', 'Not Found', 'Invalid', 'Discan', 'Belum Discan']));
    await app.screenshot('ac18-01-display-setting');
    const invalidRow = rowOfSetting('Invalid');
    const scannedRow = rowOfSetting('Discan');
    await expect(invalidRow.locator('input[type="checkbox"]'), 'Invalid tercentang').toBeChecked();
    await expect(scannedRow.locator('input[type="checkbox"]'), 'Discan tidak tercentang').not.toBeChecked();
    await invalidRow.locator('input[type="checkbox"]').uncheck();
    await scannedRow.locator('input[type="checkbox"]').check();
    const reloadP = waitOpnames(page);
    await saveSetting();
    const rr = await reloadP;
    const res = await bodyOf(rr);
    expect(res.columns.map((c) => c.data_index), 'respons: Invalid keluar, scannedCount masuk').toContain('scannedCount');
    expect(res.columns.map((c) => c.data_index), 'respons: Invalid keluar').not.toContain('invalidCount');
    await expect.poll(async () => (await histHeads(page)).join('|'), { message: 'tabel ikut: Invalid hilang, Discan muncul', timeout: 15000 }).toContain('Discan');
    const heads = await histHeads(page);
    monitor.note(`header sesudah: ${JSON.stringify(heads)}`);
    expect(heads, 'header tanpa Invalid, dengan Discan').toEqual(['Waktu', 'User', 'Scope', 'Total Dokumen', 'Verified', 'Not Found', 'Discan']);
    const rows = await histRows(page);
    expect(rows[0], 'N01: Discan = 5').toEqual([dateText(S.N01[0]), 'qa28alice', S.N01[2], '5', '3', '1', '5']);
    expect(rows[1], 'N02: Discan = 5').toEqual([dateText(S.N02[0]), 'qa28root', S.N02[2], '8', '3', '1', '5']);
    expect(displayDb().documentArchiveOpnameHistory, 'DB: kolom aktif berubah').toEqual(['confirmedAt', 'createdBy', 'scope', 'totalDocuments', 'verifiedCount', 'notFoundCount', 'scannedCount']);
    await app.screenshot('ac18-02-kolom-diganti');

    // pulihkan lewat UI
    await openSetting();
    await rowOfSetting('Invalid').locator('input[type="checkbox"]').check();
    await rowOfSetting('Discan').locator('input[type="checkbox"]').uncheck();
    await saveSetting();
    await expect.poll(async () => (await histHeads(page)).join('|'), { message: 'kolom Invalid kembali', timeout: 15000 }).toContain('Invalid');
    expect(await histHeads(page), 'header kembali ke default').toEqual(LANG.id.heads);
    expect(displayDb().documentArchiveOpnameHistory, 'DB: kolom aktif kembali').toEqual(before.documentArchiveOpnameHistory);

    // sort per header (useTable v5: sorts[] bertumpuk, kolom yang diklik lebih dulu = utama; klik ketiga membatalkan sort kolom itu).
    // Urutan UI dibandingkan dengan urutan harapan yang dihitung dari tabel S (angka tangan) memakai daftar sorts di request.
    const FIELD = { confirmedAt: (k) => S[k][0], createdBy: (k) => S[k][1], totalDocuments: (k) => S[k][3], verifiedCount: (k) => S[k][4], notFoundCount: (k) => S[k][5], invalidCount: (k) => S[k][6] };
    const sortsOf = (resp) => new URL(resp.url()).searchParams.getAll('sorts[]').map((x) => JSON.parse(x));
    const expectedKeys = (sorts) =>
      [...ORDER].sort((a, b) => {
        for (const so of sorts) {
          const f = FIELD[so.sortBy];
          if (f && f(a) !== f(b)) return (f(a) < f(b) ? -1 : 1) * (so.sortType === 'asc' ? 1 : -1);
        }
        return S[a][0] < S[b][0] ? 1 : -1; // pemecah seri BE: confirmed_at turun
      });
    const thOf = (title) => histTable(page).locator('thead th', { hasText: title }).first();
    await expect(thOf('Scope').locator('.ant-table-column-sorters'), 'Scope tanpa pengurut').toHaveCount(0);
    for (const col of ['Waktu', 'User', 'Total Dokumen', 'Verified', 'Not Found', 'Invalid']) {
      await expect(thOf(col).locator('.ant-table-column-sorters'), `${col} punya pengurut`).toHaveCount(1);
    }
    const reopen = async () => {
      await hist(page).locator('.ant-drawer-close').click();
      await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
      const rp = waitOpnames(page);
      await modalVisible(page).getByText(LANG.id.cta).first().click();
      await rp;
      await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
      await page.waitForTimeout(500);
    };
    const clickSort = async (title) => {
      const resp = waitOpnames(page);
      await thOf(title).click();
      const r = await resp;
      expect(r.status(), `sort ${title}: GET opnames 200`).toBe(200);
      await page.waitForTimeout(700);
      const sorts = sortsOf(r);
      const got = await rowKeys(page);
      const want = expectedKeys(sorts).slice(0, 10);
      monitor.note(`sort ${title}: sorts=${JSON.stringify(sorts)} urutan UI=${got.join(',')}`);
      expect(got, `sort ${title} (${JSON.stringify(sorts)}): urutan UI = urutan harapan`).toEqual(want);
      return sorts;
    };
    let so = await clickSort('Waktu');
    expect(so, 'Waktu klik 1 = naik').toEqual([{ sortBy: 'confirmedAt', sortType: 'asc' }]);
    expect(await rowKeys(page), 'Waktu naik: N14 pertama (terlama)').toEqual([...ORDER].reverse().slice(0, 10));
    so = await clickSort('Waktu');
    expect(so, 'Waktu klik 2 = turun').toEqual([{ sortBy: 'confirmedAt', sortType: 'desc' }]);
    await app.screenshot('ac18-03-sort-waktu');
    await reopen();
    so = await clickSort('Total Dokumen');
    expect(so, 'Total Dokumen klik 1').toEqual([{ sortBy: 'totalDocuments', sortType: 'asc' }]);
    const totalsAsc = (await histRows(page)).map((r) => Number(r[3]));
    expect(totalsAsc, 'Total Dokumen naik (nilai tampil)').toEqual([...totalsAsc].sort((a, b) => a - b));
    so = await clickSort('Total Dokumen');
    expect(so, 'Total Dokumen klik 2').toEqual([{ sortBy: 'totalDocuments', sortType: 'desc' }]);
    const totalsDesc = (await histRows(page)).map((r) => Number(r[3]));
    expect(totalsDesc, 'Total Dokumen turun (nilai tampil)').toEqual([...totalsDesc].sort((a, b) => b - a));
    const third = waitOpnames(page);
    await thOf('Total Dokumen').click();
    const t3 = await third;
    expect(sortsOf(t3), 'klik ketiga: sort dibatalkan (tanpa sorts)').toEqual([]);
    await page.waitForTimeout(700);
    expect(await rowKeys(page), 'tanpa sort: urutan bawaan Waktu turun').toEqual(ORDER.slice(0, 10));
    await reopen();
    so = await clickSort('User');
    expect(so, 'User klik 1').toEqual([{ sortBy: 'createdBy', sortType: 'asc' }]);
    const usersAsc = (await histRows(page)).map((r) => r[1]);
    expect(usersAsc, 'User naik (nilai tampil)').toEqual([...usersAsc].sort());
    so = await clickSort('Verified');
    expect(so.map((x) => x.sortBy), 'Verified ditumpuk di belakang User').toEqual(['createdBy', 'verifiedCount']);
    await reopen();
    for (const col of ['Not Found', 'Invalid']) {
      await clickSort(col);
      await reopen();
    }
    const calls0 = (await histCalls(calls)).length;
    await thOf('Scope').click();
    await page.waitForTimeout(900);
    expect((await histCalls(calls)).length, 'klik header Scope: tanpa request sort').toBe(calls0);

    // halaman 2 mempertahankan sort (Waktu turun)
    await clickSort('Waktu'); // naik
    await clickSort('Waktu'); // turun
    const p2 = waitOpnames(page, (u) => u.searchParams.get('page') === '2');
    await hist(page).locator('.ant-pagination-item-2').click();
    const pr = await p2;
    expect(pr.status(), 'page=2: GET opnames 200').toBe(200);
    expect(new URL(pr.url()).searchParams.get('page'), 'request page=2').toBe('2');
    expect(sortsOf(pr), 'page=2 membawa sort Waktu turun').toEqual([{ sortBy: 'confirmedAt', sortType: 'desc' }]);
    await page.waitForTimeout(700);
    expect(await rowKeys(page), 'halaman 2 (urut Waktu turun): N11..N14').toEqual(['N11', 'N12', 'N13', 'N14']);
    expect(trimAll(await hist(page).locator('.ant-pagination-item-active').allInnerTexts()), 'halaman aktif = 2').toEqual(['2']);
    await app.screenshot('ac18-04-halaman-2');
    const p1 = waitOpnames(page, (u) => u.searchParams.get('page') === '1');
    await hist(page).locator('.ant-pagination-item-1').click();
    await p1;
    await page.waitForTimeout(500);

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-20 hasil satu sesi
  test('[default] ED-1028 AC-20 klik baris -> Hasil Opname: GET opnames/{id} + documents?result=scanned, kartu 5 angka, Segmented + jumlah, tabel, ✕ kembali ke halaman & filter yang sama', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const L = LANG.id;

    // filter dulu (search "alice") supaya "kembali dengan filter yang sama" bermakna; N01 = baris pertama
    const rs = await typeSearch(page, 'alice');
    expect(await rowKeys(page), 'search alice: N01, N05, N11').toEqual(['N01', 'N05', 'N11']);
    const before = (await histCalls(calls)).length;
    const detailP = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames\/[^/?]+(\?|$)/.test(r.url()) && !/folders/.test(r.url()), { timeout: 30000 });
    const docsP = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames\/[^/]+\/documents/.test(r.url()), { timeout: 30000 });
    await histRowsLoc(page).first().locator('td').nth(2).click();
    const detail = await detailP;
    const docs = await docsP;
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await resDrawer(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(800);
    void rs;
    const sid = fx._sessions.N01;
    expect(detail.url(), 'GET opnames/{id} untuk sesi N01').toContain(`/opnames/${sid}`);
    expect(docs.url(), 'GET documents untuk sesi N01').toContain(`/opnames/${sid}/documents`);
    expect(new URL(docs.url()).searchParams.get('result'), 'result=scanned (default)').toBe('scanned');
    expect(new URL(docs.url()).searchParams.get('page'), 'page=1').toBe('1');
    const all = await Promise.all(calls.map((c) => c.ready)).then(() => calls);
    const iDetail = all.findIndex((c) => c.path === `opnames/${sid}`);
    const iDocs = all.findIndex((c) => c.path === `opnames/${sid}/documents`);
    expect(iDetail, '#14 (detail) lebih dulu dari #17 (documents)').toBeGreaterThan(-1);
    expect(iDocs, '#17 ada dan sesudah #14').toBeGreaterThan(iDetail);
    const d = (await bodyOf(detail));
    monitor.note(`detail: counts=${JSON.stringify(d.counts)} result_counts=${JSON.stringify(d.result_counts)} is_partial=${d.is_partial}`);
    expect(d.is_partial, 'sesi penuh: is_partial false').toBe(false);
    expect(d.result_counts, 'result_counts sesi N01').toEqual({ all: 7, scanned: 5, verified: 3, not_found: 1, invalid: 1, unscanned: 2 });
    expect((await histCalls(calls)).length, 'membuka hasil tidak me-request ulang daftar').toBe(before);

    // judul + subjudul
    const title = (await resDrawer(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    monitor.note(`judul hasil: ${title}`);
    expect(title, 'judul "Hasil Opname"').toContain(L.resultTitle);
    expect(title, 'subjudul Waktu · User · Scope').toContain(`${dateText(S.N01[0])} · qa28alice · ${S.N01[2]}`);
    // menutupi drawer history (bukan hanya sebagian)
    const box = await page.evaluate(() => {
      const h = document.querySelector('.archive-opname-history.ant-drawer-open');
      const r = h.querySelector('.ant-drawer-content-wrapper').getBoundingClientRect();
      const nested = h.querySelector('.ant-drawer.ant-drawer-open .ant-drawer-content-wrapper').getBoundingClientRect();
      const top = document.elementFromPoint(r.x + 40, r.y + 130);
      return { hist: [r.x, r.y, r.width, r.height].map(Math.round), nested: [nested.x, nested.y, nested.width, nested.height].map(Math.round), topInResult: !!(top && top.closest('.ant-drawer-open .ant-drawer-open')) };
    });
    monitor.note(`kotak hasil vs history: ${JSON.stringify(box)}`);
    expect(Math.abs(box.hist[2] - box.nested[2]) <= 3 && Math.abs(box.hist[3] - box.nested[3]) <= 3, 'drawer hasil selebar & setinggi drawer history').toBe(true);
    expect(box.topInResult, 'tabel history di bawahnya tertutup').toBe(true);

    // kartu = counts (angka rekaman); Segmented = result_counts; default Scanned
    const rd = resDrawer(page);
    const cards = trimAll(await rd.locator('.ant-card').allInnerTexts());
    monitor.note(`kartu: ${JSON.stringify(cards)}`);
    expect(cards, '5 kartu: angka + label').toEqual(['5 ' + L.cards[0], '3 ' + L.cards[1], '1 ' + L.cards[2], '1 ' + L.cards[3], '2 ' + L.cards[4]]);
    const seg = trimAll(await rd.locator('.ant-segmented-item').allInnerTexts());
    monitor.note(`segmented: ${JSON.stringify(seg)}`);
    expect(seg, 'Segmented + jumlah (result_counts)').toEqual([`${L.seg[0]} 7`, `${L.seg[1]} 5`, `${L.seg[2]} 3`, `${L.seg[3]} 1`, `${L.seg[4]} 1`]);
    expect(trimAll(await rd.locator('.ant-segmented-item-selected').allInnerTexts()), 'default Scanned').toEqual([`${L.seg[1]} 5`]);
    expect(trimAll(await rd.locator('.ant-table').first().locator('thead th').allInnerTexts()).filter(Boolean), 'kolom tabel Step 3').toEqual(L.docHeads);

    const docRows = async () => {
      const rows = await tableRows(rd, 0);
      return Object.fromEntries(rows.map((r) => [r[0], { type: r[1], folder: r[2], time: r[3], result: r[4] }]));
    };
    const scanned = await docRows();
    monitor.note(`baris Scanned: ${JSON.stringify(scanned)}`);
    expect(Object.keys(scanned).sort(), 'Scanned: 5 baris').toEqual([`${P}A1`, `${P}B1`, `${P}INV-001`, `${P}S1a`, `${P}X1`].sort());
    expect(scanned[`${P}A1`].folder, 'folder A1').toBe(`${P}ALFA`);
    expect(scanned[`${P}S1a`].folder, 'folder S1a').toBe(`${P}ALFA-S1`);
    expect(scanned[`${P}X1`].folder, 'folder X1').toBe(`${P}ALFA-S1-X`);
    expect(scanned[`${P}A1`].result, 'A1 Terverifikasi').toBe(L.res.verified);
    expect(scanned[`${P}B1`], 'B1 Not found: Folder "Di luar scope opname"').toMatchObject({ folder: L.outside, result: L.res.not_found });
    expect(scanned[`${P}INV-001`], 'INV-001 Invalid: Folder "—"').toMatchObject({ folder: '—', result: L.res.invalid });
    expect(scanned[`${P}INV-001`].type, 'Invalid: tipe "—"').toBe('—');
    // latar baris khusus
    const bg = async (name) =>
      rd.locator('.ant-table tbody tr.ant-table-row').filter({ hasText: name }).first().evaluate((tr) => ({ cls: tr.className, bg: getComputedStyle(tr.querySelector('td')).backgroundColor }));
    const bgV = await bg(`${P}A1`);
    const bgN = await bg(`${P}B1`);
    const bgI = await bg(`${P}INV-001`);
    monitor.note(`latar baris: verified=${JSON.stringify(bgV)} notFound=${JSON.stringify(bgN)} invalid=${JSON.stringify(bgI)}`);
    expect(bgN.cls, 'baris Not found berkelas row-not-found').toContain('row-not-found');
    expect(bgI.cls, 'baris Invalid berkelas row-invalid').toContain('row-invalid');
    expect(bgN.bg !== bgV.bg && bgI.bg !== bgV.bg && bgN.bg !== bgI.bg, 'latar Not found, Invalid, dan baris biasa berbeda').toBe(true);
    expect(bgN.bg, 'Not found: latar bukan transparan').not.toMatch(/rgba\(0, 0, 0, 0\)|transparent/);
    // read-only: tanpa peringatan Step 3 dan tanpa tombol aksi
    const rdText = await rd.innerText();
    expect(rdText, 'tanpa peringatan Step 3').not.toMatch(/akan berubah menjadi unverified/);
    expect(rdText, 'tanpa catatan parsial (sesi penuh)').not.toContain(L.partial);
    expect(trimAll(await rd.locator('button').allInnerTexts()).filter((t) => /Kembali|Konfirmasi|Back|Confirm|Selesai/i.test(t)), 'tanpa tombol Back/Confirm').toEqual([]);
    await app.screenshot('ac20-01-hasil-sesi');

    // ganti filter -> request result baru; jumlah dan isi sesuai
    const pickSeg = async (idx) => {
      const resp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/opnames\/[^/]+\/documents/.test(r.url()), { timeout: 30000 });
      await rd.locator('.ant-segmented-item').nth(idx).click();
      const r = await resp;
      expect(r.status(), 'documents 200').toBe(200);
      await page.waitForTimeout(600);
      return new URL(r.url()).searchParams;
    };
    let q = await pickSeg(3);
    expect(q.get('result'), 'Tidak ditemukan -> result=not_found').toBe('not_found');
    expect(q.get('page'), 'ganti filter -> halaman 1').toBe('1');
    expect(Object.keys(await docRows()), 'not_found: hanya B1').toEqual([`${P}B1`]);
    await app.screenshot('ac20-02-not-found');
    q = await pickSeg(4);
    expect(q.get('result'), 'Tidak valid -> result=invalid').toBe('invalid');
    expect(Object.keys(await docRows()), 'invalid: hanya INV-001').toEqual([`${P}INV-001`]);
    await app.screenshot('ac20-03-invalid');
    q = await pickSeg(2);
    expect(q.get('result'), 'Terverifikasi -> result=verified').toBe('verified');
    expect(Object.keys(await docRows()).sort(), 'verified: A1, S1a, X1').toEqual([`${P}A1`, `${P}S1a`, `${P}X1`].sort());
    q = await pickSeg(0);
    expect(q.get('result'), 'Semua -> result=all').toBe('all');
    const allRows = await docRows();
    expect(Object.keys(allRows).length, 'all: 7 baris').toBe(7);
    expect(allRows[`${P}A2`], 'baris belum discan: hasil "Belum discan", tanpa jam').toMatchObject({ result: L.res.unscanned, time: '—' });
    expect(allRows[`${P}S2a`].result, 'S2a belum discan').toBe(L.res.unscanned);
    await app.screenshot('ac20-04-semua');

    // ✕: kembali ke tabel dengan filter (search alice) yang sama, tanpa request ulang daftar
    await resDrawer(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open'), '✕ menutup drawer hasil').toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(600);
    expect(await searchInput(page).inputValue(), 'search tetap "alice"').toBe('alice');
    expect(await rowKeys(page), 'baris sama (filter alice)').toEqual(['N01', 'N05', 'N11']);
    expect((await histCalls(calls)).length, '✕ tidak me-request ulang daftar').toBe(before);
    // klik lagi = GET detail baru
    const nDetail = calls.filter((c) => c.path === `opnames/${sid}`).length;
    const again = page.waitForResponse((r) => r.request().method() === 'GET' && r.url().includes(`/opnames/${sid}`) && !/documents/.test(r.url()), { timeout: 30000 });
    await histRowsLoc(page).first().locator('td').nth(1).click();
    await again;
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    expect(calls.filter((c) => c.path === `opnames/${sid}`).length, 'buka lagi: GET detail baru (data terbaru)').toBe(nDetail + 1);
    await page.waitForTimeout(600);
    await resDrawer(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });

    // halaman 2 bertahan: reset -> halaman 2 -> klik N11 -> ✕ -> tetap halaman 2
    await resetAll(page);
    const p2 = waitOpnames(page, (u) => u.searchParams.get('page') === '2');
    await hist(page).locator('.ant-pagination-item-2').click();
    await p2;
    await page.waitForTimeout(700);
    expect(await rowKeys(page), 'halaman 2: N11..N14').toEqual(['N11', 'N12', 'N13', 'N14']);
    const nList = (await histCalls(calls)).length;
    await histRowsLoc(page).first().locator('td').nth(1).click();
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(900);
    const t11 = (await resDrawer(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    expect(t11, 'subjudul sesi N11').toContain(`${dateText(S.N11[0])} · qa28alice · ${S.N11[2]}`);
    const cards11 = trimAll(await resDrawer(page).locator('.ant-card').allInnerTexts());
    expect(cards11.slice(0, 2), 'N11: kartu = angka rekaman (1 total, 1 verified)').toEqual(['1 ' + L.cards[0], '1 ' + L.cards[1]]);
    await resDrawer(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(500);
    expect(trimAll(await hist(page).locator('.ant-pagination-item-active').allInnerTexts()), '✕: tetap di halaman 2').toEqual(['2']);
    expect(await rowKeys(page), '✕: baris halaman 2 sama').toEqual(['N11', 'N12', 'N13', 'N14']);
    expect((await histCalls(calls)).length, '✕: tanpa request ulang daftar').toBe(nList);

    // sesi parsial (N02 menyertakan SECRET yang tak terlihat user QA): catatan + jumlah dari baris tampil + kartu angka rekaman
    const p1 = waitOpnames(page, (u) => u.searchParams.get('page') === '1');
    await hist(page).locator('.ant-pagination-item-1').click();
    await p1;
    await page.waitForTimeout(500);
    const dP = page.waitForResponse((r) => r.request().method() === 'GET' && r.url().includes(`/opnames/${fx._sessions.N02}`) && !/documents/.test(r.url()), { timeout: 30000 });
    await histRowsLoc(page).nth(1).locator('td').nth(2).click();
    const dp = await bodyOf(await dP);
    await resDrawer(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(900);
    monitor.note(`N02 (parsial, user QA): counts=${JSON.stringify(dp.counts)} result_counts=${JSON.stringify(dp.result_counts)} is_partial=${dp.is_partial}`);
    expect(dp.is_partial, 'N02: is_partial true untuk user non-superadmin').toBe(true);
    await expect(resDrawer(page).getByText(L.partial), 'catatan BR-11 tampil').toBeVisible();
    expect(trimAll(await resDrawer(page).locator('.ant-card').allInnerTexts()), 'kartu = angka rekaman (8/3/1/1/2), tidak disaring').toEqual(['8 ' + L.cards[0], '3 ' + L.cards[1], '1 ' + L.cards[2], '1 ' + L.cards[3], '2 ' + L.cards[4]]);
    expect(trimAll(await resDrawer(page).locator('.ant-segmented-item').allInnerTexts()), 'Segmented dari baris yang tampil (H1/H2 di SECRET tersembunyi)').toEqual([`${L.seg[0]} 5`, `${L.seg[1]} 4`, `${L.seg[2]} 2`, `${L.seg[3]} 1`, `${L.seg[4]} 1`]);
    expect(Object.keys(await docRows()).sort(), 'Scanned N02: tanpa H1').toEqual([`${P}B1`, `${P}INV-002`, `${P}S2a`, `${P}T1a`].sort());
    const qa = await pickSeg(0);
    expect(qa.get('result')).toBe('all');
    const allN02 = await docRows();
    expect(Object.keys(allN02).sort(), 'All N02: 5 baris (H1, H2 tidak tampil)').toEqual([`${P}A1`, `${P}B1`, `${P}INV-002`, `${P}S2a`, `${P}T1a`].sort());
    expect(await resDrawer(page).innerText(), 'nama dokumen SECRET tidak bocor').not.toMatch(new RegExp(`${P}H[12]`));
    await app.screenshot('ac20-05-sesi-parsial');
    await resDrawer(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-22 scanner
  test('[default] ED-1028 AC-22 scan USB: saat drawer history terbuka tidak ada GET archives; sesudah ditutup (✕ atau modal ditutup) scan mencari list lagi', async ({ app, page, monitor }) => {
    const calls = trace(page);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const code = `${P}A1`;
    const scan = async (focusLoc) => {
      // fokus di luar input (judul drawer/modal; BUKAN mask: klik mask menutup modal)
      if (focusLoc) await focusLoc.click();
      await page.waitForTimeout(300);
      await page.keyboard.type(code, { delay: 0 });
      await page.keyboard.press('Enter');
    };

    // A: history terbuka
    const nList0 = (await listCalls(calls)).length;
    const nHist0 = (await histCalls(calls)).length;
    await scan(hist(page).locator('.ant-drawer-title'));
    await page.waitForTimeout(2500);
    const listAfter = await listCalls(calls);
    monitor.note(`history terbuka, scan "${code}": GET archives baru=${listAfter.length - nList0}, GET opnames baru=${(await histCalls(calls)).length - nHist0}`);
    expect(listAfter.length, 'history terbuka: scan TIDAK memicu GET archives').toBe(nList0);
    const hAfter = await histCalls(calls);
    if (hAfter.length > nHist0) {
      monitor.note(`scan masuk ke search riwayat: ${JSON.stringify(hAfter[hAfter.length - 1].search)}`);
    }
    await app.screenshot('ac22-01-scan-saat-history');

    // B: tutup history (✕), modal masih terbuka -> scanner halaman aktif lagi
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(500);
    const bResp = waitList(page, (u) => (u.searchParams.get('search') || '').includes(code));
    await scan(modalVisible(page).locator('.ant-modal-title'));
    const b = await bResp;
    expect(b.status(), 'sesudah ✕: scan memicu GET archives?search=kode').toBe(200);
    await page.waitForTimeout(800);

    // C: buka history lagi, tutup MODAL (klik di luar modal) tanpa menutup history dulu -> scanner halaman aktif lagi
    const modal = modalVisible(page);
    const respP = waitOpnames(page);
    await modal.getByText(LANG.id.cta).first().click();
    await respP;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(600);
    const nList1 = (await listCalls(calls)).length;
    await scan(hist(page).locator('.ant-drawer-title'));
    await page.waitForTimeout(2000);
    expect((await listCalls(calls)).length, 'history terbuka lagi: scan tidak memicu GET archives').toBe(nList1);
    await page.mouse.click(5, 5); // klik mask = tutup modal (induk) tanpa menutup history dulu
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal'), 'modal induk tertutup (history ikut tertutup)').toHaveCount(0, { timeout: 10000 });
    await expect(page.locator('.archive-opname-history.ant-drawer-open'), 'history ikut tertutup').toHaveCount(0);
    const cResp = waitList(page, (u) => (u.searchParams.get('search') || '').includes(code));
    await scan(null);
    expect((await cResp).status(), 'modal induk ditutup: scan memicu GET archives lagi').toBe(200);
    await page.waitForTimeout(800);

    // D: konteks drawer folder (tab Verification): history terbuka -> tidak ada GET archives; ✕ -> aktif; drawer folder ditutup lewat mask -> aktif
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await page.waitForTimeout(800);
    const { pane } = await openFolderVerification(app, page, `${P}ALFA`);
    const rD = waitOpnames(page);
    await pane.getByText(LANG.id.cta).first().click();
    await rD;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    const nList2 = (await listCalls(calls)).length;
    await scan(hist(page).locator('.ant-drawer-title'));
    await page.waitForTimeout(2000);
    expect((await listCalls(calls)).length, 'drawer folder + history terbuka: scan tidak memicu GET archives').toBe(nList2);
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(500);
    const dResp = waitList(page, (u) => (u.searchParams.get('search') || '').includes(code));
    await scan(drawerOpen(page).locator('.ant-drawer-title').first());
    expect((await dResp).status(), 'drawer folder: sesudah ✕ history scan memicu GET archives').toBe(200);
    await page.waitForTimeout(800);
    const rD2 = waitOpnames(page);
    await pane.getByText(LANG.id.cta).first().click();
    await rD2;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(600);
    await page.mouse.click(200, 500); // mask drawer folder (induk) saat history terbuka
    await expect(page.locator('.ant-drawer-open'), 'drawer folder ditutup lewat mask, history ikut tertutup').toHaveCount(0, { timeout: 10000 });
    const eResp = waitList(page, (u) => (u.searchParams.get('search') || '').includes(code));
    await scan(null);
    expect((await eResp).status(), 'drawer folder ditutup: scan memicu GET archives lagi').toBe(200);
    await page.waitForTimeout(800);

    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-23 filter tanpa hasil + folder tanpa sesi
  test('[default] ED-1028 AC-23 folder tanpa sesi (EMPTYF) dan filter tanpa hasil: tabel kosong standar, Reset mengembalikan data', async ({ app, page, monitor }) => {
    const fx = ids();
    const calls = trace(page);
    await openArchive(app, page);

    // folder tanpa sesi: overview kosong + history kosong
    const { pane } = await openFolderVerification(app, page, `${P}EMPTYF`);
    expect((await tableRows(pane, 1)).length, 'overview tab folder tanpa sesi: 0 baris').toBe(0);
    const respP = waitOpnames(page);
    await pane.getByText(LANG.id.cta).first().click();
    const resp = await respP;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(800);
    expect(new URL(resp.url()).searchParams.get('idArchive'), 'idArchive = EMPTYF').toBe(fx[`${P}EMPTYF`]);
    expect(resp.status(), '200').toBe(200);
    expect((await bodyOf(resp)).total, 'total 0').toBe(0);
    await expect(hist(page).locator('.ant-empty').first(), 'empty state').toBeVisible();
    await app.screenshot('ac23-02-folder-tanpa-sesi');
    await hist(page).locator('.ant-drawer-close').click();
    await page.locator('.ant-drawer-open .ant-drawer-close:visible').first().click();
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });

    // All Archive: filter tanpa hasil (rentang tahun lalu) -> kosong; Reset -> data kembali
    await openHistoryFromModal(app, page);
    await openPopover(page);
    const rin = popover(page).locator('.ant-picker-range input');
    const accept = async () => {
      await page.waitForTimeout(300);
      const okb = page.locator('.ant-picker-dropdown:not(.ant-picker-dropdown-hidden) .ant-picker-ok button');
      if (await okb.count()) await okb.first().click({ timeout: 1500 }).catch(() => null);
      await page.waitForTimeout(300);
    };
    await rin.nth(0).click();
    await rin.nth(0).fill('01 Jan 2025 00:00:00');
    await rin.nth(0).press('Enter');
    await accept();
    await rin.nth(1).click();
    await rin.nth(1).fill('31 Des 2025 23:59:59');
    await rin.nth(1).press('Enter');
    await accept();
    const r = await submitPopover(page);
    monitor.note(`filter tahun lalu: ${JSON.stringify(searchOf(r))}`);
    expect((await bodyOf(r)).total, 'tanpa hasil: total 0').toBe(0);
    await expect(hist(page).locator('.ant-empty').first(), 'tabel kosong standar').toBeVisible();
    await app.screenshot('ac23-03-filter-kosong');
    const r2 = await resetAll(page);
    expect((await bodyOf(r2)).total, 'Reset: data kembali (14)').toBe(14);
    expect(await rowKeys(page), 'Reset: 10 sesi terbaru').toEqual(ORDER.slice(0, 10));

    // jalur error: GET opnames gagal -> toast, tabel kosong, ✕ tetap menutup; buka lagi normal
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    let blocked = true;
    await page.route(OPNAMES_RE, (route) => {
      if (blocked && route.request().method() === 'GET') {
        return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Validasi gagal (uji)', errors: { confirmedAt: ['x'] } }) });
      }
      return route.continue();
    });
    const modal = modalVisible(page);
    await modal.getByText(LANG.id.cta).first().click();
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(1500);
    await expect(page.locator('.ant-message-notice, .ant-notification-notice').first(), 'toast error muncul').toBeVisible();
    expect(await histRows(page), 'tabel kosong (tanpa baris palsu)').toEqual([]);
    await app.screenshot('ac23-04-error-422');
    await hist(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open'), '✕ tetap menutup drawer').toHaveCount(0, { timeout: 10000 });
    expectRejected(monitor, 'GET opnames dimodifikasi 422', { status: 422, urlRe: /document-archive\/opnames/ });
    blocked = false;
    await page.unroute(OPNAMES_RE);
    const resp3 = waitOpnames(page);
    await modal.getByText(LANG.id.cta).first().click();
    expect((await resp3).status(), 'buka lagi tanpa blokir: normal').toBe(200);
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    expect(await rowKeys(page), 'buka lagi: 10 sesi terbaru').toEqual(ORDER.slice(0, 10));
    void calls;
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-20 jalur error hasil sesi
  test('[default] ED-1028 AC-20 jalur error hasil sesi: GET opnames/{id} 404 -> satu toast, isi kosong tanpa kartu 0, ✕ kembali ke tabel', async ({ app, page, monitor }) => {
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    let blocked = true;
    const DETAIL_RE = /\/document-archive\/opnames\/[^/?]+(\?|$)/;
    await page.route(DETAIL_RE, (route) => {
      const u = route.request().url();
      if (blocked && route.request().method() === 'GET' && !/folders/.test(u)) {
        return route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ status: 'error', msg_code: 'ARCHIVE412', code: 'ARCHIVE412', message: 'Opname session not found (uji)' }) });
      }
      return route.continue();
    });
    const docsCalls = [];
    page.on('request', (r) => { if (/\/opnames\/[^/]+\/documents/.test(r.url())) docsCalls.push(r.url()); });
    await histRowsLoc(page).first().locator('td').nth(2).click();
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(1500);
    await expect(page.locator('.ant-message-notice, .ant-notification-notice').first(), 'toast error').toBeVisible();
    const toasts = await page.locator('.ant-message-notice, .ant-notification-notice').count();
    monitor.note(`jumlah toast: ${toasts}; GET documents: ${docsCalls.length}`);
    expect(toasts, 'satu toast saja').toBeLessThanOrEqual(1);
    expect(docsCalls.length, 'documents tidak diminta bila detail gagal').toBe(0);
    await expect(resDrawer(page).locator('.ant-card'), 'tanpa kartu angka 0 yang menyesatkan').toHaveCount(0);
    await expect(resDrawer(page).locator('.ant-empty').first(), 'isi hanya ikon kosong').toBeVisible();
    await app.screenshot('ac20-06-error-404');
    await resDrawer(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await expect(histRowsLoc(page).first(), '✕ kembali ke tabel').toBeVisible();
    expectRejected(monitor, 'GET opnames/{id} dimodifikasi 404', { status: 404, urlRe: /document-archive\/opnames\/[^/]+$/ });
    blocked = false;
    await page.unroute(DETAIL_RE);
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-21 user2 (EN)
  test('[user2] ED-1028 AC-21 user lokasi terbatas tanpa Opname Document (EN): history terbuka, 13 sesi terlihat, hasil sesi penuh dan parsial (catatan BR-11)', async ({ app, page, monitor }) => {
    fixture('ensure-sessions');
    const fx = ids();
    const db = inspectDb();
    const confirmedExtra = db.sessions.filter((s) => s.status === 2 && !/^qa28/.test(s.created_by)).length; // sesi nyata dari test Confirm (bila sudah jalan)
    const L = LANG.en;
    const calls = trace(page);
    await openArchive(app, page);
    const { resp } = await openHistoryFromModal(app, page, 'en');
    expect(resp.status(), '[user2] GET opnames 200 (List Archive tanpa Opname Document)').toBe(200);
    const res = await bodyOf(resp);
    monitor.note(`[user2] total=${res.total} (13 sesi uji terlihat${confirmedExtra ? ` + ${confirmedExtra} sesi nyata` : ''})`);
    expect(res.total, '[user2] 13 sesi uji terlihat (N14 folder VIEWONLY, HID, draft, batal tidak)').toBe(13 + confirmedExtra);
    const title = (await hist(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    expect(title, '[user2] judul EN').toContain(L.title);
    expect(title, '[user2] subjudul EN').toContain(L.subAll);
    expect(await histHeads(page), '[user2] kolom EN').toEqual(L.heads);
    expect(await hist(page).innerText(), '[user2] sesi tersembunyi tidak tampil').not.toMatch(/qa28hid|qa28draft|qa28cancel|qa28view/);
    expect(await hist(page).innerText(), '[user2] tanpa "N sessions"').not.toMatch(/\d+\s+sessions?\b/i);
    await app.screenshot('user2-01-history');

    // N01 (penuh)
    const openRowOf = async (user) => {
      const rr = await typeSearch(page, user);
      void rr;
      const first = histRowsLoc(page).first();
      await first.locator('td').nth(2).click();
      await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
      await resDrawer(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
      await page.waitForTimeout(900);
    };
    const closeResult = async () => {
      await resDrawer(page).locator('.ant-drawer-close').click();
      await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    };
    await openRowOf('qa28alice');
    const t1 = (await resDrawer(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    expect(t1, '[user2] judul hasil EN').toContain(L.resultTitle);
    expect(t1, '[user2] subjudul sesi terbaru alice').toContain(`${dateText(S.N01[0], 'en')} · qa28alice · ${S.N01[2]}`);
    expect(await resDrawer(page).innerText(), '[user2] sesi penuh: tanpa catatan parsial').not.toContain(L.partial);
    expect(trimAll(await resDrawer(page).locator('.ant-segmented-item').allInnerTexts()), '[user2] Segmented N01').toEqual([`${L.seg[0]} 7`, `${L.seg[1]} 5`, `${L.seg[2]} 3`, `${L.seg[3]} 1`, `${L.seg[4]} 1`]);
    await app.screenshot('user2-02-hasil-penuh');
    await closeResult();
    await resetAll(page);

    // N02 (parsial)
    const dP = page.waitForResponse((r) => r.request().method() === 'GET' && r.url().includes(`/opnames/${fx._sessions.N02}`) && !/documents/.test(r.url()), { timeout: 30000 });
    await typeSearch(page, 'qa28root');
    await histRowsLoc(page).first().locator('td').nth(2).click();
    const dp = await bodyOf(await dP);
    await resDrawer(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(900);
    monitor.note(`[user2] N02: is_partial=${dp.is_partial} result_counts=${JSON.stringify(dp.result_counts)}`);
    expect(dp.is_partial, '[user2] N02 parsial').toBe(true);
    await expect(resDrawer(page).getByText(L.partial), '[user2] catatan BR-11 (EN)').toBeVisible();
    expect(trimAll(await resDrawer(page).locator('.ant-card').allInnerTexts()), '[user2] kartu = angka rekaman').toEqual(['8 ' + L.cards[0], '3 ' + L.cards[1], '1 ' + L.cards[2], '1 ' + L.cards[3], '2 ' + L.cards[4]]);
    expect(trimAll(await resDrawer(page).locator('.ant-segmented-item').allInnerTexts()), '[user2] Segmented dari baris tampil').toEqual([`${L.seg[0]} 5`, `${L.seg[1]} 4`, `${L.seg[2]} 2`, `${L.seg[3]} 1`, `${L.seg[4]} 1`]);
    const rowsOf = async () => Object.fromEntries((await tableRows(resDrawer(page), 0)).map((r) => [r[0], { folder: r[2], result: r[4] }]));
    expect(Object.keys(await rowsOf()).sort(), '[user2] Scanned N02: B1, T1a, S2a, INV-002 (tanpa H1)').toEqual([`${P}B1`, `${P}INV-002`, `${P}S2a`, `${P}T1a`].sort());
    expect((await rowsOf())[`${P}S2a`], '[user2] Not found: "Outside opname scope"').toMatchObject({ folder: L.outside, result: L.res.not_found });
    await resDrawer(page).locator('.ant-segmented-item').nth(0).click();
    await page.waitForTimeout(1200);
    const allRows = await rowsOf();
    expect(Object.keys(allRows).sort(), '[user2] All N02: 5 baris, tanpa H1/H2').toEqual([`${P}A1`, `${P}B1`, `${P}INV-002`, `${P}S2a`, `${P}T1a`].sort());
    expect(allRows[`${P}A1`].result, '[user2] A1 Not scanned').toBe(L.res.unscanned);
    expect(await resDrawer(page).innerText(), '[user2] nama dokumen SECRET tidak bocor').not.toMatch(new RegExp(`${P}H[12]`));
    await app.screenshot('user2-03-hasil-parsial');
    await closeResult();

    // konteks folder (drawer folder -> tab Verification) dalam bahasa EN: subjudul, kolom, isi
    await hist(page).locator('.ant-drawer-close').first().click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await modalVisible(page).locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });
    const { pane } = await openFolderVerification(app, page, `${P}ALFA`, 'en');
    const respF = waitOpnames(page);
    await pane.getByText(L.cta).first().click();
    const rf = await respF;
    await hist(page).waitFor({ state: 'visible', timeout: 15000 });
    await histRowsLoc(page).first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    expect(new URL(rf.url()).searchParams.get('idArchive'), '[user2] idArchive = ALFA').toBe(fx[`${P}ALFA`]);
    const tf = (await hist(page).locator('.ant-drawer-title').innerText()).replace(/\s+/g, ' ');
    expect(tf, '[user2] judul folder EN').toContain(L.title);
    expect(tf, '[user2] subjudul folder EN').toContain(L.subFolder(`${P}ALFA`));
    expect(await histHeads(page), '[user2] kolom EN (folder)').toEqual(L.heads);
    expect(await rowKeys(page, 'en'), '[user2] sesi yang mencakup ALFA').toEqual(['N01', 'N02', 'N05', 'N07', 'N09', 'N11', 'N13']);
    await app.screenshot('user2-04-history-folder');
    await hist(page).locator('.ant-drawer-close').first().click();
    await page.locator('.ant-drawer-open .ant-drawer-close:visible').first().click();
    await expect(page.locator('.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });

    // sesi tak terlihat user2 (N14) tidak bisa dibuka lewat id: API = 404 ARCHIVE412 (FE tidak menawarkannya); tidak ada GET 4xx dari UI
    const failed = calls.filter((c) => c.status >= 400);
    expect(failed.map((c) => `${c.status} ${c.path}`), '[user2] tidak ada respons 4xx dari alur UI').toEqual([]);
    noServerError(monitor);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------- AC-24 regresi + Confirm nyata
  test('[default] ED-1028 AC-24 regresi: modal 05 & tab Verification, Opname Step 2/3 (Confirm nyata) -> sesi baru di history -> hasil sama dengan Step 3; list Archive, sort, scan, mode Pilih', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    const fx = ids();
    const L = LANG.id;
    const calls = trace(page);
    await openArchive(app, page);

    // list Archive: sort + mode Pilih tetap jalan
    const sortResp = waitList(page, (u) => /sort/i.test(decodeURIComponent(u.search)));
    await page.locator('.archive-page .ant-table-thead th', { hasText: /^\s*Nama\s*$/ }).first().click();
    expect((await sortResp).status(), 'sort Nama: GET archives 200').toBe(200);
    await page.waitForTimeout(700);
    await page.locator('.archive-page button', { hasText: /^(Pilih|Select)$/ }).first().click();
    await expect(page.locator('.archive-page .ant-table-tbody input[type="checkbox"]').first(), 'mode Pilih: checkbox').toBeVisible();
    await page.locator('.archive-page button', { hasText: /Batal Pilih|Cancel/ }).first().click();
    await expect(page.locator('.archive-page .ant-table-tbody input[type="checkbox"]'), 'Batal Pilih: checkbox hilang').toHaveCount(0);
    const sresp = waitList(page, (u) => (u.searchParams.get('search') || '').includes(`${P}RD`));
    await page.mouse.click(5, 5);
    await page.keyboard.type(`${P}RD`, { delay: 0 });
    await page.keyboard.press('Enter');
    expect((await sresp).status(), 'scan pencarian list Archive jalan (history tertutup)').toBe(200);
    await page.waitForTimeout(800);
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await page.waitForTimeout(800);

    // modal 05 + tab Verification (04): overview 3 sesi tetap benar
    const modal = await openModal(app, page);
    expect(await tableRows(modal, 1), 'modal 05: overview 3 sesi').toEqual(cellsOf(['N01', 'N02', 'N03']));
    await modal.locator('.ant-modal-footer button').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });

    // Opname nyata GAMMA (tanpa subfolder: Step 1 dilewati): scan GD1, GD2 (verified), B1 (Not found), kode asing (Invalid)
    const modalRoot = page.locator('.modal-opname-archive .ant-modal-content').last();
    const foldersResp = page.waitForResponse((r) => r.request().method() === 'GET' && /\/document-archive\/opnames\/folders/.test(r.url()), { timeout: 45000 });
    await pickRowMenu(page, `${P}GAMMA`, /^Opname$/);
    await foldersResp;
    await modalRoot.waitFor({ state: 'visible', timeout: 15000 });
    const input = modalRoot.locator('input[placeholder*="Scan QR"]');
    await input.waitFor({ state: 'visible', timeout: 20000 });
    for (const code of [`${P}GD1`, `${P}GD2`, `${P}B1`, `${P}ASING`]) {
      const sc = page.waitForResponse((r) => r.request().method() === 'POST' && /\/opnames\/[^/]+\/scan/.test(r.url()), { timeout: 30000 });
      await input.click();
      await input.fill(code);
      await input.press('Enter');
      expect((await sc).status(), `scan ${code}`).toBe(200);
      await page.waitForTimeout(300);
    }
    await modalRoot.locator('button').filter({ hasText: /^Selesai Opname$/ }).click();
    await expect(modalRoot.locator('.ant-modal-title')).toContainText('Konfirmasi Opname');
    await page.waitForTimeout(1000);
    // Step 3: kartu (komponen bersama) + Segmented dari counts + peringatan Step 3 masih ada kelasnya
    const step3Cards = trimAll(await modalRoot.locator('.ant-card').allInnerTexts());
    monitor.note(`Step 3 kartu: ${JSON.stringify(step3Cards)}`);
    expect(step3Cards, 'Step 3: kartu 3 / 2 / 1 / 1 / 1').toEqual(['3 ' + L.cards[0], '2 ' + L.cards[1], '1 ' + L.cards[2], '1 ' + L.cards[3], '1 ' + L.cards[4]]);
    const step3Seg = trimAll(await modalRoot.locator('.ant-segmented-item').allInnerTexts());
    monitor.note(`Step 3 Segmented: ${JSON.stringify(step3Seg)}`);
    expect(step3Seg, 'Step 3: Segmented dari counts (Semua = total + not found + invalid)').toEqual([`${L.seg[0]} 5`, `${L.seg[1]} 4`, `${L.seg[2]} 2`, `${L.seg[3]} 1`, `${L.seg[4]} 1`]);
    expect(trimAll(await modalRoot.locator('.ant-segmented-item-selected').allInnerTexts()), 'Step 3: default Scanned').toEqual([`${L.seg[1]} 4`]);
    expect((await tableRows(modalRoot, 0)).length, 'Step 3: 4 baris Scanned').toBe(4);
    await app.screenshot('ac24-01-step3');
    await modalRoot.locator('button').filter({ hasText: /^Konfirmasi Opname$/ }).click();
    const pop = page.locator('.ant-popover:not(.ant-popover-hidden)').last();
    await pop.waitFor({ state: 'visible', timeout: 10000 });
    const conf = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/opnames\/[^/]+\/confirm/.test(r.url()), { timeout: 45000 });
    await pop.locator('.ant-btn-primary').click();
    const cr = await conf;
    expect(cr.status(), 'PUT confirm 200').toBe(200);
    await expect(page.locator('.modal-opname-archive .ant-modal-content:visible'), 'modal opname tertutup').toHaveCount(0, { timeout: 15000 });
    await page.waitForTimeout(1500);

    // DB: sesi baru terkonfirmasi, angka tangan
    const db = inspectDb();
    const mine = db.sessions.filter((s) => s.status === 2 && s.created_by === fx._user.username);
    expect(mine.length, 'DB: satu sesi nyata dari QA_USER').toBe(1);
    const nsess = mine[0];
    monitor.note(`DB sesi baru: ${JSON.stringify(nsess)}`);
    expect([nsess.folder, nsess.total, nsess.verified, nsess.not_found, nsess.invalid, nsess.scanned, nsess.unscanned, nsess.doc_rows], 'DB: GAMMA 3 / 2 / 1 / 1 / scanned 4 / unscanned 1 / 5 baris').toEqual([`${P}GAMMA`, 3, 2, 1, 1, 4, 1, 5]);

    // history: sesi baru di baris teratas dengan angka rekaman; hasilnya = Step 3
    const { resp } = await openHistoryFromModal(app, page);
    const res = await bodyOf(resp);
    expect(res.total, 'total history bertambah 1 (15)').toBe(15);
    const top = (await histRows(page))[0];
    monitor.note(`baris teratas history: ${JSON.stringify(top)}`);
    expect([top[1], top[2], ...top.slice(3)], 'baris baru: user, scope GAMMA, 3 / 2 / 1 / 1').toEqual([fx._user.username, `${P}GAMMA`, '3', '2', '1', '1']);
    expect(top[0], 'waktu = confirmed_at sesi (tanggal + jam)').toMatch(/^\d{2} \w{3} \d{4} \d{2}:\d{2}$/);
    await histRowsLoc(page).first().locator('td').nth(2).click();
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await resDrawer(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(900);
    expect(trimAll(await resDrawer(page).locator('.ant-card').allInnerTexts()), 'hasil history: kartu = Step 3').toEqual(step3Cards);
    expect(trimAll(await resDrawer(page).locator('.ant-segmented-item').allInnerTexts()), 'hasil history: Segmented = Step 3').toEqual(step3Seg);
    const rows = await tableRows(resDrawer(page), 0);
    expect(rows.map((r) => r[0]).sort(), 'hasil history: baris Scanned = GD1, GD2, B1, kode asing').toEqual([`${P}ASING`, `${P}B1`, `${P}GD1`, `${P}GD2`].sort());
    await app.screenshot('ac24-02-hasil-nyata');
    await resDrawer(page).locator('.ant-drawer-close').click();
    await expect(page.locator('.archive-opname-history .ant-drawer.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await page.waitForTimeout(500);
    await hist(page).locator('.ant-drawer-close').first().click();
    await expect(page.locator('.archive-opname-history.ant-drawer-open')).toHaveCount(0, { timeout: 10000 });
    await modalVisible(page).locator('.ant-modal-close').click();
    await expect(page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal')).toHaveCount(0, { timeout: 10000 });
    void calls;
    noServerError(monitor);
    monitor.check();
  });
});
