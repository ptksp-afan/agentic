'use strict';

/**
 * Harness smoke browser FE v5 (workflow agentic). Cara pakai: README.md. Biasanya lewat `run.sh`, bukan langsung.
 *
 * - Browser: Chrome sistem (`channel: 'chrome'`), headless. Tidak ada unduhan browser Playwright.
 * - FE: build staging (FE_STAGING_DIR) dilayani lib/static-server.js di 127.0.0.1:<E2E_PORT, default 4100>.
 * - BE: API_URL. Profil: E2E_PROFILES (koma), didefinisikan config (lib/profiles.js).
 * - Test fitur ada di <E2E_FEATURE_DIR>/qa/e2e/*.spec.js, test generik di tests/ (smoke dari routes.json fitur,
 *   all-routes bila E2E_ALL_ROUTES=1). Tiap profil = 2 project: `<profil>` (spec fitur) dan `<profil>:harness`,
 *   keduanya memilih test lewat tag `[profil]` di judul.
 */

const fs = require('fs');
const path = require('path');

// Spec fitur berada di luar folder ini: pastikan `require('@playwright/test')` dan `require('e2e-harness')`
// ter-resolve dari sana (worker mewarisi env). Salinan @playwright/test yang dipakai SAMA dengan runner.
process.env.NODE_PATH = [path.join(__dirname, 'node_modules'), path.join(__dirname, 'modules'), process.env.NODE_PATH]
  .filter(Boolean)
  .join(path.delimiter);
require('module').Module._initPaths();

const { defineConfig } = require('@playwright/test');

const config = require('./lib/config');
const { selectedProfiles } = require('./lib/profiles');

// Folder output ditetapkan sekali di proses utama lalu diwariskan lewat env, supaya semua worker menulis ke folder yang sama.
if (!process.env.E2E_OUT_DIR) {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
  process.env.E2E_OUT_DIR = path.join(config.AGENTIC_DIR, 'work', 'e2e', stamp);
}
const profiles = selectedProfiles();
process.env.E2E_PROFILES = profiles.join(',');

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const featureE2e = config.featureE2eDir();
const hasRoutes = Boolean(featureE2e && fs.existsSync(path.join(featureE2e, 'routes.json')));
const allRoutes = process.env.E2E_ALL_ROUTES === '1';
const harnessMatch = [hasRoutes && '**/smoke.spec.js', allRoutes && '**/all-routes.spec.js'].filter(Boolean);

const projects = [];
profiles.forEach((name) => {
  const base = { grep: new RegExp(`\\[${escapeRe(name)}\\]`), use: { profile: name } };
  if (featureE2e) projects.push({ ...base, name, testDir: featureE2e, testMatch: '**/*.spec.js' });
  if (harnessMatch.length) projects.push({ ...base, name: `${name}:harness`, testDir: path.join(__dirname, 'tests'), testMatch: harnessMatch });
});

module.exports = defineConfig({
  testDir: path.join(__dirname, 'tests'),
  outputDir: path.join(process.env.E2E_OUT_DIR, 'test-results'),
  timeout: Number(config.get('E2E_TEST_TIMEOUT', '120000')),
  expect: { timeout: 15000 },
  fullyParallel: true,
  workers: Number(config.get('E2E_WORKERS', '2')),
  retries: 0,
  forbidOnly: true,
  reporter: [[require.resolve('./lib/reporter.js')]],
  globalSetup: require.resolve('./lib/global-setup.js'),
  use: {
    baseURL: config.appBase(),
    channel: 'chrome',
    headless: true,
    viewport: { width: 1440, height: 900 },
    timezoneId: 'Asia/Jakarta',
    serviceWorkers: 'block',
    // trace/video/screenshot bawaan mati: bisa memuat token di request; screenshot diambil harness sendiri
    trace: 'off',
    video: 'off',
    screenshot: 'off',
    actionTimeout: 15000,
    navigationTimeout: 45000,
    // env sesi (token) dan kunci rahasia hanya untuk worker, tidak ikut diwariskan ke proses Chrome
    launchOptions: {
      env: Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith('E2E_SESSION_') && !/(PASS|SECRET|TOKEN)/i.test(key))),
    },
  },
  projects,
  webServer: {
    command: 'node lib/static-server.js',
    url: `${config.appBase()}/__e2e_health`,
    reuseExistingServer: true,
    timeout: 20000,
    stdout: 'ignore',
    stderr: 'pipe',
  },
});
