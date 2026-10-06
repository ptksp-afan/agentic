'use strict';

/**
 * Klien HTTP kecil tanpa dependensi (Node 16 belum punya `fetch` stabil). Hanya untuk API BE:
 * login, pilih DB, log-out. Token tidak pernah dicetak; pesan gagal lewat `config.redact`.
 */

const http = require('http');
const https = require('https');
const { URL } = require('url');

const config = require('./config');

/**
 * @param {string} method
 * @param {string} uri relatif ke `<API_BASE>/<api/v5>` (mis. `auth/info`), atau URL absolut
 * @param {{token?: string, body?: object, timeout?: number, headers?: object}} [opts]
 * @returns {Promise<{status: number, headers: object, text: string, json: any}>}
 */
function request(method, uri, opts = {}) {
  const { token, body, timeout = 60000, headers = {} } = opts;
  const url = /^https?:\/\//.test(uri) ? new URL(uri) : new URL(`${config.apiBase()}/${config.apiBasePath()}/${uri.replace(/^\/+/, '')}`);
  const payload = body === undefined ? null : Buffer.from(JSON.stringify(body));
  const lib = url.protocol === 'https:' ? https : http;

  const reqHeaders = { Accept: 'application/json', ...headers };
  if (token) reqHeaders.Authorization = `Bearer ${token}`;
  if (payload) {
    reqHeaders['Content-Type'] = 'application/json';
    reqHeaders['Content-Length'] = payload.length;
  }

  return new Promise((resolve, reject) => {
    const req = lib.request(url, { method: method.toUpperCase(), headers: reqHeaders, timeout }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        let json = null;
        try {
          json = JSON.parse(text);
        } catch (e) {
          json = null;
        }
        resolve({ status: res.statusCode, headers: res.headers, text, json });
      });
    });
    req.on('timeout', () => req.destroy(new Error(`timeout ${timeout} ms`)));
    req.on('error', (err) =>
      reject(new Error(config.redact(`${method.toUpperCase()} ${url.origin}${url.pathname} gagal: ${err.message}`))),
    );
    if (payload) req.write(payload);
    req.end();
  });
}

/** Kode pesan BE dari body sukses/gagal (`msg_code` atau `code`). */
function msgCode(res) {
  return (res && res.json && (res.json.msg_code || res.json.code)) || '-';
}

module.exports = { request, msgCode };
