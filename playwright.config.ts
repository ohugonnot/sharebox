import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  retries: 0,
  use: {
    baseURL: 'https://anime-sanctuary.net',
    ignoreHTTPSErrors: true,
    httpCredentials: {
      username: process.env.SHAREBOX_TEST_USER || 'folken',
      password: process.env.SHAREBOX_TEST_PASS || '',
    },
  },
  projects: [
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
    },
  ],
});
