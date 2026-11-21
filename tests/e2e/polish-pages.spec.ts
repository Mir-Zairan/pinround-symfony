import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { BEN_STORAGE_STATE, LENA_STORAGE_STATE } from './auth';

// The pages the P14.T17 polish pass changed that no spec of their own checks with axe. Profile, API tokens, the team
// page and Admin › AI have theirs in profile.spec.ts, tokens-page.spec.ts, team-page.spec.ts and admin-people.spec.ts.
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function open(page: Page, url: string, heading: string): Promise<void> {
  await page.goto(url, { waitUntil: 'networkidle' });
  await expect(page.getByRole('heading', { name: heading, level: 1 })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

test.describe('as Lena', () => {
  test.use({ storageState: LENA_STORAGE_STATE });

  for (const scheme of ['light', 'dark'] as const) {
    test(`the new ticket page has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await open(page, '/tickets/new', 'New ticket');

      expect(await seriousViolations(page)).toEqual([]);
    });
  }

  test('a select on the new ticket page looks like the text field above it, not like a read-only field', async ({ page }) => {
    await open(page, '/tickets/new', 'New ticket');
    // The label wraps the select, so its options are part of the label's text; the field's name finds it.
    const looksOf = (name: string) => page.locator(`main [name="${name}"]`).evaluate((field) => {
      const style = getComputedStyle(field);
      return { background: style.backgroundColor, colour: style.color };
    });

    expect(await looksOf('project_id')).toEqual(await looksOf('title'));
  });

  test('the new ticket page picks Type and Priority with the chips of Report a problem, and offers Cancel beside Create ticket', async ({ page }) => {
    await open(page, '/tickets/new', 'New ticket');
    const form = page.locator('main form[action="/tickets/new"]');
    // Bug is the form's default pick, so Story starts unpicked.
    const storyChip = form.locator('label.report__chip').filter({ has: page.locator('input[name="type"][value="story"]') });
    const chipLooks = (chip: typeof storyChip) => chip.evaluate((label) => getComputedStyle(label).backgroundColor);

    await expect(form.getByRole('group', { name: 'Type' }).locator('label.chip')).not.toHaveCount(0);
    await expect(form.getByRole('group', { name: 'Priority' }).locator('label.chip')).not.toHaveCount(0);
    await expect(storyChip.getByRole('radio')).not.toBeChecked();
    const unpicked = await chipLooks(storyChip);
    await storyChip.click();
    await expect(storyChip.getByRole('radio')).toBeChecked();
    expect(await chipLooks(storyChip)).not.toBe(unpicked);
    await expect(form.locator('.report__buttons').getByRole('link')).toHaveText(['Cancel']);
    await expect(form.locator('.report__buttons').getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/');
    await expect(form.locator('.report__buttons').getByRole('button')).toHaveText(['Create ticket']);
  });

  test('radios take the primary colour', async ({ page }) => {
    await open(page, '/me/profile', 'Profile');
    const primary = await page.evaluate(() => {
      const probe = document.createElement('span');
      probe.style.color = 'var(--primary)';
      document.body.append(probe);
      const colour = getComputedStyle(probe).color;
      probe.remove();
      return colour;
    });

    expect(await page.locator('main input[type="radio"][name="theme"]').first().evaluate((radio) => getComputedStyle(radio).accentColor)).toBe(primary);
  });

  test('Report a problem has no serious or critical accessibility violations', async ({ page }) => {
    await open(page, '/report', 'Report a problem');
    await expect(page.locator('.report__drafts')).toContainText('Claude Code and the browser extension can prepare a report for you to check and send');

    expect(await seriousViolations(page)).toEqual([]);
  });
});

// Ben leads in Beta Bakery, where no spec makes report drafts or notebooks, so both his lists show how to make the
// first one.
test.describe('as Ben, who has no report drafts or notebooks', () => {
  test.use({ storageState: BEN_STORAGE_STATE });

  const emptyLists = [
    { url: '/report-drafts', heading: 'Report drafts', firstOne: 'pick “A new report” in the browser extension' },
    { url: '/notebooks', heading: 'My notebooks', firstOne: 'pick “A new notebook” in the browser extension' },
  ];
  for (const list of emptyLists) {
    test(`the empty ${list.heading} page says how to make the first one and has no serious or critical accessibility violations`, async ({ page }) => {
      await open(page, list.url, list.heading);
      await expect(page.locator('.empty-state')).toContainText(list.firstOne);
      await expect(page.locator('.empty-state').getByRole('link', { name: 'Make an API token' })).toHaveAttribute('href', '/me/tokens');

      expect(await seriousViolations(page)).toEqual([]);
    });
  }
});
