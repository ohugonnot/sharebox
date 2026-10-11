/**
 * E2E: mobile polish (412 px) of the listing toolbar and the admin tabs.
 * Own fixtures + php -S, same helpers as reader-audio.spec.ts.
 * Run: npx playwright test -c playwright.reader-audio.config.ts -g "mobile portrait: polish" --project=mobile
 */
import { test, expect, Page } from '@playwright/test';
import * as fs from 'node:fs';
import { createFixtures, removeFixtures, startServer, snapshotTmp, cleanTmp, makePdf, TestServer } from './reader-audio-fixtures';

const SHOTS = '/home/folken/audit-screens/lot3/sharebox';
let dir = '';
let srv: TestServer;
let tmpBefore: Set<string>;

test.beforeAll(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  tmpBefore = snapshotTmp();
  dir = createFixtures();
  // 12 books: enough items for the search field to appear in a book folder.
  const many = `${dir}/livres-many`;
  fs.mkdirSync(many);
  for (let i = 1; i <= 12; i++) fs.writeFileSync(`${many}/Un livre au titre assez long numero ${i}.pdf`, makePdf([`B${i}`]));
  fs.chownSync(many, 33, 33);
  for (const f of fs.readdirSync(many)) fs.chownSync(`${many}/${f}`, 33, 33);
  srv = await startServer(dir);
});
test.afterAll(async () => {
  await srv?.stop();
  removeFixtures(dir);
  cleanTmp(tmpBefore);
});

test.describe('mobile portrait: polish', () => {
  test.use({ viewport: { width: 412, height: 915 } });
  test.beforeEach(() => { test.skip(test.info().project.name !== 'mobile', 'mobile only'); });

  const noHScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

  async function checkToolbar(page: Page, p: string, shot: string) {
    await page.goto(`${srv.base}/dl/e2e-root` + (p ? `?p=${encodeURIComponent(p)}` : ''));
    await page.locator('.toolbar').waitFor();
    const heights = await page.locator('.toolbar .tb-icon, .toolbar .tb-refresh, .toolbar .sort-btn').evaluateAll(
      (els) => els.filter((e) => (e as HTMLElement).offsetParent !== null).map((e) => (e as HTMLElement).getBoundingClientRect()),
    );
    expect(heights.length).toBeGreaterThanOrEqual(3);
    for (const r of heights) {
      expect(r.height).toBeGreaterThanOrEqual(40);
      expect(r.width).toBeGreaterThanOrEqual(40 - 1);
    }
    const search = page.locator('#main-search');
    await expect(search).toBeVisible();
    expect((await search.boundingBox())!.width).toBeGreaterThanOrEqual(120);
    expect(await noHScroll(page)).toBe(true);
    await page.screenshot({ path: `${SHOTS}/toolbar-${shot}.png`, clip: { x: 0, y: 0, width: 412, height: 420 } });
  }

  test('racine: icônes, tri et recherche tactiles, sans scroll horizontal', async ({ page }) => {
    await checkToolbar(page, '', 'root');
  });

  test('dossier de livres: la recherche reste visible à côté de la Bibliothèque', async ({ page }) => {
    await checkToolbar(page, 'livres-many', 'books');
    await expect(page.locator('.toolbar .btn-media')).toBeVisible();
  });

  test('admin: tous les onglets tiennent dans la largeur', async ({ page }) => {
    const r = await page.goto(`${srv.base}/share/admin.php`);
    test.skip(!r || r.status() !== 200, 'admin.php not reachable in the test server');
    // The Statistiques tab only exists in seedbox mode: add it to check the worst case.
    await page.evaluate(() => {
      const t = document.querySelector('.admin-tabs .tab-btn')!;
      const c = t.cloneNode(true) as HTMLElement;
      c.textContent = 'Statistiques';
      t.nextElementSibling!.after(c);
    });
    const tabs = page.locator('.admin-tabs .tab-btn');
    await expect(tabs.first()).toBeVisible();
    const n = await tabs.count();
    for (let i = 0; i < n; i++) {
      const b = (await tabs.nth(i).boundingBox())!;
      expect(b.x + b.width, `tab ${i} right edge`).toBeLessThanOrEqual(412);
      expect(b.x).toBeGreaterThanOrEqual(0);
    }
    for (const h of await page.locator('.actions .btn').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height))) {
      expect(h).toBeGreaterThanOrEqual(40);
    }
    expect(await noHScroll(page)).toBe(true);
    await page.screenshot({ path: `${SHOTS}/admin-tabs.png`, clip: { x: 0, y: 0, width: 412, height: 500 } });
  });
});
