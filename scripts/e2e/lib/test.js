'use strict';

/**
 * Objek `test` bersama untuk semua spec. Spec fitur: `const { test, expect } = require('e2e-harness')`;
 * spec harness (tests/): `require('../lib/test')`.
 *
 * - `profile`  : nama profil project (default | user2 | <nama config>), dari playwright.config.js
 * - `session`  : sesi profil dari global setup (tanpa token di laporan); test di-skip bila profil di-skip
 * - `monitor`  : pengumpul console error, page error, respons >= 400, request gagal. OTOMATIS dicek
 *                di akhir setiap test: error yang tidak di-allow-list menggagalkan test
 * - `app`      : AppDriver (open route, settle, screenshot, imageInfo)
 * - `allow`    : allow-list tambahan untuk test ini (entri format lib/allowlist.js), opsional
 *
 * Judul test WAJIB memuat tag profil, mis. `[default] ...`: project memilih test lewat grep tag itu.
 */

const base = require('@playwright/test');

const config = require('./config');
const { importSession, storageStateFor } = require('./session');
const { Monitor, AppDriver } = require('./monitor');

const test = base.test.extend({
  profile: ['default', { option: true }],

  allow: [[], { option: true }],

  session: async ({ profile }, use, testInfo) => {
    const session = importSession(profile);
    if (session.skip) testInfo.skip(true, session.skip);
    if (session.websocket && session.websocket.key) config.addSecret(String(session.websocket.key));
    await use(session);
  },

  storageState: async ({ session, baseURL }, use) => {
    await use(session && session.token ? storageStateFor(session, new URL(baseURL).origin) : undefined);
  },

  monitor: async ({ page, profile, allow }, use, testInfo) => {
    const monitor = new Monitor(page, { profile, allow });
    await use(monitor);
    const failedAlready = testInfo.status !== testInfo.expectedStatus;
    await testInfo.attach('e2e', {
      body: JSON.stringify({ ...monitor.toJSON(), screenshots: testInfo.e2eScreenshots || [] }),
      contentType: 'application/json',
    });
    // pemeriksaan otomatis: test yang lupa memanggil monitor.check() tetap gagal bila ada error
    if (!monitor.checked && !failedAlready && testInfo.status !== 'skipped') monitor.check();
  },

  app: async ({ page, monitor, profile }, use, testInfo) => {
    const app = new AppDriver(page, monitor, testInfo, { profile, outDir: process.env.E2E_OUT_DIR });
    testInfo.e2eScreenshots = app.screenshots;
    await use(app);
    // test gagal di tengah flow: simpan keadaan layar terakhir untuk developer
    if (testInfo.status !== testInfo.expectedStatus && testInfo.status !== 'skipped') {
      await app.screenshot(`GAGAL-${testInfo.title}`).catch(() => null);
    }
  },
});

module.exports = { test, expect: base.expect, config };
