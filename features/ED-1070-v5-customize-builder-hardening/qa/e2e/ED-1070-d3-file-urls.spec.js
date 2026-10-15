'use strict';

/**
 * ED-1070 D-3 (QA BE ronde 2; AC-10 "V5 tak terpengaruh"): URL berkas buatan BE yang dipakai FE.
 * Layar Perusahaan (/setups/companies/company): GET company/companies/{id} -> logoSmallUrl -> <img alt="Company Logo">
 * (src = server + logoSmallUrl). Harapan sesudah V4 dimatikan: detail 200 (ronde 1: 500), URL logo =
 * /api/v5/select/files/download (bukan api/v4), route ada (bukan 404), dan status gambar di browser = status URL yang sama
 * bila dipanggil langsung (berkas ada -> 200 + gambar; berkas hilang di disk -> 500 NOT_FOUND_ATTACHMENT, sama dengan kode V4
 * sebelum item). Respons >= 400 tetap dicek otomatis oleh harness (tanpa allow-list). NON-mutasi.
 */

const { test, expect } = require('e2e-harness');

// ED-1166 (pra-ada, V4 = V5): unduhan berkas yang tidak ada di disk = 500 JSON NOT_FOUND_ATTACHMENT (seharusnya 404); di
// <img> Chrome memblokirnya (ERR_BLOCKED_BY_ORB). Hanya untuk URL unduhan di layar Perusahaan; test di bawah tetap
// memastikan error itu memang 500 NOT_FOUND_ATTACHMENT (bukan 404/route hilang). Hapus entri begitu ED-1166 selesai.
test.use({
  allow: [
    {
      id: 'ed1166-logo-orb',
      kind: 'requestfailed',
      match: '^GET https?://[^/]+/+api/v5/select/files/download\\?idAttachment=[0-9]+ net::ERR_BLOCKED_BY_ORB$',
      paths: ['/setups/companies/company'],
      reason: 'berkas logo tidak ada di disk -> 500 JSON NOT_FOUND_ATTACHMENT, diblokir ORB di <img> (pra-ada, kode V4 identik)',
      ref: 'ED-1166',
      since: '2026-10-15',
    },
    {
      id: 'ed1166-logo-500',
      kind: 'response',
      status: 500,
      match: '^GET https?://[^/]+/+api/v5/select/files/download\\?idAttachment=[0-9]+ 500$',
      paths: ['/setups/companies/company'],
      reason: 'berkas logo tidak ada di disk -> 500 JSON NOT_FOUND_ATTACHMENT (pra-ada, kode V4 identik)',
      ref: 'ED-1166',
      since: '2026-10-15',
    },
  ],
});

test('[default] ED-1070 D-3 Perusahaan: logo memakai api/v5/select/files/download', async ({ app, page, monitor }) => {
  // request <img> ke unduhan berkas (juga yang diblokir Chrome/ORB: respons JSON untuk gambar tidak memicu event response)
  const fileReqs = [];
  page.on('request', (q) => {
    if (/select\/files\/download/.test(q.url())) fileReqs.push(q.url());
  });
  const showResp = page.waitForResponse(
    (r) => r.request().method() === 'GET' && /\/company\/companies\/[^/?]+(\?|$)/.test(r.url()),
    { timeout: 45000 },
  );
  await app.open('/setups/companies/company');
  const show = await showResp;
  expect(show.status(), 'GET company/companies/{id}').toBe(200);
  const body = await show.json().catch(() => null);
  const logo = (body && body.result && (body.result.logoSmallUrl || body.result.logo_small_url)) || null;
  monitor.note(`company show ${show.status()}; logoSmallUrl=${logo}`);
  await app.settle();
  await page.waitForTimeout(1500);

  const files = monitor.responses.filter((r) => /select\/files\/download/.test(r.url));
  const blocked = monitor.errors.filter((e) => e.kind === 'requestfailed' && /select\/files\/download/.test(e.url));
  const strip = (u) => String(u).replace(/^https?:\/\/[^/]+/, '');
  monitor.note(`request unduhan: ${fileReqs.map(strip).join(' | ') || '-'}`);
  monitor.note(`respons unduhan: ${files.map((r) => `${r.status} ${r.contentType} ${strip(r.url)}`).join(' | ') || '-'}; diblokir browser: ${blocked.map((e) => `${e.text} ${strip(e.url)}`).join(' | ') || '-'}`);
  expect(fileReqs.filter((u) => /\/api\/v4\//.test(u)), 'tidak ada request api/v4').toEqual([]);
  expect(files.filter((r) => r.status === 404), 'tidak ada 404 unduhan').toEqual([]);

  if (logo) {
    expect(logo.startsWith('/api/v5/select/files/download?'), `logoSmallUrl v5: ${logo}`).toBe(true);
    expect(fileReqs.length, 'browser meminta logo').toBeGreaterThan(0);
    expect(fileReqs.every((u) => strip(u).replace(/^\/+/, '/') === logo), 'URL yang diminta = logoSmallUrl').toBe(true);
    const img = page.locator('img[alt="Company Logo"]').first();
    const info = await app.imageInfo(img);
    monitor.note(`img: naturalWidth=${info.naturalWidth} src=${strip(info.src)}`);
    // pembanding: URL yang sama dipanggil langsung (tanpa token, seperti <img>)
    const direct = await page.request.get(fileReqs[0], { failOnStatusCode: false });
    const text = (await direct.body()).toString('latin1').slice(0, 200);
    monitor.note(`langsung: ${direct.status()} ${direct.headers()['content-type'] || ''} ${direct.status() >= 400 ? text : ''}`);
    expect(direct.status(), 'URL logo bukan 404 (route V5 ada)').not.toBe(404);
    // allow-list ED-1166 hanya sah bila kegagalannya memang berkas hilang (500 NOT_FOUND_ATTACHMENT)
    const failed = blocked.length > 0 || files.some((r) => r.status >= 400);
    if (failed) {
      expect(direct.status(), 'gagal = 500 (ED-1166)').toBe(500);
      expect(text, 'gagal = NOT_FOUND_ATTACHMENT (ED-1166)').toContain('NOT_FOUND_ATTACHMENT');
    }
    if (files.length) {
      expect(files[0].status, 'status gambar di browser = status URL langsung').toBe(direct.status());
    }
    if (direct.status() === 200) {
      expect(info.naturalWidth, 'logo tampil (bukan gambar rusak)').toBeGreaterThan(0);
    }
  }
  await app.screenshot('D3-perusahaan-logo');
});
