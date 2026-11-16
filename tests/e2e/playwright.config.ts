import { defineConfig, devices } from '@playwright/test';

const NODE_SPECS = ['pin-count.spec.ts', 'pin-position.spec.ts', 'submit-hold.spec.ts', 'upload-checks.spec.ts', 'upload-status.spec.ts'];
const DRAFT_SPECS = ['share-draft.spec.ts', 'draft-round.spec.ts'];

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
      testIgnore: [...NODE_SPECS, ...DRAFT_SPECS],
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // The draft specs fail when they run beside each other, so each is a project of its own, chained: a project starts
    // only when its dependencies are done, while the other specs keep running in parallel.
    {
      name: 'chromium-share-draft',
      testMatch: 'share-draft.spec.ts',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    {
      name: 'chromium-draft-round',
      testMatch: 'draft-round.spec.ts',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup', 'chromium-share-draft'],
    },
  ],
});
