/**
 * E2E: listing polish (size column, book icon, hidden .meta) and French admin Système tab.
 * Run: npx playwright test -c playwright.reader-audio.config.ts -g "polish lot4"
 */
import { test, expect, Page } from '@playwright/test';
import * as fs from 'node:fs';
import { createFixtures, removeFixtures, startServer, snapshotTmp, cleanTmp, makePdf, TestServer } from './reader-audio-fixtures';

const SHOTS = '/home/folken/audit-screens/lot4/sharebox';
let dir = '';
let srv: TestServer;
let tmpBefore: Set<string>;

test.beforeAll(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  tmpBefore = snapshotTmp();
  dir = createFixtures();
  const d = `${dir}/polish`;
  fs.mkdirSync(d);
  fs.writeFileSync(`${d}/aa.pdf`, makePdf(['A']));
  fs.writeFileSync(`${d}/bb.txt`, 'texte\n');
  fs.writeFileSync(`${d}/cc.txt`, 'un texte nettement plus long que le precedent\n'.repeat(40));
  fs.writeFileSync(`${d}/video.mkv.meta`, '');
  fs.chownSync(d, 33, 33);
  for (const f of fs.readdirSync(d)) fs.chownSync(`${d}/${f}`, 33, 33);
  srv = await startServer(dir);
});
test.afterAll(async () => {
  await srv?.stop();
  removeFixtures(dir);
  cleanTmp(tmpBefore);
});

const listing = (page: Page) => page.goto(`${srv.base}/dl/e2e-root?p=polish`);

test.describe('polish lot4: listing', () => {
  test('la colonne des tailles est alignée avec ou sans bouton Lire', async ({ page }) => {
    await listing(page);
    const rights = await page.locator('.row[data-type="file"] .row-meta').evaluateAll(
      (els) => els.map((e) => Math.round(e.getBoundingClientRect().right)));
    expect(rights).toHaveLength(3);
    expect(new Set(rights).size, `right edges ${rights}`).toBe(1);
    await page.screenshot({ path: `${SHOTS}/listing-${test.info().project.name}.png` });
  });

  test('un livre a une icône livre et un bouton Lire, un .meta est masqué', async ({ page }) => {
    await listing(page);
    await expect(page.locator('.row[data-name="aa.pdf"] .row-icon')).toHaveClass(/\bbook\b/);
    await expect(page.locator('.row[data-name="bb.txt"] .row-icon')).not.toHaveClass(/\bbook\b/);
    const play = page.locator('.row[data-name="aa.pdf"] .btn-play');
    await expect(play).toHaveAttribute('title', 'Lire');
    await expect(play).toHaveAttribute('aria-label', 'Lire');
    await expect(page.locator('.row[data-name="video.mkv.meta"]')).toHaveCount(0);
    await expect(page.locator('.row[data-type="file"]')).toHaveCount(3);
  });

  test('la recherche ne remonte pas les .meta', async ({ page }) => {
    await page.goto(`${srv.base}/dl/e2e-root?q=video`);
    await expect(page.locator('body')).not.toContainText('video.mkv.meta');
  });
});

test.describe('polish lot4: admin Système en français', () => {
  test.use({ locale: 'en-US' });

  test('horloge 24 h et tailles en Go/To avec virgule', async ({ page }) => {
    const r = await page.goto(`${srv.base}/share/admin.php`);
    test.skip(!r || r.status() !== 200, 'admin.php not reachable in the test server');
    const fam = await page.locator('.btn').first().evaluate((e) => getComputedStyle(e).fontFamily);
    expect(fam).not.toContain('Sora');
    await page.evaluate(() => (window as any).switchTab('systeme'));
    await expect(page.locator('#streams-last-update')).toHaveText(/^\d{2}:\d{2}:\d{2}$/);
    const f = (n: number) => page.evaluate((v) => (window as any).formatBytes(v), n);
    expect(await f(4357.65 * 1024 ** 3)).toBe('4,26 To');
    expect(await f(500 * 1024 ** 3)).toBe('500,00 Go');
    expect(await f(1536)).toBe('1,5 Ko');
    await page.screenshot({ path: `${SHOTS}/admin-systeme-${test.info().project.name}.png` });
  });
});
