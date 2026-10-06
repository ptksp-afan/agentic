'use strict';

// Jalur impor stabil untuk spec fitur di <feature-dir>/qa/e2e/*.spec.js:
//   const { test, expect } = require('e2e-harness');
// Resolve lewat NODE_PATH yang diisi playwright.config.js (scripts/e2e/modules + scripts/e2e/node_modules).
module.exports = require('../../lib/test');
