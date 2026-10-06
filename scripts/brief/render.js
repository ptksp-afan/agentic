'use strict';
// Render berkas HTML (export Claude Design, mockup) ke PNG desktop + mobile, supaya agent bisa
// "melihat" desainnya (Read tool membaca gambar). Hanya membuka berkas lokal; tidak login ke mana pun.
//   node scripts/brief/render.js <out-dir> <file.html> [file2.html ...]
// Dipanggil lewat scripts/brief-render.sh (mengurus dependensi Playwright dari scripts/e2e).
const path = require('path');
const fs = require('fs');
const { pathToFileURL } = require('url');
const { chromium } = require(path.join(__dirname, '..', 'e2e', 'node_modules', '@playwright', 'test'));

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
];

(async () => {
  const [outDir, ...files] = process.argv.slice(2);
  if (!outDir || files.length === 0) {
    console.error('pakai: node scripts/brief/render.js <out-dir> <file.html> [...]');
    process.exit(2);
  }
  fs.mkdirSync(outDir, { recursive: true });
  const chromePath = process.env.CHROME_PATH; // dari workspace.env lewat brief-render.sh; kosong = channel 'chrome'
  const browser = await chromium.launch(chromePath ? { executablePath: chromePath, headless: true } : { channel: 'chrome', headless: true });
  let failed = 0;
  try {
    for (const file of files) {
      const abs = path.resolve(file);
      const base = path.basename(abs).replace(/\.html?$/i, '');
      for (const vp of VIEWPORTS) {
        const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
        try {
          await page.goto(pathToFileURL(abs).href, { waitUntil: 'load', timeout: 30000 });
          await page.waitForTimeout(800); // font/animasi
          const out = path.join(outDir, `${base}-${vp.name}.png`);
          await page.screenshot({ path: out, fullPage: true });
          console.log(out);
        } catch (e) {
          failed += 1;
          console.error(`gagal render ${abs} (${vp.name}): ${String(e.message).split('\n')[0]}`);
        } finally {
          await page.close();
        }
      }
    }
  } finally {
    await browser.close();
  }
  process.exit(failed ? 1 : 0);
})();
