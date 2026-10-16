'use strict';

/**
 * ED-1154 / ED-1155 [QA] - e2e archive lewat transaksi nyata: A (SO -> ... -> Billing), B (Store DO+SI ke folder baru),
 * C (serah-terima manual DO). Satu `describe.serial`, semua test bertag [default] (orkestrator), user lain = konteks browser
 * per profil (BR-1): i = default (QA_USER), a = user2 (QA_USER2), w = wawan (QA_WAWAN_USER). Jalankan:
 *
 *   scripts/e2e/run.sh ED-1154 --profile default,user2,wawan --workers 1
 *
 * Data dibuat lewat UI dan DIBIARKAN (spec § 10, G-3). Jurnal run: work/ED-1154/runs/<runId>.json (id + nomor semua dokumen,
 * approval, attendance, folder F). DB dibaca lewat qa/e2e/fixture.php (sesi MySQL READ ONLY). Tidak ada fixtures.json: tidak
 * ada setup/teardown data. AC-1 gagal = BLOCKED tanpa approval/transaksi (test pertama melempar "BLOCKED", sisanya tidak jalan).
 *
 * Spec r2 (2026-10-16): A.0 approve customer di drawer Pelanggan (bila approval_status != 2), A.1 Simpan SO lalu di drawer SO
 * "Setujui" sebagai customer + approve L2 -> SO terkonfirmasi otomatis (BR-13, tanpa PIN), Pengiriman Simpan lalu Konfirmasi
 * (BR-14), SI lewat Tambah -> Action -> Impor Pengantaran Pesanan (BR-14).
 *
 * STATUS (ED-1155 r2, 2026-10-16): rantai A -> B -> C lengkap di 3 run (261016092317 debug/resume, 261016100813 1x resume,
 * 261016102000 bersih). Defect aplikasi yang direproduksi tiap run dan dicatat di jurnal (`defects`) + allow-list sempit:
 * D-1 = ED-1168 Konfirmasi SA oleh driver = 500 GE0114 (i menyelesaikan di A.6), D-2 = ED-1169 Simpan & Konfirmasi SI hasil
 * impor DO = 500 Unknown column sales_invoice_serials (lanjut Simpan + Konfirmasi drawer); E-1 = ED-1166 foto customer lokal.
 * r3: 5xx bertiket (KNOWN_5XX) dicatat di jurnal + laporan, tidak dihitung di AC-15 "0 5xx"; 5xx lain = AC-15 gagal. Lihat qa-fe.md.
 *
 * Alat debug (bukan bukti AC): ED1154_RESUME=<runId> melanjutkan run yang gagal dari jurnalnya (langkah yang sudah `ok` dilewati,
 * keadaan dibaca ulang dari DB). Run bukti = run tanpa ED1154_RESUME. Langkah gagal: dump kontrol layar ke work/ED-1154/debug/.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const { test, expect, config } = require('e2e-harness');

const LIB = path.join(path.dirname(require.resolve('e2e-harness')), '..', '..', 'lib');
const { importSession, storageStateFor } = require(path.join(LIB, 'session'));
const { Monitor, AppDriver } = require(path.join(LIB, 'monitor'));

const KEY = 'ED-1154';
const HERE = __dirname;
const RUNS_DIR = path.join(config.AGENTIC_DIR, 'work', KEY, 'runs');
const DEBUG_DIR = path.join(config.AGENTIC_DIR, 'work', KEY, 'debug');
const PHOTO = path.join(HERE, 'checkin.jpg');
const CUSTOMER_CODE = 'CST-10120';
const QUEUE_TIMEOUT = 180000; // BR-11
const ARCHIVE = { IN_FOLDER: 1, GIVEN: 2, RECEIVED: 3 };
const RESUME = process.env.ED1154_RESUME || '';

/**
 * E-1 (lingkungan, qa-fe r2; tiket ED-1166): foto customer nyata (attachments/customerPhotos/*) tidak ada di storage BE lokal ->
 * `select/files/download` menjawab NOT_FOUND_ATTACHMENT (JSON) untuk <img> -> Chrome memblokir (ERR_BLOCKED_BY_ORB).
 * Hanya di layar Pelanggan (drawer A.0), bukan buatan tes/fitur archive.
 */
const ALLOW = [
  {
    id: 'ED1154-E1-foto-customer-lokal',
    kind: 'requestfailed',
    match: '^GET \\S*/api/v5/select/files/download\\?idAttachment=\\S+ net::ERR_BLOCKED_BY_ORB$',
    paths: ['/setups/customers/customers'],
    reason: 'berkas foto customer DB salinan tidak ada di storage lokal BE (NOT_FOUND_ATTACHMENT); lingkungan, bukan fitur',
    ref: 'ED-1166',
    since: '2026-10-16',
  },
  {
    id: 'ED1154-D1-sa-confirm-driver-500',
    kind: 'response',
    status: 500,
    match: '^PUT \\S*/api/v5/sales/shipment-acceptances/[^/ ]+/confirm(\\?\\S*)? 500$',
    profiles: ['wawan'],
    reason: 'D-1 (qa-fe r2): Confirm SA oleh driver ditolak ConfirmRequest GE0114 tanpa kode HTTP -> 500; dicatat di jurnal + laporan; di AC-15 = 5xx bertiket (KNOWN_5XX), dicatat, tidak dihitung di "0 5xx"',
    ref: 'ED-1168',
    since: '2026-10-16',
  },
  {
    id: 'ED1154-D2-si-save-and-confirm-500',
    kind: 'response',
    status: 500,
    match: '^POST \\S*/api/v5/sales/sales-invoices/save-and-confirm(\\?\\S*)? 500$',
    profiles: ['default'],
    reason: 'D-2 (qa-fe r2): Simpan & Konfirmasi SI hasil impor DO -> 500 Unknown column sales_invoice_serials (QueueService::persistDraftForConfirm memakai request mentah); dicatat; di AC-15 = 5xx bertiket (KNOWN_5XX), dicatat, tidak dihitung di "0 5xx"',
    ref: 'ED-1169',
    since: '2026-10-16',
  },
  {
    id: 'ED1154-D2-si-unhandled-rejection',
    kind: 'pageerror',
    match: '^Error: Object$',
    profiles: ['default'],
    paths: ['/sales/sales-invoices'],
    reason: 'D-2 (qa-fe r2): FE melempar penolakan promise tak tertangani ("Error: Object") sesudah 500 save-and-confirm SI',
    ref: 'ED-1169',
    since: '2026-10-16',
  },
  {
    id: 'ED1154-D1-sa-unhandled-rejection',
    kind: 'pageerror',
    match: '^Error: Object$',
    profiles: ['wawan'],
    paths: ['/sales/shipment-acceptances'],
    reason: 'D-1 (qa-fe r2): FE melempar penolakan promise tak tertangani ("Error: Object") sesudah 500 Konfirmasi SA oleh driver',
    ref: 'ED-1168',
    since: '2026-10-16',
  },
];
/**
 * 5xx dari defect aplikasi bertiket di luar task ini (qa-fe r3, preseden ED-1149): tidak menghentikan rantai, dicatat di jurnal
 * (`http5xx_list` semua, `http5xx_known` dengan tiket) dan laporan, TIDAK dihitung di AC-15 "0 5xx". Sempit: aktor + method +
 * path + status, dan langkahnya sudah menangkap badan respons yang sama (RUN.defects: URL sama + kode/pesan defect).
 * Setiap 5xx lain (`http5xx_unknown`) = AC-15 gagal.
 */
const KNOWN_5XX = [
  {
    id: 'D-1',
    ref: 'ED-1168',
    who: 'w',
    method: 'PUT',
    status: 500,
    re: /\/api\/v5\/sales\/shipment-acceptances\/[^/?]+\/confirm(\?|$)/,
    sig: (d) => d.code === 'GE0114',
  },
  {
    id: 'D-2',
    ref: 'ED-1169',
    who: 'i',
    method: 'POST',
    status: 500,
    re: /\/api\/v5\/sales\/sales-invoices\/save-and-confirm(\?|$)/,
    sig: (d) => /sales_invoice_serials/.test(String(d.message || '')),
  },
];

/** hitung 5xx satu monitor sekali (bertiket vs lain) ke jurnal; kembalikan baris 5xx yang tidak bertiket */
function count5xx(who, mon) {
  if (!mon || mon.ed1154Counted) return [];
  mon.ed1154Counted = true;
  const unknown = [];
  for (const r of mon.responses.filter((x) => x.status >= 500)) {
    const url = config.safeUrl(r.url);
    const line = `${who} ${r.status} ${r.method} ${url}`;
    const k = KNOWN_5XX.find(
      (e) => e.who === who && e.method === r.method && e.status === r.status && e.re.test(url)
        && (RUN.defects || []).some((d) => d.id === e.id && d.api === `${r.method} ${url}` && d.status === r.status && e.sig(d)),
    );
    RUN.http5xx_list = [...(RUN.http5xx_list || []), line];
    RUN.http5xx = (RUN.http5xx || 0) + 1;
    if (k) RUN.http5xx_known = [...(RUN.http5xx_known || []), { id: k.id, ref: k.ref, line }];
    else {
      RUN.http5xx_unknown = [...(RUN.http5xx_unknown || []), line];
      unknown.push(line);
    }
  }
  return unknown;
}

const esc = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const trim = (s) => String(s || '').replace(/\s+/g, ' ').trim();

// ------------------------------------------------------------------------------------------------------ jurnal + DB
/** state run, dibagi antar-test dalam satu worker (describe.serial, --workers 1) */
const RUN = { key: KEY, runId: null, status: 'init', created: { attendance: {}, approvals: [] }, steps: [], blocked: [] };
let ACTORS = null; // aktor test yang sedang jalan (dump + tutup konteks saat gagal)

function writeJournal() {
  if (!RUN.runId) return;
  fs.mkdirSync(RUNS_DIR, { recursive: true });
  RUN.updated_at = new Date().toISOString();
  fs.writeFileSync(path.join(RUNS_DIR, `${RUN.runId}.json`), JSON.stringify(RUN, null, 2));
}

const RUN_NOW = new Set(); // langkah yang dijalankan di proses ini (cek layar hanya untuk ini)

/** catat langkah selesai + snapshot keadaan DB saat itu (asersi memakai snapshot, juga sesudah resume) */
function stepLog(step, ok, note) {
  RUN.steps.push({ step, ok, at: new Date().toISOString(), resumed: RESUME || undefined, note: note ? config.redact(String(note)).slice(0, 600) : undefined });
  if (ok) {
    RUN_NOW.add(step);
    if (RUN.runId && RUN.started_app) {
      RUN.snap = RUN.snap || {};
      try {
        RUN.snap[step] = inspect();
      } catch (e) {
        RUN.snap[step] = { error: String(e.message).slice(0, 300) };
      }
    }
  }
  writeJournal();
}
/** snapshot langkah; null = langkah selesai di percobaan sebelumnya tanpa snapshot (resume) -> asersinya dilewati + dicatat */
function snapOf(step) {
  if (RUN.snap && RUN.snap[step] && !RUN.snap[step].error) return RUN.snap[step];
  if (RUN_NOW.has(step)) return inspect();
  RUN.skipped_asserts = [...new Set([...(RUN.skipped_asserts || []), step])];
  writeJournal();
  return null;
}
const done = (step) => RUN.steps.some((s) => s.step === step && s.ok);

function fx(...args) {
  const out = execFileSync(config.get('PHP_BIN'), [path.join(HERE, 'fixture.php'), ...args], { encoding: 'utf8', timeout: 120000 });
  const json = JSON.parse(out.trim().split('\n').pop());
  if (json.error) throw new Error(`fixture.php ${args.join(' ')}: ${json.error}`);
  return json;
}
const inspect = () => fx('inspect', RUN.runId);

/** BR-11: tunggu sampai pred(inspect()) benar; lewat batas = BLOCKED dengan bukti antrian */
async function waitDb(label, pred, { timeout = QUEUE_TIMEOUT, queue = false } = {}) {
  const t0 = Date.now();
  let last = null;
  while (Date.now() - t0 < timeout) {
    last = inspect();
    let ok = false;
    try {
      ok = pred(last);
    } catch (e) {
      ok = false;
    }
    if (ok) return last;
    await new Promise((r) => setTimeout(r, 3000));
  }
  if (queue) {
    const ev = fx('queue', RUN.runId);
    RUN.status = 'blocked';
    RUN.blocked.push({ step: label, reason: `langkah antrian lewat ${timeout / 1000} s`, evidence: ev });
    writeJournal();
    throw new Error(`BLOCKED (BR-11) ${label}: transaksi belum berubah dalam ${timeout / 1000} s; bukti antrian: ${JSON.stringify(ev).slice(0, 1500)}`);
  }
  throw new Error(`${label}: keadaan DB tidak tercapai dalam ${timeout / 1000} s; terakhir: ${JSON.stringify(last).slice(0, 1500)}`);
}

/** asersi keadaan arsip menurut spec § 4 (status, is_active, updated_by, ekor history, induk) */
function expectArchive(row, exp, label) {
  expect(row, `${label}: baris arsip ada`).toBeTruthy();
  expect(row.status, `${label}: status`).toBe(exp.status);
  expect(row.is_active, `${label}: is_active`).toBe(1);
  expect(row.updated_by, `${label}: updated_by`).toBe(exp.by);
  if (exp.len !== undefined) expect(row.history_len, `${label}: panjang history`).toBe(exp.len);
  if (exp.tail) {
    expect(row.tail && row.tail.action, `${label}: ekor history action`).toBe(exp.tail[0]);
    expect(row.tail && row.tail.related_username, `${label}: ekor history related_username`).toBe(exp.tail[1]);
  }
  if (exp.prev) {
    const prev = row.history[row.history.length - 2] || {};
    expect([prev.action, prev.related_username], `${label}: history sebelum ekor`).toEqual(exp.prev);
  }
  if (exp.parent !== undefined) expect(row.id_parent, `${label}: induk`).toBe(exp.parent);
}

/** catat approval yang dibuat run ini (AC-15: jurnal memuat approval A.0/A.1) */
function recordApproval(step, module, idTransaction, transactionNo, rows) {
  RUN.created.approvals = RUN.created.approvals || [];
  RUN.created.approvals.push({ step, module, id_transaction: idTransaction, transaction_no: transactionNo, rows, at: new Date().toISOString() });
  writeJournal();
}

// ------------------------------------------------------------------------------------------------------ aktor (konteks per profil)
const PROFILE_OF = { i: 'default', a: 'user2', w: 'wawan' };

class Actors {
  constructor({ browser, baseURL, testInfo, app, page, monitor, geo }) {
    this.browser = browser;
    this.baseURL = baseURL;
    this.testInfo = testInfo;
    this.geo = geo;
    this.list = { i: { who: 'i', profile: 'default', app, page, monitor, own: false } };
    ACTORS = this;
  }

  async get(who) {
    if (this.list[who]) return this.list[who];
    const profile = PROFILE_OF[who];
    const session = importSession(profile);
    if (session.skip) throw new Error(`BLOCKED: profil ${profile} di-skip: ${session.skip}`);
    const ctx = await this.browser.newContext({
      baseURL: this.baseURL,
      storageState: storageStateFor(session, new URL(this.baseURL).origin),
      viewport: { width: 1440, height: 900 },
      timezoneId: 'Asia/Jakarta',
      serviceWorkers: 'block',
      ...(this.geo ? { geolocation: this.geo, permissions: ['geolocation'] } : {}),
    });
    const page = await ctx.newPage();
    const monitor = new Monitor(page, { profile, allow: ALLOW });
    const app = new AppDriver(page, monitor, this.testInfo, { profile, outDir: process.env.E2E_OUT_DIR });
    app.screenshots = this.list.i.app.screenshots; // satu galeri per test
    this.list[who] = { who, profile, ctx, page, monitor, app, own: true };
    return this.list[who];
  }

  /** 0 respons 5xx di luar defect bertiket (KNOWN_5XX) + monitor bersih di semua konteks; catat ringkasan ke monitor utama */
  async finish() {
    const main = this.list.i.monitor;
    const bad = [];
    for (const a of Object.values(this.list)) {
      bad.push(...count5xx(a.who, a.monitor));
      main.note(`[${a.who}] API ${a.monitor.responses.filter((r) => /\/api\/v5\//.test(r.url)).length}, error ${a.monitor.unallowed().length}`);
      a.counted = true;
    }
    writeJournal();
    await this.close();
    expect(bad, 'tidak ada respons 5xx di luar defect bertiket (ED-1168, ED-1169) di semua konteks').toEqual([]);
    for (const a of Object.values(this.list)) if (a.own) a.monitor.check();
  }

  async close() {
    for (const a of Object.values(this.list)) {
      if (!a.own || a.closed) continue;
      a.closed = true;
      await a.ctx.close().catch(() => null);
    }
  }
}

/** dump kontrol layar yang terlihat (debug selector saat langkah gagal) */
async function dumpPage(page, name) {
  try {
    fs.mkdirSync(DEBUG_DIR, { recursive: true });
    const d = await page.evaluate(() => {
      const vis = (el) => !!(el.offsetParent || el.getClientRects().length);
      const all = (sel) => [...document.querySelectorAll(sel)].filter(vis);
      const labelOf = (el) => {
        const fi = el.closest('.ant-form-item');
        const l = fi && fi.querySelector('.ant-form-item-label');
        return l ? l.innerText.trim() : '';
      };
      return {
        url: location.pathname,
        tab: sessionStorage.getItem('appTab'),
        modals: all('.ant-modal-title, .ant-drawer-title').map((e) => e.innerText.trim().slice(0, 80)),
        alerts: all('.ant-alert, .ant-message, .ant-notification-notice, .ant-form-item-explain-error').map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 300)),
        inputs: all('input, textarea').map((e) => ({ id: e.id, type: e.type, label: labelOf(e), val: e.type === 'password' ? '' : (e.value || '').slice(0, 40) })).slice(0, 60),
        selects: all('.ant-select').map((e) => ({ id: (e.querySelector('input') || {}).id, text: e.innerText.trim().slice(0, 60), label: labelOf(e) })).slice(0, 40),
        buttons: all('button, .ant-dropdown-trigger, .ant-dropdown-menu-item').map((e) => `${e.innerText.trim().slice(0, 40)} [${String(e.className).slice(0, 70)}]`).slice(0, 80),
        headers: all('.ant-collapse-header, .ant-steps-item-title, .ant-tabs-tab').map((e) => e.innerText.trim().slice(0, 60)),
        rows: all('.ant-table-tbody tr').slice(0, 12).map((e) => `${e.getAttribute('data-row-key') || ''} | ${e.innerText.replace(/\s+/g, ' ').trim().slice(0, 200)}`),
      };
    });
    fs.writeFileSync(path.join(DEBUG_DIR, `${RUN.runId || 'x'}-${name}.json`), JSON.stringify(d, null, 1));
    await page.screenshot({ path: path.join(DEBUG_DIR, `${RUN.runId || 'x'}-${name}.png`) }).catch(() => null);
  } catch (e) {
    // halaman sudah tertutup
  }
}

// ------------------------------------------------------------------------------------------------------ helper UI umum
const visibleModal = (page) => page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal').last();
const openDrawer = (page) => page.locator('.ant-drawer-open .ant-drawer-content').last();
const openDropdown = (page) => page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
const addBtn = (page) => page.locator('.page-container button.btn-icon.ant-btn-primary').first();

function waitApi(page, method, re, timeout = 60000) {
  return page
    .waitForResponse((r) => r.request().method() === method && re.test(new URL(r.url()).pathname), { timeout })
    .then(async (r) => {
      let body = null;
      try {
        body = r.request().postDataJSON();
      } catch (e) {
        body = null;
      }
      return { status: r.status(), url: r.url(), body, json: await r.json().catch(() => null) };
    });
}
function expectOk(resp, label) {
  expect(resp.status, `${label}: HTTP ${resp.status} ${resp.json && resp.json.message ? resp.json.message : ''}`).toBeLessThan(400);
  return resp.json && resp.json.result;
}

/** select antd: klik, ketik (bila ada), pilih opsi pertama yang cocok */
async function pick(page, scope, inputSel, search, optionRe) {
  const sel = scope.locator(`.ant-select:has(${inputSel})`).last();
  await sel.click();
  if (search) await scope.locator(inputSel).last().fill(search);
  const dd = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden)').last();
  await dd.waitFor({ state: 'visible', timeout: 15000 });
  const opt = dd.locator('.ant-select-item-option', { hasText: optionRe }).first();
  await opt.waitFor({ state: 'visible', timeout: 30000 });
  await opt.click();
  await page.waitForTimeout(600);
}

/** klik tombol yang sudah siap: terlihat, aktif, tidak sedang loading (antd menelan klik saat ant-btn-loading) */
async function clickReady(btn, timeout = 60000) {
  await btn.waitFor({ state: 'visible', timeout });
  await expect(btn).toBeEnabled({ timeout });
  await expect(btn).not.toHaveClass(/ant-btn-loading/, { timeout });
  await btn.click();
}

/** OK di popconfirm yang sedang terbuka */
async function popOk(page, timeout = 10000) {
  const pop = page.locator('.ant-popover:not(.ant-popover-hidden) .ant-popconfirm-buttons, .ant-popover:not(.ant-popover-hidden) .ant-popover-buttons').last();
  await pop.waitFor({ state: 'visible', timeout });
  await pop.locator('button.ant-btn-primary').click();
}

async function menuPick(page, trigger, re) {
  await trigger.click();
  const dd = openDropdown(page);
  await dd.waitFor({ state: 'visible', timeout: 10000 });
  await dd.locator('li.ant-dropdown-menu-item', { hasText: re }).first().click();
}

/** buka list, (opsional) cari, buka drawer baris itu: baris dipilih lewat data-row-key (id) atau teks (nomor) */
async function openRow(app, page, routePath, { id, no, search } = {}) {
  await app.open(routePath, { waitFor: '.ant-table' });
  const term = search || no;
  const q = page.locator('.page-container input#query').first();
  if (term && (await q.count())) {
    await q.fill(term);
    await q.press('Enter');
    await app.settle();
  }
  const row = id
    ? page.locator(`.page-container .ant-table-tbody tr[data-row-key="${id}"]`).first()
    : page.locator('.page-container .ant-table-tbody tr.ant-table-row', { hasText: no }).first();
  await row.waitFor({ state: 'visible', timeout: 30000 });
  const action = row.locator('button.btn-action').first();
  if (await action.count()) await action.click();
  else await row.locator('td').nth(1).click();
  const d = openDrawer(page);
  await d.waitFor({ state: 'visible', timeout: 30000 });
  await app.settle();
  return d;
}

/**
 * modal impor (ModalImport): (opsional) pilih induk di select modal (SI: "Pilih pesanan penjualan"), cari (bila ada kotak
 * cari), centang baris data-row-key, klik tombol impor ("Impor Data Terpilih" / SI: "Impor Faktur Terpilih")
 */
async function importRows(app, page, modal, ids, { search, parent } = {}) {
  await modal.waitFor({ state: 'visible', timeout: 20000 });
  await app.settle();
  if (parent) {
    await modal.locator('.ant-select').first().click();
    await page.keyboard.type(parent.search);
    const opt = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option', { hasText: parent.re }).first();
    await opt.waitFor({ state: 'visible', timeout: 30000 });
    await opt.click();
    await app.settle();
  }
  if (search) {
    const q = modal.locator('input#query').first();
    if (await q.count()) {
      await q.fill(search);
      await q.press('Enter');
      await app.settle();
    }
  }
  for (const id of ids) {
    const row = modal.locator(`tr[data-row-key="${id}"]`).first();
    await row.waitFor({ state: 'visible', timeout: 30000 });
    await row.locator('.ant-table-selection-column input').first().check();
  }
  await clickReady(modal.locator('button.ant-btn-primary', { hasText: /Impor .*Terpilih|Import Selected/ }).first());
  await app.settle();
}

/** unggah foto ke semua slot foto wajib (label .ant-form-item-required di kolom slot) di bawah scope */
async function uploadRequiredPhotos(page, scope) {
  const n = await scope.evaluate((root) => {
    let k = 0;
    root.querySelectorAll('[data-e2e-photo]').forEach((el) => el.removeAttribute('data-e2e-photo'));
    root.querySelectorAll('.ant-upload input[type="file"]').forEach((input) => {
      const col = input.closest('.ant-col');
      if (!col || col.hasAttribute('data-e2e-photo-col')) return;
      col.setAttribute('data-e2e-photo-col', '1');
      if (!col.querySelector('label.ant-form-item-required')) return;
      input.setAttribute('data-e2e-photo', String(k));
      k += 1;
    });
    root.querySelectorAll('[data-e2e-photo-col]').forEach((el) => el.removeAttribute('data-e2e-photo-col'));
    return k;
  });
  for (let k = 0; k < n; k += 1) {
    await scope.locator(`input[data-e2e-photo="${k}"]`).setInputFiles(PHOTO);
    await page.waitForTimeout(500);
  }
  return n;
}

/** ApprovalTransactionAction di drawer: "Setujui" -> drawer approval inline -> submit "Setujui" (tanpa PIN, R1-K3 c) */
async function approveTransaction(a, drawer, label) {
  const alert = drawer.locator('.ant-alert', { hasText: /Perlu Disetujui|Need Approval/ }).filter({ hasNotText: /Revisi|Revise/ }).first();
  await alert.waitFor({ state: 'visible', timeout: 30000 });
  await a.app.screenshot(`${label}-approval-alert`);
  await alert.locator('button.ant-btn-primary', { hasText: /^Setujui$|^Approve$/ }).first().click();
  const panel = a.page.locator('.drawer-approval-action').last();
  const submit = panel.locator('button[type="submit"]').first();
  await submit.waitFor({ state: 'visible', timeout: 20000 });
  expect(await panel.locator('input[type="password"], .ant-otp, input[inputmode="numeric"]').count(), `${label}: tanpa input PIN (use_approval_pin=0)`).toBe(0);
  await a.app.screenshot(`${label}-approval-drawer`);
  const resp = waitApi(a.page, 'POST', /setting\/approvals\/approve$/, 120000);
  await submit.click();
  const r = await resp;
  expectOk(r, `${label} POST setting/approvals/approve`);
  await a.app.settle();
  return r;
}

// ------------------------------------------------------------------------------------------------------ layar Archive (BR-12)
async function archiveOpen(a) {
  await a.app.open('/archives', { waitFor: '.archive-page .ant-table' });
}

/** cari dokumen/folder di layar Archive, kembalikan teks baris (status/riwayat terakhir tampil di kolom Status) */
async function archiveRowText(a, name) {
  await archiveOpen(a);
  const q = a.page.locator('.archive-page input#query').first();
  await q.fill(name);
  const resp = waitApi(a.page, 'GET', /document-archive\/archives$/);
  await q.press('Enter');
  await resp;
  await a.app.settle();
  const row = a.page.locator('.archive-page .ant-table-row', { hasText: name }).first();
  await row.waitFor({ state: 'visible', timeout: 20000 });
  return trim(await row.innerText());
}

const LABEL = {
  give: /Diserahkan oleh|Handed over by/,
  receive: /Diterima oleh|Received by/,
  place: /Diletakkan oleh|Stored by/,
  move: /Dipindahkan oleh|Moved by/,
};

async function expectArchiveScreen(a, name, action, user, label) {
  const text = await archiveRowText(a, name);
  expect(text, `${label}: layar Archive ${a.who} - label ${action}`).toMatch(LABEL[action]);
  expect(text, `${label}: layar Archive ${a.who} - user ${user}`).toContain(user);
  await a.app.screenshot(`${label}-archive-${a.who}`);
  return text;
}

/** tombol "+" Archive -> item menu */
async function archivePlus(a, re) {
  const btn = a.page.locator('.archive-page button.ant-btn-primary.ant-dropdown-trigger').first();
  await menuPick(a.page, btn, re);
  const m = visibleModal(a.page);
  await m.waitFor({ state: 'visible', timeout: 15000 });
  await a.app.settle();
  return m;
}

/** scan USB: fokus di luar input (judul modal), ketik tanpa jeda + Enter (useBarcodeScanner maxInterval 30 ms) */
async function scan(page, modal, code) {
  await modal.locator('.ant-modal-title').click();
  await page.waitForTimeout(300);
  await page.keyboard.type(`${code}\n`, { delay: 0 });
}

/** Hand Over / Receive / Store Document lewat modal scanner -> review -> tombol OK; kembalikan respons POST */
async function archiveScanAction(a, menuRe, code, okRe, apiRe, label) {
  const m = await archivePlus(a, menuRe);
  await a.app.screenshot(`${label}-modal`);
  await scan(a.page, m, code);
  await expect(m, `${label}: review memuat ${code}`).toContainText(code, { timeout: 20000 });
  const selects = await m.locator('.ant-select').count(); // BR-4: Hand Over tanpa pilihan penerima
  const resp = waitApi(a.page, 'POST', apiRe);
  // tombol aksi di badan modal (pola ED-1125): Simpan / Serahkan / Terima
  await clickReady(m.locator('button.ant-btn-primary', { hasText: okRe }).first());
  const r = await resp;
  expectOk(r, label);
  await a.app.settle();
  return { modal: m, resp: r, selects };
}

// ------------------------------------------------------------------------------------------------------ attendance
async function checkIn(a, label) {
  await a.app.open('/attendance', { waitFor: 'button.btn-attendance' });
  await pick(a.page, a.page, 'input#idCustomer', 'BERKAT', new RegExp(`${CUSTOMER_CODE}|BERKAT, TK\\.$`));
  await a.app.settle();
  await a.page.locator('#storeStatus .ant-radio-button-wrapper, .ant-radio-button-wrapper', { hasText: /^Buka$|^Open$/ }).first().click(); // BR-8
  // AttendanceNote: untuk salesman (mis. kunjungan di luar jadwal) muncul Catatan wajib sesudah customer dipilih
  const note = a.page.locator('.page-container textarea#note').first();
  if (await note.isVisible().catch(() => false)) {
    await note.fill('ED-1155 e2e (QA ED-1154)');
    RUN[`${label}_checkin_note`] = true;
  }
  const n = await uploadRequiredPhotos(a.page, a.page.locator('.page-container:visible').last());
  expect(n, `${label}: foto wajib check-in terisi`).toBeGreaterThan(0);
  await a.app.screenshot(`${label}-checkin-form`);
  const resp = waitApi(a.page, 'POST', /salesman-activity\/attendances\/check-in$/);
  await a.page.locator('button.btn-attendance', { hasText: /Check In/ }).click();
  const res = expectOk(await resp, `${label} check-in`);
  await a.app.settle();
  await a.app.screenshot(`${label}-checkin-ok`);
  return res;
}

async function checkOut(a, label) {
  await a.app.open('/attendance', { waitFor: 'button.btn-attendance' });
  const n = await uploadRequiredPhotos(a.page, a.page.locator('.page-container:visible').last());
  await a.app.screenshot(`${label}-checkout-form`);
  const resp = waitApi(a.page, 'POST', /salesman-activity\/attendances\/check-out$/);
  await a.page.locator('button.btn-attendance', { hasText: /Check Out/ }).click();
  const note = visibleModal(a.page);
  await note.waitFor({ state: 'visible', timeout: 5000 }).catch(() => null);
  if (await note.isVisible().catch(() => false)) {
    // AttandanceCheckOutNoteModal (ModalDetail): catatan wajib, tombol Simpan di badan modal (bukan .ant-modal-footer)
    await note.locator('textarea#note, textarea').first().fill('ED-1155 e2e');
    await a.app.screenshot(`${label}-checkout-note`);
    await clickReady(note.locator('button.ant-btn-primary', { hasText: /^Simpan$|^Save$/ }).first());
  }
  expectOk(await resp, `${label} check-out (foto ${n})`);
  await a.app.settle();
  await a.app.screenshot(`${label}-checkout-ok`);
}

// ======================================================================================================================
test.describe.serial(`${KEY} archive lewat transaksi nyata (A -> B -> C)`, () => {
  test.use({ allow: ALLOW });

  // langkah gagal (bukan BLOCKED) -> jurnal mencatat langkahnya; data yang sudah dibuat tetap dibiarkan (G-3)
  test.afterEach(async ({ page, monitor }, testInfo) => {
    // AC-15: 5xx test tanpa Actors.finish (AC-1, A.0, test yang gagal) ikut tercatat di jurnal
    count5xx('i', monitor);
    if (ACTORS) for (const a of Object.values(ACTORS.list)) count5xx(a.who, a.monitor);
    writeJournal();
    if (testInfo.status !== testInfo.expectedStatus && testInfo.status !== 'skipped') {
      const tag = testInfo.title.replace(/^.*?(AC-\d+|K-6)\b.*$/, '$1');
      await dumpPage(page, `${tag}-i`);
      if (ACTORS) {
        for (const a of Object.values(ACTORS.list)) if (a.own && !a.closed) await dumpPage(a.page, `${tag}-${a.who}`);
      }
      if (RUN.status === 'running') {
        RUN.status = 'failed';
        RUN.failed_step = testInfo.title;
        RUN.failed_error = config.redact(String((testInfo.error && testInfo.error.message) || '')).slice(0, 1500);
        writeJournal();
      }
    }
    if (ACTORS) await ACTORS.close();
    ACTORS = null;
  });

  // ------------------------------------------------------------------------------------------------ AC-1
  test('[default] ED-1154 AC-1 prasyarat sebelum A.0 + jurnal run', async ({ monitor, session }) => {
    test.setTimeout(300000);
    let prev = null;
    if (RESUME) {
      prev = JSON.parse(fs.readFileSync(path.join(RUNS_DIR, `${RESUME}.json`), 'utf8'));
      expect(['failed', 'running', 'blocked'], `resume ${RESUME}: jurnal belum selesai`).toContain(prev.status);
    }
    const pf = RESUME ? fx('preflight', RESUME) : fx('preflight');
    if (prev) {
      Object.assign(RUN, prev);
      RUN.resumes = [...(prev.resumes || []), { at: new Date().toISOString(), from_status: prev.status, failed_step: prev.failed_step, preflight_ok: pf.ok, blocked: pf.blocked }];
      delete RUN.failed_step;
      delete RUN.failed_error;
      RUN.created.approvals = RUN.created.approvals || [];
    } else {
      RUN.runId = pf.now.replace(/[-: ]/g, '').slice(2, 14); // yyMMddHHmmss (zona app), juga akhiran nama folder (K-5)
      RUN.started_at = new Date().toISOString();
      RUN.started_app = pf.now;
      RUN.db = pf.db;
      RUN.folder = { name: `E2E-ARSIP-${RUN.runId}` };
      RUN.preflight = {
        ok: pf.ok,
        blocked: pf.blocked,
        warn: pf.warn,
        users: Object.fromEntries(Object.entries(pf.checks.users || {}).map(([k, v]) => [k, v && { username: v.username, roles: v.roles, employee_type: v.employee_type, locations: v.employee_locations }])),
        qa_role_presets: pf.checks.qa_role_presets,
        item: pf.checks.item,
        carriers: (pf.checks.carriers || []).map((c) => ({ police: c.police_number, type: c.type, busy: c.busy })),
        customer: pf.checks.customer,
        approval: pf.checks.approval,
        attendance_open: pf.checks.attendance_open,
        archives_baseline: pf.checks.archives_baseline,
        queue: pf.checks.queue,
      };
    }
    RUN.status = 'preflight';
    writeJournal();
    monitor.note(`run ${RUN.runId}${RESUME ? ' (RESUME, debug alat uji)' : ''}; jurnal work/${KEY}/runs/${RUN.runId}.json; DB ${session.dbName}`);
    for (const w of pf.warn) monitor.note(`peringatan: ${w}`);
    const ap = pf.checks.approval || {};
    monitor.note(`AC-1 approval: pin=${ap.use_approval_pin}; CUSTOMER i level ${ap.configs && ap.configs.CUSTOMER && ap.configs.CUSTOMER.i_first_level}/${ap.configs && ap.configs.CUSTOMER && ap.configs.CUSTOMER.count_levels}; SALES_ORDER i level ${ap.configs && ap.configs.SALES_ORDER && ap.configs.SALES_ORDER.i_first_level}/${ap.configs && ap.configs.SALES_ORDER && ap.configs.SALES_ORDER.count_levels}; customer approval_status=${ap.customer_approval_status} (A.0 ${ap.need_a0 ? 'perlu' : 'tidak perlu'}); role 3: ${(ap.role_i_permissions || []).join(', ')}`);

    // AC-16: keadaan run sebelumnya (dari jurnal lengkap) dicatat sebelum run ini mengubah apa pun
    if (!prev) RUN.previous_before = fx('previous', RUN.runId).previous;
    writeJournal();

    if (!pf.ok) {
      RUN.status = 'blocked';
      RUN.blocked = pf.blocked;
      writeJournal();
      monitor.check();
      throw new Error(`BLOCKED (AC-1) tanpa approval/transaksi:\n${pf.blocked.map((b) => `  ${b.code}: ${b.msg}`).join('\n')}`);
    }

    // K-6: attendance terbuka yang tercatat di jurnal run sebelumnya -> check-out lewat UI (lainnya sudah BLOCKED di preflight)
    const ownAtt = Object.values((RUN.created && RUN.created.attendance) || {}).map((x) => x && x.id_attendance);
    RUN.checkout_first = pf.checks.attendance_open.filter((o) => o.journal && !ownAtt.includes(o.id_attendance));
    RUN.geo = (() => {
      const g = pf.checks.customer && pf.checks.customer.address && pf.checks.customer.address.geotag;
      const [lat, lng] = String(g || '').split(',').map(Number);
      return Number.isFinite(lat) && Number.isFinite(lng) ? { latitude: lat, longitude: lng } : null;
    })();
    RUN.customer = { id: pf.checks.customer.id_customer, code: pf.checks.customer.customer_code, name: pf.checks.customer.customer_name };
    if (!prev) {
      RUN.item = pf.checks.item;
      RUN.carrier = (pf.checks.carriers || []).find((c) => c.busy === 0);
    }
    if (!prev) RUN.need_a0 = Boolean(ap.need_a0);
    RUN.status = 'running';
    stepLog('AC-1', true, `item ${RUN.item.item_code} stok ${RUN.item.stock}; carrier ${RUN.carrier.police_number}; A.0 ${RUN.need_a0 ? 'perlu' : 'tidak perlu'}`);
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ K-6 (bila perlu)
  test('[default] ED-1154 K-6 check-out attendance run sebelumnya (tercatat di jurnal)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(300000);
    test.skip(!(RUN.checkout_first && RUN.checkout_first.length), 'tidak ada attendance terbuka dari run sebelumnya');
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    for (const o of RUN.checkout_first) {
      const a = await actors.get(o.who);
      await checkOut(a, `K6-${o.who}`);
    }
    stepLog('K-6', true, JSON.stringify(RUN.checkout_first));
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.0
  test('[default] ED-1154 AC-3 A.0 i approve customer CST-10120 di drawer Pelanggan (BR-13, bila approval_status != 2)', async ({ app, page, monitor }) => {
    test.setTimeout(300000);
    test.skip(done('A.0'), 'A.0 sudah (resume)');
    const before = inspect().customer;
    if (before.approval_status === 2) {
      RUN.a0 = { skipped: true, reason: 'approval_status sudah 2' };
      stepLog('A.0', true, 'tidak perlu: CST-10120 approval_status=2');
      monitor.note('A.0 dilewati: CST-10120 approval_status=2 (sudah disetujui, BR-13)');
      monitor.check();
      return;
    }
    const i = { who: 'i', app, page, monitor };
    const d = await openRow(app, page, '/setups/customers/customers', { id: RUN.customer.id, search: CUSTOMER_CODE });
    await expect(d, 'A.0 drawer Pelanggan CST-10120').toContainText(/BERKAT, TK\./);
    await approveTransaction(i, d, 'A0');
    const s = await waitDb('A.0 customer approval_status=2', (x) => x.customer.approval_status === 2, { timeout: 60000 });
    const rows = s.customer.approval_transactions.filter((r) => !before.approval_transactions.some((b) => b.id_approval_transaction === r.id_approval_transaction));
    recordApproval('A.0', 'CUSTOMER', RUN.customer.id, CUSTOMER_CODE, rows);
    RUN.a0 = { skipped: false, before: before.approval_status, after: s.customer.approval_status, last_approved_by: s.customer.last_approved_by };
    stepLog('A.0', true, `CST-10120 approval_status ${before.approval_status} -> 2`);
    expect(rows.map((r) => [r.username, r.level, r.status]), 'A.0: tepat 1 approval baru, oleh i di L4 (L1-L3 dilewati)').toEqual([[config.get('QA_USER'), 4, 1]]);
    expect(s.customer.last_approved_by, 'A.0: last_approved_by i').toBe(config.get('QA_USER'));
    await app.screenshot('A0-customer-approved');
    monitor.check();
  });

  // ------------------------------------------------------------------------------------------------ A.1-A.2
  test('[default] ED-1154 AC-3 A.1-A.2 SO (approval customer + L2, confirm otomatis) + Shipment dikonfirmasi admin -> DO lahir (karakterisasi K-3)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(900000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    let s;

    // A.1a SO (i): lokasi Semarang, customer CST-10120, salesman adi, 1 item ber-stok qty 1, Simpan (BR-13)
    if (!done('A.1a')) {
      await i.app.open('/sales/sales-orders', { waitFor: '.ant-table' });
      await addBtn(i.page).click();
      await i.page.locator('input#idLocation').first().waitFor({ state: 'visible', timeout: 45000 });
      await i.app.settle();
      await pick(i.page, i.page, 'input#idLocation', 'Semarang', /Semarang/);
      await i.app.settle();
      await pick(i.page, i.page, 'input#idCustomer', CUSTOMER_CODE, new RegExp(CUSTOMER_CODE));
      await i.app.settle();
      const salesman = trim(await i.page.locator('.ant-select:has(input#idSalesman)').last().innerText());
      if (!/\bAdi\b/.test(salesman)) await pick(i.page, i.page, 'input#idSalesman', 'Adi', /\bAdi\b/);
      const itemsHeader = i.page.locator('.ant-collapse-item', { has: i.page.locator('.ant-collapse-header', { hasText: /Rincian Item|Item Details/ }) }).first();
      if (!/ant-collapse-item-active/.test((await itemsHeader.getAttribute('class')) || '')) {
        await itemsHeader.locator('.ant-collapse-header').click();
        await i.app.settle();
      }
      const itemSel = itemsHeader.locator('.ant-select', { hasText: /Pilih item|Select item/ }).first();
      await itemSel.click();
      await i.page.keyboard.type(RUN.item.item_code);
      const itemOpt = i.page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option', { hasText: RUN.item.item_code }).first();
      await itemOpt.waitFor({ state: 'visible', timeout: 30000 });
      await itemOpt.click();
      await i.app.settle();
      const qty = i.page.locator('input[id$="salesOrderDetails_0_qty"]').first();
      if (await qty.count()) {
        if ((await qty.inputValue()) !== '1') {
          await qty.fill('1');
          await qty.blur();
          await i.app.settle();
        }
      } else {
        monitor.note('A.1: input qty salesOrderDetails_0_qty tidak ditemukan (qty bawaan dipakai)');
      }
      await i.app.screenshot('A1-so-form');
      const soResp = waitApi(i.page, 'POST', /sales\/sales-orders$/, 120000);
      await clickReady(i.page.locator('.page-container button.ant-btn-primary:visible', { hasText: /^Simpan$|^Save$/ }).last());
      const so = expectOk(await soResp, 'A.1 POST sales-orders (Simpan)');
      RUN.created.so = { id: so && (so.idSalesOrder || so.id_sales_order) };
      writeJournal();
      s = await waitDb('A.1 SO tersimpan', (x) => x.so && x.so.id_sales_order === RUN.created.so.id, { timeout: 60000 });
      stepLog('A.1a', true, `SO ${s.so.id_sales_order} transaction_status ${s.so.transaction_status} customer_approval_status ${s.so.customer_approval_status}`);
      RUN.so_after_save = { transaction_status: s.so.transaction_status, approval_status: s.so.approval_status, customer_approval_status: s.so.customer_approval_status, sales_order_no: s.so.sales_order_no };
      writeJournal();
      // karakterisasi: Simpan web = SO baru (4), menunggu persetujuan customer (1), approval internal belum (0) - X-3
      expect([s.so.transaction_status, s.so.customer_approval_status, s.so.approval_status], 'A.1 SO tersimpan: baru, menunggu customer, approval 0').toEqual([4, 1, 0]);
      expect(s.so.salesman_username, 'A.1 salesman SO = a').toBe(config.get('QA_USER2'));
    }

    // A.1b drawer SO: Setujui sebagai customer (SalesmanSalesOrderApproval, permission Allow Approval As Customer)
    if (!done('A.1b')) {
      const d = await openRow(i.app, i.page, '/sales/sales-orders', { id: RUN.created.so.id });
      const alert = d.locator('.ant-alert', { hasText: /Revisi|Revise/ }).first();
      await alert.waitFor({ state: 'visible', timeout: 30000 });
      await i.app.screenshot('A1-so-customer-approval');
      const resp = waitApi(i.page, 'PUT', /salesman-activity\/sales-orders\/[^/]+\/approve$/, 60000);
      await alert.locator('button.ant-btn-primary', { hasText: /^Setujui$|^Approve$/ }).first().click();
      await popOk(i.page);
      expectOk(await resp, 'A.1 PUT salesman-activity/sales-orders/{id}/approve (sebagai customer)');
      s = await waitDb('A.1 SO customer_approval_status=4', (x) => x.so.customer_approval_status === 4, { timeout: 60000 });
      recordApproval('A.1', 'SALES_ORDER customer (salesman-activity approve)', RUN.created.so.id, s.so.sales_order_no, [{ username: config.get('QA_USER'), customer_approval_status: 4 }]);
      stepLog('A.1b', true, 'customer_approval_status 1 -> 4');
      await i.app.settle();
    }

    // A.1c drawer SO dibuka ulang: approve L2 (L1 tidak wajib, dilewati) -> SO terkonfirmasi otomatis (tanpa klik Confirm)
    if (!done('A.1')) {
      const before = inspect().so;
      const d = await openRow(i.app, i.page, '/sales/sales-orders', { id: RUN.created.so.id });
      expect(await d.locator('button.drawer-confirm-btn').count(), 'A.1: tombol Confirm SO tidak tampil sebelum approval').toBe(0);
      await approveTransaction(i, d, 'A1');
      s = await waitDb('A.1 SO approved + confirmed', (x) => x.so.approval_status === 2 && x.so.transaction_status === 1 && x.so.sales_order_no, { queue: true });
      const rows = s.so.approval_transactions.filter((r) => !before.approval_transactions.some((b) => b.id_approval_transaction === r.id_approval_transaction));
      recordApproval('A.1', 'SALES_ORDER', RUN.created.so.id, s.so.sales_order_no, rows);
      RUN.created.so = { id: s.so.id_sales_order, no: s.so.sales_order_no };
      stepLog('A.1', true, s.so.sales_order_no);
      await i.app.screenshot('A1-so-approved');
    }
    s = snapOf('A.1');
    if (s) {
      // AC-3 (SO): customer 2, SO customer 4 / approval 2 / i hanya L2 / confirmed tanpa klik Confirm; tanpa arsip SO aktif
      expect(s.customer.approval_status, 'AC-3: CST-10120 approval_status=2').toBe(2);
      expect([s.so.customer_approval_status, s.so.approval_status, s.so.transaction_status], 'AC-3: SO customer 4, approval 2, confirmed 1').toEqual([4, 2, 1]);
      expect(s.so.approval_transactions.map((r) => [r.username, r.level, r.status]), 'AC-3: approval_transactions SO = i hanya di L2').toEqual([[config.get('QA_USER'), 2, 1]]);
      expect(s.so.salesman_username, 'A.1 salesman SO = a').toBe(config.get('QA_USER2'));
      expect(s.archives_so.filter((r) => r.is_active > 0), 'A.1/BR-3: tidak ada arsip SO aktif').toEqual([]);

    } else monitor.note(`asersi A.1 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    // A.2 Shipment (i): wizard Pilih Item -> Pilih Pengangkut (carrier tersedia + driver w) -> Tinjau -> Simpan (BR-14)
    if (!done('A.2a')) {
      await i.app.open('/sales/shipments', { waitFor: '.ant-table' });
      await addBtn(i.page).click();
      await i.page.locator('.shipment-items input#idLocation').first().waitFor({ state: 'visible', timeout: 45000 });
      await i.app.settle();
      await pick(i.page, i.page.locator('.shipment-items'), 'input#idLocation', 'Semarang', /Semarang/);
      await i.app.settle();
      const q = i.page.locator('.shipment-items input#query').first();
      await q.fill(RUN.created.so.no);
      await q.press('Enter');
      await i.app.settle();
      const soRow = i.page.locator(`.shipment-items tr[data-row-key="${RUN.created.so.id}"]`).first();
      await soRow.waitFor({ state: 'visible', timeout: 30000 });
      // ikon expand = CaretRight kustom (components/Table: span.expand-icon); item SO di tabel .sales-order-items-expand
      await soRow.locator('.expand-icon').first().click();
      const itemsTable = i.page.locator('.shipment-items tr.ant-table-expanded-row .sales-order-items-expand').first();
      await itemsTable.waitFor({ state: 'visible', timeout: 30000 });
      await i.app.settle();
      const all = itemsTable.locator('thead .ant-table-selection-column input[type="checkbox"]').first();
      if (await all.count()) await all.check();
      else {
        const checks = itemsTable.locator('tbody .ant-table-selection-column input[type="checkbox"]');
        for (let k = 0; k < (await checks.count()); k += 1) await checks.nth(k).check();
      }
      await i.app.settle();
      await i.app.screenshot('A2-shipment-items');
      await clickReady(i.page.locator('.page-container button.ant-btn-primary:visible', { hasText: /^Lanjut$|^Next$/ }).last());
      await i.app.settle();
      await i.app.screenshot('A2-shipment-carrier-step');
      await pick(i.page, i.page.locator('.page-container'), 'input[id$="idCarrier"]', RUN.carrier.police_number, new RegExp(esc(RUN.carrier.police_number)));
      await i.app.settle();
      await pick(i.page, i.page.locator('.page-container'), 'input[id$="idEmployee"]', 'Wawan', /Wawan/i);
      await i.app.settle();
      await i.app.screenshot('A2-shipment-carrier');
      await clickReady(i.page.locator('.page-container button.ant-btn-primary:visible', { hasText: /^Lanjut$|^Next$/ }).last());
      await i.app.settle();
      await i.app.screenshot('A2-shipment-review');
      const saveBtn = i.page.locator('.page-container button.ant-btn-primary:visible', { hasText: /^Simpan$|^Save$/ }).last();
      await i.app.settle();
      const shipResp = waitApi(i.page, 'POST', /sales\/shipments$/, 120000);
      await clickReady(saveBtn);
      const sh = expectOk(await shipResp, 'A.2 POST shipments (Simpan)');
      s = await waitDb('A.2 shipment tersimpan', (x) => x.shipment && x.shipment.id_shipment, { timeout: 60000 });
      RUN.created.shipment = { id: s.shipment.id_shipment, no: s.shipment.shipment_no, id_from_response: sh && sh.idShipment };
      stepLog('A.2a', true, `${s.shipment.shipment_no} status ${s.shipment.status}`);
    }
    if (!done('A.2')) {
      const d = await openRow(i.app, i.page, '/sales/shipments', { id: RUN.created.shipment.id });
      const confResp = waitApi(i.page, 'PUT', /sales\/shipments\/[^/]+\/confirm$/, 120000);
      await clickReady(d.locator('button.drawer-confirm-btn').first());
      await popOk(i.page, 5000).catch(() => null);
      expectOk(await confResp, 'A.2 PUT shipments/{id}/confirm');
      s = await waitDb('A.2 shipment confirmed (Q)', (x) => x.shipment && x.shipment.status === 2 && x.archive_do, { queue: true });
      const DO = s.delivery_orders[0];
      RUN.created.do = { id: DO.id_delivery_order, no: DO.delivery_order_no };
      RUN.created.shipment.no = s.shipment.shipment_no;
      stepLog('A.2', true, `${RUN.created.shipment.no} -> ${DO.delivery_order_no}`);
    }
    s = snapOf('A.2');
    if (s) {
      expect(s.shipment.status, 'AC-3: Shipment 2').toBe(2);
      expect(s.delivery_orders.length, 'A.2/BR-7: tepat 1 DO').toBe(1);
      expect(s.archives_do_count, 'A.2: tepat 1 baris arsip DO').toBe(1);
      expect(s.shipment.driver_username, 'A.2 driver = w').toBe(config.get('QA_WAWAN_USER'));
      // § 4 A.2 (K-3 karakterisasi): DO 2, is_active 1, i, [give i], root, sumber SO
      expectArchive(s.archive_do, { status: ARCHIVE.GIVEN, by: config.get('QA_USER'), len: 1, tail: ['give', config.get('QA_USER')], parent: null }, 'A.2 DO');
      expect(s.archive_do.document && s.archive_do.document.source_transaction_no, 'A.2 DO sumber SO').toBe(RUN.created.so.no);
      if (RUN_NOW.has('A.2')) await expectArchiveScreen(i, RUN.created.do.no, 'give', config.get('QA_USER'), 'A2');
    } else monitor.note(`asersi A.2 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.3
  test('[default] ED-1154 AC-4 A.3 w Accept Shipment -> DO Taken oleh w (R-4)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    if (!done('A.3')) {
      const w = await actors.get('w');
      const d = await openRow(w.app, w.page, '/sales/driver/shipments', { id: RUN.created.shipment.id, search: RUN.created.shipment.no });
      const boxes = d.locator('.ant-table-selection-column input[type="checkbox"]');
      for (let k = 0; k < (await boxes.count()); k += 1) await boxes.nth(k).check();
      await w.app.screenshot('A3-driver-drawer');
      const resp = waitApi(w.page, 'PUT', /sales\/shipments\/[^/]+\/confirm$/, 120000);
      await clickReady(d.locator('button.drawer-confirm-btn').first());
      await popOk(w.page, 5000).catch(() => null);
      expectOk(await resp, 'A.3 PUT shipments/{id}/confirm (driver)');
      await waitDb('A.3 shipment accepted (Q)', (x) => x.shipment && x.shipment.status === 3, { queue: true });
      stepLog('A.3', true);
    }
    const s = snapOf('A.3');
    if (s) {
      expect(s.shipment.status, 'AC-4: Shipment 3').toBe(3);
      expectArchive(s.archive_do, { status: ARCHIVE.RECEIVED, by: config.get('QA_WAWAN_USER'), len: 2, tail: ['receive', config.get('QA_WAWAN_USER')], prev: ['give', config.get('QA_USER')], parent: null }, 'A.3 DO');
      if (RUN_NOW.has('A.3')) await expectArchiveScreen(i, RUN.created.do.no, 'receive', config.get('QA_WAWAN_USER'), 'A3');
    } else monitor.note(`asersi A.3 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.4-A.7
  test('[default] ED-1154 AC-5 A.4-A.7 w check-in, Shipment Acceptance penuh tanpa bayar/retur, admin, check-out', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(900000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const w = await actors.get('w');
    const i = await actors.get('i');
    RUN.do_before_a4 = RUN.do_before_a4 || (() => {
      const r = inspect().archive_do;
      return { status: r.status, updated_by: r.updated_by, history_len: r.history_len };
    })();
    writeJournal();
    let s;

    // A.4 check-in w (toko Buka)
    if (!done('A.4')) {
      await checkIn(w, 'A4');
      s = await waitDb('A.4 attendance w', (x) => x.attendance.w.length > 0, { timeout: 60000 });
      RUN.created.attendance.w = { id_attendance: s.attendance.w[0].id_attendance };
      stepLog('A.4', true, RUN.created.attendance.w.id_attendance);
    }

    // A.5 SA (w): impor DO, diterima penuh, tanpa bayar, foto; Simpan (+Konfirmasi bila tampil)
    if (!done('A.5')) {
      if (!RUN.created.sa) {
        await w.app.open('/sales/shipment-acceptances/add', { waitFor: '.ant-collapse' });
        await w.app.settle();
        const cust = trim(await w.page.locator('.ant-select:has(input#idCustomer)').last().innerText());
        if (!/BERKAT/.test(cust)) await pick(w.page, w.page, 'input#idCustomer', 'BERKAT', new RegExp(`${CUSTOMER_CODE}|BERKAT, TK\\.$`)).catch(() => monitor.note('A.5: select Pelanggan SA tidak dipilih manual'));
        await w.page.locator('button, a', { hasText: /Impor Pengantaran Pesanan|Import Delivery Order/ }).first().click();
        await importRows(w.app, w.page, visibleModal(w.page), [RUN.created.do.id], { search: RUN.created.do.no });
        await w.app.screenshot('A5-sa-imported');
        const photoHeader = w.page.locator('.ant-collapse-item', { has: w.page.locator('.ant-collapse-header', { hasText: /^Foto$|^Photo$/ }) }).first();
        if ((await photoHeader.count()) && !/ant-collapse-item-active/.test((await photoHeader.getAttribute('class')) || '')) {
          await photoHeader.locator('.ant-collapse-header').click();
          await w.app.settle();
        }
        const nPhoto = await uploadRequiredPhotos(w.page, w.page.locator('.page-container:visible').last());
        monitor.note(`A.5: ${nPhoto} foto wajib SA diunggah`);
        await w.app.screenshot('A5-sa-form');
        const saResp = waitApi(w.page, 'POST', /sales\/shipment-acceptances$/, 120000);
        await clickReady(w.page.locator('.page-container button.ant-btn-primary:visible', { hasText: /^Simpan$|^Save$/ }).last());
        expectOk(await saResp, 'A.5 POST shipment-acceptances');
        s = await waitDb('A.5 SA tersimpan', (x) => (x.shipment_acceptances || []).length > 0, { timeout: 60000 });
        const SA = s.shipment_acceptances[0];
        RUN.created.sa = { id: SA.id_shipment_acceptance, no: SA.shipment_acceptance_no };
        RUN.sa_after_save = { status: SA.status, acceptance_status: SA.acceptance_status };
        writeJournal();
      }
      const dw = await openRow(w.app, w.page, '/sales/shipment-acceptances', { id: RUN.created.sa.id, search: RUN.created.sa.no }).catch((e) => {
        monitor.note(`A.5: drawer SA w tidak terbuka (${String(e.message).slice(0, 120)})`);
        return null;
      });
      if (dw) {
        await w.app.screenshot('A5-sa-drawer-w');
        // status 4 (No Customer Approval Shipment Acceptance) + Confirm Shipment Acceptance: dropdown "Konfirmasi Transaksi"
        const trigger = dw.locator('.ant-dropdown-trigger').last();
        let items = [];
        const dd = openDropdown(w.page);
        if (await trigger.count()) {
          await trigger.click();
          await dd.waitFor({ state: 'visible', timeout: 10000 }).catch(() => null);
          items = (await dd.locator('li.ant-dropdown-menu-item').allInnerTexts().catch(() => [])).map(trim);
        }
        RUN.sa_drawer_menu_w = items;
        writeJournal();
        if (items.some((t) => /Konfirmasi Transaksi|Confirm Transaction/.test(t))) {
          const r = waitApi(w.page, 'PUT', /sales\/shipment-acceptances\/[^/]+\/confirm$/, 120000);
          await dd.locator('li.ant-dropdown-menu-item', { hasText: /Konfirmasi Transaksi|Confirm Transaction/ }).first().click();
          await popOk(w.page);
          const rr = await r;
          await w.app.settle();
          await w.app.screenshot('A5-sa-confirm-w-result');
          RUN.sa_confirm_w = { status: rr.status, code: rr.json && rr.json.code, message: rr.json && rr.json.message };
          if (rr.status >= 500 && rr.json && rr.json.code === 'GE0114') {
            // D-1: FE menawarkan Konfirmasi ke driver (permission Confirm Shipment Acceptance), BE menolak semua driver
            // (Http/Requests/Sale/ShipmentAcceptance/ConfirmRequest.php:26-32) dengan ErrorMessageException tanpa kode HTTP -> 500
            RUN.defects = [...(RUN.defects || []).filter((d) => d.id !== 'D-1'), { id: 'D-1', step: 'A.5', api: `PUT ${config.safeUrl(rr.url)}`, status: rr.status, code: rr.json.code, message: rr.json.message }];
            monitor.note(`D-1: PUT shipment-acceptances/{id}/confirm (w) -> HTTP ${rr.status} ${rr.json.code} "${rr.json.message}"; konfirmasi diselesaikan i (A.6)`);
          } else {
            expectOk(rr, 'A.5 PUT shipment-acceptances/{id}/confirm (w)');
          }
          writeJournal();
        } else {
          RUN.sa_confirm_w = false;
          monitor.note('A.5: drawer SA w tanpa tombol Konfirmasi');
        }
      }
      stepLog('A.5', true, RUN.created.sa.no);
    }

    // A.6 admin (i): sisa aksi di "Butuh Persetujuan Admin"
    if (!done('A.6')) {
      s = inspect();
      const sa1 = s.shipment_acceptances[0];
      RUN.sa_before_admin = { status: sa1.status, acceptance_status: sa1.acceptance_status };
      if (sa1.status !== 6) {
        const da = await openRow(i.app, i.page, '/sales/shipment-acceptances/need-approvals/admin', { id: RUN.created.sa.id, search: RUN.created.sa.no });
        await i.app.screenshot('A6-sa-admin');
        // status 4 (diterima pelanggan): ShipmentAcceptanceDrawerAction -> dropdown "Konfirmasi Transaksi" (+ popconfirm)
        const trigger = da.locator('.ant-dropdown-trigger').last();
        await trigger.click();
        const dd = openDropdown(i.page);
        await dd.waitFor({ state: 'visible', timeout: 10000 });
        RUN.sa_admin_menu = (await dd.locator('li.ant-dropdown-menu-item').allInnerTexts()).map(trim);
        RUN.sa_admin_action = 'Konfirmasi Transaksi';
        const r = waitApi(i.page, 'PUT', /sales\/shipment-acceptances\/[^/]+\/confirm$/, 120000);
        await dd.locator('li.ant-dropdown-menu-item', { hasText: /Konfirmasi Transaksi|Confirm Transaction/ }).first().click();
        await popOk(i.page);
        const rr = await r;
        RUN.sa_admin_api = `PUT ${config.safeUrl(rr.url)} ${rr.status}`;
        expectOk(rr, 'A.6 PUT shipment-acceptances/{id}/confirm (admin i)');
      } else {
        RUN.sa_admin_action = null;
        monitor.note('A.6: SA sudah 6 sesudah A.5 - tidak ada sisa aksi admin');
      }
      writeJournal();
      await waitDb('A.6 SA 6 + carrier 7', (x) => x.shipment_acceptances[0].status === 6 && x.shipment.can_done, { timeout: 120000 });
      stepLog('A.6', true, RUN.sa_admin_action || 'tanpa aksi admin');
    }

    // A.7 check-out w
    if (!done('A.7')) {
      await checkOut(w, 'A7');
      await waitDb('A.7 attendance w tertutup', (x) => x.attendance.w.length > 0 && x.attendance.w.every((r) => r.check_out), { timeout: 60000 });
      stepLog('A.7', true);
    }

    // AC-5
    s = snapOf('A.7');
    if (s) {
      expect(s.attendance.w.length, 'A.4/A.7: satu attendance w').toBe(1);
      expect(Number(s.attendance.w[0].store_status), 'A.4: toko Buka').toBe(1);
      expect(s.attendance.w[0].check_out, 'A.7: attendance w check-out').toBeTruthy();
      expect(s.shipment_acceptances.length, 'A.5: satu SA').toBe(1);
      expect(s.shipment_acceptances[0].status, 'A.5-A.6: SA 6').toBe(6);
      expect(Number(s.shipment_acceptances[0].is_pay || 0), 'BR-8: SA tanpa bayar').toBe(0);
      expect(s.shipment.carrier_transactions.map((c) => c.status), 'A.6: carrier_transactions 7').toEqual([7]);
      expect(s.shipment.can_done, 'A.6: can_done').toBe(true);
      expect(s.sales_returns_since, 'BR-8: tidak ada Sales Return dari SA').toBe(0);
      expect(s.sales_invoices_since_not_i, 'BR-8: tidak ada SI dari SA').toBe(0);
      expect([s.archive_do.status, s.archive_do.updated_by, s.archive_do.history_len], 'AC-5: DO tidak berubah').toEqual([RUN.do_before_a4.status, RUN.do_before_a4.updated_by, RUN.do_before_a4.history_len]);
    } else monitor.note(`asersi A.7 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.8
  test('[default] ED-1154 AC-6 A.8 i Done Shipment -> DO Taken oleh i (R-9)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    if (!done('A.8')) {
      const d = await openRow(i.app, i.page, '/sales/shipments', { id: RUN.created.shipment.id });
      await i.app.screenshot('A8-shipment-drawer');
      const resp = waitApi(i.page, 'PUT', /sales\/shipments\/[^/]+\/done$/, 60000);
      await menuPick(i.page, d.locator('.ant-dropdown-trigger').last(), /Selesaikan Pengiriman|Done Shipment|Selesai/);
      await popOk(i.page);
      expectOk(await resp, 'A.8 PUT shipments/{id}/done');
      await waitDb('A.8 shipment done', (x) => x.shipment.status === 5, { timeout: 60000 });
      stepLog('A.8', true);
    }
    const s = snapOf('A.8');
    if (s) {
      expect(s.shipment.status, 'AC-6: Shipment 5').toBe(5);
      expectArchive(s.archive_do, { status: ARCHIVE.RECEIVED, by: config.get('QA_USER'), len: 4, tail: ['receive', config.get('QA_USER')], prev: ['give', config.get('QA_WAWAN_USER')], parent: null }, 'A.8 DO');
      if (RUN_NOW.has('A.8')) await expectArchiveScreen(i, RUN.created.do.no, 'receive', config.get('QA_USER'), 'A8');
    } else monitor.note(`asersi A.8 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.9-A.10
  test('[default] ED-1154 AC-7 A.9-A.10 SI dari DO (Impor Pengantaran Pesanan) + Billing hanya SI itu -> SI lahir (karakterisasi K-3)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(900000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    RUN.do_before_a9 = RUN.do_before_a9 || (() => {
      const r = inspect().archive_do;
      return { status: r.status, history_len: r.history_len };
    })();
    let s;

    // A.9 SI (i): Tambah -> Action -> Impor Pengantaran Pesanan (DO run ini; customer + salesman dari SO) -> Simpan & Konfirmasi (Q)
    if (!done('A.9')) {
      await i.app.open('/sales/sales-invoices', { waitFor: '.ant-table' });
      await addBtn(i.page).click();
      await i.page.locator('.page-container input#idLocation').first().waitFor({ state: 'visible', timeout: 45000 });
      await i.app.settle();
      await pick(i.page, i.page.locator('.page-container'), 'input#idLocation', 'Semarang', /Semarang/);
      await i.app.settle();
      // S-2 (QA r2): daftar impor kosong sampai Pelanggan dipilih (select sales-orders SI memfilter id_customer,
      // SelectSalesService.php:772-776); lalu modal minta "Pilih pesanan penjualan" sebelum menampilkan DO
      await pick(i.page, i.page.locator('.page-container'), 'input#idCustomer', CUSTOMER_CODE, new RegExp(CUSTOMER_CODE));
      await i.app.settle();
      // PanelMultiTrans ActionButton: Typography.Link "Action ⌄" -> menu berisi tombol (Salin / Impor Pengantaran Pesanan)
      const action = i.page.locator('.page-container:visible .ant-collapse-header .ant-dropdown-trigger', { hasText: /Action|Aksi/ }).first();
      await action.click();
      const amenu = openDropdown(i.page);
      await amenu.waitFor({ state: 'visible', timeout: 10000 });
      RUN.si_action_menu = (await amenu.locator('li.ant-dropdown-menu-item').allInnerTexts()).map(trim);
      await amenu.locator('li.ant-dropdown-menu-item button', { hasText: /Impor Pengantaran Pesanan|Import Delivery Order/ }).first().click();
      const im = visibleModal(i.page);
      await im.waitFor({ state: 'visible', timeout: 20000 });
      await i.app.settle();
      await i.app.screenshot('A9-si-import-modal');
      RUN.si_import_hint = trim(await im.locator('.ant-alert').first().innerText().catch(() => ''));
      await importRows(i.app, i.page, im, [RUN.created.do.id], { parent: { search: RUN.created.so.no, re: new RegExp(esc(RUN.created.so.no)) } });
      await i.app.screenshot('A9-si-form');
      const cust = trim(await i.page.locator('.page-container .ant-select:has(input#idCustomer)').last().innerText());
      RUN.si_form_customer = cust;
      expect(cust, 'A.9: Pelanggan SI terisi dari SO').toMatch(/BERKAT/);
      writeJournal();
      const siResp = waitApi(i.page, 'POST', /sales\/sales-invoices\/save-and-confirm$/, 120000);
      await menuPick(i.page, i.page.locator('.page-container .ant-dropdown-trigger').last(), /Simpan & Konfirmasi|Save & Confirm/);
      await popOk(i.page, 5000).catch(() => null);
      const sr = await siResp;
      RUN.si_save_and_confirm = { status: sr.status, code: sr.json && sr.json.code, message: config.redact(String((sr.json && sr.json.message) || '')).slice(0, 240) };
      writeJournal();
      if (sr.status >= 500 && /sales_invoice_serials/.test(String(sr.json && sr.json.message))) {
        // D-2: QueueService::persistDraftForConfirm (QueueService.php:329) meneruskan request mentah ke SalesInvoiceService::store
        // (controller store memakai validated()); FE mengirim salesInvoiceDetails[].salesInvoiceSerials -> kolom tak dikenal -> 500.
        // Lanjut lewat jalur layar lain yang ada: Simpan, lalu Konfirmasi di drawer (Q).
        RUN.defects = [...(RUN.defects || []).filter((d) => d.id !== 'D-2'), { id: 'D-2', step: 'A.9', api: `POST ${config.safeUrl(sr.url)}`, status: sr.status, message: RUN.si_save_and_confirm.message }];
        monitor.note(`D-2: POST sales-invoices/save-and-confirm -> HTTP ${sr.status} (Unknown column sales_invoice_serials); lanjut Simpan + Konfirmasi drawer`);
        await i.app.settle();
        await i.app.screenshot('A9-si-save-and-confirm-500');
        const stResp = waitApi(i.page, 'POST', /sales\/sales-invoices$/, 120000);
        await clickReady(i.page.locator('.page-container:visible button.ant-btn-primary', { hasText: /^Simpan$|^Save$/ }).last());
        const st = expectOk(await stResp, 'A.9 POST sales-invoices (Simpan, jalur pengganti D-2)');
        const idSi = st && (st.idSalesInvoice || st.id_sales_invoice);
        RUN.created.si = { id: idSi };
        RUN.si_path = 'Simpan + Konfirmasi drawer (D-2)';
        writeJournal();
        await i.app.settle();
        const ds = await openRow(i.app, i.page, '/sales/sales-invoices', { id: idSi });
        await i.app.screenshot('A9-si-drawer');
        const cr = waitApi(i.page, 'PUT', /sales\/sales-invoices\/[^/]+\/confirm$/, 120000);
        await clickReady(ds.locator('button.drawer-confirm-btn').first());
        await popOk(i.page);
        expectOk(await cr, 'A.9 PUT sales-invoices/{id}/confirm (Q)');
      } else {
        expectOk(sr, 'A.9 POST sales-invoices/save-and-confirm');
        RUN.si_path = 'Simpan & Konfirmasi';
      }
      s = await waitDb('A.9 SI processed (Q)', (x) => (x.sales_invoices || []).length > 0 && x.sales_invoices[0].status === 2, { queue: true });
      const SI = s.sales_invoices[0];
      RUN.created.si = { id: SI.id_sales_invoice, no: SI.sales_invoice_no };
      stepLog('A.9', true, SI.sales_invoice_no);
      expect(s.archive_si, 'A.9/BR-3: SI belum punya baris arsip').toBeNull();
    }
    s = snapOf('A.9');
    if (s) {
      expect(s.sales_invoices.length, 'A.9/BR-7: 1 SI dari DO').toBe(1);
      expect(s.sales_invoices[0].id_salesman, 'A.9 salesman SI = salesman SO (a)').toBe(s.so.id_salesman);

    } else monitor.note(`asersi A.9 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    // A.10 Billing (i): salesman a, impor HANYA SI run ini, Simpan & Konfirmasi
    if (!done('A.10')) {
      await i.app.open('/sales/billings', { waitFor: '.ant-table' });
      await addBtn(i.page).click();
      await i.page.locator('input#idEmployee').first().waitFor({ state: 'visible', timeout: 45000 });
      await i.app.settle();
      await pick(i.page, i.page, 'input#idEmployee', 'Adi', /\bAdi\b/);
      await i.app.settle();
      await i.page.locator('.page-container button', { hasText: /^Impor$|^Import$/ }).first().click();
      await importRows(i.app, i.page, visibleModal(i.page), [RUN.created.si.id], { search: RUN.created.si.no });
      await i.app.screenshot('A10-billing-form');
      const bResp = waitApi(i.page, 'POST', /sales\/billings\/save-and-confirm$/, 120000);
      await menuPick(i.page, i.page.locator('.page-container .ant-dropdown-trigger').last(), /Simpan & Konfirmasi|Save & Confirm/);
      await popOk(i.page, 5000).catch(() => null);
      expectOk(await bResp, 'A.10 POST billings/save-and-confirm');
      s = await waitDb('A.10 billing confirmed', (x) => (x.billings || []).length > 0 && x.billings[0].status === 2 && x.archive_si, { timeout: 120000 });
      const B = s.billings[0];
      RUN.created.billing = { id: B.id_billing, no: B.billing_no };
      stepLog('A.10', true, B.billing_no);
    }
    s = snapOf('A.10');
    if (s) {
      expect(s.billings.length, 'A.10: satu Billing berisi SI').toBe(1);
      expect(s.billings[0].status, 'AC-7: Billing 2').toBe(2);
      expect(s.billings[0].transactions.map((t) => t.transaction_no), 'A.10/BR-7: Billing hanya berisi SI run ini').toEqual([RUN.created.si.no]);
      expect(s.archives_si_count, 'A.10: tepat 1 baris arsip SI').toBe(1);
      expectArchive(s.archive_si, { status: ARCHIVE.GIVEN, by: config.get('QA_USER'), len: 1, tail: ['give', config.get('QA_USER')] }, 'A.10 SI');
      expect([s.archive_do.status, s.archive_do.history_len], 'A.10: arsip DO tidak berubah').toEqual([RUN.do_before_a9.status, RUN.do_before_a9.history_len]);
      if (RUN_NOW.has('A.10')) await expectArchiveScreen(i, RUN.created.si.no, 'give', config.get('QA_USER'), 'A10');
    } else monitor.note(`asersi A.10 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.11
  test('[default] ED-1154 AC-8 A.11 a Accept Billing (semua match) -> SI Taken oleh a (R-12)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    if (!done('A.11')) {
      const a = await actors.get('a');
      const d = await openRow(a.app, a.page, '/sales/billings', { id: RUN.created.billing.id, search: RUN.created.billing.no });
      const matches = d.locator('.ant-radio-button-wrapper', { hasText: /^Match$|^Cocok$|^Sesuai$/ });
      for (let k = 0; k < (await matches.count()); k += 1) await matches.nth(k).click();
      await a.app.screenshot('A11-billing-match');
      const resp = waitApi(a.page, 'PUT', /sales\/billings\/[^/]+\/confirm$/, 60000);
      await d.locator('button.ant-btn-primary, button.drawer-confirm-btn', { hasText: /^Confirm$|^Konfirmasi$|^Accept$|^Terima$/ }).first().click();
      await popOk(a.page, 5000).catch(() => null);
      expectOk(await resp, 'A.11 PUT billings/{id}/confirm (accept)');
      await waitDb('A.11 billing 6', (x) => x.billings[0].status === 6, { timeout: 60000 });
      stepLog('A.11', true);
    }
    const s = snapOf('A.11');
    if (s) {
      expect(s.billings[0].status, 'AC-8: Billing 6').toBe(6);
      expect(s.billings[0].transactions.every((t) => Number(t.is_match) === 1), 'BR-9: semua transaksi match').toBe(true);
      expectArchive(s.archive_si, { status: ARCHIVE.RECEIVED, by: config.get('QA_USER2'), len: 2, tail: ['receive', config.get('QA_USER2')], prev: ['give', config.get('QA_USER')] }, 'A.11 SI');
      if (RUN_NOW.has('A.11')) await expectArchiveScreen(i, RUN.created.si.no, 'receive', config.get('QA_USER2'), 'A11');
    } else monitor.note(`asersi A.11 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.12-A.13
  test('[default] ED-1154 AC-9 A.12-A.13 a check-in + RP atas SI; i approve + confirm (K-2 b)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(900000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const a = await actors.get('a');
    const i = await actors.get('i');
    RUN.si_before_a12 = RUN.si_before_a12 || (() => {
      const r = inspect().archive_si;
      return { status: r.status, updated_by: r.updated_by, history_len: r.history_len };
    })();
    let s;

    if (!done('A.12')) {
      await checkIn(a, 'A12');
      s = await waitDb('A.12 attendance a', (x) => x.attendance.a.length > 0, { timeout: 60000 });
      RUN.created.attendance.a = { id_attendance: s.attendance.a[0].id_attendance };
      stepLog('A.12', true, RUN.created.attendance.a.id_attendance);
    }

    // A.13a RP (a): customer dari check-in, faktur SI run ini, bayar penuh, foto, Save (PENDING)
    if (!RUN.created.rp) {
      await a.app.open('/salesman/financials/receivable-payments/all', { waitFor: '.ant-table' });
      await addBtn(a.page).click();
      await a.page.locator('.page-container input#idCustomer').first().waitFor({ state: 'visible', timeout: 45000 });
      await a.app.settle();
      // Pelanggan terisi dari check-in A.12 (select nonaktif); pilih manual hanya bila kosong dan aktif
      const custSel = a.page.locator('.page-container:visible .ant-select:has(input#idCustomer)').last();
      const filled = await expect(custSel).toContainText(/BERKAT/, { timeout: 20000 }).then(() => true).catch(() => false);
      if (!filled) await pick(a.page, a.page.locator('.page-container:visible'), 'input#idCustomer', 'BERKAT', new RegExp(`${CUSTOMER_CODE}|BERKAT, TK\\.$`));
      RUN.rp_customer_from_checkin = filled;
      await a.app.settle();
      // faktur: "Import Sales Invoice" (ModalImport, rowKey idSalesInvoice) -> SI run ini
      await a.page.locator('.page-container:visible button', { hasText: /Import Sales Invoice|Impor Faktur Penjualan/ }).first().click();
      await importRows(a.app, a.page, visibleModal(a.page), [RUN.created.si.id], { search: RUN.created.si.no });
      await a.app.screenshot('A13-rp-invoice');
      // pembayaran: Add Payment -> kategori Tunai/Cash + kas/bank pertama, jumlah bawaan (sisa tagihan)
      const pay = a.page.locator('.ant-collapse-item', { has: a.page.locator('.ant-collapse-header', { hasText: /^Payment$|^Pembayaran$/ }) }).last();
      if (!/ant-collapse-item-active/.test((await pay.getAttribute('class')) || '')) {
        await pay.locator('.ant-collapse-header').click();
        await a.app.settle();
      }
      // Cash Flow Type wajib (ReceivablePaymentPaymentDetails: idCashFlowType) -> opsi pertama
      await pick(a.page, pay, 'input#idCashFlowType', '', /./);
      RUN.rp_cash_flow_type = trim(await pay.locator('.ant-select:has(input#idCashFlowType)').first().innerText());
      await a.app.settle();
      await pay.locator('button', { hasText: /Add Payment|Tambah Pembayaran/ }).first().click();
      const pm = a.page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal, .ant-drawer-open .ant-drawer-content').last();
      await pm.waitFor({ state: 'visible', timeout: 20000 });
      await a.app.settle();
      await pick(a.page, pm, 'input[id$="idPaymentCategory"]', '', /Tunai|Cash/);
      await a.app.settle();
      if (await pm.locator('input[id$="idCashBank"]').count()) await pick(a.page, pm, 'input[id$="idCashBank"]', '', /./);
      await a.app.settle();
      await a.app.screenshot('A13-rp-payment-modal');
      RUN.rp_payment_amount = await pm.locator('input[id$="currencyAmount"]').first().inputValue().catch(() => null);
      await clickReady(pm.locator('button.ant-btn-primary', { hasText: /^Simpan$|^Save$|^OK$|^Add$|^Tambah$/ }).last());
      await a.app.settle();
      const photo = a.page.locator('.ant-collapse-item', { has: a.page.locator('.ant-collapse-header', { hasText: /^Photo$|^Foto$/ }) }).first();
      if ((await photo.count()) && !/ant-collapse-item-active/.test((await photo.getAttribute('class')) || '')) {
        await photo.locator('.ant-collapse-header').click();
        await a.app.settle();
      }
      const nPhoto = await uploadRequiredPhotos(a.page, a.page.locator('.page-container:visible').last());
      monitor.note(`A.13: ${nPhoto} foto wajib RP diunggah`);
      await a.app.screenshot('A13-rp-form');
      const rpResp = waitApi(a.page, 'POST', /financial\/receivable-payments$/, 120000);
      await clickReady(a.page.locator('.page-container button.ant-btn-primary:visible', { hasText: /^Save$|^Simpan$/ }).last());
      expectOk(await rpResp, 'A.13 POST receivable-payments (a)');
      s = await waitDb('A.13 RP tersimpan', (x) => (x.receivable_payments || []).length > 0, { timeout: 60000 });
      const RP = s.receivable_payments[0];
      RUN.created.rp = { id: RP.id_receivable_payment, no: RP.receivable_payment_no };
      RUN.rp_after_save = { status: RP.status, customer_approval_status: RP.customer_approval_status };
      writeJournal();
      stepLog('A.13a', true, `RP ${RP.id_receivable_payment} status ${RP.status}`);
    }

    // A.13b i: approve (bila diminta) + confirm di /financials/receivable-payments
    if (!done('A.13')) {
      const d = await openRow(i.app, i.page, '/financials/receivable-payments', { id: RUN.created.rp.id });
      await i.app.screenshot('A13-rp-drawer-i');
      const approve = d.locator('.ant-alert button.ant-btn-primary', { hasText: /^Setujui$|^Approve$/ }).first();
      if (await approve.count()) {
        const ar = waitApi(i.page, 'PUT', /approve$/, 60000);
        await approve.click();
        await popOk(i.page, 5000).catch(() => null);
        const arr = await ar;
        expectOk(arr, 'A.13 approve RP (i)');
        recordApproval('A.13', 'RECEIVABLE_PAYMENT customer (approve)', RUN.created.rp.id, RUN.created.rp.no, [{ username: config.get('QA_USER'), api: config.safeUrl(arr.url) }]);
        await i.app.settle();
      }
      const d2 = await openRow(i.app, i.page, '/financials/receivable-payments', { id: RUN.created.rp.id });
      const resp = waitApi(i.page, 'PUT', /financial\/receivable-payments\/[^/]+\/confirm$/, 120000);
      await i.app.settle();
      // tombol Konfirmasi loading selama kalkulasi checkout; klik saat loading ditelan antd
      await clickReady(d2.locator('button.drawer-confirm-btn').first());
      await popOk(i.page);
      expectOk(await resp, 'A.13 PUT receivable-payments/{id}/confirm (i)');
      s = await waitDb('A.13 RP processed', (x) => x.receivable_payments[0].status === 2, { timeout: 120000 });
      RUN.created.rp.no = s.receivable_payments[0].receivable_payment_no;
      stepLog('A.13', true, RUN.created.rp.no);
    }
    s = snapOf('A.13');
    if (s) {
      expect(s.receivable_payments[0].created_by, 'K-2: RP dibuat a').toBe(config.get('QA_USER2'));
      expect(s.receivable_payments[0].processed_by, 'K-2: RP dikonfirmasi i').toBe(config.get('QA_USER'));
      expect(s.attendance.a.length, 'A.12: satu attendance a').toBe(1);
      expect([s.archive_si.status, s.archive_si.updated_by, s.archive_si.history_len], 'AC-9: SI tidak berubah').toEqual([RUN.si_before_a12.status, RUN.si_before_a12.updated_by, RUN.si_before_a12.history_len]);
    } else monitor.note(`asersi A.13 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.14
  test('[default] ED-1154 AC-10 A.14 a check-out + request close Billing -> SI Handed Over oleh a (K-3)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const a = await actors.get('a');
    const i = await actors.get('i');
    if (!done('A.14a')) {
      await checkOut(a, 'A14');
      await waitDb('A.14 attendance a tertutup', (x) => x.attendance.a.length > 0 && x.attendance.a.every((r) => r.check_out), { timeout: 60000 });
      stepLog('A.14a', true);
    }
    if (!done('A.14')) {
      const d = await openRow(a.app, a.page, '/sales/billings', { id: RUN.created.billing.id, search: RUN.created.billing.no });
      await a.app.screenshot('A14-billing-drawer');
      await d.locator('button.ant-btn-primary', { hasText: /^Close$|^Tutup$/ }).first().click();
      const res = a.page.locator('.ant-drawer-open .ant-result, .ant-modal-wrap:not([style*="display: none"]) .ant-result').last();
      await res.waitFor({ state: 'visible', timeout: 15000 });
      await a.app.screenshot('A14-billing-close-result');
      const resp = waitApi(a.page, 'PUT', /sales\/billings\/[^/]+\/done$/, 60000);
      await res.locator('button.ant-btn-primary', { hasText: /^Close$|^Tutup$/ }).click();
      expectOk(await resp, 'A.14 PUT billings/{id}/done (request close)');
      await waitDb('A.14 billing 7', (x) => x.billings[0].status === 7, { timeout: 60000 });
      stepLog('A.14', true);
    }
    const s = snapOf('A.14');
    if (s) {
      expect(s.billings[0].status, 'AC-10: Billing 7').toBe(7);
      expectArchive(s.archive_si, { status: ARCHIVE.GIVEN, by: config.get('QA_USER2'), len: 3, tail: ['give', config.get('QA_USER2')] }, 'A.14 SI');
      if (RUN_NOW.has('A.14')) await expectArchiveScreen(i, RUN.created.si.no, 'give', config.get('QA_USER2'), 'A14');
    } else monitor.note(`asersi A.14 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ A.15
  test('[default] ED-1154 AC-11 A.15 i close Billing -> SI Taken oleh i (R-16)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    if (!done('A.15')) {
      const d = await openRow(i.app, i.page, '/sales/billings', { id: RUN.created.billing.id, search: RUN.created.billing.no });
      await i.app.screenshot('A15-billing-drawer');
      const resp = waitApi(i.page, 'PUT', /sales\/billings\/[^/]+\/done$/, 60000);
      await d.locator('button', { hasText: /^Tutup$|^Close$/ }).first().click();
      await popOk(i.page);
      expectOk(await resp, 'A.15 PUT billings/{id}/done (close)');
      await waitDb('A.15 billing 8', (x) => x.billings[0].status === 8, { timeout: 60000 });
      stepLog('A.15', true);
    }
    const s = snapOf('A.15');
    if (s) {
      expect(s.billings[0].status, 'AC-11: Billing 8').toBe(8);
      expectArchive(s.archive_si, { status: ARCHIVE.RECEIVED, by: config.get('QA_USER'), len: 4, tail: ['receive', config.get('QA_USER')], prev: ['give', config.get('QA_USER2')] }, 'A.15 SI');
      if (RUN_NOW.has('A.15')) await expectArchiveScreen(i, RUN.created.si.no, 'receive', config.get('QA_USER'), 'A15');
    } else monitor.note(`asersi A.15 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ B
  test('[default] ED-1154 AC-12 B folder F di root (semua lokasi) + Store DO lalu SI -> status 1 di F (R-17, R-18)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    const F = RUN.folder.name;
    let s;

    if (!done('B.F')) {
      await archiveOpen(i);
      const m = await archivePlus(i, /Tambah Folder|Add Folder/);
      await m.locator('input#name').fill(F);
      await m.locator('.ant-radio-wrapper, .ant-radio-button-wrapper', { hasText: /Semua Lokasi|All Location/ }).first().click();
      await i.app.screenshot('B-folder-modal');
      const cr = waitApi(i.page, 'POST', /document-archive\/archives\/create-folder$/);
      await clickReady(m.locator('button.ant-btn-primary', { hasText: /^Simpan$|^Save$/ }).first());
      expectOk(await cr, 'B POST create-folder');
      await i.app.settle();
      s = await waitDb('B folder F ada', (x) => x.folder && x.folder.id_archive, { timeout: 30000 });
      RUN.created.folder = { id_archive: s.folder.id_archive, name: F };
      stepLog('B.F', true, F);
    }
    s = snapOf('B.F');
    if (s) {
      expect(s.folder.id_parent, 'B: F di root').toBeNull();
      expect(s.folder.is_all_location, 'B/BR-10: F semua lokasi').toBe(1);

    } else monitor.note(`asersi B.F dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    // buka F, Store Document DO lalu SI (modal dibuka ulang per dokumen, tanpa Kembali - ED-1153)
    for (const [k, doc] of [['B.DO', RUN.created.do.no], ['B.SI', RUN.created.si.no]]) {
      if (done(k)) continue;
      await archiveOpen(i);
      const row = i.page.locator('.archive-page .ant-table-row', { hasText: F }).first();
      await row.waitFor({ state: 'visible', timeout: 20000 });
      await row.locator('td.cursor-pointer').first().click();
      await i.app.settle();
      await archiveScanAction(i, /Letakkan Dokumen|Store Document/, doc, /^Simpan$|^Save$/, /document-archive\/documents\/put-in$/, `B-${doc.slice(0, 2)}`);
      stepLog(k, true, doc);
    }
    if (!done('B')) stepLog('B', true, F);
    s = snapOf('B');
    if (s) {
      const idF = RUN.created.folder.id_archive;
      expectArchive(s.archive_do, { status: ARCHIVE.IN_FOLDER, by: config.get('QA_USER'), len: 5, tail: ['place', config.get('QA_USER')], parent: idF }, 'B DO');
      expectArchive(s.archive_si, { status: ARCHIVE.IN_FOLDER, by: config.get('QA_USER'), len: 5, tail: ['place', config.get('QA_USER')], parent: idF }, 'B SI');
      expect(s.folder_children, 'B: F berisi tepat DO dan SI').toEqual([RUN.created.do.no, RUN.created.si.no].sort());
      await i.app.screenshot('B-isi-folder-F');
      // root tidak lagi menampilkan DO/SI
      await archiveOpen(i);
      const rootText = await i.page.locator('.archive-page .ant-table').innerText();
      expect(rootText, 'B: root tidak menampilkan DO').not.toContain(RUN.created.do.no);
      expect(rootText, 'B: root tidak menampilkan SI').not.toContain(RUN.created.si.no);
      if (RUN_NOW.has('B')) await expectArchiveScreen(i, RUN.created.do.no, 'place', config.get('QA_USER'), 'B-DO');
    } else monitor.note(`asersi B dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ C.2-C.7
  test('[default] ED-1154 AC-13 C.2-C.7 serah-terima manual DO i -> a -> i, induk tetap F (R-19..R-22)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(900000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    const a = await actors.get('a');
    const DO = RUN.created.do.no;
    const idF = RUN.created.folder.id_archive;
    const steps = [
      ['C.2', i, /Serahkan Dokumen|Hand Over Document/, /^Serahkan$|^Hand Over$/, /hand-over$/, ARCHIVE.GIVEN, 'give', config.get('QA_USER'), 6],
      ['C.4', a, /Terima Dokumen|Receive Document/, /^Terima$|^Receive$/, /receive$/, ARCHIVE.RECEIVED, 'receive', config.get('QA_USER2'), 7],
      ['C.5', a, /Serahkan Dokumen|Hand Over Document/, /^Serahkan$|^Hand Over$/, /hand-over$/, ARCHIVE.GIVEN, 'give', config.get('QA_USER2'), 8],
      ['C.7', i, /Terima Dokumen|Receive Document/, /^Terima$|^Receive$/, /receive$/, ARCHIVE.RECEIVED, 'receive', config.get('QA_USER'), 9],
    ];
    for (const [label, who, menuRe, okRe, apiRe, status, action, user, len] of steps) {
      if (done(label)) continue;
      await archiveOpen(who);
      const { resp, selects } = await archiveScanAction(who, menuRe, DO, okRe, new RegExp(`document-archive/documents/${apiRe.source}`), label);
      if (action === 'give') {
        // BR-4 / AC-13: dialog Hand Over tanpa pilihan penerima; body POST hanya `name`
        RUN[`${label}_body`] = Object.keys(resp.body || {}).sort();
        expect(selects, `${label}: modal Hand Over tanpa select penerima`).toBe(0);
        expect(Object.keys(resp.body || {}).sort(), `${label}: body POST hand-over`).toEqual(['name']);
      }
      stepLog(label, true);
      const s = snapOf(label);
      expectArchive(s.archive_do, { status, by: user, len, tail: [action, user], parent: idF }, `${label} DO`);
      await expectArchiveScreen(i, DO, action, user, label.replace('.', ''));
    }
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ C.8
  test('[default] ED-1154 AC-14 C.8 Store DO ke F tempat ia berada -> status 1, history move F->F (K-4 karakterisasi)', async ({ browser, baseURL, app, page, monitor }, testInfo) => {
    test.setTimeout(600000);
    const actors = new Actors({ browser, baseURL, testInfo, app, page, monitor, geo: RUN.geo });
    const i = await actors.get('i');
    const F = RUN.folder.name;
    if (!done('C.8')) {
      await archiveOpen(i);
      const row = i.page.locator('.archive-page .ant-table-row', { hasText: F }).first();
      await row.waitFor({ state: 'visible', timeout: 20000 });
      await row.locator('td.cursor-pointer').first().click();
      await i.app.settle();
      await archiveScanAction(i, /Letakkan Dokumen|Store Document/, RUN.created.do.no, /^Simpan$|^Save$/, /document-archive\/documents\/put-in$/, 'C8');
      stepLog('C.8', true);
    }
    const s = snapOf('C.8');
    if (s) {
      expectArchive(s.archive_do, { status: ARCHIVE.IN_FOLDER, by: config.get('QA_USER'), len: 10, tail: ['move', config.get('QA_USER')], parent: RUN.created.folder.id_archive }, 'C.8 DO');
      expect([s.archive_do.tail.from_folder, s.archive_do.tail.to_folder], 'K-4 a: move F -> F').toEqual([F, F]);
      if (RUN_NOW.has('C.8')) await expectArchiveScreen(i, RUN.created.do.no, 'move', config.get('QA_USER'), 'C8');
    } else monitor.note(`asersi C.8 dilewati: langkah selesai di percobaan sebelumnya tanpa snapshot (resume)`);
    await actors.finish();
  });

  // ------------------------------------------------------------------------------------------------ AC-15 / AC-16
  test('[default] ED-1154 AC-15 AC-16 jurnal lengkap, arsip berubah = {DO, SI, F}, run sebelumnya tidak berubah', async ({ monitor }) => {
    test.setTimeout(300000);
    count5xx('i', monitor); // test ini tanpa browser; tetap dihitung sebelum asersi 0 5xx
    // rantai A -> C lengkap (semua test sebelumnya lulus di describe.serial): jurnal = complete, dasar AC-16 run berikutnya
    RUN.status = 'complete';
    RUN.completed_at = new Date().toISOString();
    const ch = fx('changed', RUN.runId);
    const names = ch.rows.map((r) => r.name).sort();
    RUN.archives_changed = ch.rows;
    writeJournal();
    expect.soft(names, 'AC-15: archives yang berubah selama run = tepat {DO, SI, F}').toEqual([RUN.created.do.no, RUN.created.si.no, RUN.folder.name].sort());
    for (const k of ['so', 'shipment', 'do', 'sa', 'si', 'billing', 'rp', 'folder']) {
      expect.soft(RUN.created[k] && (RUN.created[k].no || RUN.created[k].name), `AC-15: jurnal memuat ${k}`).toBeTruthy();
    }
    expect.soft(RUN.created.attendance.w && RUN.created.attendance.a, 'AC-15: jurnal memuat attendance w dan a').toBeTruthy();
    const mods = (RUN.created.approvals || []).map((x) => `${x.step} ${x.module}`);
    expect.soft(mods, 'AC-15: jurnal memuat approval SO (A.1)').toContain('A.1 SALES_ORDER');
    if (RUN.need_a0) expect.soft(mods, 'AC-15: jurnal memuat approval customer (A.0)').toContain('A.0 CUSTOMER');
    const known5 = RUN.http5xx_known || [];
    const unknown5 = RUN.http5xx_unknown || [];
    monitor.note(`AC-15: 5xx total ${RUN.http5xx || 0}; bertiket ${known5.length} [${known5.map((x) => `${x.ref} ${x.id}: ${x.line}`).join(' ; ') || '-'}]; tidak bertiket ${unknown5.length} [${unknown5.join(' ; ') || '-'}]; defect: ${(RUN.defects || []).map((d) => `${d.id} ${d.status} ${d.api}`).join(' ; ') || '-'}`);
    expect.soft(unknown5, 'AC-15: 0 respons 5xx di luar defect bertiket (ED-1168 D-1, ED-1169 D-2)').toEqual([]);
    expect.soft((RUN.http5xx || 0) - known5.length, 'AC-15: setiap 5xx tercatat bertiket atau tidak bertiket').toBe(unknown5.length);
    const after = fx('previous', RUN.runId).previous;
    RUN.previous_after = after;
    writeJournal();
    expect.soft(after, 'AC-16: arsip + Billing run sebelumnya (dari jurnal) tidak berubah').toEqual(RUN.previous_before);
    monitor.note(`AC-16: ${Object.keys(after).length} run sebelumnya dibandingkan: ${Object.keys(after).join(', ') || '-'}`);
  });
});
