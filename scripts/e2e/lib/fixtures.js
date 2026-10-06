'use strict';

/**
 * Hook data opsional: `<feature-dir>/qa/e2e/fixtures.json`. Dijalankan global setup SEBELUM sesi browser dibuka
 * (supaya tidak berebut sesi/force_login dengan token e2e) dan global teardown SESUDAH log-out semua sesi.
 *
 *   { "fixtures": [ {
 *       "name": "akun-qa",                      // wajib
 *       "reason": "kenapa fixture ini perlu",   // wajib
 *       "setup": "\"$PHP_BIN\" scripts/qa-http/run.php \"$FEATURE_DIR\" --only=FIX-UP",   // wajib
 *       "teardown": "... --only=FIX-DOWN",      // sangat disarankan: buang/pulihkan persis
 *       "profiles": ["default"],                // opsional: hanya bila salah satunya dipilih run ini
 *       "cwd": "scripts",                       // opsional, relatif ke agentic/ (default agentic/)
 *       "timeout_s": 300                        // opsional
 *   } ] }
 *
 * Perintah dijalankan lewat `bash -c` (E2E_SHELL mengganti) dengan env: semua kunci config (PHP_BIN, BE_DIR, ...),
 * AGENTIC_DIR, FEATURE_DIR, E2E_PROFILES. Exit != 0 = gagal. Setup gagal -> fixture yang sudah naik dibuang lagi,
 * run berhenti. Teardown gagal dicatat keras dan menggagalkan run (data sisa harus dibereskan). Keluaran disamarkan.
 * Mati total dengan E2E_FIXTURES=0.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const config = require('./config');

function log(text) {
  // eslint-disable-next-line no-console
  console.log(`[e2e] ${config.redact(text)}`);
}

function registry() {
  const dir = config.featureE2eDir();
  const file = dir && path.join(dir, 'fixtures.json');
  if (!file || !fs.existsSync(file)) return [];
  const list = JSON.parse(fs.readFileSync(file, 'utf8')).fixtures || [];
  list.forEach((fx, i) => {
    if (!fx.name || !fx.reason || !fx.setup) throw new Error(`fixtures.json entri #${i + 1}: name, reason, setup wajib diisi`);
  });
  return list;
}

/** Fixture yang berlaku untuk run ini (profil dipilih). */
function selected(profiles) {
  if (config.get('E2E_FIXTURES', '1') === '0') return [];
  return registry().filter((fx) => !fx.profiles || fx.profiles.some((p) => profiles.includes(p)));
}

function run(fx, command) {
  const env = { ...config.fileEnv(), ...process.env, AGENTIC_DIR: config.AGENTIC_DIR, FEATURE_DIR: config.featureDir() };
  Object.keys(env).forEach((key) => {
    if (key.startsWith('E2E_SESSION_')) delete env[key]; // token sesi tidak diteruskan
  });
  const res = spawnSync(config.get('E2E_SHELL', 'bash'), ['-c', command], {
    cwd: path.resolve(config.AGENTIC_DIR, fx.cwd || '.'),
    env,
    encoding: 'utf8',
    timeout: (fx.timeout_s || 300) * 1000,
  });
  const out = config.redact(`${res.stdout || ''}${res.stderr || ''}${res.error ? res.error.message : ''}`);
  const last = out.split(/\r?\n/).filter((l) => l.trim()).pop() || '';
  return { ok: res.status === 0, status: res.status, line: last.slice(0, 400), out };
}

/**
 * Jalankan setup. Mengembalikan daftar fixture yang SUDAH dijalankan (untuk down()). Bila satu gagal, yang sudah
 * naik (termasuk yang gagal) dibuang lagi lalu melempar.
 */
function up(profiles) {
  const applied = [];
  for (const fx of selected(profiles)) {
    const r = run(fx, fx.setup);
    log(`fixture ${fx.name} setup: ${r.ok ? 'OK' : `GAGAL exit ${r.status}`} ${r.line}`);
    applied.push(fx);
    if (!r.ok) {
      down(applied);
      throw new Error(`fixture ${fx.name} gagal (exit ${r.status}):\n${r.out.slice(0, 1500)}`);
    }
  }
  return applied;
}

/** Jalankan teardown (urutan terbalik). Tidak melempar: hasil dikembalikan supaya pemanggil menilai. */
function down(applied) {
  const results = [];
  for (const fx of [...applied].reverse()) {
    if (!fx.teardown) {
      results.push({ name: fx.name, ok: true, line: 'tanpa teardown' });
      continue;
    }
    const r = run(fx, fx.teardown);
    log(`fixture ${fx.name} teardown: ${r.ok ? 'OK' : `GAGAL exit ${r.status} - PERIKSA`} ${r.line}`);
    results.push({ name: fx.name, ok: r.ok, status: r.status, line: r.line });
  }
  return results;
}

module.exports = { up, down, selected };
