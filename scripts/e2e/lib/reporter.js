'use strict';

/**
 * Reporter harness: satu baris per test saat berjalan, lalu di akhir menulis
 *   <out>/report.json  (hasil lengkap, tanpa rahasia)
 *   <out>/index.html   (galeri screenshot + error, untuk dilihat sekilas developer di Gate 2)
 * dan mencetak ringkasan per route/flow.
 */

const fs = require('fs');
const path = require('path');

const config = require('./config');

const LABEL = { passed: 'OK   ', failed: 'GAGAL', timedOut: 'GAGAL', interrupted: 'HENTI', skipped: 'SKIP ' };

const HARNESS_TESTS = path.join(config.E2E_DIR, 'tests');

/** route = smoke routes.json, all-routes = --all-routes, flow = spec fitur. Hanya spec harness yang dihitung route. */
function kindOf(test) {
  if (path.resolve(path.dirname(test.location.file)).toLowerCase() !== HARNESS_TESTS.toLowerCase()) return 'flow';
  return path.basename(test.location.file) === 'all-routes.spec.js' ? 'all-routes' : 'route';
}

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    return null;
  }
}

function esc(text) {
  return String(text === undefined || text === null ? '' : text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function firstLine(text, max = 400) {
  return String(text || '')
    .replace(/\u001b\[[0-9;]*m/g, '')
    .split('\n')
    .filter((l) => l.trim())
    .slice(0, 6)
    .join('\n')
    .slice(0, max);
}

class E2EReporter {
  constructor() {
    this.rows = [];
    this.startedAt = new Date();
  }

  printsToStdio() {
    return true;
  }

  onBegin(configOrSuite, maybeSuite) {
    const suite = maybeSuite || configOrSuite;
    this.total = suite.allTests().length;
    const out = process.env.E2E_OUT_DIR;
    // eslint-disable-next-line no-console
    console.log(`[e2e] ${this.total} test, fitur ${process.env.E2E_FEATURE || 'all'}, output ${out}`);
  }

  /** Error di luar test (global setup, webServer, berkas spec rusak): tanpa ini pesannya hilang. */
  onError(error) {
    this.globalErrors = this.globalErrors || [];
    const text = config.redact(firstLine(error.message || error.value || String(error), 2000));
    this.globalErrors.push(text);
    // eslint-disable-next-line no-console
    console.error(`[e2e] ERROR: ${text}`);
  }

  onTestEnd(test, result) {
    const project = test.parent.project();
    const profile = project ? (project.use && project.use.profile) || project.name : '-';
    const att = result.attachments.find((a) => a.name === 'e2e' && a.body);
    const data = att ? JSON.parse(att.body.toString('utf8')) : {};
    const errors = data.errors || [];
    const skipReason = (test.annotations.find((a) => a.type === 'skip') || {}).description;
    const row = {
      kind: kindOf(test),
      profile,
      title: test.title,
      file: path.basename(test.location.file),
      status: result.status,
      duration_ms: result.duration,
      path: data.path || null,
      error: result.error ? config.redact(firstLine(result.error.message || result.error.value, 1500)) : null,
      skip_reason: result.status === 'skipped' ? config.redact(skipReason || '') : undefined,
      errors: errors.filter((e) => !e.allowed),
      allowed: errors.filter((e) => e.allowed),
      notes: (data.notes || []).concat(test.annotations.filter((a) => a.type === 'note').map((a) => a.description)),
      screenshots: data.screenshots || [],
      api: data.api || [],
    };
    this.rows.push(row);
    const secs = (result.duration / 1000).toFixed(1);
    const extra = row.status === 'skipped' && row.skip_reason ? ` - ${row.skip_reason}` : '';
    // eslint-disable-next-line no-console
    console.log(`  ${LABEL[result.status] || result.status} ${row.title} (${secs} dtk)${extra}`);
  }

  async onEnd(result) {
    const outDir = process.env.E2E_OUT_DIR;
    fs.mkdirSync(outDir, { recursive: true });
    const counts = this.rows.reduce((acc, r) => {
      const key = r.status === 'timedOut' || r.status === 'interrupted' ? 'failed' : r.status;
      acc[key] = (acc[key] || 0) + 1;
      return acc;
    }, {});
    const report = {
      generated_at: new Date().toISOString(),
      started_at: this.startedAt.toISOString(),
      status: result.status,
      feature: process.env.E2E_FEATURE || 'all',
      profiles: (process.env.E2E_PROFILES || '').split(',').filter(Boolean),
      all_routes: process.env.E2E_ALL_ROUTES === '1',
      app: config.appBase(),
      api: config.apiBase(),
      out_dir: outDir,
      counts,
      errors: this.globalErrors || [],
      staging: readJson(path.join(outDir, 'staging.json')),
      sessions: readJson(path.join(outDir, 'sessions.json')),
      tests: this.rows,
    };
    fs.writeFileSync(path.join(outDir, 'report.json'), `${config.redact(JSON.stringify(report, null, 2))}\n`);
    fs.writeFileSync(path.join(outDir, 'index.html'), config.redact(this.html(report)));

    const log = (t) => console.log(t); // eslint-disable-line no-console
    log('');
    log('================ Ringkasan E2E ================');
    for (const kind of ['route', 'flow', 'all-routes']) {
      const rows = this.rows.filter((r) => r.kind === kind);
      if (!rows.length) continue;
      log(kind === 'route' ? 'Route smoke:' : kind === 'flow' ? 'Flow fitur:' : 'Semua route (scope):');
      rows.forEach((r) => {
        const why = r.status === 'skipped' ? ` - ${r.skip_reason || ''}` : r.errors.length ? ` - ${r.errors.length} error` : '';
        const allowed = r.allowed.length ? ` (${r.allowed.length} error allow-list)` : '';
        log(`  ${LABEL[r.status] || r.status} ${r.title}${why}${allowed}`);
      });
    }
    log(`Total: ${counts.passed || 0} OK, ${counts.failed || 0} GAGAL, ${counts.skipped || 0} SKIP`);
    (this.globalErrors || []).forEach((e) => log(`ERROR di luar test: ${e.split('\n')[0]}`));
    log(`Laporan : ${path.join(outDir, 'report.json')}`);
    log(`Galeri  : ${path.join(outDir, 'index.html')}`);
    log(`Gambar  : ${path.join(outDir, 'screenshots')}`);
  }

  html(report) {
    const badge = (s) =>
      `<span class="b ${esc(s)}">${esc((LABEL[s] || s).trim())}</span>`;
    const errList = (list, cls) =>
      list.length
        ? `<ul class="${cls}">${list
            .map((e) => {
              const text = e.kind === 'response' ? `HTTP ${e.status} ${e.method} ${e.url}` : e.kind === 'requestfailed' ? `${e.method} ${e.url}: ${e.text}` : `${e.kind}: ${e.text}`;
              return `<li>${esc(text.slice(0, 600))}${e.allowed ? ` <em>[allow-list ${esc(e.allowed)}]</em>` : ''}</li>`;
            })
            .join('')}</ul>`
        : '';
    const rows = report.tests
      .map(
        (r) => `<section class="t ${esc(r.status)}">
  <h3>${badge(r.status)} ${esc(r.title)} <small>${esc(r.kind)} · ${(r.duration_ms / 1000).toFixed(1)} dtk</small></h3>
  ${r.skip_reason ? `<p class="muted">${esc(r.skip_reason)}</p>` : ''}
  ${r.error ? `<pre>${esc(r.error)}</pre>` : ''}
  ${errList(r.errors, 'err')}${errList(r.allowed, 'allow')}
  ${r.notes.length ? `<p class="muted">${r.notes.map(esc).join('<br>')}</p>` : ''}
  <div class="shots">${r.screenshots.map((s) => `<a href="${esc(s)}"><img loading="lazy" src="${esc(s)}" alt="${esc(s)}"></a>`).join('')}</div>
</section>`,
      )
      .join('\n');
    return `<!doctype html><html lang="id"><head><meta charset="utf-8"><title>E2E ${esc(report.feature)}</title>
<style>body{font:14px/1.4 system-ui,sans-serif;margin:16px;background:#fafafa;color:#222}h1{font-size:18px}
.t{background:#fff;border:1px solid #ddd;border-left:4px solid #999;border-radius:4px;padding:8px 12px;margin:10px 0}
.t.passed{border-left-color:#2e7d32}.t.failed,.t.timedOut{border-left-color:#c62828}.t.skipped{border-left-color:#9e9e9e}
h3{font-size:14px;margin:4px 0}small,.muted{color:#777}.b{display:inline-block;padding:0 6px;border-radius:3px;color:#fff;background:#999;font-size:12px}
.b.passed{background:#2e7d32}.b.failed,.b.timedOut{background:#c62828}pre{white-space:pre-wrap;background:#fff3f3;padding:6px;font-size:12px}
ul.err li{color:#c62828}ul.allow li{color:#8a6d3b}.shots img{max-width:420px;max-height:260px;border:1px solid #ccc;margin:4px 4px 0 0}</style></head>
<body><h1>E2E · ${esc(report.feature)} · ${esc(report.profiles.join(', '))}</h1>
<p>${esc(report.generated_at)} · OK ${report.counts.passed || 0} · GAGAL ${report.counts.failed || 0} · SKIP ${report.counts.skipped || 0}
 · FE ${esc(report.app)} → BE ${esc(report.api)} · build staging ${esc(report.staging && report.staging.builtAt)}</p>
<p class="muted">Screenshot untuk dilihat sekilas; penilaian visual tetap oleh manusia.</p>
${rows}</body></html>`;
  }
}

module.exports = E2EReporter;
