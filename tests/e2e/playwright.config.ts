import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  // Baselines sit beside the spec, one file per screenshot and platform, because font rendering differs per OS.
  snapshotPathTemplate: '{testDir}/screenshots/{arg}-{platform}{ext}',
  reporter: process.env.CI ? [['list'], ['github']] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
