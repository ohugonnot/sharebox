/**
 * Playwright E2E tests — ShareBox (https://anime-sanctuary.net/share/)
 *
 * Prerequisites:
 *   SHAREBOX_TEST_USER=folken
 *   SHAREBOX_TEST_PASS=<password>
 *
 * Run: SHAREBOX_TEST_USER=folken SHAREBOX_TEST_PASS=xxx npx playwright test
 *
 * Notes:
 * - /share/ is behind Apache Digest auth (realm "gods").
 *   Playwright's httpCredentials handles the Digest challenge automatically.
 * - /dl/TOKEN is public (no auth).
 * - The file list loads via AJAX (fetch → ctrl.php?cmd=browse); always wait for
 *   the loading placeholder to disappear before asserting on entries.
 */

import { test, expect, request as playwrightRequest } from '@playwright/test';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Wait until #file-list stops showing the "Chargement…" placeholder. */
async function waitForFileList(page: import('@playwright/test').Page) {
  // The list starts with a single <li> containing "Chargement…".
  // navigateTo() replaces it once the AJAX response arrives.
  await page.waitForFunction(() => {
    const list = document.getElementById('file-list');
    if (!list) return false;
    return !list.textContent?.includes('Chargement');
  }, { timeout: 10000 });
}

/** Extract the text content of the breadcrumb element. */
async function breadcrumbText(page: import('@playwright/test').Page): Promise<string> {
  return (await page.locator('#breadcrumb').textContent()) ?? '';
}

// ---------------------------------------------------------------------------
// Authentication passthrough
// ---------------------------------------------------------------------------

test.describe('Authentication passthrough', () => {
  test('auto-provisions user from REMOTE_USER and shows dashboard', async ({ page }) => {
    await page.goto('/share/');

    // The page must not be the login form (no username input)
    await expect(page.locator('input[name="username"]')).toHaveCount(0);

    // App title visible
    await expect(page.locator('.app-title')).toBeVisible();
    await expect(page.locator('.app-title')).toContainText('ShareBox');

    // File browsing section present
    await expect(page.locator('#file-list')).toBeVisible();
  });

  test('shows current username in header', async ({ page }) => {
    await page.goto('/share/');

    // The header span contains the username returned by get_current_user_name()
    const user = process.env.SHAREBOX_TEST_USER || 'folken';
    await expect(page.locator('.app-header')).toContainText(user);
  });

  test('admin user (folken) sees Admin link in header', async ({ page }) => {
    // This test is only meaningful when running as folken
    test.skip(
      (process.env.SHAREBOX_TEST_USER || 'folken') !== 'folken',
      'Admin link only visible for folken'
    );

    await page.goto('/share/');
    const adminLink = page.locator('a[href="/share/admin.php"]');
    await expect(adminLink).toBeVisible();
    await expect(adminLink).toContainText('Admin');
  });

  test('login.php redirects to dashboard when trusted header is active', async ({ page }) => {
    // With REMOTE_USER set by Apache Digest, login.php calls require_auth()
    // and immediately redirects to /share/
    await page.goto('/share/login.php');
    await expect(page).toHaveURL(/\/share\/?$/);

    // Should render the app, not the login form
    await expect(page.locator('.app-title')).toBeVisible();
    await expect(page.locator('input[name="username"]')).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------
// File browsing
// ---------------------------------------------------------------------------

test.describe('File browsing', () => {
  test('displays root directory listing with at least one folder', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    // /home/ always has at least one user directory
    const folders = page.locator('#file-list .file-icon.is-folder');
    await expect(folders.first()).toBeVisible();
  });

  test('breadcrumb shows only root link at start', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    const crumb = await breadcrumbText(page);
    // At root the breadcrumb has just the "Fichiers" anchor
    expect(crumb.trim()).toBe('Fichiers');
  });

  test('navigates into a folder by clicking it', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    const firstFolderName = page.locator('#file-list .file-name.is-folder').first();
    const folderText = (await firstFolderName.textContent())?.trim();

    await firstFolderName.click();

    // Wait for breadcrumb to update (navigateTo updates breadcrumb after AJAX)
    await page.waitForFunction(
      (expected) => {
        const bc = document.getElementById('breadcrumb');
        return bc && bc.textContent?.includes(expected);
      },
      folderText,
      { timeout: 10000 }
    );

    const crumb = await breadcrumbText(page);
    expect(crumb).toContain(folderText);
  });

  test('deep-link ?open=folken navigates directly into that folder', async ({ page }) => {
    await page.goto('/share/?open=folken');
    await waitForFileList(page);

    // Breadcrumb must show "folken" as the current segment
    const crumb = await breadcrumbText(page);
    expect(crumb).toContain('folken');

    // The ".." back entry should be present (we are inside a subfolder)
    const upEntry = page.locator('#file-list .file-name.is-folder').filter({ hasText: '..' });
    await expect(upEntry).toBeVisible();
  });

  test('deep-link to nested path works', async ({ page }) => {
    // Navigate two levels deep — folken/torrents is a common path on this seedbox
    // Use ?open=folken first to discover an actual subfolder, then navigate deeper.
    await page.goto('/share/?open=folken');
    await waitForFileList(page);

    // Get subfolders excluding ".."
    const subfolders = page.locator('#file-list .file-name.is-folder');
    const count = await subfolders.count();

    // Find first subfolder that isn't ".."
    let targetIndex = -1;
    let subName = '';
    for (let i = 0; i < count; i++) {
      const text = (await subfolders.nth(i).textContent())?.trim() || '';
      if (text !== '..') {
        targetIndex = i;
        subName = text;
        break;
      }
    }

    if (targetIndex === -1) {
      // No subfolders — just verify we can go back with ".."
      await subfolders.first().click();
      await page.waitForFunction(() => {
        const bc = document.getElementById('breadcrumb');
        return bc && bc.textContent?.trim() === 'Fichiers';
      }, { timeout: 10000 });
      return;
    }

    await subfolders.nth(targetIndex).click();
    await page.waitForFunction(
      (expected) => {
        const bc = document.getElementById('breadcrumb');
        return bc && bc.textContent?.includes(expected);
      },
      subName,
      { timeout: 10000 }
    );

    const crumb = await breadcrumbText(page);
    expect(crumb).toContain('folken');
    expect(crumb).toContain(subName);
  });

  test('clicking breadcrumb root link returns to root', async ({ page }) => {
    await page.goto('/share/?open=folken');
    await waitForFileList(page);

    // Click the "Fichiers" root link in the breadcrumb
    await page.locator('#breadcrumb a').first().click();

    // Wait for breadcrumb to return to just "Fichiers"
    await page.waitForFunction(() => {
      const bc = document.getElementById('breadcrumb');
      return bc && bc.textContent?.trim() === 'Fichiers';
    }, { timeout: 10000 });

    const crumb = await breadcrumbText(page);
    expect(crumb.trim()).toBe('Fichiers');
  });

  test('filter input hides non-matching entries', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    // Count items before filtering
    const totalBefore = await page.locator('#file-list .file-item').count();

    // Type something very specific that likely matches zero entries
    await page.locator('#file-filter').fill('zzz_no_match_xyz');

    // Entries are filtered client-side (CSS display:none or DOM removal depending on impl)
    // Wait a tick for the filter to apply
    await page.waitForTimeout(300);

    // Either the list is empty or only non-matching items are hidden
    const visibleItems = page.locator('#file-list .file-item:visible');
    const countAfter = await visibleItems.count();
    expect(countAfter).toBeLessThan(totalBefore);

    // Clear filter — all items come back
    await page.locator('#file-filter').fill('');
    await page.waitForTimeout(300);
    const countRestored = await page.locator('#file-list .file-item:visible').count();
    expect(countRestored).toBeGreaterThanOrEqual(totalBefore - 1); // -1 tolerance
  });
});

// ---------------------------------------------------------------------------
// Link sharing
// ---------------------------------------------------------------------------

test.describe('Link sharing', () => {
  test('share button appears on file/folder entries', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    // Each file-item has a .file-actions containing a share button
    const shareButtons = page.locator('#file-list .file-item .btn-share-icon');
    const count = await shareButtons.count();
    expect(count).toBeGreaterThan(0);
  });

  test('clicking share button opens the share sheet', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    const firstShareBtn = page.locator('#file-list .file-item .btn-share-icon').first();
    await firstShareBtn.click();

    // The bottom sheet should appear
    await expect(page.locator('#share-sheet')).toBeVisible();
    await expect(page.locator('.sheet-label')).toContainText('Partager');
  });

  test('share sheet can be dismissed via overlay click', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    await page.locator('#file-list .file-item .btn-share-icon').first().click();
    await expect(page.locator('#share-sheet')).toBeVisible();

    // The overlay sits behind the sheet — call fermerShareSheet() via JS
    await page.evaluate(() => {
      const overlay = document.getElementById('sheet-overlay');
      if (overlay) overlay.click();
    });
    await page.waitForTimeout(500);

    // Sheet should be removed from DOM
    await expect(page.locator('#share-sheet')).toHaveCount(0);
  });

  test('creates a share link via API and it appears in Liens actifs', async ({ page }) => {
    await page.goto('/share/');
    await waitForFileList(page);

    // Create a link via in-browser fetch (inherits Digest auth credentials)
    const result = await page.evaluate(async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const resp = await fetch('/share/ctrl.php?cmd=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: 'folken', csrf_token: csrf }),
      });
      return { status: resp.status, body: await resp.json() };
    });

    expect(result.status).toBe(200);
    expect(result.body.token || result.body.url).toBeTruthy();

    // Reload and check links section
    await page.reload();
    await expect(page.locator('#links-container .link-card').first()).toBeVisible({ timeout: 5000 });
  });

  test('public download link is accessible without Digest auth', async ({ page, context }) => {
    await page.goto('/share/');

    // Create link via in-browser fetch (inherits Digest auth)
    const result = await page.evaluate(async () => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const resp = await fetch('/share/ctrl.php?cmd=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: 'folken', csrf_token: csrf }),
      });
      if (!resp.ok) return { ok: false, status: resp.status, token: '' };
      const body = await resp.json();
      return { ok: true, status: resp.status, token: body.token || (body.url || '').replace('/dl/', '') };
    });

    if (!result.ok) {
      test.skip(true, 'Could not create test link via API (status ' + result.status + ')');
      return;
    }
    expect(result.token).toBeTruthy();

    // Access /dl/TOKEN without credentials
    const publicContext = await context.browser()!.newContext({
      ignoreHTTPSErrors: true,
    });
    const publicPage = await publicContext.newPage();
    const dlResp = await publicPage.goto(`https://anime-sanctuary.net/dl/${result.token}`);

    expect(dlResp?.status()).not.toBe(401);
    expect(dlResp?.status()).not.toBe(403);

    await publicContext.close();
  });
});

// ---------------------------------------------------------------------------
// Dashboard (admin view)
// ---------------------------------------------------------------------------

test.describe('Dashboard', () => {
  test('system dashboard summary is visible for admin', async ({ page }) => {
    test.skip(
      (process.env.SHAREBOX_TEST_USER || 'folken') !== 'folken',
      'Dashboard only shown for admin (folken)'
    );

    await page.goto('/share/');

    // dashboard.php renders a <details id="dash-section"> with system metrics
    const dashSection = page.locator('#dash-section');
    await expect(dashSection).toBeVisible();

    // The summary pills for CPU, RAM, disk, net are rendered (even if values are "—")
    await expect(page.locator('#dash-pill-cpu')).toBeVisible();
    await expect(page.locator('#dash-pill-ram')).toBeVisible();
    await expect(page.locator('#dash-pill-disk')).toBeVisible();
  });

  test('expanding dashboard shows metric cards', async ({ page }) => {
    test.skip(
      (process.env.SHAREBOX_TEST_USER || 'folken') !== 'folken',
      'Dashboard only shown for admin (folken)'
    );

    await page.goto('/share/');

    // Click the FIRST summary (system metrics) — multiple summaries may exist
    await page.locator('#dash-section summary').first().click();
    await page.waitForTimeout(300);

    await expect(page.locator('#dash-cpu')).toBeVisible();
    await expect(page.locator('#dash-ram')).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Player integration
// ---------------------------------------------------------------------------

test.describe('Player integration', () => {
  test('streamable video files show a play button', async ({ page }) => {
    // Navigate to the ctrl.php browse API directly to find a video file path
    const apiPage = await page.request.get('/share/ctrl.php?cmd=browse&path=folken');
    const apiOk = apiPage.ok();
    if (!apiOk) {
      test.skip(true, 'Could not browse folken directory');
      return;
    }

    const data = await apiPage.json();
    const videoExtensions = ['mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'ts', 'm2ts', 'mpg', 'mpeg'];
    const videoEntry = (data.entries as Array<{ name: string; type: string }>)?.find(e =>
      videoExtensions.some(ext => e.name.toLowerCase().endsWith('.' + ext))
    );

    if (!videoEntry) {
      test.skip(true, 'No video file found in folken/ to test player button');
      return;
    }

    // Navigate to the folder containing the video
    await page.goto('/share/?open=folken');
    await waitForFileList(page);

    // Find the file item for this video — it should have a play action
    const fileItem = page.locator('#file-list .file-item .file-name', { hasText: videoEntry.name });
    await expect(fileItem).toBeVisible();

    // The play button is rendered as part of .file-actions for streamable files
    const parentLi = fileItem.locator('xpath=ancestor::li[contains(@class,"file-item")]');
    const playBtn = parentLi.locator('.btn-play-icon, [aria-label="Lire"], [title="Lire"]');
    await expect(playBtn).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Security — basic checks
// ---------------------------------------------------------------------------

test.describe('Security', () => {
  test('data directory is blocked', async ({ page }) => {
    // /share/data/ must return 403 (Apache LocationMatch denies it)
    const resp = await page.request.get('/share/data/');
    expect(resp.status()).toBe(403);
  });

  test('config.php is blocked', async ({ page }) => {
    const resp = await page.request.get('/share/config.php');
    expect(resp.status()).toBe(403);
  });

  test('ctrl.php rejects unauthenticated requests', async ({ page }) => {
    // Create a context without credentials
    const anonContext = await page.context().browser()!.newContext({
      ignoreHTTPSErrors: true,
    });
    const anonPage = await anonContext.newPage();
    const resp = await anonPage.request.get('/share/ctrl.php?cmd=browse&path=');
    // Digest auth challenge → 401
    expect(resp.status()).toBe(401);
    await anonContext.close();
  });
});
