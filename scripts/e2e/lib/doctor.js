'use strict';

/**
 * Cek kesiapan harness tanpa membuka browser (dipanggil `run.sh --doctor`):
 *
 *   node lib/doctor.js                       # config, Chrome, build staging, BE, CORS, folder fitur (tanpa login)
 *   node lib/doctor.js --login               # + login/bind/log-out tiap profil (E2E_PROFILES)
 *   E2E_PROFILES=reguler E2E_FEATURE_DIR=<dir> node lib/doctor.js --login
 *
 * Exit 0 = siap, 1 = ada masalah, 3 = profil di-skip (kredensial/DB tidak cocok).
 * Nilai rahasia tidak pernah dicetak, hanya nama kunci.
 */

const fs = require('fs');
const http = require('http');
const path = require('path');

const config = require('./config');
const staging = require('./staging');
const { request } = require('./http');
const { selectedProfiles, profileOf, credentialKeyHint, dbAllowed } = require('./profiles');
const session = require('./session');
const routes = require('./routes');
const fixtures = require('./fixtures');

const withLogin = process.argv.slice(2).includes('--login');

function line(label, value) {
  // eslint-disable-next-line no-console
  console.log(`${label.padEnd(18)} ${value}`);
}

function healthOf(url) {
  return new Promise((resolve) => {
    const req = http.get(url, { timeout: 3000 }, (res) => {
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => resolve({ status: res.statusCode, body }));
    });
    req.on('error', () => resolve(null));
    req.on('timeout', () => {
      req.destroy();
      resolve(null);
    });
  });
}

function chromePath() {
  const env = process.env;
  const candidates = [
    env.CHROME_PATH,
    env.PROGRAMFILES && path.join(env.PROGRAMFILES, 'Google', 'Chrome', 'Application', 'chrome.exe'),
    env['PROGRAMFILES(X86)'] && path.join(env['PROGRAMFILES(X86)'], 'Google', 'Chrome', 'Application', 'chrome.exe'),
    env.LOCALAPPDATA && path.join(env.LOCALAPPDATA, 'Google', 'Chrome', 'Application', 'chrome.exe'),
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/opt/google/chrome/chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ];
  return candidates.find((p) => p && fs.existsSync(p)) || null;
}

async function main() {
  let problems = 0;
  let skips = 0;
  const profiles = selectedProfiles();

  line('config', `${config.CONFIG_FILES.map((f) => path.basename(f) + (fs.existsSync(f) ? '' : ' (TIDAK ADA)')).join(', ')}; kunci: ${config.fileKeys().join(', ') || '(kosong)'}`);
  line('node_modules', fs.existsSync(path.join(config.E2E_DIR, 'node_modules', '@playwright', 'test', 'package.json')) ? 'ada' : 'BELUM (run.sh memasang dengan npm ci)');
  const chrome = chromePath();
  line('Chrome sistem', chrome || 'TIDAK DITEMUKAN (harness memakai channel chrome; pasang Chrome)');
  if (!chrome) problems += 1;

  for (const name of profiles) {
    const p = profileOf(name);
    const dbNote = p.db ? `DB ${p.db}${dbAllowed(p.db) ? '' : ' (TIDAK ada di QA_DBS -> SKIP)'}` : 'DB: satu-satunya milik user';
    line(`profil ${name}`, p.hasCredentials ? `${p.userKey}/${p.passKey} terisi; ${dbNote}` : `kredensial TIDAK ADA (isi ${credentialKeyHint(name)}); ${dbNote}`);
    if (!p.hasCredentials || (p.db && !dbAllowed(p.db))) skips += 1;
  }

  let st;
  try {
    st = staging.inspect();
    line('build staging', st.ok ? `${st.dir} (${st.builtAt})` : st.message);
    if (!st.ok) problems += 1;
    else line('', st.message);
  } catch (e) {
    line('build staging', `MASALAH: ${e.message}`);
    problems += 1;
  }

  try {
    line('app', `${config.appBase()} (E2E_PORT)`);
    const health = await healthOf(`${config.appBase()}/__e2e_health`);
    if (!health) line('', 'port bebas (server statis dijalankan Playwright saat run)');
    else if (/agentic-e2e/.test(health.body)) line('', 'server statis harness sudah jalan (dipakai ulang)');
    else {
      line('', `PORT DIPAKAI PROSES LAIN (HTTP ${health.status}); pakai E2E_PORT lain`);
      problems += 1;
    }
  } catch (e) {
    line('app', `MASALAH: ${e.message}`);
    problems += 1;
  }

  const base = config.apiBase();
  try {
    const res = await request('GET', `${base}/`, { timeout: 10000 });
    line('BE', `${base} -> HTTP ${res.status}`);
  } catch (e) {
    line('BE', `${base} TIDAK MENJAWAB: ${e.message}`);
    problems += 1;
  }

  try {
    const res = await request('OPTIONS', 'auth/info', {
      timeout: 10000,
      headers: {
        Origin: config.appBase(),
        'Access-Control-Request-Method': 'PUT',
        'Access-Control-Request-Headers': 'authorization,content-type,accept',
      },
    });
    const allow = res.headers['access-control-allow-origin'];
    const ok = allow === '*' || allow === config.appBase();
    line('CORS', ok ? `OK (Allow-Origin: ${allow})` : `DITOLAK untuk ${config.appBase()} (Allow-Origin: ${allow || '-'}) - laporkan, jangan ubah config BE`);
    if (!ok) problems += 1;
  } catch (e) {
    line('CORS', `gagal dicek: ${e.message}`);
    problems += 1;
  }

  if (config.featureDir()) {
    try {
      const dir = config.featureE2eDir();
      const specs = dir ? fs.readdirSync(dir).filter((f) => /\.spec\.js$/.test(f)) : [];
      const feature = routes.loadFeature();
      const fx = fixtures.selected(profiles);
      line('fitur', `${config.featureDir()}: routes.json ${feature ? `${feature.routes.length} route` : 'tidak ada'}, ${specs.length} spec, ${fx.length} fixture`);
      if (!dir || (!feature && !specs.length)) {
        line('', 'qa/e2e/ kosong: butuh routes.json dan/atau *.spec.js');
        problems += 1;
      }
    } catch (e) {
      line('fitur', `MASALAH: ${config.redact(e.message)}`);
      problems += 1;
    }
  }

  if (withLogin) {
    for (const name of profiles) {
      try {
        const s = await session.openSession(name);
        line(`login ${name}`, JSON.stringify(session.describe(s)));
        line('', `websocket_conf: host=${s.websocket.host ? 'ada' : '-'} port=${s.websocket.port || '-'} key=${s.websocket.key ? 'ada' : '-'}`);
        const out = await session.logout(s.token);
        line(`log-out ${name}`, `HTTP ${out.status}, token dicabut: ${out.revoked ? 'ya' : 'TIDAK'}`);
        if (!out.revoked) problems += 1;
      } catch (e) {
        if (e instanceof session.SessionSkip) {
          line(`login ${name}`, `SKIP: ${config.redact(e.message)}`);
          skips += 1;
        } else {
          line(`login ${name}`, `GAGAL: ${config.redact(e.message)}`);
          problems += 1;
        }
      }
    }
  }

  line('hasil', problems ? `${problems} MASALAH` : skips ? `siap, tetapi ${skips} profil akan di-SKIP` : 'siap');
  process.exit(problems ? 1 : skips ? 3 : 0);
}

main().catch((e) => {
  // eslint-disable-next-line no-console
  console.error(config.redact(e.stack || e.message));
  process.exit(1);
});
