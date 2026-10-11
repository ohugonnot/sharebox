import { defineConfig, devices } from '@playwright/test';

// Starts its own php -S (see tests/e2e/reader-audio-fixtures.ts): no Digest, no prod URL.
// SB_CHROMIUM=<path> overrides the browser binary when the default build is missing.
const launchOptions = {
  args: ['--autoplay-policy=no-user-gesture-required'],
  ...(process.env.SB_CHROMIUM ? { executablePath: process.env.SB_CHROMIUM } : {}),
};

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['reader-audio.spec.ts', 'mobile-toolbar.spec.ts', 'polish.spec.ts'],
  timeout: 60000,
  expect: { timeout: 10000 },
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: { launchOptions },
  projects: [
    { name: 'desktop', grepInvert: /@landscape/, use: { browserName: 'chromium', viewport: { width: 1280, height: 800 } } },
    { name: 'mobile', grepInvert: /@landscape/, use: { ...devices['Pixel 7'], browserName: 'chromium', viewport: { width: 375, height: 667 } } },
    // Phone held sideways: only the double-page / manga scenarios.
    { name: 'landscape', grep: /@landscape/, use: { ...devices['Pixel 7'], browserName: 'chromium', viewport: { width: 740, height: 360 }, hasTouch: true } },
  ],
});
