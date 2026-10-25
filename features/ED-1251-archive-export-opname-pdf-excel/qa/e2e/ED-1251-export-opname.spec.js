'use strict';

/**
 * ED-1251 - Archive: export PDF & Excel hasil satu sesi opname (kolom aksi tabel Opname History, judul Opname Result, DrawerPdf + Customize).
 * Menguji AC-16..AC-22 [FE] di browser (profil [default] = QA_USER, pemegang Opname Document, bahasa ID bawaan; EN lewat fixture.php lang).
 *
 * Data: fixtures.json -> fixrun (FIX-UP/FIX-DOWN) membuat S1 (folder 0QA251-F + 2 subfolder, 4 angka berbeda) dan S2 (folder 0QA251-G) lewat
 * POST opnames oleh QA_USER, memulihkan persis di teardown (user, baris cds + template documentArchiveOpnameResult, template lain C-9).
 * Spec membaca .fixture-ids.json; baris S1/S2 dicari lewat nama folder di kolom Scope (sesi developer ikut tampil, tidak disentuh).
 * Isi berkas dibuktikan dari ISI (PDF: teks/MediaBox lewat fixture.php pdf; XLSX: sel lewat fixture.php xlsx), bukan hanya HTTP 200.
 * Worker pdf.js (unpkg.com) dilayani dari node_modules FE (versi sama) supaya render viewer tidak bergantung internet.
 * Mutasi: baris template documentArchiveOpnameResult (Simpan Customize; test 'Customize' memulihkan lewat UI lalu fixture.php own-restore),
 * bahasa QA_USER (test EN; dipulihkan di finally dan teardown). Jalankan dengan --workers 1.
 */

const fs = require('fs');
const { test, expect } = require('e2e-harness');
const {
  fixture, lastJson, fx, own, save, norm, pdfOf, xlsxOf, esc, trimAll, sleep, LANG, OPNAMES_RE, waitOpnames, waitList, modalVisible, drawerOpen, hist, resDrawer, pdfDrawer, customizeDrawer, histTable, histRowsLoc, searchInput, rowOf, sessionRow, actionCell, printBtn, excelBtn, idOf, reUrl, pdfWorker, openArchive, openModal, openHistoryFromModal, pickRowMenu, openFolderHistoryFromTab, openFolderHistoryFromModal, duplicateIds, expectRejected, noServerError, expectTooltip, pdfReady, clickPrint, expectPdfOf, expectXlsxOf,
} = require('./lib');

// ------------------------------------------------------------------------------------------------ test
test.describe('ED-1251 export PDF & Excel hasil opname', () => {
  test.beforeAll(() => {
    // fixture wajib ada (FIX-UP): gagal jelas bila spec dijalankan dengan E2E_FIXTURES=0
    const f = fx();
    expect(f.ids.S1 && f.ids.S2, 'fixture S1/S2 ada').toBeTruthy();
  });

  test('[default] ED-1251 AC-17/AC-16 Opname History (modal 05): tiap baris kolom kanan tetap dengan tepat 2 tombol ikon tanpa teks, tooltip, aria-label, tanpa id ganda; klik tombol tidak membuka Hasil; klik sel membuka Hasil', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    await openArchive(app, page);
    const idsBefore = await duplicateIds(page);
    await openHistoryFromModal(app, page);
    await app.screenshot('ac17-01-riwayat-desktop');

    const rows = histRowsLoc(page);
    const n = await rows.count();
    monitor.note(`baris riwayat terender: ${n}`);
    expect(n, 'ada sesi uji + sesi developer').toBeGreaterThanOrEqual(2);

    // header: kolom aksi tanpa judul, menempel kanan
    const ths = hist(page).locator('.ant-table-thead th:not(.ant-table-cell-scrollbar)');
    const thCount = await ths.count();
    const heads = trimAll(await ths.allInnerTexts());
    expect(heads[heads.length - 1], 'judul kolom aksi kosong').toBe('');
    expect(heads.slice(0, -1).filter(Boolean).length, 'kolom server tetap berjudul').toBe(thCount - 1);
    await expect(ths.last(), 'header kolom aksi menempel kanan').toHaveClass(/ant-table-cell-fix-right/);

    for (let i = 0; i < n; i += 1) {
      const row = rows.nth(i);
      const tds = row.locator('td');
      expect(await tds.count(), `baris ${i}: jumlah sel = jumlah kolom`).toBe(thCount);
      const cell = tds.last();
      await expect(cell, `baris ${i}: sel aksi menempel kanan`).toHaveClass(/ant-table-cell-fix-right/);
      expect(trimAll([await cell.innerText()])[0], `baris ${i}: sel aksi tanpa teks`).toBe('');
      const btns = cell.locator('button');
      expect(await btns.count(), `baris ${i}: tepat 2 tombol`).toBe(2);
      expect(await btns.evaluateAll((els) => els.map((e) => e.getAttribute('aria-label'))), `baris ${i}: aria-label`).toEqual(['Print Preview', 'Excel']);
      expect(await cell.locator('[id]').count(), `baris ${i}: tanpa atribut id`).toBe(0);
      await expect(btns.nth(0).locator('.anticon-printer'), `baris ${i}: ikon printer`).toHaveCount(1);
      await expect(btns.nth(1).locator('.anticon-file-excel'), `baris ${i}: ikon Excel`).toHaveCount(1);
    }
    expect(await hist(page).locator('button[aria-label="Print Preview"]').count(), 'satu tombol Print per baris').toBe(n);
    expect(await hist(page).locator('button[aria-label="Excel"]').count(), 'satu tombol Excel per baris').toBe(n);
    const idsAfter = await duplicateIds(page);
    const newDup = idsAfter.filter((x) => !idsBefore.includes(x));
    monitor.note(`id DOM ganda baru sesudah riwayat terbuka (bukan milik tombol export bila tidak memuat print/excel/pdf/xls): ${JSON.stringify(newDup)}`);
    expect(newDup.filter((x) => /print|excel|pdf|xls|export|opname/i.test(x)), 'tanpa id DOM ganda dari tombol export').toEqual([]);

    // tooltip desktop
    const s1 = sessionRow(page, 'S1');
    await printBtn(s1).hover();
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip Print').toHaveText('Print Preview');
    await excelBtn(s1).hover();
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip Excel').toHaveText('Excel');
    await app.screenshot('ac17-02-tooltip-excel');
    await page.mouse.move(5, 5);

    // posisi menempel kanan saat tabel digeser: sel aksi tetap di tepi kanan kontainer tabel
    const geo = await page.evaluate(() => {
      const h = document.querySelector('.archive-opname-history.ant-drawer-open');
      const wrap = h.querySelector('.ant-table-body, .ant-table-content') || h.querySelector('.ant-table');
      const td = h.querySelector('tbody tr.ant-table-row td:last-child');
      const w = wrap.getBoundingClientRect();
      const c = td.getBoundingClientRect();
      return { scrollW: wrap.scrollWidth, clientW: wrap.clientWidth, wrapRight: Math.round(w.right), cellRight: Math.round(c.right), cellW: Math.round(c.width), pos: getComputedStyle(td).position };
    });
    monitor.note(`geometri kolom aksi: ${JSON.stringify(geo)}`);
    expect(geo.pos, 'sel aksi sticky (fixed right)').toBe('sticky');
    expect(geo.cellRight, 'sel aksi di dalam kontainer tabel').toBeLessThanOrEqual(geo.wrapRight + 1);

    // klik tombol (Print lalu tutup, Excel) tidak membuka Hasil; klik sel lain membuka Hasil
    await printBtn(s1).click();
    await pdfReady(page);
    await expect(resDrawer(page), 'Print: Hasil Opname tidak terbuka').toHaveCount(0);
    await pdfDrawer(page).locator('.ant-drawer-close').click();
    await expect(pdfDrawer(page), 'DrawerPdf tertutup').toHaveCount(0);

    const detail = page.waitForResponse((r) => r.request().method() === 'GET' && new RegExp(`/document-archive/opnames/${idOf('S1')}(\\?|$)`).test(r.url()), { timeout: 30000 });
    await s1.locator('td').nth(2).click();
    expect((await detail).status(), 'klik sel: GET opnames/{S1} 200').toBe(200);
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await app.screenshot('ac17-03-klik-sel-membuka-hasil');
    noServerError(monitor);
  });

  test('[default] ED-1251 AC-17/AC-16/AC-21 375 px: kolom aksi tetap terlihat, tanpa tooltip; Hasil Opname judul + 2 tombol tidak bertumpuk/terpotong; DrawerPdf (menu) dan Customize terbuka', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    await page.setViewportSize({ width: 375, height: 812 });
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    await app.screenshot('ac17-10-riwayat-375');

    const s1 = sessionRow(page, 'S1');
    const rows = histRowsLoc(page);
    const n = await rows.count();
    const outOfView = [];
    for (let i = 0; i < n; i += 1) {
      const cell = actionCell(rows.nth(i));
      const box = await cell.boundingBox();
      const btns = await cell.locator('button').count();
      if (btns !== 2) outOfView.push(`baris ${i}: ${btns} tombol`);
      if (!box || box.x + box.width > 375 + 1 || box.x < 0) outOfView.push(`baris ${i}: sel aksi di luar viewport ${JSON.stringify(box)}`);
    }
    expect(outOfView, '375 px: kolom aksi terlihat penuh di viewport pada semua baris').toEqual([]);
    for (const b of [printBtn(s1), excelBtn(s1)]) {
      const box = await b.boundingBox();
      expect(box && box.width >= 20 && box.height >= 20, `tombol terlihat dan cukup besar (${JSON.stringify(box)})`).toBe(true);
    }
    await printBtn(s1).hover();
    await page.waitForTimeout(500);
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)'), '375 px: tanpa tooltip').toHaveCount(0);

    // Hasil Opname 375 px
    await s1.locator('td').nth(2).click();
    const rd = resDrawer(page);
    await rd.waitFor({ state: 'visible', timeout: 15000 });
    await rd.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    const lay = await page.evaluate(() => {
      const title = document.querySelector('.archive-opname-history .ant-drawer-open .ant-drawer-title');
      const wrap = title.querySelector('.flex-justify-between');
      const rect = (r) => ({ x: Math.round(r.x), y: Math.round(r.y), r: Math.round(r.right), b: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) });
      let titleRect = null;
      if (wrap) {
        const tn = [...wrap.childNodes].find((n) => n.nodeType === 3 && n.textContent.trim());
        if (tn) {
          const range = document.createRange();
          range.selectNodeContents(tn);
          titleRect = rect(range.getBoundingClientRect());
        }
      }
      const space = wrap && wrap.querySelector(':scope > .ant-space, :scope > *');
      const sub = title.querySelector('.text-muted');
      return {
        hasWrap: !!wrap,
        wrap: wrap && rect(wrap.getBoundingClientRect()),
        titleText: titleRect,
        buttons: space && rect(space.getBoundingClientRect()),
        sub: sub && rect(sub.getBoundingClientRect()),
        nButtons: wrap ? wrap.querySelectorAll('button').length : 0,
        vw: window.innerWidth,
      };
    });
    monitor.note(`tata letak judul Hasil (375 px): ${JSON.stringify(lay)}`);
    expect(lay.hasWrap, 'judul + tombol dalam satu div.flex-justify-between').toBe(true);
    expect(lay.nButtons, '2 tombol di judul').toBe(2);
    expect(lay.buttons.r, 'tombol tidak terpotong di kanan viewport').toBeLessThanOrEqual(lay.vw);
    expect(lay.titleText.r <= lay.buttons.x + 1 || lay.titleText.b <= lay.buttons.y || lay.buttons.b <= lay.titleText.y, 'judul dan tombol tidak bertumpuk').toBe(true);
    expect(lay.sub.y, 'subjudul di bawah judul').toBeGreaterThanOrEqual(lay.titleText.b - 2);
    await app.screenshot('ac21-10-hasil-375');

    // DrawerPdf 375: Print dari judul Hasil, menu Unduh/Sesuaikan
    const { resp, buf } = await clickPrint(page, idOf('S1'), rd.locator('.ant-drawer-title button[aria-label="Print Preview"]'));
    expect(resp.status(), 'GET pdf S1 200').toBe(200);
    expectPdfOf(pdfOf(buf, 'S1-375.pdf'), 'S1', 'id', '375 px');
    const d = await pdfReady(page);
    await app.screenshot('ac18-10-drawerpdf-375');
    // DrawerPdf (komponen bersama) membangun ulang tombol judul saat PDF selesai dimuat: tunggu, lalu ulangi klik bila menu belum terbuka
    await page.waitForTimeout(1500);
    const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    for (let attempt = 0; attempt < 3 && !(await menu.isVisible().catch(() => false)); attempt += 1) {
      await pdfDrawer(page).locator('.ant-drawer-extra .ant-dropdown-trigger').first().click({ timeout: 15000 });
      await page.waitForTimeout(800);
    }
    await menu.waitFor({ state: 'visible', timeout: 10000 });
    expect(trimAll(await menu.locator('li').allInnerTexts()), '375 px: menu Unduh + Sesuaikan').toEqual([LANG.id.download, LANG.id.customizeBtn]);
    const selTemplate = page.waitForResponse((r) => /\/select\/report\/document-archive\/opname\/transaction-templates/.test(r.url()), { timeout: 45000 });
    await menu.locator('li', { hasText: LANG.id.customizeBtn }).click();
    expect((await selTemplate).status(), 'transaction-templates 200 (375 px)').toBe(200);
    const cz = customizeDrawer(page);
    await cz.waitFor({ state: 'visible', timeout: 15000 });
    await cz.locator('.ant-skeleton-active').first().waitFor({ state: 'detached', timeout: 30000 }).catch(() => null);
    await expect(cz.locator('.ant-drawer-title'), 'judul Customize').toContainText(LANG.id.customize);
    await app.screenshot('ac19-10-customize-375');
    noServerError(monitor);
    // tutup tanpa menyimpan: kembali ke DrawerPdf (jangan Simpan)
    await cz.locator('.ant-drawer-close').click();
    await expect(customizeDrawer(page), 'Customize tertutup tanpa menyimpan').toHaveCount(0);
    expect(own().tpl_count === 0 || own().tpl_count === 1, 'tidak ada baris template ganda').toBe(true);
  });

  test('[default] ED-1251 AC-18 Print baris S1: DrawerPdf di atas riwayat, satu GET pdf 200 berisi sesi S1, Hasil tidak terbuka, tombol Print/Download/Customize; tutup = halaman & filter sama tanpa GET opnames baru', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    // filter dulu supaya "filter sama" bermakna: cari nama folder S1 (hanya sesi yang mencakup folder itu)
    const F = fx().folder_names.F;
    await searchInput(page).click();
    await searchInput(page).fill(F);
    const filtered = waitOpnames(page);
    await searchInput(page).press('Enter');
    await filtered;
    await page.waitForTimeout(600);
    const keptRows = trimAll(await histRowsLoc(page).first().locator('td').allInnerTexts());
    const rowsBefore = await histRowsLoc(page).count();

    const listCalls = [];
    page.on('response', (r) => {
      if (r.request().method() === 'GET' && OPNAMES_RE.test(r.url())) listCalls.push(r.url());
    });
    const pdfCalls = [];
    page.on('response', (r) => {
      if (/\/document-archive\/opnames\/[^/]+\/pdf(\?|$)/.test(r.url())) pdfCalls.push(`${r.request().method()} ${r.status()}`);
    });
    const s1 = sessionRow(page, 'S1');
    await expect(s1).toBeVisible();
    const { resp, buf } = await clickPrint(page, idOf('S1'), printBtn(s1));
    expect(resp.status(), 'GET pdf 200').toBe(200);
    expect(resp.headers()['content-type'], 'application/pdf').toContain('application/pdf');
    expect(resp.headers()['content-disposition'], 'Content-Disposition Opname_Archive_<tanggal>_<jam>.pdf').toMatch(/filename="?Opname_Archive_\d{2}-[A-Za-z]{3}-\d{4}_\d{6}\.pdf"?/);
    const info = pdfOf(buf, 'S1-print.pdf');
    expectPdfOf(info, 'S1', 'id', 'Print S1');
    monitor.note(`PDF S1 dari FE: ${info.bytes} byte, ${info.pages} halaman, MediaBox ${JSON.stringify(info.media[0])}`);
    expect(info.media[0][1], 'A4 potret: tinggi > lebar').toBeGreaterThan(info.media[0][0]);

    const d = await pdfReady(page);
    await expect(resDrawer(page), 'Hasil Opname tidak terbuka').toHaveCount(0);
    expect(pdfCalls, 'tepat satu GET pdf').toEqual(['GET 200']);
    const buttons = d.locator('.ant-drawer-extra button');
    expect(await buttons.count(), 'tombol Print, Download, Customize').toBe(3);
    await expect(buttons.nth(0).locator('.anticon-printer'), 'tombol 1 = Print').toHaveCount(1);
    await expectTooltip(page, buttons.nth(1), LANG.id.download, 'tooltip Download');
    await expectTooltip(page, buttons.nth(2), LANG.id.customizeBtn, 'tooltip Customize');
    await page.mouse.move(5, 5);
    await expect(d.locator('.ant-drawer-title'), 'judul DrawerPdf "Print Preview"').toContainText('Print Preview');

    // Download di DrawerPdf: berkas bernama dari header server, isi = PDF yang sama
    const dlP = page.waitForEvent('download', { timeout: 30000 });
    await buttons.nth(1).click();
    const dlPdf = await dlP;
    expect(dlPdf.suggestedFilename(), 'unduhan PDF bernama Opname_Archive_<tanggal>_<jam>.pdf (dari Content-Disposition)').toMatch(/^Opname_Archive_\d{2}-[A-Za-z]{3}-\d{4}_\d{6}\.pdf$/);
    const dlInfo = pdfOf(fs.readFileSync(await dlPdf.path()), 'S1-download.pdf');
    expectPdfOf(dlInfo, 'S1', 'id', 'unduhan DrawerPdf');
    expect(dlInfo.bytes, 'unduhan = PDF yang sama dengan pratinjau').toBe(info.bytes);

    // viewer merender PDF (isi, bukan kotak kosong): teks halaman pertama berisi judul laporan
    const viewer = d.locator('.rpv-core__viewer');
    await expect(viewer, 'viewer PDF ada').toHaveCount(1);
    await expect.poll(async () => (await d.locator('.rpv-core__text-layer-text, .rpv-core__text-layer').allInnerTexts()).join(' ').includes(LANG.id.pdfReportName), { message: 'viewer menampilkan teks PDF', timeout: 30000 }).toBe(true);
    const canvas = await d.locator('canvas').first().evaluate((c) => ({ w: c.width, h: c.height, nonBlank: (() => { const x = c.getContext('2d'); const data = x.getImageData(0, 0, c.width, c.height).data; for (let i = 0; i < data.length; i += 4) if (data[i] !== 255 || data[i + 1] !== 255 || data[i + 2] !== 255) return true; return false; })() }));
    monitor.note(`kanvas viewer: ${JSON.stringify(canvas)}`);
    expect(canvas.w > 100 && canvas.h > 100 && canvas.nonBlank, 'kanvas halaman PDF terisi (bukan putih kosong)').toBe(true);
    await app.screenshot('ac18-01-drawerpdf-s1');

    // tutup -> kembali ke riwayat: filter, baris, halaman sama; tidak ada GET opnames baru
    const callsBeforeClose = listCalls.length;
    await d.locator('.ant-drawer-close').click();
    await expect(pdfDrawer(page), 'DrawerPdf tertutup').toHaveCount(0);
    await page.waitForTimeout(800);
    expect(await searchInput(page).inputValue(), 'filter pencarian sama').toBe(F);
    expect(await histRowsLoc(page).count(), 'jumlah baris sama').toBe(rowsBefore);
    expect(trimAll(await histRowsLoc(page).first().locator('td').allInnerTexts()), 'baris pertama sama').toEqual(keptRows);
    expect(listCalls.length, 'tidak ada GET document-archive/opnames baru sesudah tutup').toBe(callsBeforeClose);
    await expect(resDrawer(page), 'Hasil Opname tetap tidak terbuka').toHaveCount(0);
    await app.screenshot('ac18-02-kembali-ke-riwayat');
    noServerError(monitor);
  });

  test('[default] ED-1251 AC-20 Excel baris S1/S2: satu GET xls per klik, unduh Opname_Archive_*.xlsx berisi sesi itu, tombol itu loading (baris/tombol lain tidak), klik ulang tanpa request kedua, Hasil tidak terbuka', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const calls = [];
    page.on('response', (r) => {
      if (/\/document-archive\/opnames\/[^/]+\/xls(\?|$)/.test(r.url())) calls.push({ url: r.url(), status: r.status(), type: r.headers()['content-type'], cd: r.headers()['content-disposition'] });
    });
    let hold = 2500;
    await page.route(/\/document-archive\/opnames\/[^/]+\/xls(\?|$)/, async (route) => {
      await sleep(hold);
      await route.continue();
    });

    const s1 = sessionRow(page, 'S1');
    const s2 = sessionRow(page, 'S2');
    const downloads = [];
    page.on('download', (dl) => downloads.push(dl));
    await excelBtn(s1).click();
    // selama unduhan berjalan: tombol itu loading, tombol lain normal
    await expect(excelBtn(s1), 'S1: tombol Excel loading').toHaveClass(/ant-btn-loading/);
    await expect(printBtn(s1), 'S1: tombol Print normal').not.toHaveClass(/ant-btn-loading/);
    await expect(excelBtn(s2), 'S2: tombol Excel normal').not.toHaveClass(/ant-btn-loading/);
    await expect(printBtn(s2), 'S2: tombol Print normal').not.toHaveClass(/ant-btn-loading/);
    await app.screenshot('ac20-01-excel-loading');
    // klik ulang selagi loading: tidak ada request kedua
    await excelBtn(s1).click({ force: true });
    await excelBtn(s1).click({ force: true });
    await page.waitForTimeout(500);
    // S2 boleh jalan bersamaan (dua request, masing-masing loading sampai berkasnya sendiri)
    await excelBtn(s2).click();
    await expect(excelBtn(s2), 'S2: tombol Excel loading').toHaveClass(/ant-btn-loading/);
    await expect(excelBtn(s1), 'S1 masih loading').toHaveClass(/ant-btn-loading/);
    // tiap permintaan ditahan 2,5 dtk dan S1 lebih dulu: unduhan pertama = S1, kedua = S2 (isi berkas dibuktikan di bawah)
    await expect.poll(() => downloads.length, { message: 'unduhan pertama (S1)', timeout: 60000 }).toBeGreaterThanOrEqual(1);
    await expect(excelBtn(s1), 'S1 normal lagi sesudah selesai').not.toHaveClass(/ant-btn-loading/);
    await expect.poll(() => downloads.length, { message: 'unduhan kedua (S2)', timeout: 60000 }).toBeGreaterThanOrEqual(2);
    await expect(excelBtn(s2), 'S2 normal lagi sesudah selesai').not.toHaveClass(/ant-btn-loading/);
    const [d1, d2] = downloads;
    await page.unroute(/\/document-archive\/opnames\/[^/]+\/xls(\?|$)/);

    expect(calls.map((c) => c.status), 'tepat dua GET xls (S1 sekali, S2 sekali) walau S1 diklik 3x').toEqual([200, 200]);
    expect(calls.filter((c) => reUrl(idOf('S1'), 'xls').test(c.url)).length, 'GET xls S1 = 1').toBe(1);
    expect(calls.filter((c) => reUrl(idOf('S2'), 'xls').test(c.url)).length, 'GET xls S2 = 1').toBe(1);
    for (const c of calls) {
      expect(c.type, 'Content-Type xlsx').toContain('spreadsheetml.sheet');
      expect(c.cd, 'Content-Disposition').toMatch(/filename="?Opname_Archive_\d{2}-[A-Za-z]{3}-\d{4}_\d{6}\.xlsx"?/);
    }
    expect(d1.suggestedFilename(), 'unduhan S1 bernama dari header').toMatch(/^Opname_Archive_\d{2}-[A-Za-z]{3}-\d{4}_\d{6}\.xlsx$/);
    expect(d2.suggestedFilename(), 'unduhan S2 bernama dari header').toMatch(/^Opname_Archive_\d{2}-[A-Za-z]{3}-\d{4}_\d{6}\.xlsx$/);
    const p1 = save('S1.xlsx', fs.readFileSync(await d1.path()));
    const p2 = save('S2.xlsx', fs.readFileSync(await d2.path()));
    const x1 = lastJson(fixture('xlsx', p1));
    const x2 = lastJson(fixture('xlsx', p2));
    x1.cells = Object.values(x1.rows).flatMap((r) => Object.values(r)).map(norm);
    x2.cells = Object.values(x2.rows).flatMap((r) => Object.values(r)).map(norm);
    expectXlsxOf(x1, 'S1', 'id', 'Excel S1');
    expectXlsxOf(x2, 'S2', 'id', 'Excel S2');
    monitor.note(`XLSX S1: ${x1.bytes} byte, ${Object.keys(x1.rows).length} baris berisi; S2: ${x2.bytes} byte, ${Object.keys(x2.rows).length} baris`);
    await expect(resDrawer(page), 'Excel tidak membuka Hasil Opname').toHaveCount(0);
    await app.screenshot('ac20-02-excel-selesai');
    noServerError(monitor);
  });

  test('[default] ED-1251 AC-21/AC-18/AC-20 Hasil Opname: judul kiri + 2 tombol kanan dalam satu div.flex-justify-between, subjudul di bawah; Print/Excel memakai sesi yang dibuka; tutup DrawerPdf kembali ke Hasil yang sama', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    // buka Hasil S2 (bukan baris pertama) supaya terbukti memakai sesi yang dibuka, bukan sesi lain
    const s2 = sessionRow(page, 'S2');
    await s2.locator('td').nth(2).click();
    const rd = resDrawer(page);
    await rd.waitFor({ state: 'visible', timeout: 15000 });
    await rd.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForTimeout(600);
    const title = rd.locator('.ant-drawer-title');
    const wrap = title.locator('.flex-justify-between');
    await expect(wrap, 'satu wadah flex-justify-between di judul').toHaveCount(1);
    await expect(wrap, 'judul "Hasil Opname" di wadah itu').toContainText(LANG.id.resultTitle);
    await expect(wrap.locator('button'), '2 tombol di wadah').toHaveCount(2);
    const g = await page.evaluate(() => {
      const t = document.querySelector('.archive-opname-history .ant-drawer-open .ant-drawer-title');
      const w = t.querySelector('.flex-justify-between');
      const tn = [...w.childNodes].find((n) => n.nodeType === 3 && n.textContent.trim());
      const range = document.createRange();
      range.selectNodeContents(tn);
      const a = range.getBoundingClientRect();
      const b = w.querySelector(':scope > *').getBoundingClientRect();
      const sub = t.querySelector('.text-muted').getBoundingClientRect();
      const css = getComputedStyle(w);
      return { display: css.display, jc: css.justifyContent, titleX: Math.round(a.x), titleY: Math.round(a.y), btnX: Math.round(b.x), btnR: Math.round(b.right), wrapR: Math.round(w.getBoundingClientRect().right), subY: Math.round(sub.y), titleB: Math.round(a.bottom) };
    });
    monitor.note(`tata letak judul Hasil (desktop): ${JSON.stringify(g)}`);
    expect(g.display + '/' + g.jc, 'flex space-between').toBe('flex/space-between');
    expect(g.btnX, 'tombol di kanan judul').toBeGreaterThan(g.titleX + 40);
    expect(g.btnR, 'tombol rata kanan wadah').toBeGreaterThanOrEqual(g.wrapR - 2);
    expect(g.subY, 'subjudul di bawah judul').toBeGreaterThanOrEqual(g.titleB - 2);
    const f = fx();
    await expect(title.locator('.text-muted'), 'subjudul memuat user · scope sesi S2').toContainText(`${f.sessions.S2.created_by} · ${f.folder_names.G}`);
    await app.screenshot('ac21-01-hasil-judul-tombol');
    // tooltip tombol judul
    await title.locator('button[aria-label="Print Preview"]').hover();
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), 'tooltip Print di judul Hasil').toHaveText('Print Preview');
    await page.mouse.move(5, 5);

    // Print dari judul: GET pdf SESI YANG DIBUKA (S2), DrawerPdf menutupi Hasil; tutup = Hasil S2 yang sama
    const { resp, buf } = await clickPrint(page, idOf('S2'), title.locator('button[aria-label="Print Preview"]'));
    expect(resp.status(), 'GET pdf S2 200').toBe(200);
    expectPdfOf(pdfOf(buf, 'S2-dari-hasil.pdf'), 'S2', 'id', 'Print dari Hasil S2');
    const d = await pdfReady(page);
    await app.screenshot('ac21-02-drawerpdf-dari-hasil');
    await d.locator('.ant-drawer-close').click();
    await expect(pdfDrawer(page), 'DrawerPdf tertutup').toHaveCount(0);
    await expect(rd, 'kembali ke Hasil Opname yang sama').toBeVisible();
    await expect(rd.locator('.ant-drawer-title .text-muted'), 'Hasil masih sesi S2').toContainText(f.folder_names.G);

    // Excel dari judul: GET xls S2, unduh berkas S2
    const xr = page.waitForResponse((r) => r.request().method() === 'GET' && reUrl(idOf('S2'), 'xls').test(r.url()), { timeout: 60000 });
    const dl = page.waitForEvent('download', { timeout: 60000 });
    await title.locator('button[aria-label="Excel"]').click();
    expect((await xr).status(), 'GET xls S2 200').toBe(200);
    const d1 = await dl;
    expect(d1.suggestedFilename(), 'nama unduhan').toMatch(/^Opname_Archive_\d{2}-[A-Za-z]{3}-\d{4}_\d{6}\.xlsx$/);
    const x = lastJson(fixture('xlsx', save('S2-dari-hasil.xlsx', fs.readFileSync(await d1.path()))));
    x.cells = Object.values(x.rows).flatMap((r) => Object.values(r)).map(norm);
    expectXlsxOf(x, 'S2', 'id', 'Excel dari Hasil S2');
    await expect(title.locator('button[aria-label="Excel"]'), 'tombol Excel normal lagi').not.toHaveClass(/ant-btn-loading/);
    noServerError(monitor);
  });

  for (const vp of [{ label: 'desktop', size: null }, { label: '375 px', size: { width: 375, height: 812 } }]) {
  test(`[default] ED-1251 AC-16 layar lain (${vp.label}): tab Verification drawer folder dan modal verifikasi folder memuat riwayat dengan 2 tombol per baris; Print membuka DrawerPdf sesi folder itu; Hasil Opname + DrawerPdf terbuka tanpa error`, async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    if (vp.size) await page.setViewportSize(vp.size);
    const sfx = vp.size ? '-375' : '';
    const f = fx();
    await openArchive(app, page);
    // (b) tab Verification
    await openFolderHistoryFromTab(app, page, f.folder_names.F);
    const rowsB = histRowsLoc(page);
    const nB = await rowsB.count();
    expect(nB, 'riwayat folder F: ada baris').toBeGreaterThanOrEqual(1);
    for (let i = 0; i < nB; i += 1) {
      expect(await actionCell(rowsB.nth(i)).locator('button').count(), `tab Verification baris ${i}: 2 tombol`).toBe(2);
    }
    const s1 = sessionRow(page, 'S1');
    await expect(s1, 'sesi S1 ada di riwayat folder F').toBeVisible();
    await app.screenshot('ac16-01-riwayat-tab-verification' + sfx);
    const { resp, buf } = await clickPrint(page, idOf('S1'), printBtn(s1));
    expect(resp.status(), 'GET pdf S1 (dari tab Verification) 200').toBe(200);
    expectPdfOf(pdfOf(buf, 'S1-tab-verification.pdf'), 'S1', 'id', 'tab Verification');
    const d = await pdfReady(page);
    await app.screenshot('ac16-02-drawerpdf-dari-tab' + sfx);
    await d.locator('.ant-drawer-close').click();
    await expect(pdfDrawer(page), 'DrawerPdf tertutup').toHaveCount(0);
    // Hasil dari riwayat folder
    await s1.locator('td').nth(2).click();
    await resDrawer(page).waitFor({ state: 'visible', timeout: 15000 });
    await resDrawer(page).locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
    await expect(resDrawer(page).locator('.ant-drawer-title button[aria-label="Print Preview"]'), 'Hasil (riwayat folder): tombol Print').toHaveCount(1);
    await expect(resDrawer(page).locator('.ant-drawer-title button[aria-label="Excel"]'), 'Hasil (riwayat folder): tombol Excel').toHaveCount(1);
    await app.screenshot('ac16-03-hasil-dari-tab' + sfx);
    noServerError(monitor);

    // (c) modal verifikasi folder: muat ulang halaman supaya mulai bersih
    await page.reload({ waitUntil: 'domcontentloaded' });
    await openArchive(app, page);
    await openFolderHistoryFromModal(app, page, f.folder_names.G);
    const rowsC = histRowsLoc(page);
    const nC = await rowsC.count();
    for (let i = 0; i < nC; i += 1) {
      expect(await actionCell(rowsC.nth(i)).locator('button').count(), `modal folder baris ${i}: 2 tombol`).toBe(2);
    }
    const s2 = sessionRow(page, 'S2');
    await expect(s2, 'sesi S2 ada di riwayat folder G').toBeVisible();
    const r2 = await clickPrint(page, idOf('S2'), printBtn(s2));
    expect(r2.resp.status(), 'GET pdf S2 (dari modal folder) 200').toBe(200);
    expectPdfOf(pdfOf(r2.buf, 'S2-modal-folder.pdf'), 'S2', 'id', 'modal folder');
    await pdfReady(page);
    await app.screenshot('ac16-04-drawerpdf-dari-modal-folder' + sfx);
    noServerError(monitor);
  });
  }
});
