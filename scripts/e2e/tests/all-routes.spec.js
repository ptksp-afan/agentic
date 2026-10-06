'use strict';

/**
 * Mode opt-in `--all-routes` (env E2E_ALL_ROUTES=1): buka SETIAP layar statis di src/routes/paths.js FE untuk
 * setiap profil, satu test per path. Lambat (±3 dtk per layar, ratusan layar). Path `/add` hanya bila
 * E2E_INCLUDE_ADD=1; path ber-parameter (`:id`) tidak.
 *
 * Pengalihan ke /unathorized di mode ini = user profil itu tidak punya permission/scope → SKIP, bukan GAGAL.
 */

const { test, expect } = require('../lib/test');
const { allowedPaths } = require('../lib/routes');
const { selectedProfiles } = require('../lib/profiles');

if (process.env.E2E_ALL_ROUTES === '1') {
  const paths = allowedPaths();
  for (const profile of selectedProfiles()) {
    test.describe(`semua route ${profile}`, () => {
      for (const routePath of paths) {
        test(`[${profile}] semua-route ${routePath}`, async ({ app, monitor }) => {
          await app.open(routePath);
          await app.screenshot(`all-${routePath}`);
          const redirect = app.redirectOf(routePath);
          if (redirect && /unathorized/.test(redirect)) {
            monitor.check();
            test.skip(true, `${redirect} - kemungkinan permission user`);
          }
          expect(redirect, `${routePath}: ${redirect}`).toBeNull();
          monitor.check();
        });
      }
    });
  }
}
