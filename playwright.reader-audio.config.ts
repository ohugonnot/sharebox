import { defineConfig, devices } from '@playwright/test';

// Starts its own php -S (see tests/e2e/reader-audio-fixtures.ts): no Digest, no prod URL.
// SB_CHROMIUM=<path> overrides the browser binary when the default build is missing.
const launchOptions = {
  args: ['--autoplay-policy=no-user-gesture-required'],
  ...(process.env.SB_CHROMIUM ? { executablePath: process.env.SB_CHROMIUM } : {}),
};

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'reader-audio.spec.ts',
  timeout: 60000,
  expect: { timeout: 10000 },
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: { launchOptions },
  projects: [
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1280, height: 800 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'], browserName: 'chromium', viewport: { width: 375, height: 667 } } },
  ],
});
