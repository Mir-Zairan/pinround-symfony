import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { BEN_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const RULE_LABELS = [
  'Only the tester can move a ticket to Done',
  'Nobody approves their own code review',
  'Code review must happen before testing',
];
const REVIEW_BEFORE_TEST = RULE_LABELS[2];

// The demo seed puts Beta Bakery under the step policy team, with Ben as lead of Ovens. The specs switch a rule, so they
// run one after the other, and the afterEach puts it back on.
test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 1280, height: 800 }, storageState: BEN_STORAGE_STATE });

async function openOvensTeam(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/teams/ovens', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

test.afterEach(async ({ page }) => {
  await openOvensTeam(page);
  const reviewBeforeTest = page.getByRole('switch', { name: REVIEW_BEFORE_TEST });
  if ((await reviewBeforeTest.getAttribute('aria-checked')) === 'false') {
    await reviewBeforeTest.click();
    await expect(page.getByText(`Turned on: ${REVIEW_BEFORE_TEST}`)).toBeVisible();
  }
  await expect(page.getByRole('switch', { name: REVIEW_BEFORE_TEST })).toHaveAttribute('aria-checked', 'true');
});

test('the lead of a team under the team policy sees who decides and one switch for each rule', async ({ page }) => {
  await openOvensTeam(page);

  await expect(page.getByText('Your admin lets each lead shape the team’s steps and rules.')).toBeVisible();
  await expect(page.getByRole('switch')).toHaveCount(RULE_LABELS.length);
  for (const label of RULE_LABELS) {
    await expect(page.getByRole('switch', { name: label })).toBeVisible();
  }
});

test('turning a rule off shows a toast, keeps the other rules and stays off after a reload', async ({ page }) => {
  await openOvensTeam(page);
  const reviewBeforeTest = page.getByRole('switch', { name: REVIEW_BEFORE_TEST });
  await expect(reviewBeforeTest).toHaveAttribute('aria-checked', 'true');

  await reviewBeforeTest.click();

  await expect(page.getByText(`Turned off: ${REVIEW_BEFORE_TEST}`)).toBeVisible();
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.getByRole('switch', { name: REVIEW_BEFORE_TEST })).toHaveAttribute('aria-checked', 'false');
  await expect(page.getByRole('switch', { name: RULE_LABELS[0] })).toHaveAttribute('aria-checked', 'true');
  await expect(page.getByRole('switch', { name: RULE_LABELS[1] })).toHaveAttribute('aria-checked', 'true');

  await page.getByRole('switch', { name: REVIEW_BEFORE_TEST }).click();

  await expect(page.getByText(`Turned on: ${REVIEW_BEFORE_TEST}`)).toBeVisible();
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.getByRole('switch', { name: REVIEW_BEFORE_TEST })).toHaveAttribute('aria-checked', 'true');
});

test('the rule switches have no serious or critical accessibility violations', async ({ page }) => {
  await openOvensTeam(page);

  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();

  expect(
    violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`),
  ).toEqual([]);
});
