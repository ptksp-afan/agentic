'use strict';

/**
 * Smoke route per fitur: untuk setiap route di <feature-dir>/qa/e2e/routes.json dan setiap profilnya, buka
 * layar, tunggu tenang, screenshot halaman penuh, dan gagal bila ada console error, page error, respons
 * >= 400, atau request gagal yang tidak di-allow-list. Route dengan `expectRedirect` (mis. layar yang harus
 * ditolak untuk profil ini) lulus bila FE mengalihkan ke path itu tanpa error.
 *
 * Bentuk routes.json: lihat lib/routes.js dan README.md. Ringkas:
 *   { "name", "path" (dari src/routes/paths.js), "profiles"?: [..], "waitFor"?: selector yang wajib tampil,
 *     "expectRedirect"?: "/unathorized", "forbidApi"?: regex URL API yang tidak boleh terpanggil, "ref"?: acuan }
 */

const { test, expect } = require('../lib/test');
const { loadFeature, profilesOf } = require('../lib/routes');

const spec = loadFeature();

if (spec && spec.routes.length) {
  test.describe(`${spec.feature} route smoke`, () => {
    test.use({ allow: spec.allow });

    for (const route of spec.routes) {
      for (const profile of profilesOf(route)) {
        const label = route.name ? `${route.name} ${route.path}` : route.path;
        test(`[${profile}] ${label}`, async ({ app, monitor }) => {
          if (route.ref) test.info().annotations.push({ type: 'note', description: `acuan: ${route.ref}` });

          await app.open(route.path, { waitFor: route.expectRedirect ? undefined : route.waitFor });

          if (route.expectRedirect) {
            await expect
              .poll(async () => String(await app.currentPath()).split('?')[0], {
                message: `${route.path} harus dialihkan ke ${route.expectRedirect}`,
                timeout: 10000,
              })
              .toBe(route.expectRedirect);
            await app.settle();
            await app.screenshot(`smoke-${route.path}`);
          } else {
            await app.screenshot(`smoke-${route.path}`);
            const redirect = app.redirectOf(route.path);
            expect(redirect, `${route.path}: ${redirect}`).toBeNull();
          }
          if (route.forbidApi) {
            // mis. layar yang ditolak untuk profil ini tidak boleh sempat memanggil API-nya
            const hits = monitor.apiResponses().filter((r) => new RegExp(route.forbidApi).test(r.url));
            expect(hits.map((r) => `${r.method} ${r.url}`), `request ${route.forbidApi} tidak boleh terkirim`).toEqual([]);
          }
          monitor.check();
        });
      }
    }
  });
}
