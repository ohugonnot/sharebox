/**
 * E2E: PDF reader and audiobook player in the public share listing.
 *
 * Self-contained: builds fixtures in /home/folken/.sharebox_e2e_<hex>/, starts its own
 * php -S (router in reader-audio-router.php) and removes everything in afterAll.
 * Run: npx playwright test -c playwright.reader-audio.config.ts
 * Screenshots: /home/folken/sharebox-screens/sb-<desktop|mobile>-<step>.png
 */
import { test as base, expect, Page, Locator } from '@playwright/test';
import * as fs from 'node:fs';
import {
  createFixtures, removeFixtures, startServer, snapshotTmp, cleanTmp, TestServer, HOSTILE_PDFS, HOSTILE_MP3S, COMIC_COLORS, LONG_COMIC_COLORS,
} from './reader-audio-fixtures';

const SHOTS = '/home/folken/sharebox-screens';
const TOKEN = 'e2e-root';

interface Audit { pageErrors: string[]; csp: string[]; bad: string[]; allow: RegExp[] }

// Every test must end with 0 pageerror, 0 CSP violation and 0 unexpected 401/404/429/5xx.
const test = base.extend<{ audit: Audit }>({
  audit: [async ({ page }, use) => {
    const a: Audit = { pageErrors: [], csp: [], bad: [], allow: [] };
    page.on('pageerror', (e) => a.pageErrors.push(String(e.message || e)));
    page.on('console', (m) => {
      if (/content security policy|violates the following|Refused to/i.test(m.text())) a.csp.push(m.text().slice(0, 200));
    });
    page.on('response', (r) => {
      const s = r.status();
      if ((s === 401 || s === 404 || s === 429 || s >= 500) && !a.allow.some((re) => re.test(r.url()))) a.bad.push(`${s} ${r.url()}`);
    });
    await use(a);
    expect.soft(a.pageErrors, 'pageerror').toEqual([]);
    expect.soft(a.csp, 'CSP violations in console').toEqual([]);
    expect.soft(a.bad, 'unexpected 401/404/429/5xx responses').toEqual([]);
  }, { auto: true }],
});

let dir = '';
let srv: TestServer;
let tmpBefore: Set<string>;

test.beforeAll(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  tmpBefore = snapshotTmp();
  dir = createFixtures();
  srv = await startServer(dir);
});
test.afterAll(async () => {
  await srv?.stop();
  removeFixtures(dir);
  cleanTmp(tmpBefore);
});

// ---------------------------------------------------------------- helpers

const prof = () => test.info().project.name;
const isMobile = () => prof() === 'mobile';
const tid = (page: Page, id: string) => page.locator(`[data-testid="${id}"]`);
const url = (p?: string, extra = '') => `${srv.base}/dl/${TOKEN}` + (p !== undefined ? `?p=${encodeURIComponent(p)}${extra}` : extra ? `?${extra.slice(1)}` : '');
const shot = (page: Page, step: string) => page.screenshot({ path: `${SHOTS}/sb-${prof()}-${step}.png` });
const noHScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

/** True once the PDF canvas holds dark pixels (text/rect), i.e. the page is really rendered. */
const inkReady = (page: Page) => page.waitForFunction(() => {
  const c = document.querySelector<HTMLCanvasElement>('[data-testid="rd-canvas"]');
  if (!c || !c.width) return false;
  const d = c.getContext('2d')!.getImageData(0, 0, c.width, c.height).data;
  let ink = 0;
  for (let i = 0; i < d.length; i += 4) if (d[i] < 128 && d[i + 1] < 128 && d[i + 2] < 128 && d[i + 3] > 0) ink++;
  return ink > 100;
}, null, { timeout: 15000 });
const canvasHash = (page: Page) => page.evaluate(() => {
  const c = document.querySelector<HTMLCanvasElement>('[data-testid="rd-canvas"]')!;
  const d = c.getContext('2d')!.getImageData(0, 0, c.width, c.height).data;
  let h = 0;
  for (let i = 0; i < d.length; i += 28) h = (h * 31 + d[i] + d[i + 1] * 3 + d[i + 2] * 5) | 0;
  return h;
});
const pageNo = async (page: Page) => +(await tid(page, 'rd-page-input').inputValue());
const expectPage = (page: Page, n: number) => expect.poll(() => pageNo(page), { timeout: 10000 }).toBe(n);

const stageRect = (page: Page) => page.evaluate(() => {
  const r = document.querySelector('[data-testid="rd-canvas"]')!.parentElement!.getBoundingClientRect();
  return { x: r.x, y: r.y, w: r.width, h: r.height };
});
async function tapStage(page: Page, pct: number) {
  const s = await stageRect(page);
  const x = Math.round(s.x + s.w * pct), y = Math.round(s.y + s.h * 0.5);
  if (isMobile()) await page.touchscreen.tap(x, y); else await page.mouse.click(x, y);
}
async function swipe(page: Page, fromPct: number, toPct: number) {
  const client = await page.context().newCDPSession(page);
  const s = await stageRect(page);
  const y = Math.round(s.y + s.h * 0.5);
  const pt = (pct: number) => [{ x: Math.round(s.x + s.w * pct), y, id: 1 }];
  await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(fromPct) });
  for (let i = 1; i <= 8; i++) await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(fromPct + (toPct - fromPct) * i / 8) });
  await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await client.detach();
}

const rowByName = (page: Page, name: string) => page.locator(`.row[data-name=${JSON.stringify(name)}]`);
const playBtn = (page: Page, name: string) => rowByName(page, name).locator('.btn-play');
const toolbarLink = (page: Page, param: 'read' | 'listen') => page.locator(`.toolbar a.btn-zip[href*="${param}=1"]`);

async function openBookFromListing(page: Page, folder: string, name: string) {
  await page.goto(url(folder));
  await playBtn(page, name).click();
  await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
  await inkReady(page);
}

const audioState = (page: Page) => page.evaluate(() => {
  const a = document.querySelector<HTMLAudioElement>('[data-testid="ab-audio"]')!;
  return { paused: a.paused, t: a.currentTime, rate: a.playbackRate, src: a.currentSrc, played: !!(window as any).__abPlayed };
});
const currentTrack = (page: Page) => page.locator('[data-testid="ab-track"][aria-current="true"]').getAttribute('data-name');

// ---------------------------------------------------------------- (1) livres

test.describe('livres: liseuse PDF', () => {
  test('listing, ouverture, pages, reprise, retour', async ({ page }) => {
    const resp = await page.goto(url('livres'));
    expect(resp!.headers()['content-security-policy'], 'no page-level CSP on the listing').toBeUndefined();

    await expect(page.locator('.row[data-type="file"]')).toHaveCount(2);
    for (const n of ['a-book.pdf', 'b-long.pdf']) await expect(playBtn(page, n)).toHaveAttribute('title', 'Lire');
    await expect(toolbarLink(page, 'read')).toHaveText(/Bibliothèque · 2 livres/);
    await expect(toolbarLink(page, 'listen')).toHaveCount(0);
    await shot(page, 'livres-listing');

    await playBtn(page, 'a-book.pdf').click();
    const viewer = tid(page, 'rd-viewer');
    await expect(viewer).toBeVisible();
    await expect(tid(page, 'rd-library')).toBeHidden();
    await inkReady(page);
    expect(page.url()).toContain('play=1');
    await expect(tid(page, 'rd-title')).toContainText(/a-book/i);
    expect(await pageNo(page)).toBe(1);
    await expect(tid(page, 'rd-page-count')).toContainText('3');
    // The viewer takes the page: most of the viewport height, full width on mobile.
    const vb = (await viewer.boundingBox())!, vp = page.viewportSize()!;
    expect(vb.height).toBeGreaterThanOrEqual(vp.height * 0.8);
    if (isMobile()) expect(vb.width).toBeGreaterThanOrEqual(vp.width - 40); // page gutter only
    expect(await noHScroll(page)).toBe(true);
    await shot(page, 'reader-page1');
    const h1 = await canvasHash(page);

    // Page 1 -> 2: right tap/click zone.
    await tapStage(page, 0.88);
    await expectPage(page, 2);
    await inkReady(page);
    await expect.poll(() => canvasHash(page)).not.toBe(h1);
    await shot(page, 'reader-page2');
    // 2 -> 3: arrow key.
    await page.keyboard.press('ArrowRight');
    await expectPage(page, 3);
    // 3 -> 2: left zone.
    await tapStage(page, 0.12);
    await expectPage(page, 2);

    // Reload => resume banner; Reprendre jumps back to page 2.
    await page.reload();
    await tid(page, 'rd-resume-banner').waitFor({ state: 'visible' });
    await shot(page, 'reader-resume-banner');
    await tid(page, 'rd-resume-yes').click();
    await expectPage(page, 2);
    await inkReady(page);
    await expect(tid(page, 'rd-resume-banner')).toBeHidden();

    // Reload again => Recommencer goes to page 1.
    await page.reload();
    await tid(page, 'rd-resume-banner').waitFor({ state: 'visible' });
    await tid(page, 'rd-resume-restart').click();
    await expectPage(page, 1);
    await inkReady(page);
    await expect(tid(page, 'rd-resume-banner')).toBeHidden();

    // Opened from its row: one Back returns to the listing, not to the reader's library.
    await expect(tid(page, 'rd-back')).toContainText('Dossier');
    await tid(page, 'rd-back').click();
    await expect(page.locator('.row[data-type="file"]')).toHaveCount(2);
    expect(page.url()).not.toContain('play=1');
    expect(page.url()).not.toContain('read=1');
  });

  test('bibliothèque via le bouton de la barre d\'outils', async ({ page }) => {
    await page.goto(url('livres'));
    await toolbarLink(page, 'read').click();
    await expect(tid(page, 'rd-library')).toBeVisible();
    await expect(tid(page, 'rd-book')).toHaveCount(2);
    await shot(page, 'library');
    await tid(page, 'rd-book').filter({ hasText: 'a-book' }).click();
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
    await inkReady(page);
    // Opened from the library: Back keeps the library.
    await expect(tid(page, 'rd-back')).toContainText('Bibliothèque');
    await tid(page, 'rd-back').click();
    await expect(tid(page, 'rd-library')).toBeVisible();
  });

  test('swipe CDP gauche/droite (mobile)', async ({ page }) => {
    test.skip(!isMobile(), 'touch only');
    await openBookFromListing(page, 'livres', 'b-long.pdf');
    await swipe(page, 0.8, 0.2);
    await expectPage(page, 2);
    await page.waitForTimeout(600);
    await swipe(page, 0.8, 0.2);
    await expectPage(page, 3);
    await page.waitForTimeout(600);
    await swipe(page, 0.2, 0.8);
    await expectPage(page, 2);
    await inkReady(page);
    await shot(page, 'reader-swipe');
  });

  test('plein écran pseudo, Escape pour sortir', async ({ page }) => {
    await openBookFromListing(page, 'livres', 'a-book.pdf');
    const fs = tid(page, 'rd-fullscreen');
    await fs.click();
    await expect(fs).toHaveAttribute('aria-pressed', 'true');
    const vp = page.viewportSize()!;
    await expect.poll(async () => (await tid(page, 'rd-viewer').boundingBox())!.width).toBeGreaterThanOrEqual(vp.width - 1);
    expect((await tid(page, 'rd-viewer').boundingBox())!.height).toBeGreaterThanOrEqual(vp.height - 1);
    await shot(page, 'reader-fullscreen');
    await page.keyboard.press('Escape');
    await expect(fs).toHaveAttribute('aria-pressed', 'false');
  });

  test('clics rapides sur un PDF A4 de 10 pages', async ({ page }) => {
    await openBookFromListing(page, 'livres', 'b-long.pdf');
    await expect(tid(page, 'rd-page-count')).toContainText('10');
    const next = tid(page, 'rd-next'), prev = tid(page, 'rd-prev');
    for (let i = 0; i < 7; i++) await next.click({ delay: 0 });
    await expectPage(page, 8);
    for (let i = 0; i < 3; i++) await prev.click({ delay: 0 });
    await expectPage(page, 5);
    for (let i = 0; i < 12; i++) await next.click({ delay: 0, force: true, noWaitAfter: true });
    await expectPage(page, 10);
    await inkReady(page);
    // Settled: the counter must not move and the canvas must stay drawn.
    await page.waitForTimeout(800);
    expect(await pageNo(page)).toBe(10);
    await inkReady(page);
    await shot(page, 'reader-rapid-clicks');
  });
});

// ---------------------------------------------------------------- (1b) bd

test.describe('bd: liseuse CBZ/CBR', () => {
  const comicImg = (page: Page) => tid(page, 'rd-viewer').locator('img').first();
  /** Centre pixel of the displayed page image, read through a canvas (same origin: not tainted). */
  const imgColor = (page: Page) => comicImg(page).evaluate((el) => {
    const i = el as HTMLImageElement;
    const c = document.createElement('canvas');
    c.width = 1; c.height = 1;
    const g = c.getContext('2d')!;
    g.drawImage(i, 0, 0, i.naturalWidth, i.naturalHeight, 0, 0, 1, 1);
    return Array.from(g.getImageData(0, 0, 1, 1).data.slice(0, 3));
  });
  const expectColor = (page: Page, n: number) => expect.poll(async () => {
    const got = await imgColor(page).catch(() => [-1, -1, -1]);
    return got.every((v, k) => Math.abs(v - COMIC_COLORS[n - 1][k]) <= 12);
  }, { timeout: 10000, message: `page ${n} shows its own colour` }).toBe(true);
  const imgLoaded = (page: Page) => expect.poll(
    () => comicImg(page).evaluate((i: HTMLImageElement) => i.complete && i.naturalWidth > 0).catch(() => false), { timeout: 10000 }).toBe(true);

  async function openComic(page: Page, folder: string, name: string) {
    await page.goto(url(folder));
    await playBtn(page, name).click();
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
  }
  async function tapViewer(page: Page, pct: number) {
    const b = (await tid(page, 'rd-viewer').boundingBox())!;
    const x = Math.round(b.x + b.width * pct), y = Math.round(b.y + b.height * 0.5);
    if (isMobile()) await page.touchscreen.tap(x, y); else await page.mouse.click(x, y);
  }
  /** Every request that names a comic archive. */
  function trackArchiveRequests(page: Page) {
    const seen: { url: string; type: string; len: number }[] = [];
    page.on('response', (r) => {
      if (!/\.(cbz|cbr)/i.test(decodeURIComponent(r.url()))) return;
      seen.push({ url: r.url(), type: r.headers()['content-type'] || '', len: +(r.headers()['content-length'] || 0) });
    });
    return seen;
  }
  const pageNumbers = (reqs: string[]) => reqs.map((u) => /[?&]page=(\d+)/.exec(u)?.[1]).filter(Boolean).map(Number);

  test('listing: bibliothèque et bouton Lire sur les BD', async ({ page }) => {
    await page.goto(url('bd'));
    await expect(page.locator('.row[data-type="file"]')).toHaveCount(2);
    for (const n of ['a-comic.cbz', 'b-comic.cbr']) await expect(playBtn(page, n)).toHaveAttribute('title', 'Lire');
    await expect(toolbarLink(page, 'read')).toHaveText(/Bibliothèque · 2 livres/);
    await expect(toolbarLink(page, 'listen')).toHaveCount(0);
    await toolbarLink(page, 'read').click();
    await expect(tid(page, 'rd-book')).toHaveCount(2);
    await shot(page, 'bd-library');
  });

  test('bibliothèque: le lien Télécharger d\'une BD donne l\'archive, pas inline=1', async ({ page, request }) => {
    await page.goto(url('bd', '&read=1'));
    const row = tid(page, 'rd-book').filter({ hasText: 'a-comic' });
    await expect(row).toBeVisible();
    // The download link sits next to the book button, not inside it.
    const dl = page.locator('a[download][aria-label="Télécharger a-comic.cbz"]');
    await expect(dl).toBeAttached();
    const href = await dl.getAttribute('href');
    expect(href).toBeTruthy();
    expect(href).not.toMatch(/[?&]inline=1/);
    const r = await request.get(new URL(href!, page.url()).toString());
    expect(r.status()).toBe(200);
    expect((await r.body()).subarray(0, 2).toString('latin1')).toBe('PK');
  });

  test('cbz: page 1, compteur, navigation bouton / clavier / zone tactile, jamais l\'archive entière', async ({ page }) => {
    const seen = trackArchiveRequests(page);
    await openComic(page, 'bd', 'a-comic.cbz');
    expect(page.url()).toContain('play=1');
    await imgLoaded(page);
    await expect(comicImg(page)).toBeVisible();
    await expectColor(page, 1);
    await expect(tid(page, 'rd-page-count')).toContainText('4');
    expect(await pageNo(page)).toBe(1);
    expect(await noHScroll(page)).toBe(true);
    const vb = (await tid(page, 'rd-viewer').boundingBox())!, ib = (await comicImg(page).boundingBox())!, vp = page.viewportSize()!;
    expect(vb.height).toBeGreaterThanOrEqual(vp.height * 0.8);
    expect(ib.width).toBeLessThanOrEqual(vp.width + 1);
    expect(ib.height).toBeLessThanOrEqual(vp.height + 1);
    await shot(page, 'bd-page1');

    await tid(page, 'rd-next').click();
    await expectPage(page, 2);
    await expectColor(page, 2);
    await page.keyboard.press('ArrowRight');
    await expectPage(page, 3);
    await expectColor(page, 3);
    await tapViewer(page, 0.88);
    await expectPage(page, 4);
    await expectColor(page, 4);
    await tid(page, 'rd-prev').click();
    await expectPage(page, 3);
    await tapViewer(page, 0.12);
    await expectPage(page, 2);
    await expectColor(page, 2);
    await page.keyboard.press('End');
    await expectPage(page, 4);
    await page.keyboard.press('Home');
    await expectPage(page, 1);
    await expectColor(page, 1);

    // The server serves one page at a time: no archive body, and every inline URL carries page= or pages=.
    expect(seen.length).toBeGreaterThan(0);
    for (const r of seen) {
      if (/[?&]inline=1/.test(r.url)) expect(r.url, 'inline URL without page/pages').toMatch(/[?&]pages?=\d+/);
      expect(r.type, r.url).not.toMatch(/zip|rar|octet-stream/i);
      expect(r.len, r.url).toBeLessThan(1_000_000);
    }
  });

  test('préchargement: seule la page suivante est demandée à l\'avance', async ({ page }) => {
    const reqs: string[] = [];
    page.on('request', (r) => { if (/\.cbz/i.test(decodeURIComponent(r.url())) && /[?&]page=\d/.test(r.url())) reqs.push(r.url()); });
    await openComic(page, 'bd', 'a-comic.cbz');
    await imgLoaded(page);
    await tid(page, 'rd-next').click();
    await expectPage(page, 2);
    await expectColor(page, 2);
    await expect.poll(() => pageNumbers(reqs), { message: 'page 3 preloaded from page 2' }).toContain(3);
    await page.waitForTimeout(800);
    expect(pageNumbers(reqs), 'page 4 must not be fetched while on page 2').not.toContain(4);
  });

  test('préchargement: la page précédente est aussi demandée, sans y revenir', async ({ page }) => {
    const reqs: string[] = [];
    page.on('request', (r) => { if (/\.cbz/i.test(decodeURIComponent(r.url())) && /[?&]page=\d/.test(r.url())) reqs.push(r.url()); });
    await openComic(page, 'bd-long', 'long-comic.cbz');
    await imgLoaded(page);
    await expect(tid(page, 'rd-epub-toggle'), 'text settings are EPUB only').toBeHidden();
    await tid(page, 'rd-page-input').fill('5');
    await tid(page, 'rd-page-input').press('Enter');
    await expectPage(page, 5);
    await expect.poll(() => pageNumbers(reqs), { message: 'page 6 preloaded from page 5' }).toContain(6);
    await expect.poll(() => pageNumbers(reqs), { message: 'page 4 preloaded from page 5' }).toContain(4);
    await page.waitForTimeout(500);
    expect(pageNumbers(reqs), 'page 3 is neither shown nor next to page 5').not.toContain(3);
    expect(await pageNo(page), 'still on page 5').toBe(5);
  });

  test('reprise de lecture après rechargement', async ({ page }) => {
    await openComic(page, 'bd', 'a-comic.cbz');
    await imgLoaded(page);
    await tid(page, 'rd-next').click();
    await expectPage(page, 2);
    await tid(page, 'rd-next').click();
    await expectPage(page, 3);

    await page.reload();
    await tid(page, 'rd-resume-banner').waitFor({ state: 'visible' });
    await shot(page, 'bd-resume-banner');
    await tid(page, 'rd-resume-yes').click();
    await expectPage(page, 3);
    await expectColor(page, 3);
    await expect(tid(page, 'rd-resume-banner')).toBeHidden();

    await page.reload();
    await tid(page, 'rd-resume-banner').waitFor({ state: 'visible' });
    await tid(page, 'rd-resume-restart').click();
    await expectPage(page, 1);
    await expectColor(page, 1);
  });

  test('fermeture puis réouverture: bannière de reprise sur la bonne page', async ({ page }) => {
    await openComic(page, 'bd', 'a-comic.cbz');
    await imgLoaded(page);
    await tid(page, 'rd-next').click();
    await expectPage(page, 2);
    // Back leaves for the listing; reopening the same book offers to resume.
    await tid(page, 'rd-back').click();
    await expect(page.locator('.row[data-type="file"]').first()).toBeVisible();
    await playBtn(page, 'a-comic.cbz').click();
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
    await tid(page, 'rd-resume-banner').waitFor({ state: 'visible' });
    await tid(page, 'rd-resume-yes').click();
    await expectPage(page, 2);
    await expectColor(page, 2);
  });

  test('plein écran pseudo, Escape pour sortir', async ({ page }) => {
    await openComic(page, 'bd', 'a-comic.cbz');
    await imgLoaded(page);
    const fsBtn = tid(page, 'rd-fullscreen');
    await fsBtn.click();
    await expect(fsBtn).toHaveAttribute('aria-pressed', 'true');
    const vp = page.viewportSize()!;
    await expect.poll(async () => (await tid(page, 'rd-viewer').boundingBox())!.width).toBeGreaterThanOrEqual(vp.width - 1);
    await expect(comicImg(page)).toBeVisible();
    await shot(page, 'bd-fullscreen');
    await page.keyboard.press('Escape');
    await expect(fsBtn).toHaveAttribute('aria-pressed', 'false');
    await expect(comicImg(page)).toBeVisible();
  });

  test('swipe CDP gauche/droite (mobile)', async ({ page }) => {
    test.skip(!isMobile(), 'touch only');
    await openComic(page, 'bd', 'a-comic.cbz');
    await imgLoaded(page);
    const sw = async (from: number, to: number) => {
      const client = await page.context().newCDPSession(page);
      const b = (await tid(page, 'rd-viewer').boundingBox())!;
      const y = Math.round(b.y + b.height * 0.5);
      const pt = (pct: number) => [{ x: Math.round(b.x + b.width * pct), y, id: 1 }];
      await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(from) });
      for (let i = 1; i <= 8; i++) await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(from + (to - from) * i / 8) });
      await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await client.detach();
    };
    await sw(0.8, 0.2);
    await expectPage(page, 2);
    await page.waitForTimeout(600);
    await sw(0.2, 0.8);
    await expectPage(page, 1);
    await expectColor(page, 1);
  });

  test('cbr: s\'ouvre, 2 pages dans l\'ordre', async ({ page }) => {
    const seen = trackArchiveRequests(page);
    await openComic(page, 'bd', 'b-comic.cbr');
    await imgLoaded(page);
    await expect(tid(page, 'rd-page-count')).toContainText('2');
    await expectColor(page, 1);
    await tid(page, 'rd-next').click();
    await expectPage(page, 2);
    await expectColor(page, 2);
    await shot(page, 'bd-cbr-page2');
    for (const r of seen) {
      if (/[?&]inline=1/.test(r.url)) expect(r.url).toMatch(/[?&]pages?=\d+/);
      expect(r.len, r.url).toBeLessThan(1_000_000);
    }
  });

  test('cbz corrompu: message d\'erreur lisible, pas d\'image cassée', async ({ page }) => {
    await openComic(page, 'bd-corrupt', 'broken.cbz');
    // Wording is the implementation's choice: any readable French/English error cue will do.
    await expect(page.locator('body')).toContainText(/erreur|impossible|illisible|corrompu|invalide|error|unreadable|corrupt/i);
    const broken = await page.evaluate(() => Array.from(document.querySelectorAll('img')).filter((i) => i.offsetParent && !(i.complete && i.naturalWidth > 0)).length);
    expect(broken).toBe(0);
    expect(await noHScroll(page)).toBe(true);
    await shot(page, 'bd-corrupt');
    // The viewer stays usable: back returns to the listing.
    await tid(page, 'rd-back').click();
    await expect(page.locator('.row[data-type="file"]').first()).toBeVisible();
  });
});

// ---------------------------------------------------------------- (2) album

// Regression: the PDF stage kept its CSS cap (100dvh - 11rem) and cut the page bottom; the comic overflowed the window.
test.describe('page entière à l\'écran', () => {
  test.skip(() => prof() !== 'desktop', 'desktop project only');
  const fits = (page: Page) => page.evaluate(() => {
    const s = document.querySelector<HTMLElement>('[data-testid="rd-stage"]')!;
    return { innerScroll: s.scrollHeight - s.clientHeight, pageScroll: document.documentElement.scrollHeight - innerHeight };
  });

  test('a PDF page fits in the stage and the window', async ({ page }) => {
    await openBookFromListing(page, 'livres', 'a-book.pdf');
    await expect.poll(async () => (await fits(page)).innerScroll).toBeLessThanOrEqual(2);
    expect((await fits(page)).pageScroll).toBeLessThanOrEqual(0);
  });

  test('a comic page fits in the stage and the window', async ({ page }) => {
    await page.goto(url('bd'));
    await playBtn(page, 'a-comic.cbz').click();
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
    await expect(page.locator('[data-testid="rd-viewer"] img[data-page="1"]')).toBeVisible();
    await expect.poll(async () => (await fits(page)).innerScroll).toBeLessThanOrEqual(2);
    expect((await fits(page)).pageScroll).toBeLessThanOrEqual(0);
  });
});

test.describe('album: lecteur audio', () => {
  test('listing, pistes, cover, lecture, piste suivante, vitesse, reprise', async ({ page }) => {
    await page.goto(url('album'));
    await expect(toolbarLink(page, 'listen')).toHaveText(/Écouter · 3 pistes/);
    await expect(toolbarLink(page, 'read')).toHaveCount(0);
    await shot(page, 'album-listing');

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('listen=1') && r.request().resourceType() === 'document'),
      toolbarLink(page, 'listen').click(),
    ]);
    expect(resp.headers()['content-security-policy'], 'page-level CSP on ?listen=1').toBeTruthy();
    await expect(tid(page, 'ab-track')).toHaveCount(3);

    const cover = page.locator('img.ab-cover');
    await expect(cover).toBeVisible();
    expect(await cover.evaluate((i: HTMLImageElement) => i.complete && i.naturalWidth > 0)).toBe(true);
    await page.evaluate(() => {
      const a = document.querySelector<HTMLAudioElement>('[data-testid="ab-audio"]')!;
      a.addEventListener('play', () => { (window as any).__abPlayed = true; });
    });

    await tid(page, 'ab-play').click();
    // Headless may refuse to start audio: accept the play event or an advancing clock.
    await expect.poll(async () => { const s = await audioState(page); return s.played || !s.paused || s.t > 0; }).toBe(true);
    await shot(page, 'audio-playing');

    await tid(page, 'ab-next').click();
    await expect.poll(() => currentTrack(page)).toBe('02 - piste.mp3');
    await expect(tid(page, 'ab-count')).toHaveText('2/3');

    await tid(page, 'ab-rate-up').click();
    await expect(tid(page, 'ab-rate')).toContainText('1.1');
    expect((await audioState(page)).rate).toBeCloseTo(1.1, 2);
    await shot(page, 'audio-track2-rate');

    // Position is saved on track change: reload => banner naming the track.
    await page.reload();
    await tid(page, 'ab-resume-banner').waitFor({ state: 'visible' });
    await expect(tid(page, 'ab-resume-banner')).toContainText(/Reprendre/);
    await expect(tid(page, 'ab-resume-banner')).toContainText('02 - piste');
    await shot(page, 'audio-resume-banner');
    await tid(page, 'ab-resume-yes').click();
    await expect(tid(page, 'ab-resume-banner')).toBeHidden();
    expect(await currentTrack(page)).toBe('02 - piste.mp3');

    await page.reload();
    await tid(page, 'ab-resume-banner').waitFor({ state: 'visible' });
    await tid(page, 'ab-resume-restart').click();
    await expect(tid(page, 'ab-resume-banner')).toBeHidden();
    expect(await currentTrack(page)).toBe('01 - piste.mp3');

    await page.locator('.mp-back').click();
    await expect(page.locator('.row[data-type="file"]')).toHaveCount(5);
  });
});

// ---------------------------------------------------------------- (3) mixte / video / (4) mp3seul

test.describe('dossiers sans lecteur dédié', () => {
  test('mixte: pas de bouton de dossier, la vidéo monte video#player, le PDF a sa flèche Lire', async ({ page }) => {
    const resp = await page.goto(url('mixte'));
    expect(resp!.headers()['content-security-policy']).toBeUndefined();
    await expect(toolbarLink(page, 'read')).toHaveCount(0);
    await expect(toolbarLink(page, 'listen')).toHaveCount(0);
    await expect(page.locator('a[href*="read=1"], a[href*="listen=1"]')).toHaveCount(0);
    await expect(playBtn(page, 'notice.pdf')).toHaveCount(1);
    await shot(page, 'mixte-listing');
    await playBtn(page, 'un.mp4').click();
    await expect(page.locator('video#player')).toBeAttached();
    await expect(page.locator('#asc-reader, #asc-audiobook')).toHaveCount(0);
    await openBookFromListing(page, 'mixte', 'notice.pdf');
  });

  test('video: aucun bouton, play monte video#player comme avant', async ({ page }) => {
    await page.goto(url('video'));
    await expect(page.locator('a[href*="read=1"], a[href*="listen=1"]')).toHaveCount(0);
    await shot(page, 'video-listing');
    await playBtn(page, 'clip.mp4').click();
    await expect(page.locator('video#player')).toBeAttached();
    expect(page.url()).toContain('play=1');
    await page.waitForTimeout(500);
    await shot(page, 'video-player');
  });

  test('mp3seul: pas de bouton Écouter, ancien lecteur audio inchangé', async ({ page }) => {
    await page.goto(url('mp3seul'));
    await expect(page.locator('a[href*="read=1"], a[href*="listen=1"]')).toHaveCount(0);
    await playBtn(page, 'solo.mp3').click();
    await expect(page.locator('audio#player')).toBeAttached();
    expect(page.url()).toContain('play=1');
    await expect(page.locator('#asc-audiobook, #asc-reader')).toHaveCount(0);
    await shot(page, 'mp3seul-player');
    // Same legacy player when addressed directly.
    await page.goto(url('mp3seul/solo.mp3', '&play=1'));
    await expect(page.locator('audio#player')).toBeAttached();
  });
});

// ---------------------------------------------------------------- (5) hostile names

test.describe('noms hostiles', () => {
  const pwned = (page: Page) => page.evaluate(() => (window as any).__PWNED);
  const noInjected = (page: Page) => page.evaluate(() => document.querySelectorAll('img[src="x"], img[onerror], [onerror]').length);

  test('listing, bibliothèque et liseuse: échappés, pas de XSS', async ({ page }) => {
    await page.goto(url('hostile'));
    const names = await page.locator('.row[data-type="file"] .row-name').allInnerTexts();
    expect([...names].sort()).toEqual([...HOSTILE_PDFS].sort());
    expect(await pwned(page)).toBeUndefined();
    expect(await noInjected(page)).toBe(0);
    await expect(toolbarLink(page, 'read')).toHaveText(/Bibliothèque · 4 livres/);
    await shot(page, 'hostile-listing');

    await toolbarLink(page, 'read').click();
    await expect(tid(page, 'rd-book')).toHaveCount(4);
    const books = await tid(page, 'rd-book').evaluateAll((els) => els.map((e) => e.getAttribute('data-name')));
    expect([...books].sort()).toEqual([...HOSTILE_PDFS].sort());
    expect(await pwned(page)).toBeUndefined();
    expect(await noInjected(page)).toBe(0);
    await shot(page, 'hostile-library');

    // Each hostile row's Lire button opens its own book, apostrophes and quotes included.
    for (const n of HOSTILE_PDFS) {
      await page.goto(url('hostile'));
      await playBtn(page, n).click();
      await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
      await inkReady(page);
      expect(await pwned(page), n).toBeUndefined();
      expect(await noInjected(page), n).toBe(0);
    }
    await expect(tid(page, 'rd-title')).toContainText('été à Noël');
    await shot(page, 'hostile-reader');
  });

  test('lecteur audio: pistes hostiles affichées littéralement', async ({ page }) => {
    await page.goto(url('hostile-audio'));
    await expect(toolbarLink(page, 'listen')).toHaveText(/Écouter · 2 pistes/);
    await toolbarLink(page, 'listen').click();
    await expect(tid(page, 'ab-track')).toHaveCount(2);
    const names = await tid(page, 'ab-track').evaluateAll((els) => els.map((e) => e.getAttribute('data-name')));
    expect([...names].sort()).toEqual([...HOSTILE_MP3S].sort());
    const labels = (await page.locator('.ab-track-label').allInnerTexts()).join('|');
    expect(labels).toContain('"><script>');
    expect(labels).toContain("it's <b>bold");
    expect(await pwned(page)).toBeUndefined();
    expect(await page.evaluate(() => document.querySelectorAll('.ab-track-label b, .ab-track-label script').length)).toBe(0);
    await tid(page, 'ab-next').click();
    await shot(page, 'hostile-audio');
  });
});

// ---------------------------------------------------------------- (6) non-regression

test.describe('non-régression', () => {
  test('listing racine, ZIP, recherche', async ({ page, request }) => {
    await page.goto(url());
    await expect(page.locator('.row', { hasText: 'album' }).first()).toBeVisible();
    await shot(page, 'root-listing');

    const zip = await request.get(url('album', '&zip=1'));
    expect(zip.status()).toBe(200);
    expect(zip.headers()['content-type']).toMatch(/zip/);
    expect((await zip.body()).subarray(0, 2).toString('latin1')).toBe('PK');

    const search = await request.get(`${srv.base}/dl/${TOKEN}?q=piste`);
    expect(search.status()).toBe(200);
    expect(await search.text()).toContain('01 - piste.mp3');
    await page.goto(`${srv.base}/dl/${TOKEN}?q=piste`);
    await expect(page.locator('body')).toContainText('01 - piste.mp3');
    await shot(page, 'search');
  });

  test('lien à mot de passe: formulaire, refus, accès, liseuse', async ({ page }) => {
    await page.goto(`${srv.base}/dl/e2e-pwd`);
    const pw = page.locator('input[name="password"]');
    await expect(pw).toBeVisible();
    await shot(page, 'password-form');
    await pw.fill('mauvais');
    await pw.press('Enter');
    await expect(page.locator('body')).toContainText(/incorrect/i);
    await page.locator('input[name="password"]').fill('secret-e2e');
    await page.locator('input[name="password"]').press('Enter');
    await expect(page.locator('.row[data-type="file"]')).toHaveCount(2);
    await expect(toolbarLink(page, 'read')).toHaveText(/Bibliothèque · 2 livres/);
    await playBtn(page, 'a-book.pdf').click();
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
    await inkReady(page);
  });

  test('PDF: attachment en direct, inline + Range 206 avec ?inline=1, .txt refusé', async ({ page, request, audit }) => {
    audit.allow.push(/note\.txt/);
    const pdf = url('livres/a-book.pdf');
    const size = fs.statSync(`${dir}/livres/a-book.pdf`).size;

    const direct = await request.get(pdf);
    expect(direct.status()).toBe(200);
    expect(direct.headers()['content-disposition']).toMatch(/^attachment/);

    const inline = await request.get(pdf + '&inline=1');
    expect(inline.status()).toBe(200);
    expect(inline.headers()['content-type']).toMatch(/^application\/pdf/);
    expect(inline.headers()['content-disposition']).toMatch(/^inline/);
    expect(inline.headers()['content-length']).toBe(String(size));
    expect(inline.headers()['content-encoding']).toBeUndefined();
    expect((await inline.body()).subarray(0, 4).toString('latin1')).toBe('%PDF');

    const range = await request.get(pdf + '&inline=1', { headers: { Range: 'bytes=0-99' } });
    expect(range.status()).toBe(206);
    expect(range.headers()['content-range']).toBe(`bytes 0-99/${size}`);
    expect(range.headers()['content-length']).toBe('100');
    expect(range.headers()['content-encoding']).toBeUndefined();
    expect(range.headers()['accept-ranges']).toBe('bytes');
    expect((await range.body()).length).toBe(100);

    const head = await request.head(pdf + '&inline=1');
    expect(head.status()).toBe(200);
    expect((await head.body()).length).toBe(0);

    const txt = await request.get(url('divers/note.txt', '&inline=1'));
    expect(txt.status()).toBe(404);
    const outside = await request.get(url('../..', '&inline=1'));
    expect(outside.status()).toBe(403);
    // 404 is a browser-visible response too: make the intent explicit.
    await page.goto(url('divers/note.txt', '&inline=1'));
  });

  test('CSP page-level sur ?read=1 / ?listen=1 / play livre, absente du listing', async ({ request }) => {
    for (const [p, extra, expected] of [
      ['livres', '&read=1', true], ['album', '&listen=1', true], ['livres/a-book.pdf', '&play=1', true],
      ['livres', '', false], ['album', '', false], ['mixte', '', false], ['video', '', false], ['mp3seul', '', false],
    ] as const) {
      const r = await request.get(url(p, extra));
      expect(r.status(), `${p}${extra}`).toBe(200);
      const csp = r.headers()['content-security-policy'];
      if (expected) {
        expect(csp, `${p}${extra}`).toContain("default-src 'self'");
        expect(csp).toContain("script-src 'self'");
      } else {
        expect(csp, `${p}${extra}`).toBeUndefined();
      }
    }
  });
});

// ---------------------------------------------------------------- (7) mobile layout

test.describe('mobile portrait', () => {
  test.skip(() => !isMobile(), 'mobile project only');

  const fits = async (page: Page, loc: Locator) => {
    const vp = page.viewportSize()!;
    const b = (await loc.boundingBox())!;
    expect(b, 'element has a box').toBeTruthy();
    expect(b.x).toBeGreaterThanOrEqual(-0.5);
    expect(b.x + b.width).toBeLessThanOrEqual(vp.width + 0.5);
  };
  const big = async (loc: Locator, what: string) => {
    const b = (await loc.boundingBox())!;
    expect(b, what).toBeTruthy();
    expect.soft(b.height, `${what} height`).toBeGreaterThanOrEqual(44);
    expect.soft(b.width, `${what} width`).toBeGreaterThanOrEqual(44);
  };

  test('listing livres/album: pas de scroll horizontal, barre d\'outils entière', async ({ page }) => {
    for (const [folder, param] of [['livres', 'read'], ['album', 'listen']] as const) {
      await page.goto(url(folder));
      expect(await noHScroll(page), folder).toBe(true);
      const link = toolbarLink(page, param);
      await expect(link).toBeVisible();
      await fits(page, page.locator('.toolbar'));
      for (const el of await page.locator('.toolbar > *:visible').all()) await fits(page, el);
      await big(link, `${folder} toolbar button`);
      expect((await link.boundingBox())!.height, `${folder} toolbar button height`).toBeGreaterThanOrEqual(44);
      await shot(page, `${folder}-listing-mobile`);
    }
  });

  test('liseuse: barre d\'outils entière, cibles >= 44px', async ({ page }) => {
    await openBookFromListing(page, 'livres', 'a-book.pdf');
    expect(await noHScroll(page)).toBe(true);
    for (const id of ['rd-prev', 'rd-next', 'rd-zoom-in', 'rd-zoom-out', 'rd-fullscreen', 'rd-back']) {
      const el = tid(page, id);
      await expect(el, id).toBeVisible();
      await fits(page, el);
      await big(el, id);
    }
    await fits(page, tid(page, 'rd-page-input'));
    expect((await tid(page, 'rd-page-input').boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await shot(page, 'reader-toolbar-mobile');
  });

  test('lecteur audio: pas de scroll horizontal, cibles >= 44px', async ({ page }) => {
    await page.goto(url('album', '&listen=1'));
    await tid(page, 'ab-track').first().waitFor();
    expect(await noHScroll(page)).toBe(true);
    for (const id of ['ab-prev', 'ab-back15', 'ab-play', 'ab-fwd30', 'ab-next', 'ab-rate-down', 'ab-rate', 'ab-rate-up', 'ab-sleep']) {
      const el = tid(page, id);
      await expect(el, id).toBeVisible();
      await fits(page, el);
      await big(el, id);
    }
    await fits(page, tid(page, 'ab-seek'));
    for (const t of await tid(page, 'ab-track').all()) await big(t.locator('.ab-track-pick'), 'track row');
    await shot(page, 'audio-dock-mobile');
  });
});

// ---------------------------------------------------------------- BD ergonomics (spec-ergo)
test.describe('bd: ergonomie', () => {
  const isTouch = () => prof() !== 'desktop';
  const viewer = (page: Page) => tid(page, 'rd-viewer');
  const pageImg = (page: Page, n: number) => viewer(page).locator(`img[data-page="${n}"]`);
  const shownPages = (page: Page) => viewer(page).locator('img[data-page]').evaluateAll(
    (els) => els.map((e) => +(e.getAttribute('data-page') || 0)).sort((a, b) => a - b));
  const expectShown = (page: Page, pages: number[]) => expect.poll(() => shownPages(page), { timeout: 10000 }).toEqual(pages);
  /** Page field plus count text: the spec leaves open whether "2-3" lives in the input or beside it. */
  const counterText = async (page: Page) => (await tid(page, 'rd-page-input').inputValue()) + ' | ' + (await tid(page, 'rd-page-count').innerText());
  const expectCounter = (page: Page, re: RegExp) => expect.poll(() => counterText(page), { timeout: 10000 }).toMatch(re);
  const colorOf = (page: Page, n: number) => pageImg(page, n).evaluate((el) => {
    const i = el as HTMLImageElement;
    const c = document.createElement('canvas');
    c.width = 1; c.height = 1;
    const g = c.getContext('2d')!;
    g.drawImage(i, 0, 0, i.naturalWidth, i.naturalHeight, 0, 0, 1, 1);
    return Array.from(g.getImageData(0, 0, 1, 1).data.slice(0, 3));
  });
  const expectLongColor = (page: Page, n: number) => expect.poll(async () => {
    const got = await colorOf(page, n).catch(() => [-1, -1, -1]);
    return got.every((v, k) => Math.abs(v - LONG_COMIC_COLORS[n - 1][k]) <= 12);
  }, { timeout: 10000, message: `page ${n} shows its own colour` }).toBe(true);

  async function open(page: Page, folder: string, name: string) {
    await page.goto(url(folder));
    await playBtn(page, name).click();
    await viewer(page).waitFor({ state: 'visible' });
    await pageImg(page, 1).waitFor({ state: 'visible' });
  }
  async function tapAt(page: Page, pct: number) {
    const b = (await viewer(page).boundingBox())!;
    const x = Math.round(b.x + b.width * pct), y = Math.round(b.y + b.height * 0.5);
    if (isTouch()) await page.touchscreen.tap(x, y); else await page.mouse.click(x, y);
  }
  async function doubleTapAt(page: Page, pct: number) {
    const b = (await viewer(page).boundingBox())!;
    const x = Math.round(b.x + b.width * pct), y = Math.round(b.y + b.height * 0.5);
    if (isTouch()) { await page.touchscreen.tap(x, y); await page.waitForTimeout(80); await page.touchscreen.tap(x, y); } else await page.mouse.dblclick(x, y);
  }
  const box = async (loc: Locator) => (await loc.boundingBox())!;
  const reload = async (page: Page) => {
    await page.reload();
    await viewer(page).waitFor({ state: 'visible' });
  };

  test('double page @landscape: one page by default, toggle on, cover alone, then pairs, remembered', async ({ page }) => {
    await open(page, 'bd-long', 'long-comic.cbz');
    await expectShown(page, [1]);
    await expectLongColor(page, 1);
    await tid(page, 'rd-next').click();
    await expectShown(page, [2]);
    await tid(page, 'rd-prev').click();
    await expectShown(page, [1]);

    // Turned on by the reader: the cover stays alone.
    await expect(tid(page, 'rd-spread-toggle')).toBeVisible();
    await tid(page, 'rd-spread-toggle').click();
    await expectShown(page, [1]);
    await tid(page, 'rd-next').click();
    await expectShown(page, [2, 3]);
    await expectCounter(page, /2\s*[-–]\s*3/);
    await expectLongColor(page, 2);
    await expectLongColor(page, 3);

    await tid(page, 'rd-next').click();
    await expectShown(page, [4, 5]);
    await expectCounter(page, /4\s*[-–]\s*5/);
    await tid(page, 'rd-prev').click();
    await expectShown(page, [2, 3]);

    const a = await box(pageImg(page, 2)), b = await box(pageImg(page, 3)), v = await box(viewer(page));
    expect(Math.abs(a.y - b.y), 'same top').toBeLessThanOrEqual(2);
    expect(a.x, 'lower page is on the left').toBeLessThan(b.x);
    expect(Math.abs(a.x + a.width - b.x), 'no gap between pages').toBeLessThanOrEqual(2);
    expect(a.x).toBeGreaterThanOrEqual(v.x - 1);
    expect(b.x + b.width).toBeLessThanOrEqual(v.x + v.width + 1);
    expect(Math.max(a.y + a.height, b.y + b.height)).toBeLessThanOrEqual(v.y + v.height + 1);

    // After a reload the book may reopen on the cover (alone anyway): judge on the toggle and the next pages.
    await reload(page);
    await expect(tid(page, 'rd-spread-toggle'), 'spread remembered').toHaveAttribute('aria-pressed', 'true');
    await tid(page, 'rd-spread-toggle').click();
    await expect(tid(page, 'rd-spread-toggle')).toHaveAttribute('aria-pressed', 'false');
    await reload(page);
    await expect(tid(page, 'rd-spread-toggle'), 'single page remembered').toHaveAttribute('aria-pressed', 'false');
  });

  test('double page @landscape: last page alone when it has no partner', async ({ page }) => {
    await open(page, 'bd', 'a-comic.cbz');
    await tid(page, 'rd-spread-toggle').click();
    await tid(page, 'rd-next').click();
    await expectShown(page, [2, 3]);
    await tid(page, 'rd-next').click();
    await expectShown(page, [4]);
  });

  test('double page @landscape: a wide page stands alone and the pairing shifts after it', async ({ page }) => {
    await open(page, 'bd-wide', 'wide-comic.cbz');
    await tid(page, 'rd-spread-toggle').click();
    await expectShown(page, [1]);
    await tid(page, 'rd-next').click();
    await expectShown(page, [2, 3]);
    await tid(page, 'rd-next').click();
    await expectShown(page, [4]);
    await expectCounter(page, /^4 \| \/ 6$/);
    await tid(page, 'rd-next').click();
    await expectShown(page, [5, 6]);
    await expectCounter(page, /5\s*[-–]\s*6/);
    await tid(page, 'rd-prev').click();
    await expectShown(page, [4]);
    await tid(page, 'rd-prev').click();
    await expectShown(page, [2, 3]);
    await tid(page, 'rd-prev').click();
    await expectShown(page, [1]);
  });

  // Regression: a wide page found by the previous-page prefetch changed the pairing but the pair on screen
  // stayed, so Prev skipped a page and Next showed one twice.
  test('double page @landscape: a wide page found by prefetch regroups the pair on screen', async ({ page }) => {
    await open(page, 'bd-wide', 'wide-comic.cbz');
    await tid(page, 'rd-spread-toggle').click();
    await expectShown(page, [1]);
    // Page 4 (wide) is unknown yet: page 6 first shows alone, then the prefetch of (4,5) finds page 4 wide.
    await tid(page, 'rd-page-input').fill('6');
    await tid(page, 'rd-page-input').press('Enter');
    await expectShown(page, [5, 6]);
    await tid(page, 'rd-prev').click();
    await expectShown(page, [4]);
  });

  test('wake lock: asked when a book opens, asked again after the browser drops it, released on close', async ({ page }) => {
    await page.addInitScript(() => {
      const w = window as any;
      w.__wl = [];
      Object.defineProperty(navigator, 'wakeLock', {
        configurable: true,
        value: {
          request: (type: string) => {
            w.__wl.push('request:' + type);
            const lock = { released: false, release: () => { lock.released = true; w.__wl.push('release'); return Promise.resolve(); } };
            w.__lock = lock;
            return Promise.resolve(lock);
          },
        },
      });
    });
    const calls = () => page.evaluate(() => (window as any).__wl);
    await page.goto(url('bd'));
    await toolbarLink(page, 'read').click();
    await tid(page, 'rd-book').filter({ hasText: 'a-comic' }).click();
    await pageImg(page, 1).waitFor({ state: 'visible' });
    await expect.poll(calls).toEqual(['request:screen']);
    // The browser drops the lock with a hidden tab; coming back asks again.
    await page.evaluate(() => { (window as any).__lock.released = true; document.dispatchEvent(new Event('visibilitychange')); });
    await expect.poll(calls).toEqual(['request:screen', 'request:screen']);
    await tid(page, 'rd-back').click();
    await expect(tid(page, 'rd-library')).toBeVisible();
    await expect.poll(calls).toEqual(['request:screen', 'request:screen', 'release']);
    // Library shown: no book, no new request.
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.waitForTimeout(200);
    expect(await calls()).toEqual(['request:screen', 'request:screen', 'release']);
  });

  test('portrait phone: chrome hidden, big page, one-line bar, class removed on close', async ({ page }) => {
    test.skip(!isMobile(), 'mobile project only');
    await open(page, 'bd', 'a-comic.cbz');
    await expect(page.locator('html')).toHaveClass(/(^|\s)rd-reading(\s|$)/);
    await expect(page.locator('.mp-bar')).toBeHidden();
    const vp = page.viewportSize()!;
    expect((await box(pageImg(page, 1))).height).toBeGreaterThanOrEqual(vp.height * 0.75);

    const ids = ['rd-back', 'rd-prev', 'rd-next', 'rd-fullscreen', 'rd-page-input'];
    const tops: number[] = [];
    for (const id of ids) {
      const el = tid(page, id);
      await expect(el, id).toBeVisible();
      const bb = await box(el);
      tops.push(bb.y);
      if (id !== 'rd-page-input') {
        expect.soft(bb.height, `${id} height`).toBeGreaterThanOrEqual(44);
        expect.soft(bb.width, `${id} width`).toBeGreaterThanOrEqual(44);
      }
    }
    expect(Math.max(...tops) - Math.min(...tops), 'toolbar on one line').toBeLessThanOrEqual(4);
    expect(await noHScroll(page)).toBe(true);

    await tid(page, 'rd-back').click();
    await expect(viewer(page)).toBeHidden();
    await expect(page.locator('html')).not.toHaveClass(/rd-reading/);
    // Opened from its row: Back returns to the folder listing.
    await expect(page.locator('.row[data-type="file"]').first()).toBeVisible();
  });

  test.describe('zoom', () => {
    const size = async (page: Page) => { const b = await box(pageImg(page, 1)); return { w: b.width, h: b.height }; };
    const expectZoomed = async (page: Page, base: { w: number; h: number }) => {
      await expect.poll(async () => (await size(page)).w / base.w, { timeout: 5000 }).toBeGreaterThan(1.8);
      await expect.poll(async () => (await size(page)).h / base.h, { timeout: 5000 }).toBeGreaterThan(1.8);
    };
    const expectFit = (page: Page, base: { w: number; h: number }) =>
      expect.poll(async () => (await size(page)).w / base.w, { timeout: 5000 }).toBeLessThanOrEqual(1.05);

    test('double tap zooms, a side tap turns the page with the zoom kept, second double tap restores', async ({ page }) => {
      await open(page, 'bd', 'a-comic.cbz');
      const base = await size(page);
      await doubleTapAt(page, 0.5);
      await expectZoomed(page, base);
      await expectPage(page, 1);

      await tid(page, 'rd-stage').evaluate((n) => { n.scrollTop = 40; });
      await tapAt(page, 0.95);
      await expectPage(page, 2);
      await pageImg(page, 2).waitFor({ state: 'visible' });
      await expect.poll(async () => (await box(pageImg(page, 2))).width / base.w, { timeout: 5000 }).toBeGreaterThan(1.8);
      expect(await tid(page, 'rd-stage').evaluate((n) => n.scrollTop), 'new page shows its top').toBe(0);

      await doubleTapAt(page, 0.5);
      await expect.poll(async () => (await box(pageImg(page, 2))).width / base.w, { timeout: 5000 }).toBeLessThanOrEqual(1.05);
      await expectPage(page, 2);
    });

    test('zoom buttons work for a comic and the zoom survives a page change, top of page shown', async ({ page }) => {
      test.skip(prof() === 'mobile', 'zoom buttons are hidden on phones for comics, double-tap zooms');
      await open(page, 'bd', 'a-comic.cbz');
      const base = await size(page);
      await expect(tid(page, 'rd-zoom-in')).toBeVisible();
      await expect(tid(page, 'rd-zoom-out')).toBeVisible();
      await tid(page, 'rd-zoom-in').click();
      await expect.poll(async () => (await size(page)).w / base.w, { timeout: 5000 }).toBeGreaterThan(1.2);
      await tid(page, 'rd-zoom-out').click();
      await expectFit(page, base);

      await tid(page, 'rd-zoom-in').click();
      await tid(page, 'rd-zoom-in').click();
      await expect.poll(async () => (await size(page)).w / base.w, { timeout: 5000 }).toBeGreaterThan(1.8);
      await tid(page, 'rd-next').click();
      await expectPage(page, 2);
      const b2 = await box(pageImg(page, 2));
      expect(b2.width / base.w, 'zoom kept on page change').toBeGreaterThan(1.8);
      // The new page starts at its top-left corner.
      expect(await tid(page, 'rd-stage').evaluate((n) => [n.scrollTop, n.scrollLeft])).toEqual([0, 0]);
    });
  });

  const scrollTop = (page: Page) => tid(page, 'rd-stage').evaluate((el) => el.scrollTop);
  async function zoomUntilOverflow(page: Page) {
    for (let k = 0; k < 4; k++) {
      if (await tid(page, 'rd-stage').evaluate((el) => el.scrollHeight > el.clientHeight + 20)) return;
      await tid(page, 'rd-zoom-in').click();
    }
    await expect.poll(() => tid(page, 'rd-stage').evaluate((el) => el.scrollHeight > el.clientHeight + 20)).toBe(true);
  }
  async function expectArrowScroll(page: Page) {
    await zoomUntilOverflow(page);
    await tid(page, 'rd-stage').evaluate((el) => { el.scrollTop = 0; });
    const p0 = await pageNo(page);
    await page.keyboard.press('ArrowDown');
    await expect.poll(() => scrollTop(page), { timeout: 5000 }).toBeGreaterThan(0);
    const down = await scrollTop(page);
    expect(await pageNo(page), 'ArrowDown keeps the page').toBe(p0);
    await page.keyboard.press('ArrowUp');
    await expect.poll(() => scrollTop(page), { timeout: 5000 }).toBeLessThan(down);
    expect(await pageNo(page), 'ArrowUp keeps the page').toBe(p0);
  }

  test('zoomed comic: ArrowDown/ArrowUp scroll the page and never turn it', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await open(page, 'bd', 'a-comic.cbz');
    await expectArrowScroll(page);
  });

  test('zoomed pdf: ArrowDown/ArrowUp scroll the page and never turn it', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await openBookFromListing(page, 'livres', 'a-book.pdf');
    await expectArrowScroll(page);
  });

  test('zoomed comic: ArrowRight scrolls until the right edge, then turns the page', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await open(page, 'bd', 'a-comic.cbz');
    // Up to the top step: the button disables itself there.
    while (await tid(page, 'rd-zoom-in').isEnabled()) await tid(page, 'rd-zoom-in').click();
    const stage = tid(page, 'rd-stage');
    await expect.poll(() => stage.evaluate((el) => el.scrollWidth > el.clientWidth + 20)).toBe(true);
    await stage.evaluate((el) => { el.scrollLeft = 0; });
    const left = () => stage.evaluate((el) => el.scrollLeft);

    await page.keyboard.press('ArrowRight');
    await expect.poll(left, { timeout: 5000 }).toBeGreaterThan(0);
    await expectPage(page, 1);

    // Press until the right edge: the press that reaches it scrolls, it never turns the page.
    const maxLeft = await stage.evaluate((el) => el.scrollWidth - el.clientWidth);
    for (let k = 0; k < 80 && (await left()) < maxLeft - 1; k++) {
      await page.keyboard.press('ArrowRight');
      await page.waitForTimeout(60);
    }
    expect(await left()).toBeGreaterThanOrEqual(maxLeft - 1);
    await expectPage(page, 1);

    await page.keyboard.press('ArrowRight');
    await expectPage(page, 2);
  });

  test('zoomed comic: Space scrolls down, at the bottom it turns the page', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await open(page, 'bd', 'a-comic.cbz');
    await zoomUntilOverflow(page);
    await tid(page, 'rd-stage').evaluate((el) => { el.scrollTop = 0; });
    await page.keyboard.press('Space');
    await expect.poll(() => scrollTop(page), { timeout: 5000 }).toBeGreaterThan(0);
    await expectPage(page, 1);
    await tid(page, 'rd-stage').evaluate((el) => { el.scrollTop = el.scrollHeight; });
    await page.keyboard.press('Space');
    await expectPage(page, 2);
  });

  test('zoomed comic: PageDown turns the page at once', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await open(page, 'bd', 'a-comic.cbz');
    await zoomUntilOverflow(page);
    await tid(page, 'rd-stage').evaluate((el) => { el.scrollTop = 0; });
    await page.keyboard.press('PageDown');
    await expectPage(page, 2);
    await page.keyboard.press('PageUp');
    await expectPage(page, 1);
  });

  test('sharpness slider: default on, 0 turns it off, value remembered, also on a PDF', async ({ page }) => {
    await open(page, 'bd', 'a-comic.cbz');
    const filt = () => pageImg(page, 1).evaluate((n) => getComputedStyle(n).filter);
    const setRange = (v: string) => tid(page, 'rd-sharp-range').evaluate((n: HTMLInputElement, val) => {
      n.value = val; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true }));
    }, v);
    expect(await filt()).toContain('rd-sharp-filter');
    await expect(tid(page, 'rd-sharp-panel')).toBeHidden();
    await tid(page, 'rd-sharp-toggle').click();
    await expect(tid(page, 'rd-sharp-panel')).toBeVisible();
    await expect(tid(page, 'rd-sharp-range')).toHaveValue('50');
    await setRange('0');
    expect(await filt()).toBe('none');
    await setRange('80');
    expect(await filt()).toContain('rd-sharp-filter');
    // Escape closes the panel without leaving the book.
    await tid(page, 'rd-sharp-range').press('Escape');
    await expect(tid(page, 'rd-sharp-panel')).toBeHidden();
    await expect(viewer(page)).toBeVisible();
    await page.reload();
    await viewer(page).waitFor({ state: 'visible' });
    await pageImg(page, 1).waitFor();
    await tid(page, 'rd-sharp-toggle').click();
    await expect(tid(page, 'rd-sharp-range')).toHaveValue('80');
    await page.goto(url('livres'));
    await playBtn(page, 'a-book.pdf').click();
    await viewer(page).waitFor({ state: 'visible' });
    await inkReady(page);
    // Comic PDFs are scans too: same slider, same remembered value.
    await expect(tid(page, 'rd-sharp-toggle')).toBeVisible();
    expect(await tid(page, 'rd-canvas').evaluate((n) => getComputedStyle(n).filter)).toContain('rd-sharp-filter');
  });

  test('fullscreen: only the handle brings the bar back', async ({ page }) => {
    test.skip(isMobile(), 'desktop pointer and keyboard');
    await open(page, 'bd', 'a-comic.cbz');
    await tid(page, 'rd-fullscreen').click();
    const v = viewer(page);
    await expect(v).toHaveClass(/rd-bar-hidden/, { timeout: 6000 });
    const b = (await tid(page, 'rd-stage').boundingBox())!;
    // Middle tap, wheel up, ArrowUp and a page change must all leave it hidden.
    await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2);
    await page.waitForTimeout(500);
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.wheel(0, -300);
    await page.keyboard.press('0');
    await page.keyboard.press('ArrowUp');
    await page.keyboard.press('ArrowRight');
    await expectPage(page, 2);
    await page.waitForTimeout(300);
    await expect(v).toHaveClass(/rd-bar-hidden/);
    await tid(page, 'rd-bar-handle').click();
    await expect(v).not.toHaveClass(/rd-bar-hidden/);
  });

  // Regression: on a real Android phone (Brave) touch-action alone still let a pinch zoom the whole page.
  test('a comic turns the browser pinch zoom off, fullscreen keeps it off, a PDF leaves it on', async ({ page }) => {
    const meta = () => page.locator('meta[name="viewport"]').getAttribute('content');
    const lock = ', maximum-scale=1, user-scalable=no';
    await open(page, 'bd', 'a-comic.cbz');
    const locked = (await meta())!;
    expect(locked.endsWith(lock)).toBe(true);
    await tid(page, 'rd-fullscreen').click();
    expect(await meta()).toContain('viewport-fit=cover');
    expect((await meta())!.split('user-scalable').length, 'lock written once').toBe(2);
    await page.keyboard.press('f');
    await expect(viewer(page)).not.toHaveClass(/rd-fs/);
    expect(await meta(), 'leaving fullscreen keeps the lock and restores the rest').toBe(locked);

    await page.goto(url('livres'));
    await playBtn(page, 'a-book.pdf').click();
    await viewer(page).waitFor({ state: 'visible' });
    expect(await meta()).not.toContain('user-scalable');
  });

  test.describe('touch zoom', () => {
    test.skip(() => !isMobile(), 'mobile project only');
    /** CDP touch gesture: frames[i] holds every finger's point at step i (Playwright's touchscreen only taps). */
    async function gesture(page: Page, frames: { x: number; y: number }[][]) {
      const client = await page.context().newCDPSession(page);
      const pts = (f: { x: number; y: number }[]) => f.map((p, id) => ({ x: Math.round(p.x), y: Math.round(p.y), id }));
      await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pts(frames[0]) });
      for (const f of frames.slice(1)) {
        await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pts(f) });
        await page.waitForTimeout(16);
      }
      await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await client.detach();
    }
    const steps = (n: number, at: (k: number) => { x: number; y: number }[]) => Array.from({ length: n + 1 }, (_, k) => at(k / n));
    async function drag(page: Page, x0: number, x1: number, y: number) {
      await gesture(page, steps(12, (k) => [{ x: x0 + (x1 - x0) * k, y }]));
    }
    const imgWidth = (page: Page) => pageImg(page, 1).evaluate((n) => n.getBoundingClientRect().width);

    test('pinch zooms the comic (not the page), a zoomed drag only pans, a side tap turns the page', async ({ page }) => {
      await open(page, 'bd', 'a-comic.cbz');
      await expect.poll(() => imgWidth(page)).toBeGreaterThan(50);
      const fitW = await imgWidth(page);
      const b = (await tid(page, 'rd-stage').boundingBox())!;
      const cx = b.x + b.width / 2, cy = b.y + b.height / 2;

      await gesture(page, steps(12, (k) => [{ x: cx - 20 - 80 * k, y: cy }, { x: cx + 20 + 80 * k, y: cy }]));
      await expect.poll(() => imgWidth(page), { message: 'reader zoom > 1' }).toBeGreaterThan(fitW * 1.5);
      expect(await page.evaluate(() => window.visualViewport!.scale), 'browser page zoom untouched').toBe(1);
      await expect(tid(page, 'rd-stage')).toHaveClass(/rd-zoomed/);
      expect(await pageNo(page)).toBe(1);

      // Zoomed: a horizontal drag pans and never turns the page, even when it reaches the edge.
      const sl0 = await tid(page, 'rd-stage').evaluate((n) => n.scrollLeft);
      await drag(page, cx + 80, cx - 80, cy);
      await page.waitForTimeout(400);
      expect(await pageNo(page), 'drag while zoomed keeps the page').toBe(1);
      expect(await tid(page, 'rd-stage').evaluate((n) => n.scrollLeft), 'the drag panned').not.toBe(sl0);
      await tid(page, 'rd-stage').evaluate((n) => { n.scrollLeft = n.scrollWidth; });
      await drag(page, cx + 80, cx - 80, cy);
      await page.waitForTimeout(400);
      expect(await pageNo(page), 'drag from the right edge keeps the page').toBe(1);

      // A tap on the right third turns the page; the zoom stays and the new page shows its top.
      await page.touchscreen.tap(Math.round(b.x + b.width * 0.9), Math.round(cy));
      await expectPage(page, 2);
      await expect(tid(page, 'rd-stage')).toHaveClass(/rd-zoomed/);
      expect(await tid(page, 'rd-stage').evaluate((n) => n.scrollTop)).toBe(0);
    });

    test('a pinch released near the fit snaps back to it and never turns the page', async ({ page }) => {
      await open(page, 'bd', 'a-comic.cbz');
      await expect.poll(() => imgWidth(page)).toBeGreaterThan(50);
      const fitW = await imgWidth(page);
      const b = (await tid(page, 'rd-stage').boundingBox())!;
      const cx = b.x + b.width / 2, cy = b.y + b.height / 2;
      // Fingers drift sideways a lot while spreading by 2 %: a multi-touch gesture is never a swipe.
      await gesture(page, steps(12, (k) => [{ x: cx - 100 - 100 * k, y: cy }, { x: cx + 100 - 96 * k, y: cy }]));
      await page.waitForTimeout(400);
      await expect(tid(page, 'rd-stage')).not.toHaveClass(/rd-zoomed/);
      expect(Math.abs((await imgWidth(page)) - fitW)).toBeLessThanOrEqual(1);
      expect(await pageNo(page)).toBe(1);
    });
  });

  test('zoomed comic: a mouse drag-pan starting in the right third never turns the page', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop mouse only');
    await open(page, 'bd', 'a-comic.cbz');
    await tid(page, 'rd-zoom-in').click();
    await tid(page, 'rd-zoom-in').click();
    const stage = tid(page, 'rd-stage');
    await expect.poll(() => stage.evaluate((el) => el.scrollWidth > el.clientWidth + 20)).toBe(true);
    const b = (await stage.boundingBox())!;
    const y = b.y + b.height / 2;
    const sl0 = await stage.evaluate((n) => n.scrollLeft);
    await page.mouse.move(b.x + b.width * 0.9, y);
    await page.mouse.down();
    await page.mouse.move(b.x + b.width * 0.5, y, { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(500);
    expect(await pageNo(page), 'a drag is not a tap').toBe(1);
    expect(await stage.evaluate((n) => n.scrollLeft), 'the drag panned').not.toBe(sl0);
  });

  test('zoomed pdf: a tap on the right third turns the page', async ({ page }) => {
    await openBookFromListing(page, 'livres', 'a-book.pdf');
    await tid(page, 'rd-zoom-in').click();
    await expect(tid(page, 'rd-stage')).toHaveClass(/rd-zoomed/);
    await tapStage(page, 0.9);
    await expectPage(page, 2);
    await expect(tid(page, 'rd-stage')).toHaveClass(/rd-zoomed/);
  });

  test('fullscreen: the cursor hides when the mouse stays still, comes back on move, bar stays hidden', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop mouse only');
    await open(page, 'bd', 'a-comic.cbz');
    const v = viewer(page);
    await tid(page, 'rd-fullscreen').click();
    await expect(v).toHaveClass(/rd-bar-hidden/, { timeout: 6000 });
    const b = (await tid(page, 'rd-stage').boundingBox())!;
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await expect(v).not.toHaveClass(/rd-idle/);
    await page.waitForTimeout(2700);
    await expect(v).toHaveClass(/rd-idle/);
    expect(await tid(page, 'rd-stage').evaluate((n) => getComputedStyle(n).cursor)).toBe('none');
    await expect(v).toHaveClass(/rd-bar-hidden/);
    await page.mouse.move(b.x + b.width / 2 + 30, b.y + b.height / 2 + 10);
    await expect(v).not.toHaveClass(/rd-idle/);
    await expect(v, 'moving the mouse never brings the bar back').toHaveClass(/rd-bar-hidden/);
    // Out of fullscreen the cursor never hides.
    await page.keyboard.press('f');
    await expect(v).not.toHaveClass(/rd-fs/);
    await page.waitForTimeout(2700);
    await expect(v).not.toHaveClass(/rd-idle/);
  });

  test('wheel zooms a PDF', async ({ page }) => {
    test.skip(isMobile(), 'desktop wheel');
    await page.goto(url('livres'));
    await playBtn(page, 'a-book.pdf').click();
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
    await inkReady(page);
    const canvas = page.locator('[data-testid="rd-viewer"] canvas').first();
    const w0 = (await canvas.boundingBox())!.width;
    const b = (await tid(page, 'rd-stage').boundingBox())!;
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.wheel(0, -300);
    await expect.poll(async () => (await canvas.boundingBox())!.width / w0, { timeout: 5000 }).toBeGreaterThan(1.1);
    await page.mouse.wheel(0, 300);
    await expect.poll(async () => (await canvas.boundingBox())!.width / w0, { timeout: 5000 }).toBeLessThanOrEqual(1.05);
  });

  test.describe('keyboard zoom', () => {
    test.skip(() => prof() !== 'desktop', 'desktop project only');
    const width = async (page: Page) => (await box(pageImg(page, 1))).width;

    test('+ zooms in, 0 restores the fit, - at the fit stays fitted', async ({ page }) => {
      await open(page, 'bd', 'a-comic.cbz');
      const base = await width(page);
      await page.keyboard.press('-');
      await page.waitForTimeout(300);
      expect(await width(page) / base, '- from the fit').toBeLessThanOrEqual(1.05);

      await page.keyboard.press('+');
      await expect.poll(async () => (await width(page)) / base, { timeout: 5000 }).toBeGreaterThan(1.2);
      await page.keyboard.press('0');
      await expect.poll(async () => (await width(page)) / base, { timeout: 5000 }).toBeLessThanOrEqual(1.05);

      await page.keyboard.press('=');
      await expect.poll(async () => (await width(page)) / base, { timeout: 5000 }).toBeGreaterThan(1.2);
      await page.keyboard.press('-');
      await expect.poll(async () => (await width(page)) / base, { timeout: 5000 }).toBeLessThanOrEqual(1.05);
    });

    test('Control++ is left to the browser: the reader does not zoom', async ({ page }) => {
      await open(page, 'bd', 'a-comic.cbz');
      const base = await width(page);
      await page.keyboard.press('Control+=');
      await page.keyboard.press('Control+0');
      await page.waitForTimeout(300);
      expect(await width(page) / base).toBeLessThanOrEqual(1.05);
    });

    test('typing 0 in the page field does not reset the zoom', async ({ page }) => {
      await open(page, 'bd', 'a-comic.cbz');
      const base = await width(page);
      await tid(page, 'rd-zoom-in').click();
      await expect.poll(async () => (await width(page)) / base, { timeout: 5000 }).toBeGreaterThan(1.2);
      await tid(page, 'rd-page-input').click();
      await page.keyboard.type('0');
      await page.waitForTimeout(300);
      expect(await width(page) / base).toBeGreaterThan(1.2);
    });
  });

  test('not zoomed: Space and ArrowRight turn the page', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await open(page, 'bd', 'a-comic.cbz');
    await page.keyboard.press('Space');
    await expectPage(page, 2);
    await page.keyboard.press('ArrowRight');
    await expectPage(page, 3);
  });

  test('not zoomed: ArrowDown/ArrowUp do not change page', async ({ page }) => {
    test.skip(prof() !== 'desktop', 'desktop project only');
    await open(page, 'bd', 'a-comic.cbz');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowUp');
    await page.waitForTimeout(300);
    await expectPage(page, 1);
  });

  test('pdf: forward by zone, key and button; no spread switch', async ({ page }) => {
    await openBookFromListing(page, 'livres', 'a-book.pdf');
    await expect(tid(page, 'rd-spread-toggle')).toBeHidden();
    await tid(page, 'rd-next').click();
    await expectPage(page, 2);
    await page.keyboard.press('ArrowRight');
    await expectPage(page, 3);
    await page.keyboard.press('ArrowLeft');
    await expectPage(page, 2);
    await tapStage(page, 0.9);
    await expectPage(page, 3);
  });
});

test.describe('epub: réglages du texte', () => {
  // EPUB is off in sharebox (foliate-js is not shipped): a stand-in foliate-view renders one same-origin
  // chapter frame and applies setStyles there, so the real reader.js wiring and CSS are checked.
  const FAKE_FOLIATE = `
    class FakeView extends HTMLElement {
      async open() {
        const frame = document.createElement('iframe');
        frame.setAttribute('data-testid', 'fake-chapter');
        frame.style.cssText = 'width:100%;height:300px;border:0';
        this.appendChild(frame);
        const d = frame.contentDocument;
        d.open();
        d.write('<!doctype html><html><head><style id="rd"></style></head><body><p>Chapitre un</p></body></html>');
        d.close();
        this.book = { transformTarget: new EventTarget(), sections: [{ id: 'c1' }], destroy() {} };
        this.renderer = { setStyles: (css) => { frame.contentDocument.getElementById('rd').textContent = css; } };
      }
      async init() { setTimeout(() => this.dispatchEvent(new CustomEvent('relocate', { detail: { fraction: 0, cfi: 'epubcfi(/6/2)' } })), 0); }
      close() {}
      prev() { return Promise.resolve(); }
      next() { return Promise.resolve(); }
      goTo() { return Promise.resolve(); }
      goToFraction() { return Promise.resolve(); }
    }
    customElements.define('foliate-view', FakeView);`;
  const HARNESS = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><link rel="icon" href="data:,">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/share/reader-theme.css"><link rel="stylesheet" href="/share/reader.css"></head>
    <body><div id="asc-reader"></div><script src="/share/reader.js"></script><script>
    AscReader.init({ root: document.getElementById('asc-reader'), token: 'epub-harness', libBase: '/share/__fake/',
      books: [{ name: 'Roman.epub', url: '/share/__fake/roman.epub', kind: 'epub', size: 1000 }] }).open('Roman.epub');
    </script></body></html>`;

  async function openHarness(page: Page) {
    await page.route('**/share/__epub-harness.html', (r) => r.fulfill({ contentType: 'text/html; charset=utf-8', body: HARNESS }));
    await page.route('**/share/__fake/foliate/view.js', (r) => r.fulfill({ contentType: 'text/javascript', body: FAKE_FOLIATE }));
    await page.route('**/share/__fake/roman.epub', (r) => r.fulfill({ contentType: 'application/epub+zip', body: 'PK' }));
    await page.goto(`${srv.base}/share/__epub-harness.html`);
    await tid(page, 'rd-viewer').waitFor({ state: 'visible' });
    await tid(page, 'fake-chapter').waitFor();
  }
  const chapter = (page: Page) => tid(page, 'fake-chapter').evaluate((f: HTMLIFrameElement) => {
    const d = f.contentDocument!;
    return { size: getComputedStyle(d.documentElement).fontSize, bg: getComputedStyle(d.body).backgroundColor, fg: getComputedStyle(d.body).color };
  });
  const setSize = (page: Page, v: string) => tid(page, 'rd-epub-size').evaluate((n: HTMLInputElement, val) => {
    n.value = val; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true }));
  }, v);
  const SEPIA = 'rgb(244, 236, 216)';

  test('Aa: size and theme applied live to the chapter, remembered after a reload', async ({ page }) => {
    await openHarness(page);
    await expect(tid(page, 'rd-sharp-toggle')).toBeHidden();
    const toggle = tid(page, 'rd-epub-toggle');
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveText('Aa');
    expect((await chapter(page)).size, 'default size').toBe('16px');
    await expect(tid(page, 'rd-epub-panel')).toBeHidden();
    await toggle.click();
    await expect(tid(page, 'rd-epub-panel')).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(tid(page, 'rd-epub-size')).toHaveValue('100');
    await expect(tid(page, 'rd-epub-theme-site')).toHaveAttribute('aria-pressed', 'true');

    await setSize(page, '140');
    await expect(tid(page, 'rd-epub-panel')).toContainText('140 %');
    await tid(page, 'rd-epub-theme-sepia').click();
    await expect(tid(page, 'rd-epub-theme-sepia')).toHaveAttribute('aria-pressed', 'true');
    await expect(tid(page, 'rd-epub-theme-site')).toHaveAttribute('aria-pressed', 'false');
    expect(await chapter(page)).toEqual({ size: '22.4px', bg: SEPIA, fg: 'rgb(91, 70, 54)' });
    expect(await tid(page, 'rd-epub-host').evaluate((n) => getComputedStyle(n).backgroundColor), 'margins match').toBe(SEPIA);

    // Escape closes the panel without leaving the book.
    await tid(page, 'rd-epub-theme-sepia').press('Escape');
    await expect(tid(page, 'rd-epub-panel')).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(tid(page, 'rd-viewer')).toBeVisible();

    await page.reload();
    await tid(page, 'fake-chapter').waitFor();
    await expect.poll(() => chapter(page)).toEqual({ size: '22.4px', bg: SEPIA, fg: 'rgb(91, 70, 54)' });
    await toggle.click();
    await expect(tid(page, 'rd-epub-size')).toHaveValue('140');
    await expect(tid(page, 'rd-epub-theme-sepia')).toHaveAttribute('aria-pressed', 'true');

    // A press outside closes it; back to the site colours clears the margin colour.
    await tid(page, 'rd-epub-theme-site').click();
    expect(await tid(page, 'rd-epub-host').evaluate((n) => n.style.background)).toBe('');
    await page.mouse.click(5, (await page.viewportSize())!.height - 5);
    await expect(tid(page, 'rd-epub-panel')).toBeHidden();
  });
});
