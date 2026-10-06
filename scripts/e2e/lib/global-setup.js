'use strict';

/**
 * Global setup Playwright: cek build staging + server statis, jalankan fixture fitur (qa/e2e/fixtures.json),
 * buka satu sesi API per profil, dan kembalikan fungsi teardown yang me-log-out SEMUA sesi yang dibuat
 * (diverifikasi: auth/info -> 401) lalu menjalankan teardown fixture.
 *
 * Sesi diteruskan ke worker lewat env proses (bukan berkas). `sessions.json` di folder output hanya
 * berisi ringkasan tanpa token.
 */

const fs = require('fs');
const http = require('http');
const path = require('path');

const config = require('./config');
const staging = require('./staging');
const session = require('./session');
const fixtures = require('./fixtures');
const { selectedProfiles } = require('./profiles');

function log(text) {
  // eslint-disable-next-line no-console
  console.log(`[e2e] ${config.redact(text)}`);
}

function health() {
  return new Promise((resolve) => {
    const req = http.get(`${config.appBase()}/__e2e_health`, { timeout: 5000 }, (res) => {
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => resolve(body));
    });
    req.on('error', () => resolve(null));
    req.on('timeout', () => {
      req.destroy();
      resolve(null);
    });
  });
}

function writeJson(file, data) {
  fs.writeFileSync(file, `${JSON.stringify(data, null, 2)}\n`);
}

module.exports = async function globalSetup() {
  const outDir = process.env.E2E_OUT_DIR;
  fs.mkdirSync(outDir, { recursive: true });

  const st = staging.inspect();
  writeJson(path.join(outDir, 'staging.json'), st);
  if (!st.ok) throw new Error(st.message);
  log(st.message);
  if (st.stale && config.get('E2E_REQUIRE_FRESH_BUILD', '0') === '1') throw new Error(st.message);

  const body = await health();
  if (!body || !/agentic-e2e/.test(body)) {
    throw new Error(`Port ${config.port()} bukan server statis harness (isi: ${body ? body.slice(0, 80) : 'tidak menjawab'}). Pakai E2E_PORT lain.`);
  }
  const served = JSON.parse(body).root;
  if (path.resolve(served) !== path.resolve(st.dir)) {
    throw new Error(`Server statis di port ${config.port()} melayani ${served}, bukan ${st.dir}. Hentikan server itu atau pakai E2E_PORT lain.`);
  }

  // Fixture data fitur dibuat SEBELUM sesi browser dibuka, dibuang di teardown sesudah log-out.
  const applied = fixtures.up(selectedProfiles());

  const opened = [];
  const summary = [];
  try {
    for (const profile of selectedProfiles()) {
      try {
        const s = await session.openSession(profile);
        opened.push(s);
        session.exportSession(s);
        summary.push(session.describe(s));
        log(`sesi ${profile}: ${JSON.stringify(session.describe(s))}`);
      } catch (err) {
        if (!(err instanceof session.SessionSkip)) throw err;
        session.exportSkip(profile, err.message);
        summary.push({ profile, skip: err.message });
        log(`SKIP ${err.message}`);
      }
    }
  } catch (err) {
    // jangan tinggalkan token: log-out yang sudah terbuka, lalu gagal
    for (const s of opened) await session.logout(s.token).catch(() => null);
    fixtures.down(applied);
    throw new Error(config.redact(err.message));
  }
  writeJson(path.join(outDir, 'sessions.json'), { sessions: summary, fixtures: applied.map((f) => f.name) });

  return async function globalTeardown() {
    const results = [];
    for (const s of opened) {
      try {
        const out = await session.logout(s.token);
        results.push({ profile: s.profile, log_out: out.status, revoked: out.revoked });
      } catch (err) {
        results.push({ profile: s.profile, error: config.redact(err.message) });
      }
    }
    const fixtureResults = fixtures.down(applied);
    writeJson(path.join(outDir, 'sessions.json'), { sessions: summary, logout: results, fixtures: fixtureResults });
    const ok = results.filter((r) => r.revoked).length;
    log(`log-out: ${ok}/${opened.length} token dicabut${ok === opened.length ? '' : ' - PERIKSA: ' + JSON.stringify(results)}`);
    const failed = fixtureResults.filter((r) => !r.ok);
    if (failed.length) throw new Error(`teardown fixture GAGAL (data sisa harus dibereskan): ${failed.map((f) => f.name).join(', ')}`);
  };
};
