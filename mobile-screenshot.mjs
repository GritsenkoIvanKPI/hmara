import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const dir = path.join(__dirname, 'temporary screenshots');
const url = process.argv[2] || 'http://localhost:3001';
const label = process.argv[3] || 'mobile';

const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true });
await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
await new Promise(r => setTimeout(r, 1200));
await page.evaluate(() => { document.querySelectorAll('.reveal').forEach(el => el.classList.add('visible')); });
await new Promise(r => setTimeout(r, 600));

const totalHeight = await page.evaluate(() => document.body.scrollHeight);
const viewportHeight = 844;
let i = 0;
for (let y = 0; y < totalHeight; y += viewportHeight) {
  await page.evaluate((sy) => window.scrollTo(0, sy), y);
  await new Promise(r => setTimeout(r, 150));
  await page.screenshot({ path: path.join(dir, `${label}-${String(i).padStart(2, '0')}.png`) });
  i++;
}
await browser.close();
console.log('done', i, 'segments, totalHeight', totalHeight);
