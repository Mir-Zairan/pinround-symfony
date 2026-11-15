import { defineConfig, devices } from '@playwright/test';

const NODE_SPECS = ['pin-count.spec.ts', 'pin-position.spec.ts', 'submit-hold.spec.ts', 'upload-checks.spec.ts', 'upload-status.spec.ts'];

export default defineConfig({
  testDir: '.',
  // Baselines sit beside the spec, one file per screenshot and platform, because font rendering differs per OS.
  snapshotPathTemplate: '{testDir}/screenshots/{arg}-{platform}{ext}',
  reporter: process.env.CI ? [['list'], ['github']] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: 'auth.setup.ts' },
    // Pure-function specs run in Node with no page, so they need neither the app nor a login.
    { name: 'node', testMatch: NODE_SPECS },
    {
      name: 'chromium',
      testMatch: '*.spec.ts',
      testIgnore: NODE_SPECS,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
  ],
});
