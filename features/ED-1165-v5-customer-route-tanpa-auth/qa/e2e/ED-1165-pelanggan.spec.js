'use strict';

/**
 * ED-1165 AC-6 [FE] - regresi Setup > Pelanggan sesudah 12 route BE `customer/{address,contacts,bank-accounts}*`
 * dinonaktifkan. FE tidak berubah. TIDAK ADA data tersimpan:
 *   - semua POST/PUT ke `customer/customers[...]` diintersep `page.route` dan di-abort (`route.abort('aborted')`), body
 *     diasersi berisi customer_addresses / customer_contacts / customer_bank_accounts;
 *   - setiap request tulis (non-GET) lain ke /api/v5/ dicatat; harus 0 (selain yang diintersep);
 *   - COUNT 6 tabel pelanggan (fixture.php counts, READ ONLY) dicek sebelum = sesudah.
 * Monitor jaringan: 0 request ke URI R1-R12 (GET/POST/PUT) dan 0 respons >= 400 (monitor harness, tanpa allow-list).
 *
 *   scripts/e2e/run.sh ED-1165
 */

const path = require('path');
const { execFileSync } = require('child_process');

const { test, expect, config } = require('e2e-harness');

const LIST_PATH = '/setups/customers/customers';
const FIXTURE = path.join(__dirname, 'fixture.php');
/** R1-R12: api/v5/customer/{address,contacts,bank-accounts}[/...] (bukan customers/customer-types/devices) */
const R1_R12 = /\/api\/v5\/customer\/(address|contacts|bank-accounts)(\/|\?|$)/;
/** POST/PUT ke customer/customers, customer/customers/{id}, customer/customers/import|approve/... */
const CUSTOMERS_WRITE = /\/api\/v5\/customer\/customers(\/[^?]*)?(\?|$)/;

function fixture(cmd) {
  const out = execFileSync(config.get('PHP_BIN'), [FIXTURE, cmd], { encoding: 'utf8', timeout: 120000 }).trim().split('\n').pop();
  return JSON.parse(out);
}

/** Pasang penjaga jaringan di page; kembalikan state yang diasersi test. */
async function installGuard(page, monitor) {
  const state = { intercepted: [], otherWrites: [], r1r12: [], bodies: [], abortedAtMs: [] };
  page.on('request', (req) => {
    if (R1_R12.test(req.url())) state.r1r12.push(`${req.method()} ${req.url()}`);
  });
  await page.route(/\/api\/v5\//, async (route) => {
    const req = route.request();
    const method = req.method();
    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return route.continue();
    if ((method === 'POST' || method === 'PUT') && CUSTOMERS_WRITE.test(req.url())) {
      let body = null;
      try {
        body = JSON.parse(req.postData() || 'null');
      } catch (e) {
        body = { _unparsed: String(req.postData() || '').slice(0, 200) };
      }
      state.intercepted.push({ method, url: config.safeUrl(req.url()), body });
      state.abortedAtMs.push(Date.now() - monitor.started);
      return route.abort('aborted');
    }
    // tulis lain: dicatat; DIBLOK supaya tidak ada data berubah (harus 0 dalam flow ini)
    state.otherWrites.push(`${method} ${config.safeUrl(req.url())}`);
    return route.abort('aborted');
  });
  return state;
}

/** key snake_case atau camelCase (FE mengirim camelCase lalu middleware/axios mengubahnya; terima keduanya, catat yang terlihat) */
function hasList(body, snake, camel) {
  const v = body && (body[snake] !== undefined ? body[snake] : body[camel]);
  return Array.isArray(v) ? v : null;
}

async function pickFirstOption(page, scope, inputId) {
  const select = scope.locator(`.ant-select:has(#${inputId})`).first();
  await expect(select, `select ${inputId} aktif`).not.toHaveClass(/ant-select-disabled/, { timeout: 20000 });
  await select.scrollIntoViewIfNeeded();
  await select.locator('.ant-select-selector').click();
  // dropdown milik select ini saja (listbox rc-select = #<id>_list); dropdown select sebelumnya bisa masih ada di DOM
  const dropdown = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').filter({ has: page.locator(`#${inputId}_list`) });
  const option = dropdown.locator('.ant-select-item-option').first();
  await option.waitFor({ state: 'visible', timeout: 20000 });
  const text = (await option.innerText()).trim();
  await option.click();
  await expect.poll(() => selectedText(scope, inputId), { timeout: 10000, message: `${inputId} terisi` }).not.toBe('');
  return text;
}

async function selectedText(scope, inputId) {
  return scope
    .locator(`.ant-select:has(#${inputId}) .ant-select-selection-item`)
    .first()
    .innerText()
    .catch(() => '');
}

/** drawer paling atas (sub-drawer alamat/kontak/rekening) */
function topDrawer(page) {
  return page.locator('.ant-drawer-open').last();
}

async function openPanel(scope, title) {
  const header = scope.locator('.ant-collapse-header').filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) }).first();
  await header.scrollIntoViewIfNeeded();
  await header.click();
}

async function fillSubDrawerAddress(page, app, monitor, name) {
  const d = topDrawer(page);
  await d.locator('#customerAddressName').fill(name);
  await d.locator('#address').fill('Jl. QA1165 No. 1 (tidak disimpan)');
  await d.locator('#phone1').fill('081234567890');
  await d.locator('#geotag_lat').fill('-6.9');
  await d.locator('#geotag_long').fill('110.4');
  // detail alamat: negara / provinsi / kota (select dari select/customer/*)
  await d.getByText(/Tambahkan detail/).click();
  await d.locator('#idCountry').waitFor({ state: 'attached', timeout: 15000 });
  // negara terisi default (negara bawaan, mis. Indonesia); provinsi/kota aktif hanya untuk negara bawaan -> pilih opsi pertama
  await expect.poll(() => selectedText(d, 'idCountry'), { timeout: 20000, message: 'negara terisi default' }).not.toBe('');
  const country = await selectedText(d, 'idCountry');
  const province = await pickFirstOption(page, d, 'idProvince');
  const city = await pickFirstOption(page, d, 'idCity');
  monitor.note(`alamat: negara=${country}; provinsi=${province}; kota=${city}`);
  expect(await selectedText(d, 'idCountry'), 'negara terisi').not.toBe('');
  expect(await selectedText(d, 'idProvince'), 'provinsi terisi').not.toBe('');
  expect(await selectedText(d, 'idCity'), 'kota terisi').not.toBe('');
  await app.screenshot(`${name}-drawer-alamat`);
  await d.getByRole('button', { name: /Simpan/ }).click();
  await page.waitForTimeout(600);
}

async function fillSubDrawerContact(page, app, monitor, name) {
  const d = topDrawer(page);
  await d.locator('#customerContactName').fill(name);
  await d.locator('#phone').fill('081234567891');
  await d.locator('#addressName').fill('Alamat kontak QA1165');
  await d.locator('#address').fill('Jl. Kontak QA1165 No. 2 (tidak disimpan)');
  await expect.poll(() => selectedText(d, 'idCountry'), { timeout: 20000, message: 'kontak: negara terisi default' }).not.toBe('');
  const country = await selectedText(d, 'idCountry');
  const province = await pickFirstOption(page, d, 'idProvince');
  const city = await pickFirstOption(page, d, 'idCity');
  monitor.note(`kontak: negara=${country}; provinsi=${province}; kota=${city}`);
  expect(await selectedText(d, 'idCountry'), 'kontak negara terisi').not.toBe('');
  expect(await selectedText(d, 'idProvince'), 'kontak provinsi terisi').not.toBe('');
  expect(await selectedText(d, 'idCity'), 'kontak kota terisi').not.toBe('');
  await app.screenshot(`${name}-drawer-kontak`);
  await d.getByRole('button', { name: /Simpan/ }).click();
  await page.waitForTimeout(600);
}

async function fillSubDrawerBank(page, app, monitor, name) {
  const d = topDrawer(page);
  await d.locator('#bankName').fill('Bank QA1165');
  await d.locator('#accountNumber').fill('1234567890');
  await d.locator('#bankAccountName').fill(name);
  await app.screenshot(`${name}-drawer-rekening`);
  await d.getByRole('button', { name: /Simpan/ }).click();
  await page.waitForTimeout(600);
}

/** 1x1 PNG; foto wajib (Tampak Dalam/Depan Toko). Hanya diisi ke state form (base64); tidak ada request sampai Simpan (diintersep) */
const PNG_1X1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function uploadPhotos(page, scope, monitor) {
  await openPanel(scope, 'Foto');
  await page.waitForTimeout(500);
  const panel = scope.locator('.ant-collapse-item', { has: page.locator('.ant-collapse-header').filter({ hasText: /^\s*Foto\s*$/ }) }).first();
  const inputs = panel.locator('input[type=file]');
  const n = await inputs.count();
  expect(n, 'input file foto (Tampak Dalam/Depan Toko)').toBeGreaterThanOrEqual(1);
  for (let i = 0; i < n; i += 1) {
    await inputs.nth(i).setInputFiles({ name: `qa1165-${i}.png`, mimeType: 'image/png', buffer: PNG_1X1 });
    await page.waitForTimeout(400);
  }
  monitor.note(`foto wajib diisi lewat input file (${n} slot, 1x1 PNG, hanya state form)`);
}

/** Simpan di form utama -> request diintersep; kembalikan entri intercepted terakhir */
async function saveAndGetIntercepted(page, state, saveButton) {
  const before = state.intercepted.length;
  await saveButton.click();
  await expect.poll(() => state.intercepted.length, { timeout: 30000, message: 'POST/PUT customer/customers terintersep' }).toBeGreaterThan(before);
  return state.intercepted[state.intercepted.length - 1];
}

/**
 * Request yang di-abort e2e membuat FE mencatat satu `console.error` (kegagalan jaringan yang kita sebabkan sendiri).
 * Bukan error produk dan bukan error lama (tanpa tiket), jadi bukan allow-list: entri console dalam 3 dtk sesudah abort
 * ditandai `allowed: ED-1165-e2e-abort` (tetap tampil di laporan/galeri), semua jenis error lain tetap menggagalkan test.
 */
function markInducedConsole(state, monitor) {
  let marked = 0;
  for (const e of monitor.errors) {
    if (e.kind !== 'console' || e.allowed) continue;
    if (state.abortedAtMs.some((t) => e.at >= t - 200 && e.at <= t + 3000)) {
      e.allowed = 'ED-1165-e2e-abort';
      marked += 1;
    }
  }
  monitor.note(`console.error akibat abort e2e (ditandai, bukan allow-list tiket): ${marked}`);
  return marked;
}

function assertBody(entry, expectMethod, monitor) {
  expect(entry.method).toBe(expectMethod);
  const addr = hasList(entry.body, 'customer_addresses', 'customerAddresses');
  const cont = hasList(entry.body, 'customer_contacts', 'customerContacts');
  const bank = hasList(entry.body, 'customer_bank_accounts', 'customerBankAccounts');
  expect(addr && addr.length, `${expectMethod} body.customer_addresses`).toBeGreaterThanOrEqual(1);
  expect(cont && cont.length, `${expectMethod} body.customer_contacts`).toBeGreaterThanOrEqual(1);
  expect(bank && bank.length, `${expectMethod} body.customer_bank_accounts`).toBeGreaterThanOrEqual(1);
  const keys = Object.keys(entry.body || {}).filter((k) => /address|contact|bank/i.test(k));
  monitor.note(`${expectMethod} ${entry.url} dicegat+abort; body: ${keys.join(',')} (addresses=${addr.length}, contacts=${cont.length}, bank_accounts=${bank.length})`);
}

function assertNoLeak(state, monitor) {
  // monitor.apiResponses() memfilter dengan API_URL (`http://127.0.0.1:80`) dan tidak cocok dengan URL browser tanpa `:80`;
  // pakai semua respons yang dikumpulkan monitor, saring /api/v5/ sendiri.
  const api = monitor.responses.filter((r) => /\/api\/v5\//.test(r.url));
  const byStatus = {};
  api.forEach((r) => {
    byStatus[r.status] = (byStatus[r.status] || 0) + 1;
  });
  monitor.note(`jaringan: ${api.length} respons API ${JSON.stringify(byStatus)}; request ke R1-R12: ${state.r1r12.length}; request tulis di luar yang diintersep: ${state.otherWrites.length}; diintersep: ${state.intercepted.length}`);
  expect(api.length, 'ada respons API yang dipantau').toBeGreaterThan(5);
  expect(state.r1r12, '0 request ke URI R1-R12').toEqual([]);
  expect(api.filter((r) => /\/customer\/(address|contacts|bank-accounts)(\/|\?|$)/.test(r.url)), '0 respons dari URI R1-R12').toEqual([]);
  expect(state.otherWrites, '0 request tulis lain ke /api/v5/').toEqual([]);
  const bad = api.filter((r) => r.status >= 400).map((r) => `${r.status} ${r.method} ${config.safeUrl(r.url)}`);
  expect(bad, '0 respons >= 400 dari BE (request yang diintersep tidak punya respons)').toEqual([]);
}

test.describe.configure({ mode: 'serial' });

let countsBefore = null;

test.describe('ED-1165 Setup > Pelanggan', () => {
  test('[default] ED-1165 AC-6 prasyarat: COUNT tabel pelanggan sebelum', async ({ monitor }) => {
    countsBefore = fixture('counts');
    const cust = fixture('customer');
    expect(cust, 'ada pelanggan aktif dengan alamat+kontak+rekening di QA_DB').not.toBeNull();
    monitor.note(`COUNT sebelum: ${JSON.stringify(countsBefore)}; pelanggan acuan ${cust.code} (${cust.n_addresses} alamat, ${cust.n_contacts} kontak, ${cust.n_banks} rekening)`);
  });

  test('[default] ED-1165 AC-6a daftar tampil, detail pelanggan terbuka, panel Kontak/Alamat berisi', async ({ app, page, monitor }) => {
    const state = await installGuard(page, monitor);
    const cust = fixture('clean'); // pelanggan tanpa foto (berkas foto tidak ada di disk QA, O-1) dengan alamat + kontak

    const listResp = page.waitForResponse((r) => /\/customer\/customers(\?|$)/.test(r.url()) && r.request().method() === 'GET', { timeout: 45000 });
    await app.open(LIST_PATH, { waitFor: '.ant-table' });
    const resp = await listResp;
    expect(resp.status(), 'GET customer/customers').toBe(200);
    const body = await resp.json();
    const rows = (body.result && (body.result.data || body.result)) || [];
    expect(rows.length, 'daftar berisi baris').toBeGreaterThan(0);
    await expect(page.locator('.ant-table-tbody tr.ant-table-row').first()).toBeVisible();
    await app.screenshot('ED-1165-pelanggan-daftar');

    // cari pelanggan acuan lewat kotak Cari
    const searchResp = page.waitForResponse((r) => /\/customer\/customers\?/.test(r.url()) && r.request().method() === 'GET' && /search/.test(r.url()), { timeout: 30000 });
    const input = page.locator('input[placeholder="Cari"]').first();
    await input.fill(cust.code);
    await input.press('Enter');
    expect((await searchResp).status(), 'GET customers?search').toBe(200);
    await app.settle();
    const row = page.locator('.ant-table-tbody tr.ant-table-row', { hasText: cust.code }).first();
    await expect(row).toBeVisible({ timeout: 20000 });

    // buka detail (tombol ubah = tombol btn-action pertama, BUKAN yang `ant-btn-dangerous` = nonaktifkan)
    const detailResp = page.waitForResponse((r) => new RegExp(`/customer/customers/${cust.id}(\\?|$)`).test(r.url()) && r.request().method() === 'GET', { timeout: 30000 });
    await row.locator('button.btn-action:not(.ant-btn-dangerous)').first().click();
    const dResp = await detailResp;
    expect(dResp.status(), 'GET customer/customers/{id}').toBe(200);
    const detail = (await dResp.json()).result;
    expect(detail.customer_addresses.length, 'detail: alamat').toBe(cust.n_addresses);
    expect(detail.customer_contacts.length, 'detail: kontak').toBe(cust.n_contacts);
    expect(detail.customer_bank_accounts.length, 'detail: rekening bank').toBe(cust.n_banks);
    monitor.note(`pelanggan ${cust.code}: ${cust.n_addresses} alamat, ${cust.n_contacts} kontak, ${cust.n_banks} rekening (API detail = DB)`);

    const drawer = page.locator('.ant-drawer-open').first();
    await expect(drawer.locator('#customerName')).toBeVisible({ timeout: 20000 });
    await expect(drawer.locator('#customerName')).toHaveValue(/\S/);
    await app.settle();
    for (const title of ['Kontak', 'Alamat', 'Rekening Bank']) {
      await openPanel(drawer, title);
      await page.waitForTimeout(400);
    }
    await app.screenshot('ED-1165-pelanggan-detail-panel');
    for (const [title, n] of [['Kontak', cust.n_contacts], ['Alamat', cust.n_addresses], ['Rekening Bank', cust.n_banks]]) {
      const panel = drawer.locator('.ant-collapse-item', { has: page.locator('.ant-collapse-header').filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) }) }).first();
      const count = await panel.locator('.ant-table-tbody tr.ant-table-row, .ant-table-tbody tr[data-row-key]').count();
      monitor.note(`panel ${title}: ${count} baris tampil (DB ${n})`);
      if (n > 0) expect(count, `panel ${title} menampilkan baris`).toBeGreaterThanOrEqual(1);
      else expect(count, `panel ${title} kosong (DB 0)`).toBe(0);
    }
    expect(state.intercepted, 'tidak ada tulis di alur baca').toEqual([]);
    assertNoLeak(state, monitor);
  });

  test('[default] ED-1165 AC-6b Ubah: drawer alamat/kontak/rekening + select terisi; Simpan = PUT diintersep', async ({ app, page, monitor }) => {
    const state = await installGuard(page, monitor);
    const cust = fixture('clean');

    await app.open(LIST_PATH, { waitFor: '.ant-table' });
    const input = page.locator('input[placeholder="Cari"]').first();
    await input.fill(cust.code);
    await input.press('Enter');
    const row = page.locator('.ant-table-tbody tr.ant-table-row', { hasText: cust.code }).first();
    await expect(row).toBeVisible({ timeout: 30000 });
    const detailResp = page.waitForResponse((r) => new RegExp(`/customer/customers/${cust.id}(\\?|$)`).test(r.url()) && r.request().method() === 'GET', { timeout: 30000 });
    await row.locator('button.btn-action:not(.ant-btn-dangerous)').first().click();
    expect((await detailResp).status()).toBe(200);
    const drawer = page.locator('.ant-drawer-open').first();
    await expect(drawer.locator('#customerName')).toBeVisible({ timeout: 20000 });
    await app.settle();

    // tambah alamat, kontak, rekening di form Ubah (hanya di state form; tidak disimpan)
    await openPanel(drawer, 'Alamat');
    await drawer.getByRole('button', { name: /Tambah Alamat/ }).click();
    await page.waitForTimeout(1200);
    await fillSubDrawerAddress(page, app, monitor, 'QA1165 alamat ubah');

    await openPanel(drawer, 'Kontak');
    await drawer.getByRole('button', { name: /Tambah Kontak/ }).click();
    await page.waitForTimeout(1200);
    await fillSubDrawerContact(page, app, monitor, 'QA1165 kontak ubah');

    await openPanel(drawer, 'Rekening Bank');
    await drawer.getByRole('button', { name: /Tambah Rekening Bank/ }).click();
    await page.waitForTimeout(1200);
    await fillSubDrawerBank(page, app, monitor, 'QA1165 rekening ubah');

    await uploadPhotos(page, drawer, monitor);
    await app.screenshot('ED-1165-pelanggan-ubah-sebelum-simpan');
    // Simpan di drawer utama (aksi di header drawer)
    const save = drawer.getByRole('button', { name: /^\s*Simpan\s*$/ }).first();
    await save.scrollIntoViewIfNeeded().catch(() => null);
    const entry = await saveAndGetIntercepted(page, state, save);
    assertBody(entry, 'PUT', monitor);
    await page.waitForTimeout(1500);
    markInducedConsole(state, monitor);
    expect(entry.url, 'PUT ke customer/customers/{id}').toContain(`/customer/customers/${cust.id}`);
    await page.waitForTimeout(800);
    await app.screenshot('ED-1165-pelanggan-ubah-setelah-simpan-dicegat');
    expect(state.intercepted.length, 'tepat 1 PUT terintersep').toBe(1);
    assertNoLeak(state, monitor);
  });

  test('[default] ED-1165 AC-6c Buat: drawer alamat/kontak/rekening + select terisi; Simpan = POST diintersep', async ({ app, page, monitor }) => {
    const state = await installGuard(page, monitor);

    await app.open(LIST_PATH, { waitFor: '.ant-table' });
    await page.locator('button.ant-btn-primary.button-filter-wrapper').first().click();
    const modal = page.locator('.ant-modal').first();
    await modal.waitFor({ state: 'visible' });
    await app.settle();
    await modal.locator('#customerName').fill('QA1165 pelanggan uji (jangan simpan)');
    await modal.locator('#ownerName').fill('QA1165 pemilik');
    const type = await pickFirstOption(page, modal, 'idCustomerType');
    monitor.note(`tipe pelanggan dipilih: ${type}`);
    // select otomatis terisi default (lokasi, akun piutang, kategori & termin pembayaran)
    await expect.poll(async () => (await selectedText(modal, 'idLocation')) + (await selectedText(modal, 'idAccount')) + (await selectedText(modal, 'idPaymentCategory')), { timeout: 20000 }).not.toBe('');
    await app.screenshot('ED-1165-pelanggan-buat-profil');

    await openPanel(modal, 'Alamat');
    await modal.getByRole('button', { name: /Tambah Alamat/ }).click();
    await page.waitForTimeout(1200);
    await fillSubDrawerAddress(page, app, monitor, 'QA1165 alamat buat');

    await openPanel(modal, 'Kontak');
    await modal.getByRole('button', { name: /Tambah Kontak/ }).click();
    await page.waitForTimeout(1200);
    await fillSubDrawerContact(page, app, monitor, 'QA1165 kontak buat');

    await openPanel(modal, 'Rekening Bank');
    await modal.getByRole('button', { name: /Tambah Rekening Bank/ }).click();
    await page.waitForTimeout(1200);
    await fillSubDrawerBank(page, app, monitor, 'QA1165 rekening buat');

    await uploadPhotos(page, modal, monitor);
    await app.screenshot('ED-1165-pelanggan-buat-sebelum-simpan');
    const save = modal.getByRole('button', { name: /^\s*Simpan\s*$/ }).first();
    const entry = await saveAndGetIntercepted(page, state, save);
    assertBody(entry, 'POST', monitor);
    await page.waitForTimeout(1500);
    markInducedConsole(state, monitor);
    await page.waitForTimeout(800);
    await app.screenshot('ED-1165-pelanggan-buat-setelah-simpan-dicegat');
    expect(state.intercepted.length, 'tepat 1 POST terintersep').toBe(1);
    assertNoLeak(state, monitor);
  });

  test('[default] ED-1165 AC-6d detail pelanggan dengan alamat+kontak+rekening existing (panel menampilkan semua; foto di-abort, O-1)', async ({ app, page, monitor }) => {
    const state = await installGuard(page, monitor);
    // O-1 (di luar ED-1165): berkas foto pelanggan tidak ada di disk QA -> select/files/download = 500 NOT_FOUND_ATTACHMENT untuk
    // semua pelanggan berfoto. Di test ini HANYA request unduh foto yang di-abort e2e (dihitung dan dicatat); sisa jaringan
    // tetap dimonitor penuh (0 respons >= 400, 0 R1-R12). Didaftarkan sesudah guard sehingga dipakai lebih dulu.
    let photoAborted = 0;
    await page.route(/\/api\/v5\/select\/files\/download/, (route) => {
      photoAborted += 1;
      return route.abort('aborted');
    });
    const cust = fixture('customer');

    await app.open(LIST_PATH, { waitFor: '.ant-table' });
    const input = page.locator('input[placeholder="Cari"]').first();
    await input.fill(cust.code);
    await input.press('Enter');
    const row = page.locator('.ant-table-tbody tr.ant-table-row', { hasText: cust.code }).first();
    await expect(row).toBeVisible({ timeout: 30000 });
    const detailResp = page.waitForResponse((r) => new RegExp(`/customer/customers/${cust.id}(\\?|$)`).test(r.url()) && r.request().method() === 'GET', { timeout: 30000 });
    await row.locator('button.btn-action:not(.ant-btn-dangerous)').first().click();
    expect((await detailResp).status()).toBe(200);
    const drawer = page.locator('.ant-drawer-open').first();
    await expect(drawer.locator('#customerName')).toBeVisible({ timeout: 20000 });
    await app.settle();
    for (const title of ['Kontak', 'Alamat', 'Rekening Bank']) {
      await openPanel(drawer, title);
      await page.waitForTimeout(400);
    }
    await app.screenshot('ED-1165-pelanggan-detail-tiga-panel');
    for (const [title, n] of [['Kontak', cust.n_contacts], ['Alamat', cust.n_addresses], ['Rekening Bank', cust.n_banks]]) {
      const panel = drawer.locator('.ant-collapse-item', { has: page.locator('.ant-collapse-header').filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) }) }).first();
      const count = await panel.locator('.ant-table-tbody tr.ant-table-row, .ant-table-tbody tr[data-row-key]').count();
      monitor.note(`pelanggan ${cust.code} panel ${title}: ${count} baris tampil (DB ${n})`);
      expect(count, `panel ${title} menampilkan ${n} baris`).toBe(n);
    }
    // buka satu rekening existing: drawer terisi
    const bankPanel = drawer.locator('.ant-collapse-item', { has: page.locator('.ant-collapse-header').filter({ hasText: /^\s*Rekening Bank\s*$/ }) }).first();
    await bankPanel.locator('.ant-table-tbody tr.ant-table-row td').first().click();
    await page.waitForTimeout(1000);
    await expect(topDrawer(page).locator('#bankName')).toHaveValue(/\S/);
    await expect(topDrawer(page).locator('#accountNumber')).toHaveValue(/\d/);
    await app.screenshot('ED-1165-pelanggan-rekening-existing');
    monitor.note(`request unduh foto di-abort e2e (O-1, di luar item): ${photoAborted}`);
    expect(state.intercepted, 'tidak ada tulis di alur baca').toEqual([]);
    assertNoLeak(state, monitor);
  });

  test('[default] ED-1165 AC-6 penutup: COUNT tabel pelanggan sesudah = sebelum (tidak ada data tersimpan)', async ({ monitor }) => {
    const after = fixture('counts');
    monitor.note(`COUNT sebelum: ${JSON.stringify(countsBefore)}; sesudah: ${JSON.stringify(after)}`);
    expect(countsBefore, 'COUNT sebelum tercatat').not.toBeNull();
    expect(after).toEqual(countsBefore);
  });
});
