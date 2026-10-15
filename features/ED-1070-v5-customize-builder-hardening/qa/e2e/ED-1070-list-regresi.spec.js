'use strict';

/**
 * ED-1070 AC-12 - regresi FE (FE tidak berubah; BE: guard CustomizeBuilder + normalize_pagination/normalize_search).
 * Layar: Archive (root, buka folder, cari, filter Tipe, sort Nama/Salesman, ukuran halaman), Pelanggan, Pesanan
 * Pembelian, Faktur Penjualan (cari, sort, ukuran halaman). Tiap aksi: GET list = 200 dan data tampil; error konsol,
 * page error dan respons API >= 400 dicek otomatis oleh harness. NON-mutasi (tidak ada simpan/hapus).
 */

const { test, expect } = require('e2e-harness');

/** Tunggu GET yang URL-nya cocok re (+ predikat opsional) -> {status, url, body}. */
function waitList(page, re, pred, timeout = 45000) {
  return page
    .waitForResponse((r) => r.request().method() === 'GET' && re.test(r.url()) && (!pred || pred(new URL(r.url()))), { timeout })
    .then(async (r) => ({ status: r.status(), url: decodeURIComponent(r.url()), body: await r.json().catch(() => null) }));
}

const rowsOf = (resp) => {
  const res = resp.body && resp.body.result;
  if (!res) return [];
  return Array.isArray(res) ? res : res.data || [];
};
const totalOf = (resp) => (resp.body && resp.body.result && resp.body.result.total) || 0;

function noServerError(monitor) {
  const bad = monitor.responses.filter((r) => r.status >= 500);
  expect(bad.map((r) => `${r.status} ${r.method} ${r.url}`), 'tidak ada respons 5xx').toEqual([]);
}

function trace(page, monitor, re) {
  page.on('response', (r) => {
    if (re.test(r.url()) && r.request().method() === 'GET') {
      monitor.note(`API ${r.status()} GET ${decodeURIComponent(r.url().replace(/^https?:\/\/[^/]+\/(api\/v5\/)?/, '')).slice(0, 220)}`);
    }
  });
}

/** Klik header kolom ber-sorter yang teksnya cocok; kembalikan respons list yang memuat sorts. */
async function sortBy(page, scope, re, headerRe) {
  const th = page.locator(`${scope} th.ant-table-column-has-sorters`, { hasText: headerRe }).first();
  await th.waitFor({ state: 'visible', timeout: 15000 });
  const resp = waitList(page, re, (u) => !!(u.searchParams.get('sorts[]') || u.searchParams.get('sorts[0]') || /sorts/.test(u.search)));
  await th.click();
  return resp;
}

/** Ganti ukuran halaman lewat size changer antd (opsi kedua); kembalikan respons list ber-pagination baru. */
async function changePageSize(page, scope, re) {
  const changer = page.locator(`${scope} .ant-pagination-options-size-changer`).first();
  await changer.waitFor({ state: 'visible', timeout: 15000 });
  await changer.click();
  const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
  const opt = dd.locator('.ant-select-item-option').nth(1);
  await opt.waitFor({ state: 'visible', timeout: 10000 });
  const label = (await opt.innerText()).trim();
  const size = (label.match(/\d+/) || [''])[0];
  const resp = waitList(page, re, (u) => u.searchParams.get('pagination') === size);
  await opt.click();
  const r = await resp;
  return { r, size };
}

async function searchText(page, scope, re, text) {
  const input = page.locator(`${scope} .search-bar input`).first();
  await input.waitFor({ state: 'visible', timeout: 15000 });
  const resp = waitList(page, re, (u) => (u.searchParams.get('search') || '').includes(text));
  await input.fill(text);
  await input.press('Enter');
  return resp;
}

test.describe('ED-1070 AC-12 list v5 (regresi FE)', () => {
  test('[default] ED-1070 AC-12 Archive: root, folder, cari, filter Tipe, sort Nama/Salesman, ukuran halaman', async ({ app, page, monitor }) => {
    const RE = /\/document-archive\/archives(\?|$)/;
    trace(page, monitor, /\/document-archive\//);
    const first = waitList(page, RE);
    await app.open('/archives', { waitFor: '.archive-page .ant-table' });
    const root = await first;
    expect(root.status, 'GET archives (root)').toBe(200);
    expect(rowsOf(root).length, 'root: ada baris').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-01-root');

    // sort Nama asc/desc dan Salesman (sort relasi document.relatedEmployeeName, #9)
    let r = await sortBy(page, '.archive-page', RE, /^(Nama|Name)$/);
    expect(r.status, 'sort Nama').toBe(200);
    expect(rowsOf(r).length, 'sort Nama: ada baris').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-02-sort-nama');
    r = await sortBy(page, '.archive-page', RE, /Salesman/);
    expect(r.status, 'sort Salesman').toBe(200);
    expect(rowsOf(r).length, 'sort Salesman: ada baris').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-03-sort-salesman');

    // ukuran halaman
    const ps = await changePageSize(page, '.archive-page', RE);
    expect(ps.r.status, `ukuran halaman ${ps.size}`).toBe(200);
    expect(rowsOf(ps.r).length, `ukuran halaman ${ps.size}: baris <= ${ps.size}`).toBeLessThanOrEqual(Number(ps.size));
    expect(rowsOf(ps.r).length, 'ukuran halaman: ada baris').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-04-ukuran-halaman');

    // cari
    r = await searchText(page, '.archive-page', RE, 'Backup');
    expect(r.status, 'cari "Backup"').toBe(200);
    expect(rowsOf(r).length, 'cari "Backup": ada hasil').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-05-cari');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await app.settle();

    // filter Tipe = Folder lewat popover
    await page.locator('.archive-page .search-config').first().click();
    const pop = page.locator('.archive-page .search-bar-overlay').first();
    await pop.locator('.ant-select').first().waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(700);
    const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
    for (let attempt = 1; attempt <= 3; attempt += 1) {
      await pop.locator('.ant-select').first().click();
      try {
        await dd.locator('.ant-select-item-option').first().waitFor({ state: 'visible', timeout: attempt === 3 ? 15000 : 4000 });
        break;
      } catch (e) {
        if (attempt === 3) throw e;
      }
    }
    await dd.locator('.ant-select-item-option', { hasText: /^Folder$/ }).first().click();
    const typeResp = waitList(page, RE, (u) => /"type"\s*:\s*"FOLDER"/.test(u.searchParams.get('search') || ''));
    await pop.locator('.search-bar-dropdown-actions button.ant-btn-primary').first().click();
    r = await typeResp;
    expect(r.status, 'filter Tipe Folder').toBe(200);
    expect(rowsOf(r).length, 'filter Tipe Folder: ada hasil').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-06-filter-tipe-folder');
    await page.locator('.archive-page .reset-filter').first().click().catch(() => null);
    await app.settle();

    // buka folder Backup Arsip
    const folderResp = waitList(page, RE, (u) => !!(u.searchParams.get('idArchive') || u.searchParams.get('id_archive')));
    await page.locator('.archive-page .ant-table-row', { hasText: 'Backup Arsip' }).first().locator('td.cursor-pointer').first().click();
    r = await folderResp;
    expect(r.status, 'buka folder Backup Arsip').toBe(200);
    expect(rowsOf(r).length, 'folder: ada isi').toBeGreaterThan(0);
    await app.settle();
    await app.screenshot('AC12-archive-07-folder');
    monitor.note(`archives root total=${totalOf(root)}, folder total=${totalOf(r)}`);
    noServerError(monitor);
  });

  const LISTS = [
    { name: 'Pelanggan', path: '/setups/customers/customers', re: /\/customer\/customers(\?|$)/, q: 'a' },
    { name: 'Pesanan Pembelian', path: '/purchases/purchase-orders', re: /\/purchase\/purchase-orders(\?|$)/, q: 'PO' },
    { name: 'Faktur Penjualan', path: '/sales/sales-invoices', re: /\/sales\/sales-invoices(\?|$)/, q: 'a' },
  ];
  for (const L of LISTS) {
    test(`[default] ED-1070 AC-12 ${L.name}: tampil, cari, sort, ukuran halaman`, async ({ app, page, monitor }) => {
      trace(page, monitor, L.re);
      const first = waitList(page, L.re);
      await app.open(L.path, { waitFor: '.ant-table' });
      const base = await first;
      expect(base.status, `GET ${L.name}`).toBe(200);
      expect(rowsOf(base).length, `${L.name}: ada baris`).toBeGreaterThan(0);
      await app.settle();
      await app.screenshot(`AC12-${L.name}-01-tampil`);

      // sort: kolom ber-sorter pertama (asc lalu desc)
      let r = await sortBy(page, 'body', L.re, /.+/);
      expect(r.status, `${L.name} sort #1`).toBe(200);
      r = await sortBy(page, 'body', L.re, /.+/);
      expect(r.status, `${L.name} sort #2`).toBe(200);
      expect(rowsOf(r).length, `${L.name} sort: ada baris`).toBeGreaterThan(0);
      await app.settle();
      await app.screenshot(`AC12-${L.name}-02-sort`);

      const ps = await changePageSize(page, 'body', L.re);
      expect(ps.r.status, `${L.name} ukuran halaman ${ps.size}`).toBe(200);
      expect(rowsOf(ps.r).length, `${L.name} ukuran halaman: ada baris`).toBeGreaterThan(0);
      await app.settle();
      await app.screenshot(`AC12-${L.name}-03-ukuran-halaman`);

      r = await searchText(page, 'body', L.re, L.q);
      expect(r.status, `${L.name} cari "${L.q}"`).toBe(200);
      await app.settle();
      await app.screenshot(`AC12-${L.name}-04-cari`);
      monitor.note(`${L.name}: total=${totalOf(base)}, cari "${L.q}" total=${totalOf(r)}`);
      noServerError(monitor);
    });
  }
});
