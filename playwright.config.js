import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/playwright',
  timeout: 30000,
  expect: {
    timeout: 5000
  },
  use: {
    browserName: 'chromium',
    headless: true,
    viewport: { width: 1280, height: 720 },
    actionTimeout: 0,
    ignoreHTTPSErrors: true,
  },
});
