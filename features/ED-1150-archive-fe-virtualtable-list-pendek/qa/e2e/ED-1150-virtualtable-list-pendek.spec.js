'use strict';

/**
 * ED-1150 - Archive FE: baris folder terpotong/hilang sesudah navigasi (klik nama folder / breadcrumb / paging / cari) pada daftar
 * pendek, karena satu instance VirtualTable dipakai untuk semua daftar (konteks virtualisasi membawa tinggi/offset daftar lama).
 *
 * Profil: [sam] = QA_USER3 (K-4 a), data ASLI sam di api_sidomaju, jalankan lewat `qa/e2e/run-sam.sh` (memetakan profil sam ke
 * QA_USER3/QA_PASS3 di env proses). TIDAK ADA fixture dan TIDAK ADA tulis DB: semua flow non-mutasi (modal Move/Bulk Move selalu
 * ditutup lewat X, tidak ada tombol simpan; tiap test menjaga tidak ada request non-GET ke /document-archive/).
 * Prasyarat data (dicek ulang tiap test dari respons jaringan; tidak terpenuhi = test SKIP "BLOCKED-by-data", bukan GAGAL):
 *   root = 3 baris termasuk Folder A; Folder A = 2 baris (Folder B, Folder C); Folder B >= 10 baris (398 dokumen).
 *
 * PEMBANDING tiap langkah = `result.data` respons list (GET document-archive/archives) TERAKHIR yang ditangkap dari jaringan
 * (bukan nilai hardcode; id folder diambil dari respons). Tiap langkah dibaca dari DOM:
 *   - DOM     : jumlah `tr.ant-table-row` dan nama barisnya (urut) = respons (daftar pendek; dokumen/related transaction diratakan
 *               seperti `flattenArchives`). Daftar lebih tinggi dari scroll.y (mis. 20 baris) memang tervirtualisasi: yang dibandingkan
 *               potongan berurutan dari baris pertama (scrollTop 0) dan wadah tidak boleh terpotong.
 *   - TERLIHAT: baris benar-benar tampil di wadah scroll `.ant-table-body` (>= 50% tinggi baris di dalam area terlihat) dan tinggi wadah
 *               tidak lebih kecil dari isinya (clientHeight >= min(max-height, scrollHeight)). Ini yang dilaporkan developer
 *               ("hanya Folder B", "Folder A hilang"): di baseline baris tetap ada di DOM tetapi wadah VirtualTable memakai tinggi
 *               daftar sebelumnya (inline `height`/`max-height` basi) sehingga baris terakhir terpotong. Hitungan DOM saja tidak cukup.
 * Dua penilaian per langkah dicatat terpisah ("literal" = bunyi AC-1, "terlihat" = baris tampil di layar); langkah lulus bila keduanya OK.
 * Tanpa scroll dan tanpa reload di langkah daftar pendek (klik pada baris yang terpotong memakai dispatchEvent, bukan scroll Playwright).
 * Tiap langkah: keadaan sesaat sesudah settle + keadaan akhir (polling <= 4 dtk) -> `steps-ED-1150.log` di folder run.
 *
 * Jalankan dengan 1 worker: features/ED-1150-.../qa/e2e/run-sam.sh
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('e2e-harness');

const PATH = '/archives';
const LIST_PATH_RE = /\/document-archive\/archives$/;
const WRITE_RE = /\/document-archive\//;
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const nameRe = (name) => new RegExp(`(^|\\s)${esc(name)}(?![\\w-])`);
const PAGE_ROWS = '.archive-page tr.ant-table-row:not(.ant-modal-wrap *)';
const MODAL_ROWS = '.ant-modal-wrap:not([style*="display: none"]) tr.ant-table-row';
const STABLE_MS = 4000;

const nameOf = (r) => {
  if (!r) return '';
  if (Array.isArray(r.name)) return String((r.name[0] || {}).transaction_no || '');
  return String(r.name || r.transaction_no || '');
};

function logLine(line) {
  fs.appendFileSync(path.join(process.env.E2E_OUT_DIR, 'steps-ED-1150.log'), `${line}\n`);
}

/** Pembanding: baris respons list (diratakan seperti FE flattenArchives). */
function expectedNames(resp) {
  const out = [];
  ((resp.body && resp.body.result && resp.body.result.data) || []).forEach((item) => {
    out.push(nameOf(item));
    (item.related_transactions || []).forEach((rt) => out.push(nameOf(rt)));
  });
  return out;
}

/** Konteks test: perekam respons list + penjaga tulis + helper langkah. */
function createCtx({ page, app, monitor }, ac) {
  const entries = [];
  const writes = [];
  page.on('response', async (resp) => {
    const req = resp.request();
    const u = new URL(resp.url());
    if (req.method() !== 'GET' && WRITE_RE.test(u.pathname)) writes.push(`${req.method()} ${u.pathname}`);
    if (req.method() !== 'GET' || !LIST_PATH_RE.test(u.pathname)) return;
    let body = null;
    try {
      body = await resp.json();
    } catch (e) {
      body = null;
    }
    entries.push({
      seq: entries.length,
      status: resp.status(),
      idArchive: u.searchParams.get('idArchive') || u.searchParams.get('id_archive') || null,
      page: Number(u.searchParams.get('page') || 1),
      pagination: u.searchParams.get('pagination') ? Number(u.searchParams.get('pagination')) : null,
      search: u.searchParams.get('search') ? decodeURIComponent(u.searchParams.get('search')) : null,
      body,
    });
  });
  return { page, app, monitor, ac, entries, writes, n: 0, ids: {}, rowsSeen: {} };
}

async function waitList(ctx, since, pred, label) {
  const deadline = Date.now() + 45000;
  while (Date.now() < deadline) {
    const hit = ctx.entries.slice(since).filter(pred).pop();
    if (hit) return hit;
    await ctx.page.waitForTimeout(100);
  }
  throw new Error(`respons list "${label}" tidak tiba dalam 45 dtk`);
}

/** Jalankan aksi, tunggu respons list yang cocok, tunggu tenang; kembalikan respons TERAKHIR sesudah aksi. */
async function act(ctx, label, action, pred) {
  const since = ctx.entries.length;
  await action();
  await waitList(ctx, since, pred, label);
  await ctx.app.settle();
  const after = ctx.entries.slice(since);
  const last = after[after.length - 1];
  expect(last.status, `${label}: HTTP list`).toBe(200);
  return last;
}

const isRoot = (e) => !e.idArchive && !e.search;
const isFolder = (id, pageNo, size) => (e) =>
  e.idArchive === id && !e.search && (pageNo === undefined || e.page === pageNo) && (size === undefined || e.pagination === size);

// ---------------------------------------------------------------------------------------------------------------------------
// Pembacaan DOM

function snapshot(page, scope) {
  return page.evaluate(({ scope }) => {
    const wraps = [...document.querySelectorAll('.ant-modal-wrap')].filter((w) => getComputedStyle(w).display !== 'none');
    const mw = scope === 'modal' ? wraps[wraps.length - 1] || null : null;
    const inScope = (el) => {
      if (scope === 'page') return !el.closest('.ant-modal-wrap');
      if (!mw || !mw.contains(el)) return false;
      const pane = el.closest('.ant-tabs-tabpane');
      return !pane || pane.classList.contains('ant-tabs-tabpane-active');
    };
    const rows = [...document.querySelectorAll('tr.ant-table-row')].filter(inScope);
    const body = [...document.querySelectorAll('.ant-table-body')].filter(inScope)[0] || null;
    const br = body ? body.getBoundingClientRect() : null;
    const crumbEl = [...document.querySelectorAll('.breadcrumbs')].filter(inScope)[0] || null;
    return {
      rows: rows.map((r) => {
        const b = r.getBoundingClientRect();
        const inside = br ? Math.max(0, Math.min(b.bottom, br.bottom) - Math.max(b.top, br.top)) : b.height;
        return {
          text: r.innerText.replace(/\s+/g, ' ').trim(),
          h: Math.round(b.height),
          vis: b.height ? Math.round((inside / b.height) * 100) / 100 : 0,
          selected: r.classList.contains('ant-table-row-selected'),
          checked: Boolean(r.querySelector('input[type="checkbox"]:checked')),
        };
      }),
      sh: body ? body.scrollHeight : 0,
      ch: body ? body.clientHeight : 0,
      st: body ? Math.round(body.scrollTop) : 0,
      maxH: body ? parseFloat(getComputedStyle(body).maxHeight) || 600 : 600,
      wrap: body && body.firstElementChild ? body.firstElementChild.style.height || null : null,
      crumbs: crumbEl ? [...crumbEl.querySelectorAll('.ant-breadcrumb-link')].map((e) => e.innerText.trim()) : [],
    };
  }, { scope });
}

/**
 * Menilai satu pembacaan DOM terhadap respons. Dua penilaian terpisah:
 *   literal  = bunyi AC-1 apa adanya: jumlah `tr.ant-table-row` dan nama barisnya (urut) = respons
 *   terlihat = baris benar-benar tampil di wadah scroll (wadah tidak terpotong, semua baris di area terlihat)
 */
function evaluateSnap(snap, expected) {
  const literal = [];
  const visual = [];
  const rowH = (snap.rows[0] && snap.rows[0].h) || 37;
  const short = expected.length * rowH <= snap.maxH;
  const texts = snap.rows.map((r) => r.text);
  if (short) {
    if (snap.rows.length !== expected.length) literal.push(`DOM ${snap.rows.length} baris != respons ${expected.length}`);
    else if (!expected.every((n, i) => nameRe(n).test(texts[i]))) literal.push('nama baris DOM beda dengan respons');
  } else {
    const idx = expected.findIndex((n) => texts[0] && nameRe(n).test(texts[0]));
    const contiguous = idx >= 0 && texts.every((t, i) => expected[idx + i] !== undefined && nameRe(expected[idx + i]).test(t));
    if (!snap.rows.length) literal.push('daftar panjang: tidak ada baris di DOM');
    else if (!contiguous) literal.push('daftar panjang: baris DOM bukan potongan berurutan dari respons');
    else if (snap.st <= 1 && idx !== 0) literal.push(`daftar panjang: scrollTop 0 tetapi baris pertama DOM = respons[${idx}]`);
  }
  const visible = snap.rows.filter((r) => r.vis >= 0.5).length;
  if (snap.ch < Math.min(snap.maxH, snap.sh) - 1) visual.push(`wadah terpotong (clientHeight ${snap.ch} < scrollHeight ${snap.sh}, tinggi inline wrapper ${snap.wrap})`);
  if (short && visible !== expected.length) visual.push(`hanya ${visible} dari ${expected.length} baris terlihat di layar`);
  if (!short && visible < 1) visual.push('daftar panjang: tidak ada baris terlihat');
  return { pass: !literal.length && !visual.length, literal, visual, visible, short };
}

const brief = (snap, ev) =>
  `DOM=${snap.rows.length} terlihat=${ev.visible} wadah=${snap.ch}/${snap.sh}px scrollTop=${snap.st}`;
const verdict = (ev) =>
  `literal(DOM=respons) ${ev.literal.length ? `GAGAL(${ev.literal.join('; ')})` : 'OK'}, terlihat ${ev.visual.length ? `GAGAL(${ev.visual.join('; ')})` : 'OK'}`;

/**
 * Satu langkah AC: bandingkan layar dengan respons terakhir. Keadaan sesaat (sesudah settle) dan keadaan akhir (polling).
 * Kegagalan memakai expect.soft supaya semua langkah tercatat.
 */
async function checkStep(ctx, label, resp, { scope = 'page', shot = true } = {}) {
  ctx.n += 1;
  const expected = expectedNames(resp);
  const first = await snapshot(ctx.page, scope);
  const firstEv = evaluateSnap(first, expected);
  let snap = first;
  let ev = firstEv;
  const deadline = Date.now() + STABLE_MS;
  while (!ev.pass && Date.now() < deadline) {
    await ctx.page.waitForTimeout(250);
    snap = await snapshot(ctx.page, scope);
    ev = evaluateSnap(snap, expected);
  }
  const name = `${ctx.ac}-${String(ctx.n).padStart(2, '0')}-${label}`;
  const line =
    `[${ctx.ac}] langkah ${ctx.n} "${label}" (${scope}): respons=${expected.length} [${expected.slice(0, 4).join(' | ')}${expected.length > 4 ? ' ...' : ''}] ` +
    `sesaat: ${brief(first, firstEv)} ${firstEv.pass ? 'OK' : 'GAGAL'} | akhir: ${brief(snap, ev)} -> ${verdict(ev)}`;
  logLine(line);
  ctx.monitor.note(line);
  if (shot) await ctx.app.screenshot(`${ev.pass ? '' : 'GAGAL-'}${name}`).catch(() => null);
  expect.soft(ev.literal, `${ctx.ac} langkah ${ctx.n} "${label}" [literal: jumlah/nama baris DOM = respons]`).toEqual([]);
  expect.soft(ev.visual, `${ctx.ac} langkah ${ctx.n} "${label}" [terlihat: baris tidak terpotong di wadah]`).toEqual([]);
  return { snap, ev, expected };
}

// ---------------------------------------------------------------------------------------------------------------------------
// Aksi UI

const rowsOf = (ctx, scope) => ctx.page.locator(scope === 'modal' ? MODAL_ROWS : PAGE_ROWS);
const rowNamed = (ctx, scope, name) => rowsOf(ctx, scope).filter({ hasText: nameRe(name) }).first();
const crumbsOf = (ctx, scope) =>
  scope === 'modal'
    ? ctx.page.locator('.ant-modal-wrap:not([style*="display: none"]) .breadcrumbs').first()
    : ctx.page.locator('.archive-page .breadcrumbs').first();

/** Klik biasa bila elemen terlihat penuh di wadahnya; bila terpotong: dispatchEvent (tanpa scroll yang bisa menyamarkan bug). */
async function softClick(loc, note) {
  await loc.waitFor({ state: 'attached', timeout: 20000 });
  const seen = await loc.evaluate((el) => {
    const cont = el.closest('.ant-table-body');
    if (!cont) return true;
    const b = el.getBoundingClientRect();
    const c = cont.getBoundingClientRect();
    return b.top >= c.top - 1 && b.bottom <= c.bottom + 1;
  });
  if (seen) await loc.click({ timeout: 15000 });
  else {
    if (note) note(`klik via dispatchEvent (elemen terpotong di wadah)`);
    await loc.dispatchEvent('click');
  }
}

const openRoot = async (ctx) => {
  await ctx.app.open(PATH, { waitFor: '.archive-page .ant-table' });
  await ctx.app.settle();
  let resp = ctx.entries.filter(isRoot).pop();
  if (!resp) resp = await waitList(ctx, 0, isRoot, 'root');
  expect(resp.status, 'root HTTP').toBe(200);
  return resp;
};

/** Klik nama folder (sel nama) dan tunggu respons folder itu. id folder dari respons yang sedang dipantau. */
async function clickFolder(ctx, scope, name, id, extraPred) {
  const cell = rowNamed(ctx, scope, name).locator('td.cursor-pointer').first();
  return act(ctx, `buka ${name}`, () => softClick(cell, (t) => ctx.monitor.note(`${name}: ${t}`)), extraPred || isFolder(id, 1));
}

/** Klik breadcrumb: `target` = null (root) atau nama folder. */
async function clickCrumb(ctx, scope, target, id) {
  const bar = crumbsOf(ctx, scope);
  const btn = target ? bar.locator('button', { hasText: nameRe(target) }).first() : bar.locator('button').first();
  return act(ctx, `breadcrumb ${target || 'root'}`, () => btn.click({ timeout: 15000 }), target ? isFolder(id, 1) : isRoot);
}

const idOfRow = (resp, name) => {
  const row = ((resp.body && resp.body.result && resp.body.result.data) || []).find((r) => nameOf(r) === name);
  return row ? row.id_archive : null;
};

/** Prasyarat dari respons jaringan; tidak terpenuhi = SKIP (BLOCKED-by-data). Mengisi ctx.ids. */
function requireRoot(ctx, rootResp) {
  const rows = expectedNames(rootResp);
  const ok = rows.length === 3 && rows.includes('Folder A');
  test.skip(!ok, `BLOCKED-by-data: root sam bukan 3 baris termasuk Folder A (respons: ${rows.join(', ')})`);
  ctx.ids.A = idOfRow(rootResp, 'Folder A');
}
function requireA(ctx, aResp) {
  const rows = expectedNames(aResp);
  const ok = rows.length === 2 && rows.includes('Folder B') && rows.includes('Folder C');
  test.skip(!ok, `BLOCKED-by-data: Folder A bukan 2 baris (Folder B, Folder C) (respons: ${rows.join(', ')})`);
  ctx.ids.B = idOfRow(aResp, 'Folder B');
  ctx.ids.C = idOfRow(aResp, 'Folder C');
}
function requireB(ctx, bResp) {
  const n = ((bResp.body && bResp.body.result && bResp.body.result.data) || []).length;
  test.skip(n < 10, `BLOCKED-by-data: Folder B hanya ${n} baris (< 10)`);
  ctx.bFirst = nameOf(bResp.body.result.data[0]);
  ctx.bLastPage = Number(bResp.body.result.last_page || 1);
  ctx.bTotal = Number(bResp.body.result.total || 0);
}

/** catat satu fakta (bukan langkah banding) ke steps-ED-1150.log dan notes laporan. */
const fact = (ctx, text) => {
  logLine(`[${ctx.ac}] ${text}`);
  ctx.monitor.note(`[${ctx.ac}] ${text}`);
};

/** akhir test: tidak boleh ada request tulis ke /document-archive/ (flow non-mutasi). */
const noWrites = (ctx) => expect(ctx.writes, 'tidak ada request tulis ke /document-archive/').toEqual([]);

// ---------------------------------------------------------------------------------------------------------------------------

test.describe.configure({ mode: 'default' });

test('[sam] ED-1150 AC-1: root -> Folder A -> Folder B -> breadcrumb Folder A -> breadcrumb root (tanpa scroll/reload): baris layar = respons', async ({ app, page, monitor }) => {
  test.setTimeout(240000);
  const ctx = createCtx({ page, app, monitor }, 'AC1');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  await checkStep(ctx, 'root', root);

  const a = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
  requireA(ctx, a);
  await checkStep(ctx, 'Folder-A', a);

  const b = await clickFolder(ctx, 'page', 'Folder B', ctx.ids.B);
  requireB(ctx, b);
  await checkStep(ctx, 'Folder-B', b);

  const a2 = await clickCrumb(ctx, 'page', 'Folder A', ctx.ids.A);
  await checkStep(ctx, 'breadcrumb-Folder-A', a2);

  const root2 = await clickCrumb(ctx, 'page', null);
  await checkStep(ctx, 'breadcrumb-root', root2);
  noWrites(ctx);
});

test('[sam] ED-1150 AC-2: siklus klik nama folder + breadcrumb dua kali, lalu A -> root -> A -> B; folder ditolak View', async ({ app, page, monitor }) => {
  test.setTimeout(420000);
  const ctx = createCtx({ page, app, monitor }, 'AC2');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  await checkStep(ctx, 'c0-root', root);
  const hiddenIn = (resp) => ((resp.body.result.data) || []).filter((x) => x.type === 'Folder' && x.access && x.access.view === false).map(nameOf);
  const denied = hiddenIn(root);

  for (let cycle = 1; cycle <= 2; cycle += 1) {
    const c = `c${cycle}`;
    const a = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
    if (cycle === 1) {
      requireA(ctx, a);
      denied.push(...hiddenIn(a));
    }
    await checkStep(ctx, `${c}-klik-Folder-A`, a);
    const b = await clickFolder(ctx, 'page', 'Folder B', ctx.ids.B);
    if (cycle === 1) requireB(ctx, b);
    await checkStep(ctx, `${c}-klik-Folder-B`, b);
    await checkStep(ctx, `${c}-breadcrumb-Folder-A`, await clickCrumb(ctx, 'page', 'Folder A', ctx.ids.A));
    await checkStep(ctx, `${c}-breadcrumb-root`, await clickCrumb(ctx, 'page', null));
  }

  // varian: daftar mengecil/membesar lain (3 -> 2 -> 3 -> 2 -> 10 -> 2)
  await checkStep(ctx, 'v-klik-Folder-A', await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A));
  await checkStep(ctx, 'v-breadcrumb-root', await clickCrumb(ctx, 'page', null));
  await checkStep(ctx, 'v-klik-Folder-A-lagi', await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A));
  await checkStep(ctx, 'v-klik-Folder-C', await clickFolder(ctx, 'page', 'Folder C', ctx.ids.C));
  await checkStep(ctx, 'v-breadcrumb-Folder-A', await clickCrumb(ctx, 'page', 'Folder A', ctx.ids.A));

  // bagian folder ditolak View (403 ARCHIVE407 + "Back to Archive"): hanya bila ada folder dengan access.view=false untuk sam
  if (denied.length) {
    // tidak ada folder ditolak di data sam saat spec ditulis; cabang ini sengaja dibiarkan gagal keras agar ditinjau, bukan dilewati diam-diam
    expect.soft(denied, `folder ditolak View ditemukan (${denied.join(', ')}) - lengkapi langkah Back to Archive di spec`).toEqual([]);
  } else {
    const note = 'AC-2 bagian "folder ditolak View": SKIP - tidak ada folder dengan access.view=false untuk sam di root/Folder A (semua view=true), jadi tidak ada 403 ARCHIVE407 untuk dibuka';
    fact(ctx, note);
    test.info().annotations.push({ type: 'skip-bagian', description: note });
  }
  noWrites(ctx);
});

test('[sam] ED-1150 AC-3: Folder B halaman 1 -> terakhir -> 1, ukuran halaman 10 -> 20 -> 10: baris = respons', async ({ app, page, monitor }) => {
  test.setTimeout(420000);
  const ctx = createCtx({ page, app, monitor }, 'AC3');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  const a = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
  requireA(ctx, a);
  const b = await clickFolder(ctx, 'page', 'Folder B', ctx.ids.B);
  requireB(ctx, b);
  await checkStep(ctx, 'B-hal-1', b);

  const pager = page.locator('.archive-page .ant-table-pagination').first();
  const last = ctx.bLastPage;
  const lastResp = await act(ctx, `halaman ${last}`, () => pager.locator(`li.ant-pagination-item-${last}`).click(), isFolder(ctx.ids.B, last, undefined));
  expect(lastResp.body.result.data.length, `halaman terakhir (${ctx.bTotal} dokumen)`).toBe(ctx.bTotal - (last - 1) * 10);
  await checkStep(ctx, `B-hal-${last}`, lastResp);

  await checkStep(ctx, 'B-hal-1-lagi', await act(ctx, 'halaman 1', () => pager.locator('li.ant-pagination-item-1').click(), isFolder(ctx.ids.B, 1, undefined)));

  const pickSize = async (size) => {
    await pager.locator('.ant-pagination-options .ant-select').click();
    const option = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: new RegExp(`^\\s*${size}\\b`) }).first();
    await option.waitFor({ state: 'visible', timeout: 10000 });
    await option.click();
  };
  const r20 = await act(ctx, 'ukuran 20', () => pickSize(20), (e) => e.idArchive === ctx.ids.B && !e.search && e.pagination === 20);
  expect(r20.body.result.data.length, 'ukuran halaman 20').toBe(20);
  await checkStep(ctx, 'B-ukuran-20', r20);
  const r10 = await act(ctx, 'ukuran 10', () => pickSize(10), (e) => e.idArchive === ctx.ids.B && !e.search && e.pagination === 10);
  expect(r10.body.result.data.length, 'ukuran halaman 10').toBe(10);
  await checkStep(ctx, 'B-ukuran-10', r10);
  noWrites(ctx);
});

test('[sam] ED-1150 AC-4: cari nomor transaksi dari Folder A, reset pencarian: baris = respons', async ({ app, page, monitor }) => {
  test.setTimeout(300000);
  const ctx = createCtx({ page, app, monitor }, 'AC4');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  const a = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
  requireA(ctx, a);
  const b = await clickFolder(ctx, 'page', 'Folder B', ctx.ids.B);
  requireB(ctx, b);
  const txn = ctx.bFirst; // nomor transaksi dokumen nyata di Folder B (dari respons, bukan hardcode)
  expect(txn, 'nomor transaksi dokumen pertama Folder B').toBeTruthy();
  await checkStep(ctx, 'Folder-B', b);
  await checkStep(ctx, 'breadcrumb-Folder-A', await clickCrumb(ctx, 'page', 'Folder A', ctx.ids.A));

  const input = page.locator('.archive-page #query').first();
  await input.fill(txn);
  const found = await act(ctx, `cari ${txn}`, () => input.press('Enter'), (e) => !e.idArchive && !!e.search && e.search.includes(txn));
  expect(expectedNames(found).some((n) => n === txn), `hasil pencarian memuat ${txn}`).toBe(true);
  await checkStep(ctx, 'hasil-cari', found);

  fact(ctx, `pencarian "${txn}" dari Folder A memakai idArchive=null (cari di semua folder): hasil ${expectedNames(found).length} baris`);
  const reset = await act(ctx, 'reset pencarian', () => page.locator('.archive-page .reset-filter').first().click(), (e) => !e.search && e.page === 1);
  // sesudah reset FE kembali ke daftar tanpa pencarian (idArchive terakhir = null -> root); respons terakhir jadi pembanding
  await checkStep(ctx, `reset-pencarian(${reset.idArchive ? 'folder' : 'root'})`, reset);
  const a2 = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
  await checkStep(ctx, 'Folder-A-utuh', a2);
  expect(expectedNames(a2).sort(), 'isi Folder A utuh sesudah reset').toEqual(['Folder B', 'Folder C']);
  noWrites(ctx);
});

test('[sam] ED-1150 AC-5: Reload di Folder B sesudah scroll ke bawah: tabel tidak di-remount, posisi scroll tetap, baris = respons', async ({ app, page, monitor }) => {
  test.setTimeout(300000);
  const ctx = createCtx({ page, app, monitor }, 'AC5');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  const a = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
  requireA(ctx, a);
  const b = await clickFolder(ctx, 'page', 'Folder B', ctx.ids.B);
  requireB(ctx, b);

  // ukuran 20 baris (> scroll.y 600) supaya tabel punya scrollbar di keadaan yang benar
  const pager = page.locator('.archive-page .ant-table-pagination').first();
  const r20 = await act(ctx, 'ukuran 20', async () => {
    await pager.locator('.ant-pagination-options .ant-select').click();
    const option = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: /^\s*20\b/ }).first();
    await option.waitFor({ state: 'visible', timeout: 10000 });
    await option.click();
  }, (e) => e.idArchive === ctx.ids.B && !e.search && e.pagination === 20);
  await checkStep(ctx, 'B-ukuran-20', r20);

  const body = page.locator('.archive-page .ant-table-body:not(.ant-modal-wrap *)').first();
  await body.evaluate((el) => el.scrollTo(0, el.scrollHeight));
  await page.waitForTimeout(800);
  const before = await snapshot(page, 'page');
  expect(before.st, 'tabel sudah di-scroll ke bawah (scrollTop > 0)').toBeGreaterThan(0);
  await body.evaluate((el) => {
    el.__ed1150 = 'wadah-sama';
  });

  const reloaded = await act(ctx, 'Reload', () => page.locator('.archive-page button.button-filter-wrapper').first().click(), (e) => e.idArchive === ctx.ids.B && !e.search && e.pagination === 20);
  await page.waitForTimeout(800);
  const after = await snapshot(page, 'page');
  const marks = await body.evaluate((el) => ({ body: el.__ed1150 || null }));
  fact(ctx, `sebelum Reload scrollTop=${before.st}, sesudah scrollTop=${after.st}, penanda elemen wadah tabel=${marks.body}`);
  expect.soft(after.st, 'posisi scroll tetap sesudah Reload').toBeGreaterThanOrEqual(before.st - 2);
  expect.soft(marks.body, 'elemen wadah tabel sama (tidak di-remount)').toBe('wadah-sama');
  await checkStep(ctx, 'sesudah-Reload', reloaded);
  noWrites(ctx);
});

test('[sam] ED-1150 AC-6: mode Pilih lintas folder (A -> B -> breadcrumb A): pilihan tetap, modal Bulk Move menampilkan keduanya', async ({ app, page, monitor }) => {
  test.setTimeout(420000);
  const ctx = createCtx({ page, app, monitor }, 'AC6');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  const a = await clickFolder(ctx, 'page', 'Folder A', ctx.ids.A);
  requireA(ctx, a);
  await checkStep(ctx, 'Folder-A', a);

  await page.locator('.archive-page button', { hasText: /^(Select|Pilih)$/ }).first().click();
  await page.waitForTimeout(500);
  const tick = async (name) => softClick(rowNamed(ctx, 'page', name).locator('input[type="checkbox"]').first(), (t) => monitor.note(`${name}: ${t}`));
  const counter = async () => {
    const m = /(\d+)\s+(selected|dipilih)/i.exec(await page.locator('.archive-page').first().innerText());
    return m ? Number(m[1]) : null;
  };

  await tick('Folder C'); // B tetap tidak dipilih supaya bisa dibuka (folder terpilih terkunci)
  await page.waitForTimeout(400);
  const c1 = await counter();
  fact(ctx, `mode Pilih di Folder A: Folder C dicentang, penghitung=${c1}`);
  expect(c1, 'satu terpilih (Folder C) di Folder A').toBe(1);

  const b = await clickFolder(ctx, 'page', 'Folder B', ctx.ids.B);
  requireB(ctx, b);
  await checkStep(ctx, 'Folder-B-mode-Pilih', b);
  const doc = ctx.bFirst;
  await tick(doc);
  await page.waitForTimeout(400);
  const c2 = await counter();
  fact(ctx, `di Folder B: ${doc} dicentang, penghitung=${c2} (pilihan Folder C dari Folder A ikut)`);
  expect(c2, `dua terpilih (Folder C dan ${doc})`).toBe(2);
  await app.screenshot('AC6-pilihan-di-Folder-B');

  // modal Bulk Move: langkah Konfirmasi menampilkan kedua pilihan; ditutup tanpa simpan
  await page.locator('.archive-page button', { hasText: /^(Move|Pindahkan)$/ }).first().click();
  await page.waitForTimeout(1200);
  const modal = await snapshot(page, 'modal');
  fact(ctx, `modal Bulk Move (Konfirmasi): ${modal.rows.length} baris [${modal.rows.map((x) => x.text.slice(0, 30)).join(' | ')}]`);
  await app.screenshot('AC6-modal-bulk-move');
  expect.soft(modal.rows.length, 'modal Bulk Move menampilkan 2 baris terpilih').toBe(2);
  expect.soft(modal.rows.some((x) => nameRe('Folder C').test(x.text)), 'modal memuat Folder C').toBe(true);
  expect.soft(modal.rows.some((x) => nameRe(doc).test(x.text)), `modal memuat ${doc}`).toBe(true);
  await page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last().click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);

  const a2 = await clickCrumb(ctx, 'page', 'Folder A', ctx.ids.A);
  await checkStep(ctx, 'breadcrumb-Folder-A-mode-Pilih', a2);
  const c3 = await counter();
  const sel = (await snapshot(page, 'page')).rows.filter((x) => x.checked || x.selected).map((x) => x.text);
  fact(ctx, `kembali ke Folder A lewat breadcrumb: penghitung=${c3}, baris tercentang di layar=[${sel.map((t) => t.slice(0, 20)).join(' | ')}]`);
  expect.soft(c3, 'masih 2 terpilih sesudah kembali ke Folder A').toBe(2);
  expect.soft(sel.some((t) => nameRe('Folder C').test(t)), `Folder C tetap tercentang di Folder A (tercentang: ${sel.join(' | ')})`).toBe(true);
  noWrites(ctx);
});

test('[sam] ED-1150 AC-7: picker ArchiveTable di modal Move: root -> Folder A -> Folder B -> breadcrumb A -> breadcrumb root, baris = respons', async ({ app, page, monitor }) => {
  test.setTimeout(300000);
  const ctx = createCtx({ page, app, monitor }, 'AC7');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);

  // modal Move dari menu baris "Backup Arsip" (sam punya Move: item menu ada). Folder tujuan tidak dipilih, modal ditutup (X).
  const rowMenuBtn = rowNamed(ctx, 'page', 'Backup Arsip').locator('button.btn-action').first();
  const rootPicker = await act(ctx, 'buka modal Move', async () => {
    await rowMenuBtn.click();
    const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
    await menu.waitFor({ state: 'visible', timeout: 10000 });
    const items = menu.locator('li.ant-dropdown-menu-item', { hasText: /Move|Pindahkan/ });
    test.skip((await items.count()) === 0, 'sam tanpa akses Move: jalankan AC-7 sebagai [default] (QA_USER)');
    await items.first().click();
    await page.locator('.ant-modal-wrap:not([style*="display: none"]) tr.ant-table-row').first().waitFor({ state: 'attached', timeout: 20000 });
  }, isRoot);
  await checkStep(ctx, 'picker-root', rootPicker, { scope: 'modal' });
  const a = await clickFolder(ctx, 'modal', 'Folder A', ctx.ids.A);
  requireA(ctx, a);
  await checkStep(ctx, 'picker-Folder-A', a, { scope: 'modal' });
  const b = await clickFolder(ctx, 'modal', 'Folder B', ctx.ids.B);
  await checkStep(ctx, 'picker-Folder-B', b, { scope: 'modal' });
  await checkStep(ctx, 'picker-breadcrumb-Folder-A', await clickCrumb(ctx, 'modal', 'Folder A', ctx.ids.A), { scope: 'modal' });
  await checkStep(ctx, 'picker-breadcrumb-root', await clickCrumb(ctx, 'modal', null), { scope: 'modal' });

  await page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last().click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  noWrites(ctx);
});

test('[sam] ED-1150 AC-8: layar Archive, modal Move dan modal Bulk Move terbuka tanpa error konsol', async ({ app, page, monitor }) => {
  test.setTimeout(240000);
  const ctx = createCtx({ page, app, monitor }, 'AC8');
  const root = await openRoot(ctx);
  requireRoot(ctx, root);
  await app.screenshot('AC8-layar-Archive');

  // modal Move
  await rowNamed(ctx, 'page', 'Backup Arsip').locator('button.btn-action').first().click();
  const menu = page.locator('.ant-dropdown:not(.ant-dropdown-hidden)').last();
  await menu.waitFor({ state: 'visible', timeout: 10000 });
  await menu.locator('li.ant-dropdown-menu-item', { hasText: /Move|Pindahkan/ }).first().click();
  await page.locator(MODAL_ROWS).first().waitFor({ state: 'attached', timeout: 20000 });
  await app.settle();
  await app.screenshot('AC8-modal-Move');
  await page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last().click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);

  // modal Bulk Move: mode Pilih, pilih satu baris, tombol Move, lalu langkah Pilih Folder (picker); tutup lewat X
  await page.locator('.archive-page button', { hasText: /^(Select|Pilih)$/ }).first().click();
  await page.waitForTimeout(500);
  await rowNamed(ctx, 'page', 'Folder A').locator('input[type="checkbox"]').first().click();
  await page.waitForTimeout(400);
  await page.locator('.archive-page button', { hasText: /^(Move|Pindahkan)$/ }).first().click();
  await page.locator(MODAL_ROWS).first().waitFor({ state: 'attached', timeout: 20000 });
  await app.settle();
  await app.screenshot('AC8-modal-Bulk-Move-konfirmasi');
  await page.locator('.ant-modal-wrap:not([style*="display: none"]) button', { hasText: /^(Next|Lanjut)$/ }).first().click();
  await app.settle();
  await page.waitForTimeout(800);
  await app.screenshot('AC8-modal-Bulk-Move-pilih-folder');
  await page.locator('.ant-modal-wrap:not([style*="display: none"]) .ant-modal-close').last().click();
  await page.locator('.ant-modal-wrap:not([style*="display: none"])').waitFor({ state: 'detached', timeout: 10000 }).catch(() => null);
  noWrites(ctx);
});
