import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
  testDir: './scripts',
  outputDir: 'test-results',
  timeout: 120_000,
  workers: 1,
  reporter: [ [ 'list' ] ],
  use: {
    ...devices[ 'Desktop Chrome' ],
    baseURL: 'http://localhost:3050',
    launchOptions: { args: [ '--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--ignore-gpu-blocklist' ] },
  },
  webServer: { command: 'pnpm dev', url: 'http://localhost:3050', reuseExistingServer: true, timeout: 60_000 },
} );
