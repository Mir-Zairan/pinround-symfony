import { defineConfig, devices } from '@playwright/test';

const NODE_SPECS = ['pin-count.spec.ts', 'pin-position.spec.ts', 'submit-hold.spec.ts', 'upload-checks.spec.ts', 'upload-status.spec.ts'];
const DRAFT_SPECS = ['share-draft.spec.ts', 'draft-round.spec.ts', 'review-journey.spec.ts'];
const AI_SETTINGS_SPECS = ['check-with-ai.spec.ts', 'summary-poll.spec.ts', 'local-ai.spec.ts', 'laptop-summary.spec.ts'];

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
      testIgnore: [...NODE_SPECS, ...DRAFT_SPECS, ...AI_SETTINGS_SPECS],
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // The draft specs read and decide the same people's drafts and fail when they run beside each other, so they share
    // one worker, one test at a time, while the other specs keep running in parallel. No dependency chain between them:
    // a failure in one spec must not hide the other.
    {
      name: 'chromium-drafts',
      testMatch: DRAFT_SPECS,
      workers: 1,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // These specs each store Acme Foods' AI settings (cloud, off, laptops) for their tests; side by side, one spec's
    // settings replace another's mid-test. One worker, one test at a time, as the draft specs.
    {
      name: 'chromium-ai-settings',
      testMatch: AI_SETTINGS_SPECS,
      workers: 1,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
  ],
});
