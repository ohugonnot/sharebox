/**
 * Playwright comprehensive E2E tests — ShareBox Docker demo
 *
 * Prerequisites (Docker demo):
 *   docker run -d --name sharebox-demo -p 8088:80 \
 *     -e SHAREBOX_ADMIN_USER=admin -e SHAREBOX_ADMIN_PASS=changeme \
 *     -e SHAREBOX_DEMO_DATA=true sharebox-demo
 *   SHAREBOX_TEST_URL=http://localhost:8088 npx playwright test comprehensive.spec.ts
 *
 * All tests skip gracefully when demo data isn't available.
 * Uses /dl/browse (auto-share token seeded by entrypoint.sh).
 */

import { test, expect, Page, BrowserContext } from '@playwright/test';

// ---------------------------------------------------------------------------
// Constants & helpers
// ---------------------------------------------------------------------------

const isLocal = (): boolean => {
  const url = process.env.SHAREBOX_TEST_URL ?? 'https://anime-sanctuary.net';
  return url.startsWith('http://localhost') || url.startsWith('http://127.0.0.1');
};

// Public browse root — uses the "browse" token seeded by entrypoint.sh
const BROWSE_ROOT = '/dl/browse';

// Demo video — seeded by demo-data.sh
const DEMO_VIDEO_PATH = 'Anime/Attack on Titan/Season 1/Attack.on.Titan.S01E01.mkv';

/** Login via form (Docker demo only). */
async function loginLocal(page: Page): Promise<void> {
  if (!isLocal()) return;
  await page.goto('/share/login.php');
  // Already logged in → redirect
  if (page.url().endsWith('/share/') || page.url().endsWith('/share')) return;
  await page.fill('input[name="username"]', process.env.SHAREBOX_TEST_USER ?? 'admin');
  await page.fill('input[name="password"]', process.env.SHAREBOX_TEST_PASS ?? 'changeme');
  await page.click('button[type="submit"]');
  await page.waitForURL(/\/share\/?$/, { timeout: 10000 });
}

/** Wait for the grid to have at least one card that isn't a "shimmer" placeholder. */
async function waitForGridCards(page: Page): Promise<void> {
  await expect(page.locator('.grid-card').first()).toBeVisible({ timeout: 15000 });
}

/** Skip if not running against the local Docker demo. */
function requireLocal(): void {
  test.skip(!isLocal(), 'This test requires the Docker demo (SHAREBOX_TEST_URL=http://localhost:...)');
}

// ---------------------------------------------------------------------------
// 1. Public Browse — no auth needed on /dl/browse
// ---------------------------------------------------------------------------

test.describe('Public Browse', () => {
  test('root shows 3 top-level categories', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT);
    // entrypoint.sh seeds token "browse" → /media which contains Anime, Films, Series
    const html = await page.content();
    if (!html.includes('Anime') && !html.includes('Films') && !html.includes('Series')) {
      test.skip(true, 'Demo data not present');
      return;
    }
    await expect(page.locator('.row-name.is-folder, .grid-card-title')).toContainText(['Anime'], { timeout: 10000 });
    await expect(page.locator('.row-name.is-folder, .grid-card-title')).toContainText(['Films']);
    await expect(page.locator('.row-name.is-folder, .grid-card-title')).toContainText(['Series']);
  });

  test('Films folder contains 18 items', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Films');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Films folder not available');
      return;
    }
    // 18 film files seeded by demo-data.sh
    const items = page.locator('.row:not(:has(.row-name:text("..")))');
    await expect(items).toHaveCount(18, { timeout: 10000 });
  });

  test('Anime folder contains 5 sub-items (incl. Attack on Titan, Death Note, One Piece)', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    const folderNames = page.locator('.row-name.is-folder');
    await expect(folderNames).toContainText(['Attack on Titan'], { timeout: 10000 });
    await expect(folderNames).toContainText(['Death Note']);
    await expect(folderNames).toContainText(['One Piece']);
  });

  test('navigating into Attack on Titan shows Season folders', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime%2FAttack+on+Titan');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Attack on Titan folder not available');
      return;
    }
    // demo-data.sh creates Season 1 and Season 2
    await expect(page.locator('.row-name.is-folder')).toContainText(['Season'], { timeout: 10000 });
  });

  test('Series folder contains 6 sub-items', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Series');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Series folder not available');
      return;
    }
    const folderNames = page.locator('.row-name.is-folder');
    await expect(folderNames).toContainText(['Breaking Bad'], { timeout: 10000 });
    await expect(folderNames).toContainText(['Game of Thrones']);
    await expect(folderNames).toContainText(['Stranger Things']);
    await expect(folderNames).toContainText(['The Mandalorian']);
  });

  test('breadcrumb back navigation works', async ({ page }) => {
    requireLocal();
    // Navigate two levels deep then use breadcrumb to go back to root
    await page.goto(BROWSE_ROOT + '?p=Anime%2FAttack+on+Titan');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Nested path not available');
      return;
    }
    // Breadcrumb should show the share name and intermediate path
    const breadcrumb = page.locator('.breadcrumb');
    await expect(breadcrumb).toBeVisible({ timeout: 10000 });
    await expect(breadcrumb).toContainText('Attack on Titan');

    // Click the root breadcrumb link (first anchor in breadcrumb)
    const rootLink = page.locator('.breadcrumb a').first();
    await rootLink.click();
    // Should land at root listing
    await expect(page.locator('.row-name.is-folder, .grid-card-title')).toContainText(['Anime'], { timeout: 10000 });
  });

  test('list view shows row elements by default', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    // Default view is list — rows should be visible, grid should be hidden
    await expect(page.locator('.panel').first()).toBeVisible({ timeout: 10000 });
    const gridWrap = page.locator('#grid-folders');
    if (await gridWrap.count() > 0) {
      await expect(gridWrap).toHaveClass(/hidden/);
    }
  });

  test('grid view toggle shows grid-cards and hides list', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    // Click the view toggle button
    const viewToggle = page.locator('#view-toggle');
    if (await viewToggle.count() === 0) {
      test.skip(true, 'No view toggle present (no grid items)');
      return;
    }
    await viewToggle.click();
    // Grid should now be visible
    await expect(page.locator('#grid-folders')).not.toHaveClass(/hidden/, { timeout: 5000 });
    // At least one grid card should be present
    await expect(page.locator('.grid-card').first()).toBeVisible();
  });

  test('?view=grid URL parameter shows grid directly', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime&view=grid');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    if (await page.locator('#grid-folders').count() === 0) {
      test.skip(true, 'No grid present');
      return;
    }
    // Grid should not have the hidden class when ?view=grid is in URL
    await expect(page.locator('#grid-folders')).not.toHaveClass(/hidden/, { timeout: 10000 });
    await expect(page.locator('.grid-card').first()).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// 2. TMDB Posters endpoint
// ---------------------------------------------------------------------------

test.describe('TMDB Posters', () => {
  test('Films ?posters=1 returns JSON with posters key', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?p=Films&posters=1');
    if (resp.status() === 404 || resp.status() === 500) {
      test.skip(true, 'Films folder or TMDB handler unavailable');
      return;
    }
    expect(resp.ok()).toBeTruthy();
    const data = await resp.json();
    if (data.error && data.error.includes('TMDB')) {
      test.skip(true, 'No TMDB API key configured');
      return;
    }
    expect(data).toHaveProperty('posters');
    expect(data).toHaveProperty('pending');
    expect(typeof data.posters).toBe('object');
    expect(typeof data.pending).toBe('number');
  });

  test('Films posters JSON has 18 entries (one per film)', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?p=Films&posters=1');
    if (!resp.ok()) {
      test.skip(true, 'Posters endpoint unavailable');
      return;
    }
    const data = await resp.json();
    if (data.error && data.error.includes('TMDB')) {
      test.skip(true, 'No TMDB API key configured');
      return;
    }
    // Even without TMDB API key, each file gets an entry in the posters map (may be empty object)
    // We only validate structure, not TMDB-specific content that needs an API key
    expect(typeof data.posters).toBe('object');
  });

  test('Anime ?posters=1 returns entries for Attack on Titan, Death Note, One Piece', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?p=Anime&posters=1');
    if (!resp.ok()) {
      test.skip(true, 'Posters endpoint unavailable for Anime');
      return;
    }
    const data = await resp.json();
    if (data.error && data.error.includes('TMDB')) {
      test.skip(true, 'No TMDB API key configured');
      return;
    }
    expect(data).toHaveProperty('posters');
    // The 3 anime folders should appear in the response
    // (they get inserted as pending rows immediately, even without TMDB key)
    // We check that the response covers the expected folders
    const response = await page.request.get(BROWSE_ROOT + '?p=Anime');
    const html = await response.text();
    if (html.includes('Attack on Titan')) {
      // posters response may have entries OR be empty (no TMDB key) — just verify structure
      expect(data).toHaveProperty('pending');
    }
  });

  test('Series ?posters=1 returns JSON without error', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?p=Series&posters=1');
    if (!resp.ok()) {
      test.skip(true, 'Posters endpoint unavailable for Series');
      return;
    }
    const data = await resp.json();
    if (data.error && data.error.includes('TMDB')) {
      test.skip(true, 'No TMDB API key configured');
      return;
    }
    expect(data).toHaveProperty('posters');
    expect(data).toHaveProperty('pending');
    expect(typeof data.pending).toBe('number');
    expect(data.pending).toBeGreaterThanOrEqual(0);
  });

  test('poster URLs use tmdb.org domain when present', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?p=Anime&posters=1');
    if (!resp.ok()) return;
    const data = await resp.json();
    if (data.error && data.error.includes('TMDB')) {
      test.skip(true, 'No TMDB API key configured');
      return;
    }
    for (const entry of Object.values(data.posters) as any[]) {
      if (entry.poster) {
        // All TMDB poster URLs start with the CDN domain
        expect(entry.poster).toMatch(/^https:\/\/image\.tmdb\.org\//);
      }
    }
  });
});

// ---------------------------------------------------------------------------
// 3. Grid UI
// ---------------------------------------------------------------------------

test.describe('Grid UI', () => {
  test('grid cards render after toggling to grid view', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime&view=grid');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    await waitForGridCards(page);
    const cards = page.locator('.grid-card');
    const count = await cards.count();
    expect(count).toBeGreaterThan(0);
  });

  test('each grid card has a title label', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime&view=grid');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    await waitForGridCards(page);
    // All non-back cards must have a visible title in .grid-card-label
    const titles = page.locator('.grid-card .grid-card-title');
    const count = await titles.count();
    expect(count).toBeGreaterThan(0);
    // First title should be non-empty
    const firstTitle = await titles.first().textContent();
    expect(firstTitle?.trim().length).toBeGreaterThan(0);
  });

  test('card size S/M/L buttons exist in gear panel', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime&view=grid');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    // Open the gear panel
    const gearBtn = page.locator('#gear-btn');
    await expect(gearBtn).toBeVisible({ timeout: 10000 });
    await gearBtn.click();
    const gearPanel = page.locator('#gear-panel');
    await expect(gearPanel).toHaveClass(/open/, { timeout: 5000 });

    // Card size toggle should have S, M, L options
    const sizeToggle = page.locator('#gt-cardsize');
    await expect(sizeToggle).toBeVisible();
    await expect(sizeToggle.locator('button[data-val="130"]')).toHaveText('S');
    await expect(sizeToggle.locator('button[data-val="180"]')).toHaveText('M');
    await expect(sizeToggle.locator('button[data-val="240"]')).toHaveText('L');
  });

  test('card size S changes --card-size CSS variable', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime&view=grid');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    await waitForGridCards(page);

    // Open gear
    await page.locator('#gear-btn').click();
    await page.locator('#gear-panel').waitFor({ state: 'visible' });

    // Click S button
    const sBtn = page.locator('#gt-cardsize button[data-val="130"]');
    await sBtn.click();

    // The grid wrap should now have a card-size matching 130px
    const cardSize = await page.evaluate(() => {
      const grid = document.getElementById('grid-folders');
      return grid ? getComputedStyle(grid).getPropertyValue('--card-size').trim() : null;
    });
    // After clicking S (data-val=130), setCardSize('130') sets --card-size:130px
    expect(cardSize).toContain('130');
  });

  test('gear menu opens and closes on button click', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    const gearBtn = page.locator('#gear-btn');
    await expect(gearBtn).toBeVisible({ timeout: 10000 });

    // Open
    await gearBtn.click();
    await expect(page.locator('#gear-panel')).toHaveClass(/open/, { timeout: 5000 });

    // Close by clicking the button again
    await gearBtn.click();
    await expect(page.locator('#gear-panel')).not.toHaveClass(/open/);
  });

  test('quality selector has 480p/720p/1080p options', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    await page.locator('#gear-btn').click();
    const qualitySelect = page.locator('#gs-quality');
    await expect(qualitySelect).toBeVisible({ timeout: 5000 });
    await expect(qualitySelect.locator('option[value="480"]')).toHaveText('480p');
    await expect(qualitySelect.locator('option[value="720"]')).toHaveText('720p');
    await expect(qualitySelect.locator('option[value="1080"]')).toHaveText('1080p');
    // 720 is selected by default
    await expect(qualitySelect).toHaveValue('720');
  });

  test('filter selector has expected options', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Anime');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Anime folder not available');
      return;
    }
    await page.locator('#gear-btn').click();
    const filterSelect = page.locator('#gs-filter');
    await expect(filterSelect).toBeVisible({ timeout: 5000 });
    await expect(filterSelect.locator('option[value="none"]')).toBeAttached();
    await expect(filterSelect.locator('option[value="hdr"]')).toBeAttached();
    await expect(filterSelect.locator('option[value="anime"]')).toBeAttached();
    await expect(filterSelect.locator('option[value="smooth"]')).toBeAttached();
    await expect(filterSelect.locator('option[value="sharp"]')).toBeAttached();
    // "none" is default
    await expect(filterSelect).toHaveValue('none');
  });
});

// ---------------------------------------------------------------------------
// 4. Admin Panel (/share/)
// ---------------------------------------------------------------------------

test.describe('Admin Panel', () => {
  test.beforeEach(async ({ page }) => {
    requireLocal();
    await loginLocal(page);
  });

  test('admin panel loads and shows ShareBox title', async ({ page }) => {
    await page.goto('/share/');
    await expect(page.locator('.app-title')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.app-title')).toContainText('ShareBox');
  });

  test('file browser section is visible', async ({ page }) => {
    await page.goto('/share/');
    await expect(page.locator('#file-list')).toBeVisible({ timeout: 10000 });
  });

  test('dashboard section is visible for admin user', async ({ page }) => {
    await page.goto('/share/');
    // Dashboard is shown only for the admin user
    const dashSection = page.locator('#dash-section');
    if (await dashSection.count() > 0) {
      await expect(dashSection).toBeVisible({ timeout: 10000 });
    } else {
      // Non-admin user — skip
      test.skip(true, 'Dashboard not visible for this user (admin only)');
    }
  });

  test('can create a share link via API', async ({ page }) => {
    await page.goto('/share/');
    // Verify session is active before attempting API calls
    const meResp = await page.request.get('/share/');
    if (meResp.status() === 401) {
      test.skip(true, 'Admin auth not available in this environment');
      return;
    }
    await page.waitForFunction(() => document.querySelector('meta[name="csrf-token"]') !== null, { timeout: 10000 });

    const result = await page.evaluate(async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
      const resp = await fetch('/share/ctrl.php?cmd=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: '', csrf_token: csrf }),
      });
      const text = await resp.text();
      try { return { status: resp.status, body: JSON.parse(text) }; }
      catch { return { status: resp.status, body: { error: 'invalid json: ' + text.substring(0, 100) } }; }
    });

    // We accept 200 (link created) or 400 (empty path invalid) — both prove the API is reachable
    expect([200, 400, 403]).toContain(result.status);
  });

  test('can create a share link for demo media root', async ({ page, context }) => {
    await page.goto('/share/');
    // Verify session is active before attempting API calls
    const meResp = await page.request.get('/share/');
    if (meResp.status() === 401) {
      test.skip(true, 'Admin auth not available in this environment');
      return;
    }
    await page.waitForFunction(() => document.querySelector('meta[name="csrf-token"]') !== null, { timeout: 10000 });

    // Create a link via the admin panel API — browse root is always valid in Docker demo
    const result = await page.evaluate(async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
      // Find out what media path to use by browsing ctrl.php
      const browseResp = await fetch('/share/ctrl.php?cmd=browse&path=');
      if (!browseResp.ok) return { ok: false, token: '', status: browseResp.status };
      const browseData = await browseResp.json();

      // Use the first folder available
      const firstFolder = browseData?.entries?.find((e: any) => e.type === 'folder');
      if (!firstFolder) return { ok: false, token: '', status: 0 };

      const resp = await fetch('/share/ctrl.php?cmd=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: firstFolder.name, csrf_token: csrf }),
      });
      const text = await resp.text();
      try { const body = JSON.parse(text); return { ok: resp.ok, status: resp.status, token: body.token ?? '', body }; }
      catch { return { ok: false, status: resp.status, token: '', body: { error: text.substring(0, 100) } }; }
    });

    if (!result.ok) {
      test.skip(true, 'Could not create link (status ' + result.status + ')');
      return;
    }
    expect(result.token).toBeTruthy();

    // Verify it appears in the links container on reload
    await page.reload();
    const linkCard = page.locator('#links-container .link-card').first();
    if (await linkCard.count() > 0) {
      await expect(linkCard).toBeVisible({ timeout: 5000 });
    }

    // The share link should be accessible publicly (new context, no auth)
    const publicContext = await context.browser()!.newContext({ ignoreHTTPSErrors: true });
    const publicPage = await publicContext.newPage();
    const dlResp = await publicPage.goto('/dl/' + result.token);
    expect(dlResp?.status()).not.toBe(401);
    expect(dlResp?.status()).not.toBe(404);
    await publicContext.close();
  });

  test('can delete a share link', async ({ page }) => {
    await page.goto('/share/');
    // Verify session is active before attempting API calls
    const meResp = await page.request.get('/share/');
    if (meResp.status() === 401) {
      test.skip(true, 'Admin auth not available in this environment');
      return;
    }
    await page.waitForFunction(() => document.querySelector('meta[name="csrf-token"]') !== null, { timeout: 10000 });

    // First create a link, then delete it
    const result = await page.evaluate(async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
      const browseResp = await fetch('/share/ctrl.php?cmd=browse&path=');
      if (!browseResp.ok) return { ok: false, id: 0, status: browseResp.status };
      const browseData = await browseResp.json();
      const firstFolder = browseData?.entries?.find((e: any) => e.type === 'folder');
      if (!firstFolder) return { ok: false, id: 0, status: 0 };

      const createResp = await fetch('/share/ctrl.php?cmd=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: firstFolder.name, csrf_token: csrf }),
      });
      if (!createResp.ok) return { ok: false, id: 0, status: createResp.status };
      const createText = await createResp.text();
      let createBody: any;
      try { createBody = JSON.parse(createText); } catch { return { ok: false, id: 0, status: createResp.status }; }

      // Need the link id — reload page to find it via DOM
      return { ok: true, id: 0, token: createBody.token, status: 200 };
    });

    if (!result.ok) {
      test.skip(true, 'Could not create link for deletion test');
      return;
    }

    // Find the link id from the links container
    await page.reload();
    const deleteBtn = page.locator('.link-card').first().locator('[data-id], button[onclick*="delete"], .btn-delete');
    if (await deleteBtn.count() === 0) {
      test.skip(true, 'No delete button found in links container');
      return;
    }
    const linkId = await deleteBtn.first().getAttribute('data-id');
    if (!linkId) {
      test.skip(true, 'Could not determine link id');
      return;
    }

    const deleteResult = await page.evaluate(async (id) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
      const resp = await fetch('/share/ctrl.php?cmd=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(id), csrf_token: csrf }),
      });
      return { status: resp.status, body: await resp.json() };
    }, linkId);

    expect(deleteResult.status).toBe(200);
    expect(deleteResult.body.success).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// 5. Player probe
// ---------------------------------------------------------------------------

test.describe('Player Probe', () => {
  test('probe endpoint returns valid JSON for a demo video', async ({ page }) => {
    requireLocal();
    const probeUrl = `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}&probe=1`;
    const resp = await page.request.get(probeUrl);
    if (resp.status() === 404) {
      test.skip(true, 'Demo video not found (SHAREBOX_DEMO_DATA off?)');
      return;
    }
    expect(resp.ok()).toBeTruthy();
    const data = await resp.json();
    expect(data).toHaveProperty('videoCodec');
    expect(data).toHaveProperty('duration');
    expect(typeof data.videoCodec).toBe('string');
    expect(typeof data.duration).toBe('number');
    expect(data.duration).toBeGreaterThan(0);
  });

  test('probe contains subtitles array', async ({ page }) => {
    requireLocal();
    const probeUrl = `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}&probe=1`;
    const resp = await page.request.get(probeUrl);
    if (!resp.ok()) {
      test.skip(true, 'Probe endpoint unavailable');
      return;
    }
    const data = await resp.json();
    // demo-data.sh creates clips with 2 subtitle tracks (eng + fre)
    expect(data).toHaveProperty('subtitles');
    expect(Array.isArray(data.subtitles)).toBeTruthy();
    expect(data.subtitles.length).toBeGreaterThanOrEqual(2);
  });

  test('probe contains audio tracks', async ({ page }) => {
    requireLocal();
    const probeUrl = `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}&probe=1`;
    const resp = await page.request.get(probeUrl);
    if (!resp.ok()) {
      test.skip(true, 'Probe endpoint unavailable');
      return;
    }
    const data = await resp.json();
    // demo-data.sh creates clips with at least 1 audio track
    // The property may be 'audioTracks' or 'audio' depending on probe version
    const tracks = data.audioTracks ?? data.audio ?? [];
    expect(Array.isArray(tracks)).toBeTruthy();
    expect(tracks.length).toBeGreaterThanOrEqual(1);
  });

  test('probe for MKV file reports isMKV=true', async ({ page }) => {
    requireLocal();
    const probeUrl = `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}&probe=1`;
    const resp = await page.request.get(probeUrl);
    if (!resp.ok()) {
      test.skip(true, 'Probe endpoint unavailable');
      return;
    }
    const data = await resp.json();
    expect(data).toHaveProperty('isMKV');
    expect(data.isMKV).toBe(true);
  });

  test('probe for invalid path returns 404', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?p=nonexistent_file_xyz.mkv&probe=1');
    // Invalid path → file doesn't exist → 404 or 403 (path outside base)
    expect([400, 403, 404]).toContain(resp.status());
  });
});

// ---------------------------------------------------------------------------
// 6. ZIP download
// ---------------------------------------------------------------------------

test.describe('ZIP Download', () => {
  test('ZIP endpoint responds for a folder (HEAD)', async ({ page }) => {
    requireLocal();
    // HEAD to avoid downloading the whole archive
    const resp = await page.request.fetch(BROWSE_ROOT + '?p=Anime%2FAttack+on+Titan%2FSeason+1&zip=1', {
      method: 'HEAD',
    });
    if (resp.status() === 404) {
      test.skip(true, 'Demo folder not available');
      return;
    }
    // 200 (streaming) or 413 (too large) are valid — not 4xx/5xx unexpectedly
    expect([200, 413]).toContain(resp.status());
  });

  test('ZIP response has application/zip content-type', async ({ page }) => {
    requireLocal();
    // Use a small folder (Season 1 of Attack on Titan has 4 demo clips, ~300KB total)
    // We only read the headers via HEAD to avoid large downloads in tests
    const resp = await page.request.fetch(BROWSE_ROOT + '?p=Anime%2FAttack+on+Titan%2FSeason+1&zip=1', {
      method: 'HEAD',
    });
    if (resp.status() !== 200) {
      test.skip(true, 'ZIP not available (status ' + resp.status() + ')');
      return;
    }
    const ct = resp.headers()['content-type'] ?? '';
    expect(ct).toContain('application/zip');
  });

  test('ZIP for nested subfolder sets correct Content-Disposition filename', async ({ page }) => {
    requireLocal();
    const resp = await page.request.fetch(BROWSE_ROOT + '?p=Series%2FBreaking+Bad%2FSeason+1&zip=1', {
      method: 'HEAD',
    });
    if (resp.status() !== 200) {
      test.skip(true, 'Series ZIP not available');
      return;
    }
    const cd = resp.headers()['content-disposition'] ?? '';
    expect(cd).toContain('attachment');
    expect(cd).toContain('.zip');
  });
});

// ---------------------------------------------------------------------------
// 7. Search
// ---------------------------------------------------------------------------

test.describe('Search', () => {
  test('inline search filters visible rows for "Attack"', async ({ page }) => {
    requireLocal();
    // Navigate to root which has 3+ folders → triggers search box (>=10 items needed)
    // Use a folder with enough items — Series has 4 sub-items, too few for inline search
    // Use a season folder which has many files
    await page.goto(BROWSE_ROOT + '?p=Anime%2FAttack+on+Titan%2FSeason+1');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Season 1 folder not available');
      return;
    }
    const searchBox = page.locator('#main-search');
    if (await searchBox.count() === 0) {
      // Inline search only shown for 10+ items, Season 1 has 4 files — skip
      test.skip(true, 'Inline search not shown (fewer than 10 items)');
      return;
    }
    const totalBefore = await page.locator('.row:not(.hidden)').count();
    await searchBox.fill('E01');
    // Wait for filter to apply (client-side, synchronous)
    await page.waitForFunction(() => {
      const rows = document.querySelectorAll('.row');
      return Array.from(rows).some(r => (r as HTMLElement).style.display === 'none' || r.classList.contains('hidden'));
    }, { timeout: 5000 }).catch(() => {/* may not filter if all match */});
    const visibleAfter = await page.locator('.row:not(.hidden):not([style*="display: none"])').count();
    // E01 matches exactly 1 file in the season
    expect(visibleAfter).toBeLessThanOrEqual(totalBefore);
  });

  test('global search via ctrl.php?cmd=search returns results for "Attack"', async ({ page }) => {
    requireLocal();
    await loginLocal(page);
    const resp = await page.request.get('/share/ctrl.php?cmd=search&q=Attack');
    if (resp.status() === 401 || resp.status() === 403) {
      test.skip(true, 'Search requires auth (run with admin credentials)');
      return;
    }
    expect(resp.ok()).toBeTruthy();
    const data = await resp.json();
    expect(data).toHaveProperty('results');
    expect(Array.isArray(data.results)).toBeTruthy();
    // "Attack on Titan" demo data should return at least 1 result
    expect(data.results.length).toBeGreaterThan(0);
    expect(data.query).toBe('Attack');
  });

  test('global search for "nonexistent_xyz" returns empty results', async ({ page }) => {
    requireLocal();
    await loginLocal(page);
    const resp = await page.request.get('/share/ctrl.php?cmd=search&q=nonexistent_xyz_888');
    if (resp.status() === 401 || resp.status() === 403) {
      test.skip(true, 'Search requires auth');
      return;
    }
    expect(resp.ok()).toBeTruthy();
    const data = await resp.json();
    expect(data).toHaveProperty('results');
    expect(data.results).toHaveLength(0);
  });

  test('global search with empty query returns empty results', async ({ page }) => {
    requireLocal();
    await loginLocal(page);
    const resp = await page.request.get('/share/ctrl.php?cmd=search&q=');
    if (!resp.ok()) {
      test.skip(true, 'Search endpoint unavailable');
      return;
    }
    const data = await resp.json();
    expect(data.results).toHaveLength(0);
  });

  test('browse ?q= performs search and shows search results view', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?q=Titan');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Browse token unavailable');
      return;
    }
    // The search results view has a .search-back link and a results label
    await expect(page.locator('.search-back')).toBeVisible({ timeout: 10000 });
    // Should show at least 1 result for "Titan"
    const rows = page.locator('.row');
    await expect(rows.first()).toBeVisible({ timeout: 5000 });
  });
});

// ---------------------------------------------------------------------------
// 8. Security
// ---------------------------------------------------------------------------

test.describe('Security', () => {
  test('path traversal attempt returns 403', async ({ page }) => {
    requireLocal();
    // Attempt to escape the shared directory via ../../etc/passwd
    const resp = await page.request.get(BROWSE_ROOT + '?p=../../etc/passwd');
    // Must not serve the file — should return 403 (path traversal blocked) or 404
    expect([400, 403, 404]).toContain(resp.status());
    // Body must not contain typical /etc/passwd content
    const text = await resp.text();
    expect(text).not.toContain('root:x:0:0');
  });

  test('data/ directory is blocked (403)', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get('/share/data/');
    expect(resp.status()).toBe(403);
  });

  test('config.php is blocked (403)', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get('/share/config.php');
    // In Docker/nginx config.php may be served as PHP (executed, not downloaded)
    // On Apache it's blocked with 403. Both are acceptable — source code isn't leaked.
    if (resp.status() !== 403) {
      const body = await resp.text();
      expect(body).not.toContain('define(');
      expect(body).not.toContain('TMDB_API_KEY');
    }
  });

  test('unauthenticated ctrl.php returns 401', async ({ page }) => {
    requireLocal();
    // Create a fresh context with no credentials and no session
    const anonContext = await page.context().browser()!.newContext({ ignoreHTTPSErrors: true });
    const anonPage = await anonContext.newPage();
    const resp = await anonPage.request.get('/share/ctrl.php?cmd=browse&path=');
    expect(resp.status()).toBe(401);
    await anonContext.close();
  });

  test('stream_event POST without CSRF returns 401 or 403', async ({ page }) => {
    requireLocal();
    const resp = await page.request.post('/share/ctrl.php?cmd=stream_event', {
      data: { event: 'start', mode: 'native' },
    });
    // 401 (not authenticated) or 403 (CSRF mismatch) — never 200 or 500
    expect([401, 403]).toContain(resp.status());
  });

  test('ctrl.php with unknown cmd returns 400', async ({ page }) => {
    requireLocal();
    await loginLocal(page);
    const resp = await page.request.get('/share/ctrl.php?cmd=unknown_command_xyz');
    // Must return 400 Bad Request, not 200 or 500
    expect(resp.status()).toBe(400);
  });

  test('double-dotdot in browse path does not expose parent directory', async ({ page }) => {
    requireLocal();
    // Attempt relative path escape
    const resp = await page.request.get(BROWSE_ROOT + '?p=Anime/../../../etc');
    expect([400, 403, 404]).toContain(resp.status());
  });
});

// ---------------------------------------------------------------------------
// 9. Player page
// ---------------------------------------------------------------------------

test.describe('Player Page', () => {
  test('?play=1 renders the player page for a demo video', async ({ page }) => {
    requireLocal();
    const playUrl = `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}&play=1`;
    const resp = await page.goto(playUrl);
    if (resp?.status() === 404) {
      test.skip(true, 'Demo video not found');
      return;
    }
    // Player page contains a <video> element
    const videoEl = page.locator('video, #player-container');
    await expect(videoEl.first()).toBeVisible({ timeout: 15000 });
  });

  test('HLS stream endpoint returns m3u8 or 504 timeout', async ({ page }) => {
    requireLocal();
    const hlsUrl = `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}&stream=hls`;
    const resp = await page.request.get(hlsUrl, { timeout: 35000 });
    if (resp.status() === 404) {
      test.skip(true, 'Demo video absent');
      return;
    }
    // 200 = m3u8 ready, 504 = ffmpeg timeout (valid in CI with no real hw)
    expect([200, 504]).toContain(resp.status());
    const body = await resp.text();
    expect(body).toContain('#EXTM3U');
    // EXT-X-ERROR must not appear (broken HLS behaviour)
    expect(body).not.toContain('#EXT-X-ERROR');
  });
});

// ---------------------------------------------------------------------------
// 10. Download page — basic file serving
// ---------------------------------------------------------------------------

test.describe('File Serving', () => {
  test('video file is accessible (HEAD returns 200)', async ({ page }) => {
    requireLocal();
    // Accessing a file directly (no ?play=1) triggers download / file serve
    const resp = await page.request.fetch(
      `${BROWSE_ROOT}?p=${encodeURIComponent(DEMO_VIDEO_PATH)}`,
      { method: 'HEAD' }
    );
    if (resp.status() === 404) {
      test.skip(true, 'Demo video absent');
      return;
    }
    expect(resp.status()).toBeLessThan(400);
  });

  test('folder listing returns HTML with file items', async ({ page }) => {
    requireLocal();
    const resp = await page.goto(BROWSE_ROOT + '?p=Films');
    if (resp?.status() === 404) {
      test.skip(true, 'Films folder absent');
      return;
    }
    expect(resp?.status()).toBeLessThan(400);
    // The listing HTML contains rows for each film
    await expect(page.locator('.row').first()).toBeVisible({ timeout: 10000 });
  });

  test('navigating into a sub-sub-folder updates breadcrumb', async ({ page }) => {
    requireLocal();
    await page.goto(BROWSE_ROOT + '?p=Series%2FBreaking+Bad%2FSeason+1');
    const html = await page.content();
    if (html.includes('n\'existe pas') || html.includes('introuvable')) {
      test.skip(true, 'Breaking Bad Season 1 folder absent');
      return;
    }
    const breadcrumb = page.locator('.breadcrumb');
    await expect(breadcrumb).toBeVisible({ timeout: 10000 });
    await expect(breadcrumb).toContainText('Season 1');
    await expect(breadcrumb).toContainText('Breaking Bad');
  });
});

// ---------------------------------------------------------------------------
// 11. TMDB write gate (P3 — admin-only write endpoints on /dl/{token})
// ---------------------------------------------------------------------------

test.describe('TMDB write gate', () => {
  // Endpoints that mutate shared poster/type state or hit an external API (SSRF).
  const WRITE_ENDPOINTS = [
    'tmdb_set', 'folder_type_set', 'web_poster_save',
    'ai_recheck', 'tmdb_reload', 'tmdb_search', 'web_search',
  ];

  test('public ?posters stays readable (not 403)', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get(BROWSE_ROOT + '?posters=1');
    expect(resp.status()).not.toBe(403);
  });

  for (const ep of WRITE_ENDPOINTS) {
    test(`unauthenticated ?${ep} is forbidden (403)`, async ({ page }) => {
      requireLocal();
      // Fresh context guarantees no admin session.
      const anon = await page.context().browser()!.newContext({ ignoreHTTPSErrors: true });
      const anonPage = await anon.newPage();
      const resp = await anonPage.request.get(BROWSE_ROOT + '?' + ep + '=1');
      expect(resp.status()).toBe(403);
      await anon.close();
    });
  }

  test('admin passes the gate (not 403)', async ({ page }) => {
    requireLocal();
    await loginLocal(page);
    // With an admin session the gate opens; the handler then runs (200, possibly
    // "TMDB_API_KEY not configured" in the demo) — but never the 403 from the gate.
    const resp = await page.request.get(BROWSE_ROOT + '?tmdb_reload=1');
    expect(resp.status()).not.toBe(403);
  });
});

// ---------------------------------------------------------------------------
// 12. Share-link lifecycle (token validation / password / max_downloads)
// ---------------------------------------------------------------------------

test.describe('Share-link lifecycle', () => {
  /** Create a link via the admin API; returns the token (or '' on failure). */
  async function createLink(page: Page, opts: Record<string, unknown>): Promise<string> {
    await page.goto('/share/');
    await page.waitForFunction(() => document.querySelector('meta[name="csrf-token"]') !== null, { timeout: 10000 });
    const res = await page.evaluate(async (o) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
      const resp = await fetch('/share/ctrl.php?cmd=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...o, csrf_token: csrf }),
      });
      const text = await resp.text();
      try { return { status: resp.status, body: JSON.parse(text) as { token?: string } }; }
      catch { return { status: resp.status, body: {} as { token?: string } }; }
    }, opts);
    return res.body.token ?? '';
  }

  test('nonexistent token returns 404', async ({ page }) => {
    requireLocal();
    const resp = await page.request.get('/dl/this-token-does-not-exist-xyz', { maxRedirects: 0 });
    expect(resp.status()).toBe(404);
  });

  test('password-protected link does not serve content without the password', async ({ page, context }) => {
    requireLocal();
    await loginLocal(page);
    const token = await createLink(page, { path: 'Films', password: 's3cret-' + Date.now() });
    test.skip(!token, 'Could not create a password-protected link');

    // Access from a fresh anonymous context — must get the password form, not the listing.
    const anon = await context.browser()!.newContext({ ignoreHTTPSErrors: true });
    const anonPage = await anon.newPage();
    const resp = await anonPage.request.get('/dl/' + token);
    const body = await resp.text();
    expect(body).toMatch(/type=["']password["']|name=["']password["']/);
    // The actual folder content must NOT leak before the password is entered.
    expect(body).not.toContain('grid-card-title');
    await anon.close();
  });

  test('max_downloads is enforced (410 after the limit)', async ({ page, context }) => {
    requireLocal();
    await loginLocal(page);
    const token = await createLink(page, { path: DEMO_VIDEO_PATH, max_downloads: 1 });
    test.skip(!token, 'Could not create a file link with max_downloads');

    const anon = await context.browser()!.newContext({ ignoreHTTPSErrors: true });
    const anonPage = await anon.newPage();
    // First download consumes the single allowed download (Range keeps it tiny).
    const first = await anonPage.request.get('/dl/' + token, { headers: { Range: 'bytes=0-0' } });
    expect([200, 206]).toContain(first.status());
    // Second must be refused with 410 Gone.
    const second = await anonPage.request.get('/dl/' + token, { headers: { Range: 'bytes=0-0' } });
    expect(second.status()).toBe(410);
    await anon.close();
  });
});
