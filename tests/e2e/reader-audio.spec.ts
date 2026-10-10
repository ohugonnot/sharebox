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
  createFixtures, removeFixtures, startServer, snapshotTmp, cleanTmp, TestServer, HOSTILE_PDFS, HOSTILE_MP3S, COMIC_COLORS,
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

    // Back: viewer closes into the library, whose Retour link returns to the listing.
    await tid(page, 'rd-back').click();
    await expect(tid(page, 'rd-library')).toBeVisible();
    await shot(page, 'reader-library');
    await page.locator('.mp-back').click();
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
    await tid(page, 'rd-back').click();
    await expect(tid(page, 'rd-library')).toBeVisible();
    // Closing the book frees the page image.
    await expect(tid(page, 'rd-viewer').locator('img:visible')).toHaveCount(0);

    await tid(page, 'rd-book').filter({ hasText: 'a-comic' }).click();
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
    // The viewer stays usable: back returns to the library.
    await tid(page, 'rd-back').click();
    await expect(tid(page, 'rd-library')).toBeVisible();
  });
});

// ---------------------------------------------------------------- (2) album

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
  test('mixte: aucun bouton Lire/Écouter de dossier, la vidéo monte video#player', async ({ page }) => {
    const resp = await page.goto(url('mixte'));
    expect(resp!.headers()['content-security-policy']).toBeUndefined();
    await expect(toolbarLink(page, 'read')).toHaveCount(0);
    await expect(toolbarLink(page, 'listen')).toHaveCount(0);
    await expect(page.locator('a[href*="read=1"], a[href*="listen=1"]')).toHaveCount(0);
    await expect(playBtn(page, 'notice.pdf')).toHaveCount(0);
    await shot(page, 'mixte-listing');
    await playBtn(page, 'un.mp4').click();
    await expect(page.locator('video#player')).toBeAttached();
    await expect(page.locator('#asc-reader, #asc-audiobook')).toHaveCount(0);
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
