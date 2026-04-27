import { defineConfig, devices } from '@playwright/test';

/**
 * Base URL is read from the ESSF_BASE_URL env var so the config works
 * with Studio's dynamic port assignment without hardcoding.
 * Set it before running: ESSF_BASE_URL=http://localhost:8883 npm run test:e2e
 */
const baseURL = process.env.ESSF_BASE_URL ?? 'http://localhost:8883';
const adminUser = process.env.ESSF_ADMIN_USER ?? 'admin';
const adminPass = process.env.ESSF_ADMIN_PASS ?? '';

export { adminUser, adminPass };

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false, // local site, run serially
  retries: process.env.CI ? 2 : 1,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    headless: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },
  snapshotPathTemplate: '{testDir}/__snapshots__/{testFilePath}/{arg}-{projectName}{ext}',
  projects: [
    {
      name: 'setup',
      testMatch: /.*\/fixtures\/auth\.setup\.ts/,
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/e2e/fixtures/.auth.json',
      },
      dependencies: ['setup'],
    },
  ],
});
