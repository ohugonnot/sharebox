/**
 * E2E: seeks in a burst are merged into one transcode restart.
 * Run: npx playwright test -c playwright.reader-audio.config.ts player-seek --project=desktop
 * The clip is mpeg4 so that Chromium must go through the transcode path.
 */
import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as crypto from 'node:crypto';
import { startServer, snapshotTmp, cleanTmp, TestServer } from './reader-audio-fixtures';

const DATA_ROOT = '/srv/sharebox-seek-data';
const URL_PATH = '/dl/e2e-root?p=clip.mkv&play=1';
// SB_SEEK_GAP_MS only serves the before/after comparison; the default is the burst case.
const KEY_GAP_MS = parseInt(process.env.SB_SEEK_GAP_MS || '400', 10);
let dir = '';
let srv: TestServer;
let tmpBefore: Set<string>;

test.beforeAll(async () => {
  tmpBefore = snapshotTmp();
  fs.mkdirSync(DATA_ROOT, { recursive: true, mode: 0o755 });
  dir = `${DATA_ROOT}/${crypto.randomBytes(6).toString('hex')}`;
  fs.mkdirSync(dir, { mode: 0o755 });
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=s=1280x720:d=180:r=15',
    '-f', 'lavfi', '-i', 'sine=d=180', '-c:v', 'mpeg4', '-c:a', 'mp2', `${dir}/clip.mkv`]);
  execFileSync('chown', ['-R', 'www-data:www-data', dir]);
  process.env.SB_E2E_STREAM_LOG = `${dir}/stream.log`;
  srv = await startServer(dir);
});
test.afterAll(async () => {
  await srv?.stop();
  delete process.env.SB_E2E_STREAM_LOG;
  if (dir.startsWith(`${DATA_ROOT}/`)) fs.rmSync(dir, { recursive: true, force: true });
  cleanTmp(tmpBefore);
});

interface Req { at: number; start: number; url: string }

/** Records every transcode/hls stream request, with its start= value (0 when absent). */
function trackStreams(page: Page): Req[] {
  const reqs: Req[] = [];
  page.on('request', (r) => {
    const u = r.url();
    if (!/[?&]stream=(transcode|hls)\b/.test(u)) return;
    reqs.push({ at: Date.now(), start: parseFloat(new URL(u).searchParams.get('start') || '0'), url: u });
  });
  return reqs;
}

/** A seek before 'playing' is dropped at commit (paused player), so wait for real playback. */
async function waitPlaying(page: Page) {
  await expect.poll(() => page.evaluate(() => {
    const v = document.getElementById('player') as HTMLVideoElement;
    return !v.paused && v.currentTime > 0.3 && v.readyState >= 3;
  }), { timeout: 30000 }).toBe(true);
}

const position = (page: Page) => page.evaluate(() => (document.getElementById('player') as HTMLVideoElement).currentTime);

async function barX(page: Page, frac: number) {
  const box = (await page.locator('#seek-bar').boundingBox())!;
  return { x: box.x + box.width * frac, y: box.y + box.height / 2 };
}

async function open(page: Page) {
  const reqs = trackStreams(page);
  await page.goto(`${srv.base}${URL_PATH}`);
  await waitPlaying(page);
  return reqs;
}

const distinctStarts = (reqs: Req[]) => new Set(reqs.map((r) => r.start)).size;

test.describe('player seek coalescing', () => {
  test('6 ArrowRight 400 ms apart give 1 stream request at about +60 s', async ({ page }) => {
    const reqs = await open(page);
    const before = reqs.length;
    const p0 = await position(page);
    for (let i = 0; i < 6; i++) { await page.keyboard.press('ArrowRight'); await page.waitForTimeout(KEY_GAP_MS); }
    await page.waitForTimeout(2500);
    const added = reqs.slice(before);
    const starts = fs.readFileSync(`${dir}/stream.log`, 'utf8').split('\n').filter((l) => l.includes('TRANSCODE start')).length;
    console.log(`TRANSCODE start in stream.log: ${starts} (gap ${KEY_GAP_MS} ms, start values ${JSON.stringify(reqs.map((r) => r.start))})`);
    expect(starts).toBe(2);
    expect(added.map((r) => r.start), JSON.stringify(added.map((r) => r.url))).toHaveLength(1);
    expect(added[0].start).toBeGreaterThan(p0 + 55);
    expect(added[0].start).toBeLessThan(p0 + 66);
  });

  test('6 bar clicks 400 ms apart give 1 stream request on the last position', async ({ page }) => {
    const reqs = await open(page);
    const before = reqs.length;
    for (let i = 0; i < 6; i++) {
      const { x, y } = await barX(page, 0.2 + i * 0.1);
      await page.mouse.click(x, y);
      await page.waitForTimeout(400);
    }
    await page.waitForTimeout(2500);
    const added = reqs.slice(before);
    expect(added, JSON.stringify(added.map((r) => r.url))).toHaveLength(1);
    expect(Math.abs(added[0].start - 0.7 * 180)).toBeLessThan(6);
  });

  test('an isolated bar click gives exactly 1 request in under 1.5 s', async ({ page }) => {
    const reqs = await open(page);
    const before = reqs.length;
    const { x, y } = await barX(page, 0.5);
    const t0 = Date.now();
    await page.mouse.click(x, y);
    await expect.poll(() => reqs.length - before, { timeout: 1500 }).toBe(1);
    expect(reqs[before].at - t0).toBeLessThan(1500);
    await page.waitForTimeout(1500);
    expect(reqs.length - before).toBe(1);
    expect(Math.abs(reqs[before].start - 90)).toBeLessThan(6);
  });

  test('the first stream request is not delayed by the seek debounce', async ({ page }) => {
    const reqs = trackStreams(page);
    let probedAt = 0;
    page.on('response', (r) => { if (/[?&]probe=1\b/.test(r.url()) && !probedAt) probedAt = Date.now(); });
    await page.goto(`${srv.base}${URL_PATH}`);
    await expect.poll(() => reqs.length, { timeout: 20000 }).toBeGreaterThan(0);
    expect(probedAt).toBeGreaterThan(0);
    expect(reqs[0].at - probedAt).toBeLessThan(500);
  });

  test('a quality change restarts at once, with the new quality', async ({ page }) => {
    const reqs = await open(page);
    const before = reqs.length;
    const sel = page.locator('select[data-track="quality"]');
    const values = await sel.locator('option').evaluateAll((o) => o.map((e) => (e as HTMLOptionElement).value));
    const current = await sel.inputValue();
    const target = values.includes('480') && current !== '480' ? '480' : values.find((v) => v !== current)!;
    expect(target, `options ${values}, current ${current}`).toBeTruthy();
    const t0 = Date.now();
    await sel.selectOption(target);
    await expect.poll(() => reqs.length - before, { timeout: 1500 }).toBe(1);
    expect(reqs[before].at - t0).toBeLessThan(1000);
    expect(reqs[before].url).toContain(`quality=${target}`);
    await page.waitForTimeout(1500);
    expect(reqs.length - before).toBe(1);
  });

  test('a seek then a quality change 200 ms later give 1 request at the seek target', async ({ page }) => {
    const reqs = await open(page);
    const before = reqs.length;
    const { x, y } = await barX(page, 0.5);
    await page.mouse.click(x, y);
    await page.waitForTimeout(200);
    const sel = page.locator('select[data-track="quality"]');
    const values = await sel.locator('option').evaluateAll((o) => o.map((e) => (e as HTMLOptionElement).value));
    const current = await sel.inputValue();
    const target = values.find((v) => v !== current)!;
    await sel.selectOption(target);
    await page.waitForTimeout(2500);
    const added = reqs.slice(before);
    expect(added, JSON.stringify(added.map((r) => r.url))).toHaveLength(1);
    expect(added[0].url).toContain(`quality=${target}`);
    expect(Math.abs(added[0].start - 90)).toBeLessThan(6);
  });

  test('dragging the bar sends nothing until the button is released', async ({ page }) => {
    const reqs = await open(page);
    const before = reqs.length;
    const a = await barX(page, 0.3);
    await page.mouse.move(a.x, a.y);
    await page.mouse.down();
    for (let i = 1; i <= 4; i++) { const b = await barX(page, 0.3 + i * 0.05); await page.mouse.move(b.x, b.y); }
    await page.waitForTimeout(1000);
    expect(reqs.length - before).toBe(0);
    await page.mouse.up();
    await expect.poll(() => reqs.length - before, { timeout: 2000 }).toBe(1);
    await page.waitForTimeout(1500);
    expect(reqs.length - before).toBe(1);
    expect(Math.abs(reqs[before].start - 0.5 * 180)).toBeLessThan(6);
  });
});
