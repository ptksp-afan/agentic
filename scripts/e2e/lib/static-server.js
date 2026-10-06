'use strict';

/**
 * Server statis kecil untuk build FE staging, dengan fallback SPA ke index.html. Dijalankan dan
 * dihentikan oleh Playwright (`webServer` di playwright.config.js). Tidak pernah memakai FE_SERVE_PORT (4000)
 * atau folder build/ milik developer (config.js menolak keduanya).
 *
 *   node lib/static-server.js            # FE_STAGING_DIR, E2E_PORT (default 4100)
 *
 * GET /__e2e_health → {"server":"agentic-e2e","root":...} supaya harness bisa memastikan port itu
 * memang server ini, bukan proses lain.
 */

const fs = require('fs');
const http = require('http');
const path = require('path');

const config = require('./config');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.otf': 'font/otf',
  '.eot': 'application/vnd.ms-fontobject',
  '.wasm': 'application/wasm',
  '.webmanifest': 'application/manifest+json',
};

const root = path.resolve(config.stagingDir());
const port = config.port();
const indexFile = path.join(root, 'index.html');

if (!fs.existsSync(indexFile)) {
  // eslint-disable-next-line no-console
  console.error(`[e2e-static] Build FE staging tidak ada: ${indexFile}. Build dulu ke staging (lihat README).`);
  process.exit(3);
}

function send(res, status, file) {
  const type = MIME[path.extname(file).toLowerCase()] || 'application/octet-stream';
  res.writeHead(status, {
    'Content-Type': type,
    // index.html jangan di-cache: build staging bisa diganti di antara run
    'Cache-Control': file === indexFile ? 'no-store' : 'public, max-age=3600',
  });
  fs.createReadStream(file).pipe(res);
}

const server = http.createServer((req, res) => {
  let pathname;
  try {
    pathname = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  } catch (e) {
    res.writeHead(400).end();
    return;
  }

  if (pathname === '/__e2e_health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ server: 'agentic-e2e', root }));
    return;
  }

  const file = path.resolve(root, `.${pathname}`);
  if (!file.startsWith(root)) {
    res.writeHead(403).end();
    return;
  }

  fs.stat(file, (err, stat) => {
    if (!err && stat.isFile()) return send(res, 200, file);
    // aset yang hilang (berekstensi) → 404 apa adanya, supaya chunk yang hilang terlihat di laporan
    if (path.extname(pathname)) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('not found');
      return;
    }
    return send(res, 200, indexFile);
  });
});

server.listen(port, '127.0.0.1', () => {
  // eslint-disable-next-line no-console
  console.log(`[e2e-static] ${root} → http://127.0.0.1:${port}`);
});

const stop = () => server.close(() => process.exit(0));
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
