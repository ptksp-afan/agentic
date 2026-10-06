'use strict';

/**
 * Monitor error halaman + driver kecil untuk membuka route FE.
 *
 * FE v5 memakai `MemoryRouter` per tab (`containers/App/App`, `containers/AppTab`), jadi URL browser
 * tidak menentukan layar. Route dibuka dengan mengisi sessionStorage `appTab` = {"app-tab-0": path}
 * (yang sama dengan yang ditulis `App/app-components/Element` saat user berpindah layar) lalu memuat
 * `/`. Path aktif dibaca balik dari `appTab` untuk mendeteksi pengalihan (unauthorized, sign-in).
 */

const fs = require('fs');
const path = require('path');

const config = require('./config');
const allowlist = require('./allowlist');

const REDIRECTS = {
  '/unathorized': 'dialihkan ke /unathorized (scope/permission/role)',
  '/sign-in': 'dialihkan ke /sign-in (sesi hilang / token ditolak)',
  '/server-setting': 'dialihkan ke /server-setting (localStorage server/websocket tidak lengkap)',
};

/** Pesan konsol Chrome yang duplikat dengan event response/requestfailed (URL + status sudah dicatat di sana). */
const CONSOLE_DUPLICATES = [/^Failed to load resource: the server responded with a status of \d+/, /^Failed to load resource: net::/];

class Monitor {
  /**
   * @param {import('@playwright/test').Page} page
   * @param {{profile: string, allow?: object[]}} ctx
   */
  constructor(page, { profile, allow = [] }) {
    this.page = page;
    this.profile = profile;
    this.routePath = null;
    this.entries = allowlist.load(allow);
    this.errors = [];
    this.responses = [];
    this.notes = [];
    this.checked = false;
    this.started = Date.now();

    page.on('console', (msg) => {
      if (msg.type() !== 'error') return;
      const text = msg.text();
      if (CONSOLE_DUPLICATES.some((re) => re.test(text))) return;
      const loc = msg.location() || {};
      this.add({ kind: 'console', text: text.slice(0, 2000), source: loc.url ? config.safeUrl(loc.url) : undefined });
    });
    page.on('pageerror', (err) => {
      this.add({ kind: 'pageerror', text: `${err.name || 'Error'}: ${err.message}`.slice(0, 2000) });
    });
    page.on('response', (res) => {
      const req = res.request();
      const entry = {
        method: req.method(),
        url: res.url(),
        status: res.status(),
        type: req.resourceType(),
        contentType: (res.headers()['content-type'] || '').split(';')[0],
        at: Date.now() - this.started,
      };
      this.responses.push(entry);
      if (entry.status >= 400) {
        this.add({ kind: 'response', method: entry.method, url: entry.url, status: entry.status, type: entry.type });
      }
    });
    page.on('requestfailed', (req) => {
      const failure = (req.failure() && req.failure().errorText) || 'failed';
      // pembatalan normal (navigasi, gambar diganti, request dibatalkan app) bukan error
      if (/ERR_ABORTED|NS_BINDING_ABORTED/.test(failure)) return;
      this.add({ kind: 'requestfailed', method: req.method(), url: req.url(), text: failure, type: req.resourceType() });
    });
  }

  add(error) {
    const clean = { ...error, at: Date.now() - this.started };
    if (clean.url) clean.url = config.safeUrl(clean.url);
    if (clean.text) clean.text = config.redact(clean.text);
    const hit = allowlist.match(this.entries, clean, { profile: this.profile, path: this.routePath });
    if (hit) clean.allowed = hit.id;
    this.errors.push(clean);
  }

  note(text) {
    this.notes.push(config.redact(text));
  }

  unallowed() {
    return this.errors.filter((e) => !e.allowed);
  }

  /** Respons API BE (bukan aset FE), URL aman untuk laporan. */
  apiResponses() {
    const base = config.apiBase();
    return this.responses
      .filter((r) => r.url.startsWith(base))
      .map((r) => ({ ...r, url: config.safeUrl(r.url) }));
  }

  summary() {
    const lines = this.unallowed().map((e) => {
      if (e.kind === 'response') return `  - HTTP ${e.status} ${e.method} ${e.url}`;
      if (e.kind === 'requestfailed') return `  - request gagal ${e.method} ${e.url}: ${e.text}`;
      return `  - ${e.kind}: ${String(e.text).split('\n')[0].slice(0, 300)}`;
    });
    return lines.join('\n');
  }

  /** Gagal bila ada error yang tidak di-allow-list. Dipanggil otomatis di akhir test (fixture). */
  check() {
    this.checked = true;
    const bad = this.unallowed();
    if (bad.length) {
      throw new Error(`${bad.length} error di halaman [${this.profile}] ${this.routePath || ''}:\n${this.summary()}`);
    }
  }

  toJSON() {
    return {
      profile: this.profile,
      path: this.routePath,
      errors: this.errors,
      notes: this.notes,
      api: this.apiResponses().map((r) => `${r.status} ${r.method} ${r.url}`),
    };
  }
}

class AppDriver {
  /**
   * @param {import('@playwright/test').Page} page
   * @param {Monitor} monitor
   * @param {import('@playwright/test').TestInfo} testInfo
   * @param {{profile: string, outDir: string}} ctx
   */
  constructor(page, monitor, testInfo, { profile, outDir }) {
    this.page = page;
    this.monitor = monitor;
    this.testInfo = testInfo;
    this.profile = profile;
    this.outDir = outDir;
    this.screenshots = [];
    this.finalPath = null;
  }

  /**
   * Buka route FE di tab app pertama dan tunggu sampai tenang.
   * @param {string} routePath path dari src/routes/paths.js
   * @param {{waitFor?: string, settleTimeout?: number}} [opts] waitFor = selector yang wajib tampil
   */
  async open(routePath, opts = {}) {
    this.monitor.routePath = routePath;
    await this.page.addInitScript((p) => {
      try {
        window.sessionStorage.setItem('appTab', JSON.stringify({ 'app-tab-0': p }));
        window.sessionStorage.setItem('appTabActiveKey', JSON.stringify('app-tab-0'));
      } catch (e) {
        // sessionStorage tidak tersedia: biarkan app memakai tab default
      }
    }, routePath);
    await this.page.goto('/', { waitUntil: 'domcontentloaded' });
    await this.page.locator('#root > *').first().waitFor({ state: 'attached', timeout: 30000 });
    await this.settle(opts);
    if (opts.waitFor) {
      const timeout = opts.settleTimeout || 30000;
      try {
        await this.page.locator(opts.waitFor).first().waitFor({ state: 'visible', timeout });
        await this.settle(opts);
      } catch (e) {
        // dicatat sebagai error (menggagalkan test lewat monitor.check), screenshot tetap diambil
        this.monitor.add({ kind: 'timeout', text: `selector ${opts.waitFor} tidak tampil dalam ${timeout} ms` });
      }
    }
    // guard route (scope/permission) baru menilai sesudah auth/info: baca path sesudah itu dan sesudah stabil
    await this.waitForAuthInfo();
    this.finalPath = await this.stablePath();
    return this.finalPath;
  }

  async waitForAuthInfo(timeout = 15000) {
    const seen = () => this.monitor.responses.some((r) => /\/auth\/info(\?|$)/.test(r.url));
    if (seen()) return;
    await this.page
      .waitForResponse((r) => /\/auth\/info(\?|$)/.test(r.url()), { timeout })
      .catch(() => this.monitor.note(`auth/info tidak terlihat dalam ${timeout} ms`));
    await this.page.waitForTimeout(300);
  }

  /** Path aktif yang sama pada 3 pembacaan berturut-turut (jeda 250 ms, maks ±5 dtk). */
  async stablePath() {
    let last = await this.currentPath();
    let same = 1;
    for (let i = 0; i < 20 && same < 3; i += 1) {
      await this.page.waitForTimeout(250);
      const now = await this.currentPath();
      same = now === last ? same + 1 : 1;
      last = now;
    }
    return last;
  }

  /** network idle + tidak ada spinner/skeleton antd yang aktif di layar. Timeout dicatat sebagai error. */
  async settle({ settleTimeout = 30000 } = {}) {
    const t0 = Date.now();
    await this.page.waitForLoadState('networkidle', { timeout: settleTimeout }).catch(() => {
      this.monitor.add({ kind: 'timeout', text: `network belum idle sesudah ${settleTimeout} ms` });
    });
    const left = Math.max(5000, settleTimeout - (Date.now() - t0));
    await this.page
      .waitForFunction(
        () => {
          const busy = [...document.querySelectorAll('.ant-spin-spinning, .ant-skeleton-active')];
          // hanya yang terlihat: tab app yang tidak aktif tetap ter-mount
          return !busy.some((el) => el.offsetParent !== null && el.getClientRects().length > 0);
        },
        null,
        { timeout: left, polling: 250 },
      )
      .catch(() => {
        this.monitor.add({ kind: 'timeout', text: `spinner/skeleton masih tampil sesudah ${left} ms` });
      });
    await this.page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => null);
    await this.page.waitForTimeout(300);
  }

  /** Path tab app aktif menurut sessionStorage `appTab` (ditulis FE setiap location berubah). */
  async currentPath() {
    return this.page.evaluate(() => {
      try {
        const tabs = JSON.parse(window.sessionStorage.getItem('appTab') || '{}');
        const key = JSON.parse(window.sessionStorage.getItem('appTabActiveKey') || '"app-tab-0"');
        return tabs[key] || null;
      } catch (e) {
        return null;
      }
    });
  }

  /** Pesan pengalihan bila path akhir berbeda dari yang dibuka (null = tetap di route itu). */
  redirectOf(expected, actual = this.finalPath) {
    if (!actual) return null;
    const pathname = String(actual).split('?')[0];
    if (pathname === expected) return null;
    // index sebuah space (mis. /setups/accounts) membuka layar anak pertama yang boleh: bukan pengalihan
    if (pathname.startsWith(`${expected.replace(/\/+$/, '')}/`)) {
      this.monitor.note(`${expected} membuka layar anak ${pathname}`);
      return null;
    }
    return REDIRECTS[pathname] || `dialihkan ke ${pathname}`;
  }

  /** Screenshot halaman penuh ke <out>/screenshots/<profil>/<nama>.png; path relatif dikembalikan. */
  async screenshot(name) {
    const slug = String(name)
      .replace(/^\/+/, '')
      .replace(/[^A-Za-z0-9._-]+/g, '_')
      .replace(/_+/g, '_')
      .slice(0, 120) || 'root';
    const rel = path.join('screenshots', this.profile, `${slug}.png`);
    const abs = path.join(this.outDir, rel);
    fs.mkdirSync(path.dirname(abs), { recursive: true });
    await this.page.screenshot({ path: abs, fullPage: true });
    this.screenshots.push(rel.replace(/\\/g, '/'));
    return rel;
  }

  /**
   * Status <img>: src aktif, ukuran asli, dan respons jaringan untuk src itu (bila tertangkap).
   * @param {import('@playwright/test').Locator} locator
   */
  async imageInfo(locator) {
    await locator.waitFor({ state: 'attached', timeout: 15000 });
    await this.page
      .waitForFunction((el) => el.complete, await locator.elementHandle(), { timeout: 15000 })
      .catch(() => null);
    const info = await locator.evaluate((img) => ({
      src: img.currentSrc || img.src,
      naturalWidth: img.naturalWidth,
      naturalHeight: img.naturalHeight,
      complete: img.complete,
    }));
    const response = [...this.monitor.responses].reverse().find((r) => r.url === info.src);
    return { ...info, response: response || null };
  }
}

module.exports = { Monitor, AppDriver };
