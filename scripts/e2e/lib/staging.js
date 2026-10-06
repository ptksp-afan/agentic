'use strict';

/**
 * Build FE staging (FE_STAGING_DIR, bukan build/ milik developer): ada/tidak, dan apakah lebih tua
 * dari commit FE terakhir atau ada perubahan FE yang belum di-commit. Hanya membaca repo FE (FE_DIR).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const config = require('./config');

const BUILD_HINT = () =>
  `cd ${config.feDir()} && BUILD_PATH="${config.stagingDir()}" yarn build   (±5 menit; JANGAN build ke build/)`;

function git(args) {
  if (!config.feDir()) return null;
  try {
    return execFileSync('git', ['-C', config.feDir(), ...args], { stdio: ['ignore', 'pipe', 'ignore'], timeout: 20000 })
      .toString()
      .trim();
  } catch (e) {
    return null;
  }
}

/**
 * @returns {{ok: boolean, dir: string, builtAt: string|null, feHead: string|null, feHeadAt: string|null,
 *   feDirty: string[]|null, stale: boolean, message: string}}
 */
function inspect() {
  const dir = config.stagingDir();
  const index = path.join(dir, 'index.html');
  if (!fs.existsSync(index)) {
    return {
      ok: false,
      dir,
      builtAt: null,
      stale: true,
      message: `Build FE staging tidak ada (${index}). Orchestrator: build dulu ke staging: ${BUILD_HINT()}`,
    };
  }

  const builtMs = fs.statSync(index).mtimeMs;
  const head = git(['log', '-1', '--format=%h|%ct|%s']);
  const [feHead, feHeadTs, feSubject] = head ? head.split('|') : [null, null, null];
  const dirtyRaw = git(['status', '--porcelain', '--', 'src', 'public', 'package.json', '.env']);
  const feDirty = dirtyRaw === null ? null : dirtyRaw.split(/\r?\n/).filter(Boolean);
  const headMs = feHeadTs ? Number(feHeadTs) * 1000 : null;

  const reasons = [];
  if (headMs && headMs > builtMs) reasons.push(`commit FE ${feHead} (${new Date(headMs).toISOString()}) lebih baru dari build`);
  if (feDirty && feDirty.length) reasons.push(`${feDirty.length} berkas FE belum di-commit (build mungkin tidak memuatnya)`);

  const stale = reasons.length > 0;
  return {
    ok: true,
    dir,
    builtAt: new Date(builtMs).toISOString(),
    feHead,
    feHeadSubject: feSubject,
    feHeadAt: headMs ? new Date(headMs).toISOString() : null,
    feDirty,
    stale,
    message: stale
      ? `Build staging MUNGKIN USANG: ${reasons.join('; ')}. Build ulang: ${BUILD_HINT()}`
      : feHead
        ? `Build staging ${new Date(builtMs).toISOString()} >= commit FE ${feHead}`
        : `Build staging ${new Date(builtMs).toISOString()} (repo FE tidak terbaca: umur build tidak dicek)`,
  };
}

module.exports = { inspect, BUILD_HINT };
