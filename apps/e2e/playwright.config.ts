import { defineConfig, devices } from '@playwright/test';

export const BASE_URL = 'http://localhost:8889';

export default defineConfig({
  testDir: './specs',
  // Specs share the store's settings and campaigns, so they run one at a time.
  workers: 1,
  fullyParallel: false,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  globalSetup: './support/global-setup.ts',
  use: {
    ...devices['Desktop Chrome'],
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});
