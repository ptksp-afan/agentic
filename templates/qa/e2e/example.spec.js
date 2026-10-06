'use strict';

/**
 * Contoh spec fitur. Salin ke features/<KEY>-<slug>/qa/e2e/<slug>.spec.js lalu sesuaikan.
 * Aturan: judul test diawali tag profil `[default]` (project memilih test lewat tag itu); NON-mutasi, atau
 * kembalikan persis apa yang diubah (data lain/UI lain tidak boleh berubah). Error console/page/HTTP >= 400
 * dicek OTOMATIS di akhir setiap test lewat fixture `monitor`.
 *
 * Fixture: app (open/settle/screenshot/imageInfo/currentPath), page, monitor (check/apiResponses/note),
 * session (dbName, userKey, info.roles, info.permissionCount).
 */

const { test, expect } = require('e2e-harness');

const LIST_PATH = '/setups/customers/customers'; // harus ada di src/routes/paths.js FE

test.describe('ED-1234 daftar customer', () => {
  test('[default] ED-1234 daftar tampil dan memanggil API di DB sesi', async ({ app, page, monitor, session }) => {
    // tangkap respons API SEBELUM membuka layar
    const listResp = page.waitForResponse((r) => /\/customer\/customers(\?|$)/.test(r.url()) && r.request().method() === 'GET', { timeout: 45000 });

    await app.open(LIST_PATH, { waitFor: '.ant-table' }); // waitFor = selector yang wajib tampil
    const resp = await listResp;
    expect(resp.status(), 'GET customer/customers').toBe(200);
    const body = await resp.json();
    expect(Array.isArray(body.result && (body.result.data || body.result)), 'result berupa daftar').toBe(true);

    await app.screenshot('ED-1234-customer-list'); // tampil di galeri index.html
    monitor.note(`DB sesi: ${session.dbName}`); // catatan ikut ke laporan

    // asersi UI contoh: tidak dialihkan ke layar lain
    expect(app.redirectOf(LIST_PATH), 'tidak dialihkan').toBeNull();
    monitor.check(); // opsional: dipanggil otomatis di akhir test
  });

  // Profil lain (opsional): hanya jalan bila dipilih --profile user2 dan kredensial QA_USER2/QA_PASS2 terisi.
  test('[user2] ED-1234 user terbatas tidak melihat tombol tambah', async ({ app, page }) => {
    await app.open(LIST_PATH, { waitFor: '.ant-table' });
    await expect(page.locator('button.ant-btn-primary', { hasText: /Add|Tambah/ })).toHaveCount(0);
  });
});
