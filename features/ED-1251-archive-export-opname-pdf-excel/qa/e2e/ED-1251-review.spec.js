'use strict';

/**
 * ED-1251 - revisi review 2026-10-26, bagian FE R-2 (QA FE r2): tombol Print/Excel baris Opname History SAMA dengan tombol judul Opname Result
 * (type primary, ikon filled, icon only, tooltip, aria-label). R-1 (label display setting) tidak punya layar ter-route (DisplaySettingPage tanpa
 * route/path/menu): dibuktikan statis + unit test, bukan di sini. R-3/R-4 FE (footer tiap halaman, pratinjau Continuous) ada di
 * ED-1251-customize.spec.js. Berbagi data dengan ED-1251-export-opname.spec.js (fixture S1/S2). Tidak mutasi data (Excel ditahan lewat page.route,
 * unduhan dibuang). Jalankan dengan --workers 1.
 */

const { test, expect } = require('e2e-harness');
const { fx, sleep, hist, resDrawer, sessionRow, actionCell, printBtn, excelBtn, openArchive, openHistoryFromModal, noServerError, crop, antIconPaths } = require('./lib');

const XLS_RE = /\/document-archive\/opnames\/[^/]+\/xls(\?|$)/;

/** semua sifat yang membuat dua tombol "tampak sama": kelas, ukuran, warna, bentuk, ikon (path svg), nama aksesibel */
const lookOf = (container) =>
  container.evaluate((root) =>
    [...root.querySelectorAll('button')].map((b) => {
      const cs = getComputedStyle(b);
      const r = b.getBoundingClientRect();
      const ic = b.querySelector('.anticon');
      const svg = b.querySelector('svg');
      return {
        aria: b.getAttribute('aria-label'),
        type: b.getAttribute('type'),
        cls: b.className.split(/\s+/).filter((c) => c && c !== 'ant-btn-loading' && c !== 'ant-click-animating-without-extra-node').sort().join(' '),
        loading: b.classList.contains('ant-btn-loading'),
        hasId: b.hasAttribute('id'),
        w: Math.round(r.width * 10) / 10,
        h: Math.round(r.height * 10) / 10,
        bg: cs.backgroundColor,
        color: cs.color,
        radius: cs.borderTopLeftRadius,
        border: `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}`,
        shadow: cs.boxShadow,
        padding: cs.padding,
        iconFont: ic ? getComputedStyle(ic).fontSize : null,
        iconPaths: svg ? [...svg.querySelectorAll('path')].map((p) => p.getAttribute('d')) : [],
        svgFill: svg ? getComputedStyle(svg).fill : null,
        hasLoadingIcon: !!b.querySelector('.ant-btn-loading-icon'),
        text: (b.textContent || '').trim(),
      };
    }),
  );

const strip = (looks, drop = []) => looks.map((l) => Object.fromEntries(Object.entries(l).filter(([k]) => !drop.includes(k))));

/** daftar perbedaan sifat tombol-per-tombol (kosong = sama persis); pesan gagal memuat sifat dan nilainya, bukan diff JSON yang terpotong */
const diffLooks = (a, b) => {
  const out = [];
  if (a.length !== b.length) out.push(`jumlah tombol ${a.length} != ${b.length}`);
  a.forEach((x, i) => {
    const y = b[i] || {};
    [...new Set([...Object.keys(x), ...Object.keys(y)])].forEach((k) => {
      if (JSON.stringify(x[k]) !== JSON.stringify(y[k])) out.push(`tombol ${i} (${x.aria}) ${k}: ${JSON.stringify(x[k])} != ${JSON.stringify(y[k])}`);
    });
  });
  return out;
};

/** buka Hasil Opname sesi S1 dari riwayat; kembalikan { rd, title } */
async function openResult(page, key = 'S1') {
  await sessionRow(page, key).locator('td').nth(2).click();
  const rd = resDrawer(page);
  await rd.waitFor({ state: 'visible', timeout: 15000 });
  await rd.locator('.ant-card').first().waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(600);
  return { rd, title: rd.locator('.ant-drawer-title') };
}

test.describe('ED-1251 revisi review R-2: gaya tombol Opname History', () => {
  test.use({ deviceScaleFactor: 2 });

  test.beforeAll(() => {
    expect(fx().ids.S1, 'fixture S1 ada').toBeTruthy();
  });

  test('[default] ED-1251 R-2 desktop: tombol baris riwayat = tombol judul Opname Result (primary, ikon filled, icon only, tooltip, aria-label, 40 px, tidak terpotong); Excel loading tampil sama', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const s1 = sessionRow(page, 'S1');
    const cell = actionCell(s1);

    // --- tombol baris
    const rowLook = await lookOf(cell);
    monitor.note(`tombol baris riwayat: ${JSON.stringify(strip(rowLook, ['iconPaths']))}`);
    expect(rowLook.map((b) => b.aria), 'baris: aria-label').toEqual(['Print Preview', 'Excel']);
    for (const b of rowLook) {
      expect(b.type, `${b.aria}: <button type="button">`).toBe('button');
      expect(b.cls.split(' '), `${b.aria}: kelas primary + icon only + btn-icon icon`).toEqual(expect.arrayContaining(['ant-btn', 'ant-btn-primary', 'ant-btn-icon-only', 'btn-icon', 'icon']));
      expect(b.cls.split(' '), `${b.aria}: bukan varian kecil/biru muda (tanpa light, btn-action, ant-btn-sm)`).not.toEqual(expect.arrayContaining(['light']));
      expect(b.cls, `${b.aria}: tanpa btn-action`).not.toMatch(/btn-action/);
      expect(b.cls, `${b.aria}: tanpa ant-btn-sm`).not.toMatch(/ant-btn-sm/);
      expect(b.text, `${b.aria}: tanpa teks (icon only)`).toBe('');
      expect(b.hasId, `${b.aria}: tanpa atribut id`).toBe(false);
      expect([b.w, b.h], `${b.aria}: 40 x 40 px`).toEqual([40, 40]);
      expect(b.color, `${b.aria}: ikon putih`).toBe('rgb(255, 255, 255)');
      expect(b.bg, `${b.aria}: latar biru penuh (bukan biru muda/transparan)`).not.toMatch(/rgba\(0, 0, 0, 0\)|rgb\(255, 255, 255\)/);
      expect(b.iconFont, `${b.aria}: ikon 20 px`).toBe('20px');
    }
    // ikon FILLED: path svg sama dengan PrinterFilled / FileExcelFilled dari paket ikon, dan BUKAN versi Outlined
    const [pf, pFilled, pOut] = [rowLook[0].iconPaths, antIconPaths('PrinterFilled'), antIconPaths('PrinterOutlined')];
    const [ef, eFilled, eOut] = [rowLook[1].iconPaths, antIconPaths('FileExcelFilled'), antIconPaths('FileExcelOutlined')];
    expect(pf, 'baris: ikon printer = PrinterFilled').toEqual(pFilled);
    expect(pf, 'baris: ikon printer bukan PrinterOutlined').not.toEqual(pOut);
    expect(ef, 'baris: ikon Excel = FileExcelFilled').toEqual(eFilled);
    expect(ef, 'baris: ikon Excel bukan FileExcelOutlined').not.toEqual(eOut);
    expect(rowLook[0].bg, 'dua tombol baris warna sama').toBe(rowLook[1].bg);

    // --- geometri sel/baris: tombol utuh di dalam sel dan baris, kolom 96 px, jarak 4 px
    const geo = await s1.evaluate((tr) => {
      const rect = (e) => {
        const r = e.getBoundingClientRect();
        return { x: r.x, y: r.y, r: r.right, b: r.bottom, w: r.width, h: r.height };
      };
      const td = tr.querySelector('td:last-child');
      const bs = [...td.querySelectorAll('button')].map(rect);
      const others = [...tr.querySelectorAll('td:not(:last-child)')].map((t) => Math.round(t.getBoundingClientRect().height * 10) / 10);
      return { row: rect(tr), cell: rect(td), buttons: bs, otherCellHeights: others, overflow: getComputedStyle(td).overflow };
    });
    monitor.note(`geometri baris riwayat (desktop): ${JSON.stringify({ rowH: geo.row.h, cellW: geo.cell.w, cellH: geo.cell.h, b0: geo.buttons[0], b1: geo.buttons[1] })}`);
    expect(geo.cell.w, 'kolom aksi 96 px').toBeCloseTo(96, 0);
    for (const [i, b] of geo.buttons.entries()) {
      expect(b.x, `tombol ${i}: kiri di dalam sel`).toBeGreaterThanOrEqual(geo.cell.x - 0.5);
      expect(b.r, `tombol ${i}: kanan di dalam sel`).toBeLessThanOrEqual(geo.cell.r + 0.5);
      expect(b.y, `tombol ${i}: atas di dalam baris`).toBeGreaterThanOrEqual(geo.row.y - 0.5);
      expect(b.b, `tombol ${i}: bawah di dalam baris (tidak terpotong)`).toBeLessThanOrEqual(geo.row.b + 0.5);
    }
    expect(Math.round(geo.buttons[1].x - geo.buttons[0].r), 'jarak dua tombol 4 px').toBe(4);
    expect(geo.row.h, 'tinggi baris >= tinggi tombol (40)').toBeGreaterThanOrEqual(40);
    monitor.note(`catatan developer (R-2): tinggi baris riwayat sekarang ${geo.row.h} px (tombol 40 px + padding sel); sel lain tinggi ${JSON.stringify([...new Set(geo.otherCellHeights)])}`);
    await app.screenshot('r2-01-riwayat-desktop');
    await crop(app, page, hist(page).locator('.ant-table-wrapper').first(), 'r2-02-tabel-riwayat-zoom', 0);
    await crop(app, page, s1, 'r2-03-baris-riwayat-zoom', 6);

    // --- tooltip desktop pada tombol baris
    for (const [btn, text] of [[printBtn(s1), 'Print Preview'], [excelBtn(s1), 'Excel']]) {
      await btn.hover();
      await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), `tooltip baris ${text}`).toHaveText(text);
    }
    await crop(app, page, cell, 'r2-04-tooltip-baris-excel', 30);
    await page.mouse.move(5, 5);

    // --- Excel loading di baris (permintaan ditahan): kelas dan ukuran sama dengan tombol primary biasa + spinner, tombol lain normal
    const calls = [];
    await page.route(XLS_RE, async (route) => {
      calls.push(route.request().url());
      await sleep(3500);
      await route.continue();
    });
    const dl = page.waitForEvent('download', { timeout: 60000 }).catch(() => null);
    await excelBtn(s1).click();
    await expect(excelBtn(s1), 'baris: Excel loading').toHaveClass(/ant-btn-loading/);
    // tampilan loading diukur sesudah animasi gelombang/transisi antd selesai dan kursor dijauhkan (hover/transisi bukan bagian "tampilan loading")
    await page.mouse.move(5, 5);
    await page.waitForTimeout(900);
    const rowLoading = await lookOf(cell);
    await crop(app, page, cell, 'r2-05-baris-excel-loading', 6);
    expect(rowLoading[0].loading, 'baris: Print tidak loading').toBe(false);
    expect(rowLoading[1].loading && rowLoading[1].hasLoadingIcon, 'baris: Excel loading dengan spinner').toBe(true);
    await dl;
    await expect(excelBtn(s1), 'baris: Excel normal lagi').not.toHaveClass(/ant-btn-loading/, { timeout: 30000 });
    await page.unroute(XLS_RE);
    expect(calls.length, 'tepat satu GET xls').toBe(1);

    // --- tombol judul Opname Result (pembanding)
    const { title } = await openResult(page, 'S1');
    const headBox = title.locator('.flex-justify-between');
    const headLook = await lookOf(headBox);
    monitor.note(`tombol judul Opname Result: ${JSON.stringify(strip(headLook, ['iconPaths']))}`);
    await app.screenshot('r2-06-hasil-judul-desktop');
    await crop(app, page, title, 'r2-07-judul-hasil-zoom', 8);
    expect(headLook.map((b) => b.aria), 'judul: aria-label').toEqual(['Print Preview', 'Excel']);
    // SAMA PERSIS: kelas, ukuran, warna, bentuk, bayangan, ikon, ukuran ikon
    expect(diffLooks(strip(rowLook), strip(headLook)), 'tombol baris === tombol judul Opname Result (semua sifat)').toEqual([]);
    for (const btn of [title.locator('button[aria-label="Print Preview"]'), title.locator('button[aria-label="Excel"]')]) {
      await btn.hover();
      const text = await btn.getAttribute('aria-label');
      await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)').last(), `tooltip judul ${text}`).toHaveText(text);
    }
    await page.mouse.move(5, 5);

    // --- Excel loading di judul: tampil sama dengan loading di baris
    await page.route(XLS_RE, async (route) => {
      await sleep(3500);
      await route.continue();
    });
    const dl2 = page.waitForEvent('download', { timeout: 60000 }).catch(() => null);
    await title.locator('button[aria-label="Excel"]').click();
    await expect(title.locator('button[aria-label="Excel"]'), 'judul: Excel loading').toHaveClass(/ant-btn-loading/);
    await page.mouse.move(5, 5);
    await page.waitForTimeout(900);
    const headLoading = await lookOf(headBox);
    await crop(app, page, headBox, 'r2-08-judul-excel-loading', 8);
    monitor.note(`loading baris: ${JSON.stringify(strip(rowLoading, ['iconPaths']))}`);
    monitor.note(`loading judul: ${JSON.stringify(strip(headLoading, ['iconPaths']))}`);
    expect(diffLooks(strip(rowLoading), strip(headLoading)), 'tampilan loading baris === loading judul').toEqual([]);
    await dl2;
    await expect(title.locator('button[aria-label="Excel"]'), 'judul: Excel normal lagi').not.toHaveClass(/ant-btn-loading/, { timeout: 30000 });
    await page.unroute(XLS_RE);
    noServerError(monitor);
  });

  test('[default] ED-1251 R-2 375 px: kolom aksi 96 px menempel kanan saat tabel digeser, kedua tombol utuh berdampingan tanpa bertumpuk, tanpa tooltip; judul Hasil Opname 2 tombol sama', async ({ app, page, monitor }) => {
    test.setTimeout(240000);
    await page.setViewportSize({ width: 375, height: 812 });
    await openArchive(app, page);
    await openHistoryFromModal(app, page);
    const s1 = sessionRow(page, 'S1');
    const cell = actionCell(s1);

    const measure = () =>
      page.evaluate(() => {
        const h = document.querySelector('.archive-opname-history.ant-drawer-open');
        const body = h.querySelector('.ant-table-body, .ant-table-content');
        const tr = [...h.querySelectorAll('tbody tr.ant-table-row')].find((r) => r.querySelector('button'));
        const td = tr.querySelector('td:last-child');
        const rect = (e) => {
          const r = e.getBoundingClientRect();
          return { x: Math.round(r.x * 10) / 10, r: Math.round(r.right * 10) / 10, y: Math.round(r.y * 10) / 10, b: Math.round(r.bottom * 10) / 10, w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10 };
        };
        return { vw: window.innerWidth, scrollLeft: body.scrollLeft, scrollW: body.scrollWidth, clientW: body.clientWidth, wrap: rect(body), cell: rect(td), buttons: [...td.querySelectorAll('button')].map(rect), pos: getComputedStyle(td).position };
      });
    const setScroll = (left) =>
      page.evaluate((l) => {
        const h = document.querySelector('.archive-opname-history.ant-drawer-open');
        const body = h.querySelector('.ant-table-body, .ant-table-content');
        body.scrollLeft = l === 'end' ? body.scrollWidth : l;
        body.dispatchEvent(new Event('scroll'));
      }, left);

    const at = {};
    for (const where of [0, 'end', 0]) {
      await setScroll(where);
      await page.waitForTimeout(500);
      const m = await measure();
      at[`${where}#${Object.keys(at).length}`] = m;
      const label = `scrollLeft=${m.scrollLeft} (${where})`;
      expect(m.scrollW, '375 px: tabel melebihi viewport (bisa digeser)').toBeGreaterThan(m.clientW);
      expect(m.pos, `${label}: sel aksi sticky`).toBe('sticky');
      expect(m.cell.w, `${label}: kolom aksi 96 px`).toBeCloseTo(96, 0);
      expect(m.cell.r, `${label}: sel aksi menempel tepi kanan tabel`).toBeLessThanOrEqual(m.wrap.r + 1);
      expect(m.cell.r, `${label}: sel aksi di dalam viewport`).toBeLessThanOrEqual(m.vw + 0.5);
      expect(m.buttons.length, `${label}: 2 tombol`).toBe(2);
      for (const [i, b] of m.buttons.entries()) {
        expect([b.w, b.h], `${label}: tombol ${i} 40 x 40`).toEqual([40, 40]);
        expect(b.x, `${label}: tombol ${i} kiri di dalam sel`).toBeGreaterThanOrEqual(m.cell.x - 0.5);
        expect(b.r, `${label}: tombol ${i} kanan di dalam sel`).toBeLessThanOrEqual(m.cell.r + 0.5);
        expect(b.r, `${label}: tombol ${i} di dalam viewport`).toBeLessThanOrEqual(m.vw);
        expect(b.b, `${label}: tombol ${i} bawah tidak melewati baris`).toBeLessThanOrEqual(m.cell.b + 0.5);
      }
      expect(m.buttons[0].r + 3.5, `${label}: dua tombol berdampingan, tidak bertumpuk`).toBeLessThanOrEqual(m.buttons[1].x);
      expect(Math.abs(m.buttons[0].y - m.buttons[1].y), `${label}: sebaris`).toBeLessThanOrEqual(0.5);
      await app.screenshot(`r2-10-riwayat-375-${where === 'end' ? 'geser-kanan' : 'geser-kiri'}-${Object.keys(at).length}`);
    }
    monitor.note(`375 px kolom aksi (tiga posisi geser): ${JSON.stringify(Object.values(at).map((m) => ({ sl: m.scrollLeft, cellR: m.cell.r, w: m.cell.w, b0: [m.buttons[0].x, m.buttons[0].r], b1: [m.buttons[1].x, m.buttons[1].r] })))}`);
    await crop(app, page, cell, 'r2-11-baris-375-zoom', 6);
    await printBtn(s1).hover();
    await page.waitForTimeout(500);
    await expect(page.locator('.ant-tooltip:not(.ant-tooltip-hidden)'), '375 px: tanpa tooltip').toHaveCount(0);

    // judul Hasil Opname 375 px: sama (kursor dijauhkan dulu: hover mengubah warna tombol, bukan bagian "tampilan diam")
    await page.mouse.move(5, 5);
    await page.waitForTimeout(500);
    const rowLook = await lookOf(cell);
    const { title } = await openResult(page, 'S1');
    const headBox = title.locator('.flex-justify-between');
    const headLook = await lookOf(headBox);
    monitor.note(`375 px baris: ${JSON.stringify(strip(rowLook, ['iconPaths']))}`);
    monitor.note(`375 px judul: ${JSON.stringify(strip(headLook, ['iconPaths']))}`);
    expect(diffLooks(strip(rowLook), strip(headLook)), '375 px: tombol baris === tombol judul').toEqual([]);
    const lay = await page.evaluate(() => {
      const w = document.querySelector('.archive-opname-history .ant-drawer-open .ant-drawer-title .flex-justify-between');
      const bs = [...w.querySelectorAll('button')].map((b) => b.getBoundingClientRect());
      return { vw: window.innerWidth, n: bs.length, r: bs.map((b) => Math.round(b.right)), x: bs.map((b) => Math.round(b.x)), y: bs.map((b) => Math.round(b.y)) };
    });
    expect(lay.n, '375 px: 2 tombol di judul').toBe(2);
    expect(Math.max(...lay.r), '375 px: tombol judul tidak melewati viewport').toBeLessThanOrEqual(lay.vw);
    expect(lay.r[0] + 3, '375 px: tombol judul tidak bertumpuk').toBeLessThanOrEqual(lay.x[1]);
    await app.screenshot('r2-12-hasil-judul-375');
    await crop(app, page, title, 'r2-13-judul-hasil-375-zoom', 6);
    noServerError(monitor);
  });
});
