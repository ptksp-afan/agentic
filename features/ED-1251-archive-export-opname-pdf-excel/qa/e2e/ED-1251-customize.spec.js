'use strict';

/**
 * ED-1251 - AC-19 (Customize: select template laporan modul ini, preview, Simpan, Excel ikut kolom tersimpan), AC-22 (jalur error + bahasa).
 * Berbagi data dengan ED-1251-export-opname.spec.js (fixture S1/S2). MUTASI: baris transaction_pdf_templates / column_display_settings
 * documentArchiveOpnameResult (dibuat aplikasi, DU-5 a; dipulihkan UI lalu fixture.php own-restore + teardown), bahasa QA_USER (test EN;
 * dipulihkan di finally). Template lain dijaga count + checksum (C-9). Jalankan dengan --workers 1.
 */

const fs = require('fs');
const { test, expect } = require('e2e-harness');
const {
  fixture, lastJson, fx, own, save, norm, pdfOf, xlsxOf, trimAll, LANG, waitOpnames, hist, resDrawer, pdfDrawer, customizeDrawer, histRowsLoc,
  sessionRow, printBtn, excelBtn, idOf, reUrl, openArchive, openHistoryFromModal, expectRejected, noServerError, pdfReady, clickPrint, expectPdfOf, footerReport,
} = require('./lib');

const SEL_RE = /\/select\/report\/document-archive\/opname\/([a-z-]+)/;
const XLS_RE = /\/document-archive\/opnames\/[^/]+\/xls(\?|$)/;
const PDF_RE = /\/document-archive\/opnames\/[^/]+\/pdf(\?|$)/;

/** tabel Customize: [{ name, rows: [{ title, checked }] }] urut tampil (Ringkasan lalu Rincian) */
const readTables = (cz) =>
  cz.evaluate((root) => {
    const out = [];
    root.querySelectorAll('.ant-table-wrapper').forEach((w) => {
      if (!w.querySelector('.ant-table-selection-column')) return;
      const holder = w.closest('.ant-col') || w.parentElement;
      const strong = holder.querySelector('strong');
      out.push({
        name: strong ? strong.textContent.trim() : null,
        rows: [...w.querySelectorAll('tbody tr.ant-table-row')].map((tr) => ({
          title: (tr.textContent || '').replace(/\s+/g, ' ').trim(),
          checked: !!(tr.querySelector('.ant-checkbox-input') || {}).checked,
        })),
      });
    });
    return out;
  });

/** Print baris S1 -> DrawerPdf siap; kembalikan { d, buf } (PDF yang dimuat FE) */
async function printS1(page) {
  const { resp, buf } = await clickPrint(page, idOf('S1'), printBtn(sessionRow(page, 'S1')));
  expect(resp.status(), 'GET pdf S1 200').toBe(200);
  return { d: await pdfReady(page), buf };
}

/** tutup semua toast notifikasi yang masih tampil */
async function closeToasts(page) {
  for (let i = 0; i < 6; i += 1) {
    const close = page.locator('.ant-notification-notice-close').first();
    if (!(await close.count())) break;
    await close.click({ timeout: 2000 }).catch(() => null);
    await page.waitForTimeout(400);
  }
  await expect(page.locator('.ant-notification-notice'), 'tanpa toast tersisa').toHaveCount(0, { timeout: 10000 });
}

/** buka Customize dari DrawerPdf (desktop: tombol ke-3); tunggu form selesai dimuat; kembalikan { cz, selects } (respons select yang terjadi) */
async function openCustomize(page, d) {
  const selects = [];
  const onResp = (r) => {
    const m = SEL_RE.exec(r.url());
    if (m && r.request().method() === 'GET') selects.push({ name: m[1], status: r.status(), url: r.url() });
  };
  page.on('response', onResp);
  const posts = [];
  const onReq = (r) => {
    if (r.method() === 'POST' && /\/pdf\/preview(\?|$)/.test(r.url())) posts.push(r);
  };
  page.on('request', onReq);
  // sesudah Simpan DrawerPdf memuat ulang PDF (tombol di judul dibangun ulang): tunggu tombol stabil sebelum klik Customize
  await pdfReady(page);
  await closeToasts(page); // toast sukses Simpan menutupi tombol Customize di pojok kanan atas
  await page.waitForTimeout(1500);
  await pdfDrawer(page).locator('.ant-drawer-extra button').nth(2).click({ timeout: 30000 });
  const cz = customizeDrawer(page);
  await cz.waitFor({ state: 'visible', timeout: 15000 });
  await expect.poll(() => selects.map((s) => s.name).sort().join(','), { message: 'empat GET select template laporan', timeout: 45000 }).toContain('transaction-templates');
  await cz.locator('.ant-tabs-tab').first().waitFor({ state: 'visible', timeout: 30000 });
  await cz.locator('.ant-skeleton-active').first().waitFor({ state: 'detached', timeout: 30000 }).catch(() => null);
  await expect.poll(() => posts.length, { message: 'POST pdf/preview setelah Customize dibuka', timeout: 45000 }).toBeGreaterThanOrEqual(1);
  await page.waitForTimeout(800);
  page.off('response', onResp);
  page.off('request', onReq);
  return { cz, selects, posts };
}

async function openTables(page, cz, lang = 'id') {
  const tabKonten = cz.locator('.ant-tabs-tab', { hasText: lang === 'id' ? /^Konten$/ : /^Content$/ });
  await tabKonten.click();
  const header = cz.locator('.ant-collapse-header', { hasText: lang === 'id' ? /^Tabel$/ : /^Tables$/ });
  await header.click();
  await page.waitForTimeout(500);
}

const setColumn = async (cz, tableIdx, title, checked) => {
  const table = cz.locator('.ant-table-wrapper').filter({ has: cz.page().locator('.ant-table-selection-column') }).nth(tableIdx);
  const row = table.locator('tbody tr.ant-table-row').filter({ hasText: title }).first();
  const input = row.locator('.ant-checkbox-input');
  if ((await input.isChecked()) !== checked) await row.locator('.ant-checkbox-wrapper').click();
  expect(await input.isChecked(), `kolom "${title}" ${checked ? 'dicentang' : 'dicabut'}`).toBe(checked);
};

/**
 * Ubah kolom lalu TUNGGU pratinjau (debounce DrawerCustomize) selesai sebelum Simpan. Simpan < 1 dtk sesudah mengubah kolom membuat
 * pratinjau yang tertunda berjalan sesudah drawer di-unmount: TypeError "undefined is not iterable" (komponen bersama, bukan item ini;
 * dicatat di laporan QA "Di luar item").
 */
async function changeColumns(page, cz, changes) {
  const prev = page.waitForResponse((r) => r.request().method() === 'POST' && /\/pdf\/preview(\?|$)/.test(r.url()), { timeout: 30000 });
  for (const [tableIdx, title, checked] of changes) await setColumn(cz, tableIdx, title, checked);
  expect((await prev).status(), 'POST pdf/preview sesudah ubah kolom 200').toBe(200);
  await page.waitForTimeout(1500);
}

/** Simpan Customize: kembalikan { req (POST update), body, pdfBuf (GET pdf yang dimuat ulang) } */
async function saveCustomize(page, cz) {
  const updReq = page.waitForRequest((r) => r.method() === 'POST' && /\/setting\/pdf-templates\/update(\?|$)/.test(r.url()), { timeout: 45000 });
  const reload = page.waitForResponse((r) => r.request().method() === 'GET' && reUrl(idOf('S1'), 'pdf').test(r.url()), { timeout: 60000 });
  await cz.locator('.ant-drawer-extra button.ant-btn-primary').click();
  const req = await updReq;
  const resp = await req.response();
  const body = JSON.parse(req.postData());
  expect(resp.status(), 'POST setting/pdf-templates/update 200').toBe(200);
  const rl = await reload;
  expect(rl.status(), 'PDF dimuat ulang (GET pdf) 200').toBe(200);
  await expect(customizeDrawer(page), 'Customize tertutup sesudah Simpan').toHaveCount(0, { timeout: 20000 });
  await expect(page.locator('.ant-notification-notice-success').first(), 'toast sukses').toBeVisible({ timeout: 15000 });
  return { body, pdfBuf: await rl.body(), status: resp.status() };
}

/** header tabel XLSX: baris yang memuat penanda -> sel urut kolom */
const headerRow = (x, marker) => {
  const rows = Object.entries(x.rows).sort((a, b) => Number(a[0]) - Number(b[0]));
  const hit = rows.find(([, cols]) => Object.values(cols).map(norm).includes(marker));
  return hit ? Object.values(hit[1]).map(norm) : null;
};

/** unduh Excel S1 lewat tombol di baris (DrawerPdf harus tertutup) */
async function downloadExcelS1(page, name) {
  const xr = page.waitForResponse((r) => r.request().method() === 'GET' && reUrl(idOf('S1'), 'xls').test(r.url()), { timeout: 60000 });
  const dl = page.waitForEvent('download', { timeout: 60000 });
  await excelBtn(sessionRow(page, 'S1')).click();
  expect((await xr).status(), 'GET xls S1 200').toBe(200);
  const d = await dl;
  const x = lastJson(fixture('xlsx', save(name, fs.readFileSync(await d.path()))));
  x.cells = Object.values(x.rows).flatMap((r) => Object.values(r)).map(norm);
  return x;
}

test.describe('ED-1251 Customize & jalur error', () => {
  test('[default] ED-1251 AC-19 Customize: 4 GET select template laporan modul ini + POST preview, tabel Ringkasan & Rincian dari display setting; orientasi -> preview A4-L; Simpan -> update 200 + PDF dimuat ulang; Excel ikut kolom tersimpan; dipulihkan', async ({ app, page, monitor }) => {
    test.setTimeout(480000);
    const L = LANG.id;
    const before = own();
    // r1: baris belum ada (0); r2: baris template 519 + cds 2585 sudah ada (dibuat developer, tables kosong = jatuh ke display setting); jurnal FIX-UP memulihkan apa pun keadaan awalnya
    expect([0, 1], 'awal: tidak ada baris template ganda').toContain(before.tpl_count);
    expect(before.tables || [], 'awal: baris template belum menyimpan tabel (jatuh ke display setting)').toEqual([]);
    let step = 'awal';
    const t0 = Date.now();
    const trail = [];
    page.on('request', (r) => {
      if (/\/pdf\/preview|pdf-templates\/update|transaction-templates|\/opnames\/[^/]+\/pdf(\?|$)/.test(r.url())) trail.push(`${Date.now() - t0} [${step}] ${r.method()} ${r.url().replace(/.*api\/v5\//, '').slice(0, 70)}`);
    });
    page.on('pageerror', (err) => {
      trail.push(`${Date.now() - t0} [${step}] PAGEERROR ${err.message}`);
      monitor.note(`pageerror @ langkah "${step}": ${String(err.stack || err.message).split('\n').slice(0, 6).join(' <- ')}`);
    });
    try {
      await openArchive(app, page);
      await openHistoryFromModal(app, page);
      const { d, buf: pdf0 } = await printS1(page);
      const info0 = pdfOf(pdf0, 'S1-awal.pdf');
      expectPdfOf(info0, 'S1', 'id', 'sebelum Customize');
      const countInvalid = (info) => (info.text.match(/Tidak Valid/g) || []).length;
      const invalidBefore = countInvalid(info0);
      expect(info0.text, 'PDF awal memuat kolom Waktu').toContain(L.time);

      // --- buka Customize
      step = 'buka Customize';
      const { cz, selects, posts } = await openCustomize(page, d);
      await expect(cz.locator('.ant-drawer-title'), 'judul Customize').toContainText(L.customize);
      await expect(cz.locator('.ant-drawer-title .text-muted'), 'subjudul Customize = Hasil Opname').toHaveText(L.resultTitle);
      const byName = Object.fromEntries(selects.map((s) => [s.name, s.status]));
      monitor.note(`select Customize: ${JSON.stringify(selects.map((s) => `${s.status} ${s.name}`))}; POST preview saat dibuka: ${posts.length}`);
      for (const n of ['transaction-templates', 'selected-header-templates', 'selected-pdf-templates', 'selected-footer-templates']) {
        expect(byName[n], `GET select/report/document-archive/opname/${n} 200`).toBe(200);
      }
      expect(selects.every((s) => s.status === 200), 'semua GET select modul ini 200').toBe(true);
      const allApi = monitor.responses.map((r) => r.url);
      expect(allApi.filter((u) => /purchase-order/.test(u)), 'tidak ada request purchase-order (bukan endpoint PO)').toEqual([]);
      const firstPreview = JSON.parse(posts[0].postData());
      expect(firstPreview.transactionName, 'preview membawa transactionName modul ini').toBe('documentArchiveOpnameResult');
      expect((await posts[0].response()).status(), 'POST pdf/preview 200').toBe(200);
      await app.screenshot('ac19-01-customize-terbuka');

      // --- tabel Ringkasan & Rincian = kolom display setting
      step = 'tabel Customize';
      await openTables(page, cz);
      let tables = await readTables(cz);
      monitor.note(`tabel Customize: ${JSON.stringify(tables)}`);
      expect(tables.length, 'dua tabel (Ringkasan, Rincian)').toBe(2);
      expect(tables[0].name, 'tabel 1 Ringkasan').toBe(L.sumTitle);
      expect(tables[1].name, 'tabel 2 Rincian').toBe(L.detTitle);
      expect(tables[0].rows.map((r) => r.title), 'kolom Ringkasan').toEqual(L.sumCols);
      expect(tables[1].rows.map((r) => r.title), 'kolom Rincian').toEqual(L.detCols);
      expect(tables.flatMap((t) => t.rows).every((r) => r.checked), 'semua kolom terpilih').toBe(true);
      await app.screenshot('ac19-02-customize-tabel');
      // baris template dibuat BE dari config saat dipakai pertama (DU-5 a)
      const own1 = own();
      expect(own1.tpl_count, 'baris template documentArchiveOpnameResult ada (dibuat aplikasi, atau sudah ada sejak awal)').toBe(1);
      expect(own1.other, 'C-9: template lain sama persis (count + checksum)').toEqual(before.other);

      // --- ubah orientasi -> POST preview A4-L, MediaBox lebar > tinggi
      step = 'ubah orientasi';
      const prevReq = page.waitForRequest((r) => r.method() === 'POST' && /\/pdf\/preview(\?|$)/.test(r.url()) && /"paperSize":"A4-L"/.test(r.postData() || ''), { timeout: 45000 });
      await cz.locator('.ant-tabs-tab', { hasText: /^Halaman$/ }).click();
      await cz.locator('.ant-radio-button-wrapper', { hasText: 'Landscape' }).click();
      const pr = await prevReq;
      const prr = await pr.response();
      expect(prr.status(), 'POST pdf/preview A4-L 200').toBe(200);
      const prevInfo = pdfOf(await prr.body(), 'S1-preview-landscape.pdf');
      expect(prevInfo.ok, 'pratinjau = PDF').toBe(true);
      expect(prevInfo.media[0][0], 'pratinjau landscape: lebar > tinggi').toBeGreaterThan(prevInfo.media[0][1]);
      expect(own().paper_size, 'pratinjau tidak menyimpan: kertas tersimpan tetap A4-P').toBe('A4-P');
      await app.screenshot('ac19-03-preview-landscape');

      // --- Simpan
      step = 'Simpan landscape';
      const sv = await saveCustomize(page, cz);
      expect(sv.body.transactionName, 'Simpan: transactionName = documentArchiveOpnameResult').toBe('documentArchiveOpnameResult');
      const ownId = own();
      expect(ownId.paper_size, 'DB: kertas tersimpan A4-L').toBe('A4-L');
      expect(ownId.name, 'DB: nama transaksi tak berubah').toBe('documentArchiveOpnameResult');
      expect(String(sv.body.idTransactionPdfTemplate), 'C-9: Simpan mengirim id baris template milik modul ini (bukan baris laporan lain)').toBe(ownId.id);
      expect(ownId.other, 'C-9 sesudah Simpan: template lain sama persis (count + checksum)').toEqual(before.other);
      expect(ownId.tpl_count, 'C-9: tidak ada baris template baru').toBe(1);
      const infoL = pdfOf(sv.pdfBuf, 'S1-setelah-simpan-landscape.pdf');
      expect(infoL.media[0][0], 'PDF dimuat ulang: landscape').toBeGreaterThan(infoL.media[0][1]);
      await app.screenshot('ac19-04-pdf-dimuat-ulang-landscape');

      // --- Excel ikut kolom tersimpan: ronde 2 cabut Waktu (Rincian) dan Tidak Valid (Ringkasan)
      step = 'ronde 2 cabut kolom';
      const { cz: cz2 } = await openCustomize(page, pdfDrawer(page));
      await openTables(page, cz2);
      await changeColumns(page, cz2, [[1, L.time, false], [0, L.invalid, false]]);
      const sv2 = await saveCustomize(page, cz2);
      const own2 = own();
      const cols2 = Object.fromEntries(own2.tables.map((t) => [JSON.stringify(t.data_index), t.current]));
      monitor.note(`kolom tersimpan sesudah cabut Waktu + Tidak Valid: ${JSON.stringify(own2.tables)}`);
      expect(own2.tables.length, 'DB: dua tabel tersimpan').toBe(2);
      expect(own2.tables[1].current, 'DB: Rincian tanpa scanTime (urutan lain tetap)').toEqual(['code', 'transactionTypeLabel', 'folderName', 'resultLabel']);
      expect(own2.tables[0].current, 'DB: Ringkasan tanpa invalid').toEqual(['totalDocuments', 'verified', 'notFound', 'unscanned']);
      expect(own2.other, 'C-9: template lain tetap sama').toEqual(before.other);
      const info2 = pdfOf(sv2.pdfBuf, 'S1-tanpa-waktu.pdf');
      expect(info2.text, 'PDF dimuat ulang: tanpa kolom Waktu').not.toContain(L.time);
      expect(countInvalid(info2), 'PDF: header "Tidak Valid" Ringkasan hilang (satu kemunculan lebih sedikit)').toBe(invalidBefore - 1);
      expect(info2.text, 'PDF: kolom lain tetap').toContain('Terverifikasi');
      void cols2;
      await app.screenshot('ac19-05-pdf-tanpa-waktu');
      // tutup DrawerPdf lalu Excel baris S1
      await pdfDrawer(page).locator('.ant-drawer-close').click();
      await expect(pdfDrawer(page)).toHaveCount(0);
      const x2 = await downloadExcelS1(page, 'S1-excel-tanpa-waktu.xlsx');
      const sumHead = headerRow(x2, L.sumCols[0]);
      const detHead = headerRow(x2, L.detCols[0]);
      monitor.note(`Excel (kolom tersimpan): Ringkasan=${JSON.stringify(sumHead)} Rincian=${JSON.stringify(detHead)}`);
      expect(sumHead, 'Excel: Ringkasan tanpa Tidak Valid').toEqual(L.sumCols.filter((c) => c !== L.invalid));
      expect(detHead, 'Excel: Rincian tanpa Waktu').toEqual(L.detCols.filter((c) => c !== L.time));
      expect(x2.cells, 'Excel: tabel judul Ringkasan + Rincian tetap ada').toEqual(expect.arrayContaining([L.sumTitle, L.detTitle]));

      // --- pulihkan lewat UI: kembalikan Waktu + Tidak Valid + Portrait, Simpan
      step = 'ronde 3 pulihkan';
      const { d: d3 } = await printS1(page);
      const { cz: cz3 } = await openCustomize(page, d3);
      await openTables(page, cz3);
      await changeColumns(page, cz3, [[1, L.time, true], [0, L.invalid, true]]);
      await cz3.locator('.ant-tabs-tab', { hasText: /^Halaman$/ }).click();
      const prevP = page.waitForResponse((r) => r.request().method() === 'POST' && /\/pdf\/preview(\?|$)/.test(r.url()), { timeout: 30000 });
      await cz3.locator('.ant-radio-button-wrapper', { hasText: 'Portrait' }).click();
      await prevP;
      await page.waitForTimeout(1500);
      const sv3 = await saveCustomize(page, cz3);
      const info3 = pdfOf(sv3.pdfBuf, 'S1-dipulihkan.pdf');
      expect(info3.media[0][1], 'PDF pulih: potret').toBeGreaterThan(info3.media[0][0]);
      expect(info3.text, 'PDF pulih: kolom Waktu kembali').toContain(L.time);
      expect(countInvalid(info3), 'PDF pulih: jumlah "Tidak Valid" seperti awal').toBe(invalidBefore);
      const own3 = own();
      expect(own3.paper_size, 'DB pulih: A4-P').toBe('A4-P');
      expect(own3.tables[1].current, 'DB pulih: Rincian lengkap (scanTime kembali)').toEqual(expect.arrayContaining(['scanTime']));
      expect(own3.tables[0].current, 'DB pulih: Ringkasan lengkap (invalid kembali)').toEqual(expect.arrayContaining(['invalid']));
      await pdfDrawer(page).locator('.ant-drawer-close').click();
      const x3 = await downloadExcelS1(page, 'S1-excel-dipulihkan.xlsx');
      // mencentang kembali kolom menaruhnya di urutan pilihan (perilaku DrawerPdf bersama, sama di PO Report): himpunan kolom kembali penuh
      const detHead3 = headerRow(x3, L.detCols[1]);
      const sumHead3 = headerRow(x3, L.sumCols[1]);
      monitor.note(`Excel sesudah dipulihkan lewat UI: Ringkasan=${JSON.stringify(sumHead3)} Rincian=${JSON.stringify(detHead3)} (urutan = urutan pilihan Customize)`);
      expect([...detHead3].sort(), 'Excel pulih: Rincian memuat kembali kelima kolom').toEqual([...L.detCols].sort());
      expect([...sumHead3].sort(), 'Excel pulih: Ringkasan memuat kembali kelima kolom').toEqual([...L.sumCols].sort());
      noServerError(monitor);
    } finally {
      monitor.note(`jejak request/pageerror: ${trail.join(' || ')}`);
      // pulihkan persis ke keadaan sebelum test (jurnal FIX-UP): 0 baris template + 0 baris cds sendiri
      const r = lastJson(fixture('own-restore'));
      const after = own();
      expect(r.restored, 'own-restore').toBe(true);
      expect(after.tpl_count, 'baris template sendiri kembali ke awal').toBe(before.tpl_count);
      expect(after.cds_count, 'baris cds sendiri kembali ke awal').toBe(before.cds_count);
      expect(after.other, 'C-9 akhir: template lain sama persis').toEqual(before.other);
    }
  });

  test('[default] ED-1251 AC-22 jalur error: /xls dan /pdf dicegat 500/404 -> notifikasi error, tanpa crash, tombol normal lagi; sesudah buka blokir berhasil', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const s1 = sessionRow(page, 'S1');

    // --- Excel 500 (respons error dibaca sebagai blob: notifikasi generik)
    await page.route(XLS_RE, (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server error', code: 'ERR' }) }));
    let notif = page.locator('.ant-notification-notice');
    await excelBtn(s1).click();
    await expect(notif.first(), 'notifikasi error muncul (Excel 500)').toBeVisible({ timeout: 15000 });
    monitor.note(`notifikasi Excel 500: ${trimAll(await notif.allInnerTexts()).join(' | ')}`);
    await expect(excelBtn(s1), 'tombol Excel normal lagi (500)').not.toHaveClass(/ant-btn-loading/);
    await expect(page.locator('#root > *').first(), 'tidak crash (root masih ada)').toBeAttached();
    await expect(resDrawer(page), 'Hasil tidak terbuka').toHaveCount(0);
    await app.screenshot('ac22-01-excel-500');
    await page.unroute(XLS_RE);
    expectRejected(monitor, 'Excel 500', { status: 500, urlRe: XLS_RE });
    // --- Excel 404
    await page.route(XLS_RE, (route) => route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Not found', code: 'ARCHIVE412' }) }));
    await page.locator('.ant-notification-notice-close').first().click({ timeout: 3000 }).catch(() => null);
    await excelBtn(s1).click();
    await expect(page.locator('.ant-notification-notice').first(), 'notifikasi error (Excel 404)').toBeVisible({ timeout: 15000 });
    await expect(excelBtn(s1), 'tombol Excel normal lagi (404)').not.toHaveClass(/ant-btn-loading/);
    await page.unroute(XLS_RE);
    expectRejected(monitor, 'Excel 404', { status: 404, urlRe: XLS_RE });

    // --- PDF 404 (DrawerPdf tanpa PDF, tanpa crash)
    await page.route(PDF_RE, (route) => route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Not found', code: 'ARCHIVE412' }) }));
    const pdfResp = page.waitForResponse((r) => r.request().method() === 'GET' && PDF_RE.test(r.url()), { timeout: 30000 });
    await printBtn(s1).click();
    expect((await pdfResp).status(), 'GET pdf dicegat 404').toBe(404);
    await expect(page.locator('.ant-notification-notice').last(), 'notifikasi error (PDF 404)').toBeVisible({ timeout: 15000 });
    await expect(page.locator('#root > *').first(), 'tidak crash (PDF 404)').toBeAttached();
    await expect(pdfDrawer(page), 'DrawerPdf terbuka (tanpa PDF)').toBeVisible();
    await expect(pdfDrawer(page).locator('canvas'), 'tanpa kanvas PDF').toHaveCount(0);
    await app.screenshot('ac22-02-pdf-404');
    await page.unroute(PDF_RE);
    expectRejected(monitor, 'PDF 404', { status: 404, urlRe: PDF_RE });
    await pdfDrawer(page).locator('.ant-drawer-close').click();
    await expect(pdfDrawer(page)).toHaveCount(0);

    // --- sesudah blokir dibuka: Print dan Excel berhasil lagi
    const { resp, buf } = await clickPrint(page, idOf('S1'), printBtn(s1));
    expect(resp.status(), 'GET pdf S1 200 sesudah blokir dibuka').toBe(200);
    expectPdfOf(pdfOf(buf, 'S1-pulih.pdf'), 'S1', 'id', 'sesudah blokir dibuka');
    await pdfReady(page);
    await pdfDrawer(page).locator('.ant-drawer-close').click();
    const x = await downloadExcelS1(page, 'S1-excel-pulih.xlsx');
    expect(x.ok, 'XLSX berhasil sesudah blokir dibuka').toBe(true);
    // tidak ada 5xx NYATA dari server (500 hasil intercept sudah dikeluarkan dari monitor oleh expectRejected)
    expect(monitor.responses.filter((r) => r.status >= 500 && !XLS_RE.test(r.url)).map((r) => `${r.status} ${r.url}`), 'tanpa 5xx server nyata').toEqual([]);
  });

  test('[default] ED-1251 AC-22 bahasa EN: tooltip/aria "Print Preview" / "Excel", judul "Opname Result", PDF dan Excel berbahasa Inggris, Customize berjudul EN; bahasa dipulihkan', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    const L = LANG.en;
    const orig = fixture('lang', 'EN');
    try {
      expect(lastJson(orig).lang, 'bahasa QA_USER sementara EN').toBe('EN');
      await openArchive(app, page);
      await openHistoryFromModal(app, page, 'en');
      const s1 = sessionRow(page, 'S1');
      expect(await printBtn(s1).count(), 'aria-label EN Print Preview').toBe(1);
      expect(await excelBtn(s1).count(), 'aria-label EN Excel').toBe(1);
      await printBtn(s1).hover();
      await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip EN Print').toHaveText('Print Preview');
      await excelBtn(s1).hover();
      await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip EN Excel').toHaveText('Excel');
      await page.mouse.move(5, 5);
      await s1.locator('td').nth(2).click();
      const rd = resDrawer(page);
      await rd.waitFor({ state: 'visible', timeout: 15000 });
      await rd.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
      await expect(rd.locator('.ant-drawer-title .flex-justify-between'), 'judul EN "Opname Result"').toContainText(L.resultTitle);
      await app.screenshot('ac22-10-hasil-en');
      const { resp, buf } = await clickPrint(page, idOf('S1'), rd.locator('.ant-drawer-title button[aria-label="Print Preview"]'));
      expect(resp.status(), 'GET pdf 200 (EN)').toBe(200);
      expectPdfOf(pdfOf(buf, 'S1-en.pdf'), 'S1', 'en', 'PDF EN');
      const d = await pdfReady(page);
      const { cz } = await openCustomize(page, d);
      await expect(cz.locator('.ant-drawer-title'), 'judul Customize EN').toContainText(L.customize);
      await expect(cz.locator('.ant-drawer-title .text-muted'), 'subjudul Customize EN = Opname Result').toHaveText(L.resultTitle);
      await app.screenshot('ac22-11-customize-en');
      await cz.locator('.ant-drawer-close').click();
      await pdfDrawer(page).locator('.ant-drawer-close').click();
      const x = await downloadExcelS1ViaTitle(page, rd);
      expect(x.cells, 'Excel EN: judul laporan').toContain(L.pdfReportName);
      noServerError(monitor);
    } finally {
      const back = lastJson(fixture('lang-restore'));
      monitor.note(`bahasa QA_USER dipulihkan: ${back.lang}`);
      const aft = own();
      if (aft.tpl_count > 0) fixture('own-restore'); // Customize dibuka (tanpa Simpan) bisa membuat baris config; pulihkan ke jurnal
    }
  });
});

async function downloadExcelS1ViaTitle(page, rd) {
  const xr = page.waitForResponse((r) => r.request().method() === 'GET' && reUrl(idOf('S1'), 'xls').test(r.url()), { timeout: 60000 });
  const dl = page.waitForEvent('download', { timeout: 60000 });
  await rd.locator('.ant-drawer-title button[aria-label="Excel"]').click();
  expect((await xr).status(), 'GET xls S1 200').toBe(200);
  const d = await dl;
  const x = lastJson(fixture('xlsx', save('S1-excel-en.xlsx', fs.readFileSync(await d.path()))));
  x.cells = Object.values(x.rows).flatMap((r) => Object.values(r)).map(norm);
  return x;
}

// ================================================================================================ revisi review 2026-10-26 (QA FE r2)

/**
 * viewer PDF di `root` (DrawerPdf atau Customize): toolbar menunjukkan "/ <pages>" (= jumlah halaman berkas), lalu seluruh dokumen digulir dan lapisan teks
 * tiap halaman (pdf.js merender HANYA halaman di sekitar layar, jadi digulir bertahap) dikumpulkan: SETIAP halaman 1..pages harus memuat footer
 * "Halaman i dari pages" / "Page i of pages" di viewer sendiri (R-3, bukan hanya di berkas). Kembalikan { pages, withFooter }.
 */
async function viewerFooters(page, root, pages, label) {
  await expect
    .poll(async () => new RegExp(`/\\s*${pages}(?!\\d)`).test(norm((await root.locator('.rpv-toolbar').first().innerText().catch(() => '')) || '')), {
      message: `${label}: toolbar viewer menunjukkan "/ ${pages}" halaman`,
      timeout: 60000,
    })
    .toBe(true);
  const scroller = root.locator('[data-testid="core__inner-pages"]').first();
  await scroller.waitFor({ state: 'visible', timeout: 30000 });
  const seen = {};
  const collect = async () => {
    const got = await root.locator('[data-testid^="core__text-layer-"]').evaluateAll((els) =>
      els.map((e) => [Number(e.getAttribute('data-testid').replace('core__text-layer-', '')), e.innerText || '']),
    );
    got.forEach(([i, t]) => {
      if (!seen[i] || t.length > seen[i].length) seen[i] = norm(t);
    });
  };
  const dims = await scroller.evaluate((el) => ({ h: el.scrollHeight, c: el.clientHeight }));
  const step = Math.max(200, Math.floor(dims.c * 0.5));
  for (let y = 0; y <= dims.h + step; y += step) {
    await scroller.evaluate((el, top) => {
      el.scrollTop = top;
    }, y);
    await page.waitForTimeout(450);
    await collect();
  }
  // halaman terakhir: tunggu lapisan teksnya terisi
  await expect
    .poll(async () => {
      await scroller.evaluate((el) => {
        el.scrollTop = el.scrollHeight;
      });
      await page.waitForTimeout(500);
      await collect();
      return Object.keys(seen).length;
    }, { message: `${label}: viewer merender ${pages} halaman (digulir bertahap)`, timeout: 60000 })
    .toBe(pages);
  const bad = [];
  let withFooter = 0;
  for (let i = 0; i < pages; i += 1) {
    const re = new RegExp(`(Halaman|Page)\\s+${i + 1}\\s+(dari|of)\\s+${pages}(?!\\d)`);
    if (re.test(seen[i] || '')) withFooter += 1;
    else bad.push(i + 1);
  }
  expect(bad, `${label}: halaman di viewer tanpa footer "Halaman i dari ${pages}" (dari ${pages})`).toEqual([]);
  return { pages, withFooter };
}

/** POST pdf/preview yang dipicu `action` dengan paperSize `paper`: { status, info (PDF diurai), notif (jumlah notifikasi error sesudahnya) } */
async function previewOf(page, paper, action, name) {
  const reqP = page.waitForRequest((r) => r.method() === 'POST' && /\/pdf\/preview(\?|$)/.test(r.url()) && new RegExp(`"paperSize":"${paper}"`).test(r.postData() || ''), { timeout: 60000 });
  await action();
  const req = await reqP;
  const resp = await req.response();
  const buf = await resp.body();
  await page.waitForTimeout(1200);
  return { status: resp.status(), info: pdfOf(buf, name), notif: await page.locator('.ant-notification-notice-error').count(), body: JSON.parse(req.postData()) };
}

/** Simpan Customize untuk sesi `id` (DrawerPdf memuat ulang GET pdf sesi itu) */
async function saveCustomizeFor(page, cz, id) {
  const updReq = page.waitForRequest((r) => r.method() === 'POST' && /\/setting\/pdf-templates\/update(\?|$)/.test(r.url()), { timeout: 45000 });
  const reload = page.waitForResponse((r) => r.request().method() === 'GET' && reUrl(id, 'pdf').test(r.url()), { timeout: 120000 });
  await cz.locator('.ant-drawer-extra button.ant-btn-primary').click();
  const req = await updReq;
  const resp = await req.response();
  expect(resp.status(), 'POST setting/pdf-templates/update 200').toBe(200);
  const rl = await reload;
  expect(rl.status(), 'PDF dimuat ulang (GET pdf) 200').toBe(200);
  await expect(customizeDrawer(page), 'Customize tertutup sesudah Simpan').toHaveCount(0, { timeout: 20000 });
  await expect(page.locator('.ant-notification-notice-success').first(), 'toast sukses').toBeVisible({ timeout: 15000 });
  return { body: JSON.parse(req.postData()), pdfBuf: await rl.body() };
}

const pickPaper = async (page, cz, optionRe) => {
  await cz.locator('.ant-tabs-tab', { hasText: /^Halaman$/ }).click();
  const sel = cz.locator('.ant-form-item').filter({ hasText: /Ukuran Kertas|Paper Size/ }).locator('.ant-select').first();
  await sel.click();
  const opt = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: optionRe }).first();
  await opt.waitFor({ state: 'visible', timeout: 20000 });
  const texts = trimAll(await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').allInnerTexts());
  await opt.click();
  return texts;
};

test.describe('ED-1251 revisi review 2026-10-26 R-3/R-4 (FE): pratinjau PDF', () => {
  test('[default] ED-1251 R-3/R-4 sesi 247 baris: footer di SETIAP halaman (DrawerPdf, pratinjau Customize A4-P/A4-L/Continuous-L/-P), pratinjau Continuous = 200 tanpa notifikasi error + ukuran Continuous; Simpan Continuous lalu dipulihkan', async ({ app, page, monitor }) => {
    test.setTimeout(1200000);
    const f = fx();
    expect(f.bulk && f.bulk.id, 'fixture sesi sisipan 247 baris ada (FIX-UP)').toBeTruthy();
    const BULK = f.bulk.id;
    const before = own();
    const rep = {};
    const trail = [];
    page.on('pageerror', (err) => {
      trail.push(`PAGEERROR ${err.message}`);
      monitor.note(`pageerror: ${String(err.stack || err.message).split('\n').slice(0, 6).join(' <- ')}`);
    });
    const checkFooters = (info, label, minPages = 2) => {
      const fr = footerReport(info, f.user);
      rep[label] = { pages: info.pages, withFooter: fr.withFooter, media: info.media[0].map((v) => Math.round(v * 10) / 10) };
      expect(info.pages, `${label}: PDF berhalaman banyak`).toBeGreaterThanOrEqual(minPages);
      expect(fr.missing, `${label}: halaman tanpa footer`).toEqual([]);
      expect(fr.wrongNumber, `${label}: nomor "Halaman i dari N" salah`).toEqual([]);
      expect(fr.noUser, `${label}: footer tanpa "Dicetak oleh ${f.user}"`).toEqual([]);
      expect(info.text, `${label}: baris pertama sesi ada`).toContain('QA251BULK-0000000');
      expect(info.text, `${label}: baris terakhir sesi ada`).toContain(`QA251BULK-${String(f.bulk.rows - 1).padStart(7, '0')}`);
      expect(info.ptexts.filter((t) => /Dicetak oleh|Printed by/.test(t)).length, `${label}: footer tercetak di tiap halaman`).toBe(info.pages);
    };
    try {
      await openArchive(app, page);
      await openHistoryFromModal(app, page);
      const rows = histRowsLoc(page).filter({ hasNotText: f.folder_names.F }).filter({ hasNotText: f.folder_names.G });
      expect(await rows.count(), 'tepat satu baris selain S1/S2 = sesi sisipan (sesi developer tidak terlihat QA_USER)').toBe(1);
      const row = rows.first();

      // --- 1. DrawerPdf (GET pdf, A4-P bawaan): footer tiap halaman di berkas DAN di viewer
      const { resp, buf } = await clickPrint(page, BULK, printBtn(row));
      expect(resp.status(), 'GET pdf sesi sisipan 200').toBe(200);
      const info0 = pdfOf(buf, 'BULK-drawerpdf-a4p.pdf');
      checkFooters(info0, 'DrawerPdf GET pdf A4-P', 5);
      expect(info0.media[0][1], 'A4-P potret').toBeGreaterThan(info0.media[0][0]);
      const d = await pdfReady(page);
      await viewerFooters(page, d, info0.pages, 'DrawerPdf');
      await app.screenshot('r3-01-drawerpdf-halaman-terakhir-footer');

      // --- 2. Customize: pratinjau A4-P bawaan
      const { cz, posts } = await openCustomize(page, d);
      const first = await posts[0].response();
      expect(first.status(), 'POST pdf/preview bawaan 200').toBe(200);
      const info1 = pdfOf(await first.body(), 'BULK-preview-a4p.pdf');
      checkFooters(info1, 'Customize pratinjau A4-P', 5);
      await viewerFooters(page, cz, info1.pages, 'Customize A4-P');
      await app.screenshot('r3-02-customize-a4p-halaman-terakhir');

      // --- 3. A4-L
      await cz.locator('.ant-tabs-tab', { hasText: /^Halaman$/ }).click();
      const pl = await previewOf(page, 'A4-L', () => cz.locator('.ant-radio-button-wrapper', { hasText: 'Landscape' }).click(), 'BULK-preview-a4l.pdf');
      expect(pl.status, 'pratinjau A4-L 200').toBe(200);
      expect(pl.notif, 'pratinjau A4-L: tanpa notifikasi error').toBe(0);
      expect(pl.info.media[0][0], 'A4-L: lebar > tinggi').toBeGreaterThan(pl.info.media[0][1]);
      checkFooters(pl.info, 'Customize pratinjau A4-L', 5);
      await viewerFooters(page, cz, pl.info.pages, 'Customize A4-L');
      await app.screenshot('r3-03-customize-a4l-halaman-terakhir');

      // --- 4. kertas Continuous (orientasi L bawaan dari langkah 3) -> Continuous-L; lalu Portrait -> Continuous-P (R-4: 200, bukan 422/notifikasi)
      let paperOptions = [];
      const pcl = await previewOf(page, 'Continuous-L', async () => {
        paperOptions = await pickPaper(page, cz, /^Continuous$/);
      }, 'BULK-preview-continuous-l.pdf');
      monitor.note(`opsi kertas di Customize: ${JSON.stringify(paperOptions)}`);
      expect(pcl.status, 'pratinjau Continuous-L 200 (bukan 422)').toBe(200);
      expect(pcl.notif, 'pratinjau Continuous-L: tanpa notifikasi error').toBe(0);
      expect(pcl.body.paperSize, 'FE mengirim paperSize Continuous-L').toBe('Continuous-L');
      expect(pcl.info.ok, 'Continuous-L = PDF').toBe(true);
      expect(pcl.info.media[0].map((v) => Math.round(v * 10) / 10), 'Continuous-L = 784,8 x 597,6 pt (276,86 x 210,82 mm), bukan A4').toEqual([784.8, 597.6]);
      expect(new Set(pcl.info.media.map((m) => m.map((v) => Math.round(v * 10) / 10).join('x'))).size, 'semua halaman ukuran Continuous-L').toBe(1);
      checkFooters(pcl.info, 'Customize pratinjau Continuous-L', 5);
      await viewerFooters(page, cz, pcl.info.pages, 'Customize Continuous-L');
      await app.screenshot('r3-04-customize-continuous-l');

      const pcp = await previewOf(page, 'Continuous-P', () => cz.locator('.ant-radio-button-wrapper', { hasText: 'Portrait' }).click(), 'BULK-preview-continuous-p.pdf');
      expect(pcp.status, 'pratinjau Continuous-P 200 (bukan 422)').toBe(200);
      expect(pcp.notif, 'pratinjau Continuous-P: tanpa notifikasi error').toBe(0);
      expect(pcp.body.paperSize, 'FE mengirim paperSize Continuous-P').toBe('Continuous-P');
      expect(pcp.info.media[0].map((v) => Math.round(v * 10) / 10), 'Continuous-P = 612 x 381,6 pt (215,9 x 134,62 mm), bukan A4').toEqual([612, 381.6]);
      expect(new Set(pcp.info.media.map((m) => m.map((v) => Math.round(v * 10) / 10).join('x'))).size, 'semua halaman ukuran Continuous-P').toBe(1);
      checkFooters(pcp.info, 'Customize pratinjau Continuous-P', 5);
      await viewerFooters(page, cz, pcp.info.pages, 'Customize Continuous-P');
      await app.screenshot('r3-05-customize-continuous-p');
      expect(own().paper_size, 'pratinjau tidak menyimpan: kertas tersimpan tidak berubah').toBe(before.paper_size || 'A4-P');

      // --- 5. Simpan Continuous-P: GET pdf dimuat ulang berukuran Continuous + footer tiap halaman; DB = Continuous-P
      const sv = await saveCustomizeFor(page, cz, BULK);
      expect(sv.body.paperSize, 'Simpan mengirim paperSize Continuous-P').toBe('Continuous-P');
      expect(sv.body.transactionName, 'Simpan: transactionName').toBe('documentArchiveOpnameResult');
      const infoS = pdfOf(sv.pdfBuf, 'BULK-tersimpan-continuous-p.pdf');
      expect(infoS.media[0].map((v) => Math.round(v * 10) / 10), 'GET pdf tersimpan Continuous-P = ukuran Continuous (bukan A4)').toEqual([612, 381.6]);
      checkFooters(infoS, 'GET pdf tersimpan Continuous-P', 5);
      const ownS = own();
      expect(ownS.paper_size, 'DB: kertas tersimpan Continuous-P').toBe('Continuous-P');
      expect(ownS.other, 'C-9: template lain sama persis').toEqual(before.other);
      expect(ownS.tpl_count, 'tidak ada baris template baru').toBe(before.tpl_count || 1);
      await viewerFooters(page, pdfDrawer(page), infoS.pages, 'DrawerPdf tersimpan Continuous-P');
      await app.screenshot('r3-06-drawerpdf-tersimpan-continuous-p');

      // --- 6. pulihkan lewat UI: A4 + Portrait, Simpan
      const { cz: cz2 } = await openCustomize(page, pdfDrawer(page));
      const pa4 = await previewOf(page, 'A4-P', async () => {
        await pickPaper(page, cz2, /^A4$/);
      }, 'BULK-preview-a4p-pulih.pdf');
      expect(pa4.status, 'pratinjau A4-P (pulih) 200').toBe(200);
      checkFooters(pa4.info, 'Customize pratinjau A4-P (pulih)', 5);
      await page.waitForTimeout(1500);
      const sv2 = await saveCustomizeFor(page, cz2, BULK);
      const infoP = pdfOf(sv2.pdfBuf, 'BULK-tersimpan-a4p-pulih.pdf');
      expect(infoP.media[0][1], 'PDF pulih: potret A4').toBeGreaterThan(infoP.media[0][0]);
      checkFooters(infoP, 'GET pdf tersimpan A4-P (pulih)', 5);
      expect(own().paper_size, 'DB pulih: A4-P').toBe('A4-P');
      monitor.note(`footer per kondisi (halaman dengan footer / total, MediaBox pt): ${JSON.stringify(rep)}`);
      noServerError(monitor);
    } finally {
      monitor.note(`ringkas footer: ${JSON.stringify(rep)}; jejak: ${trail.join(' || ')}`);
      const r = lastJson(fixture('own-restore'));
      const after = own();
      expect(r.restored, 'own-restore').toBe(true);
      expect(after.tpl_count, 'baris template sendiri kembali ke awal').toBe(before.tpl_count);
      expect(after.cds_count, 'baris cds sendiri kembali ke awal').toBe(before.cds_count);
      expect(after.other, 'C-9 akhir: template lain sama persis').toEqual(before.other);
    }
  });
});
