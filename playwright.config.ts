import { defineConfig } from '@playwright/test';

// SHAREBOX_TEST_URL : override pour cibler un Docker demo local (default = prod anime-sanctuary).
// Exemple : SHAREBOX_TEST_URL=http://localhost:8080 npx playwright test
const baseURL = process.env.SHAREBOX_TEST_URL || 'https://anime-sanctuary.net';
const isLocal = baseURL.startsWith('http://localhost') || baseURL.startsWith('http://127.0.0.1');

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  retries: 0,
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    // Sur Docker demo local : auth via login form (admin/changeme par default).
    // Sur prod : Apache Digest via httpCredentials.
    httpCredentials: isLocal ? undefined : {
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
