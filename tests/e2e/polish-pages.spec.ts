import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

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

  test('radios take the primary colour', async ({ page }) => {
    await open(page, '/tickets/new', 'New ticket');
    const primary = await page.evaluate(() => {
      const probe = document.createElement('span');
      probe.style.color = 'var(--primary)';
      document.body.append(probe);
      const colour = getComputedStyle(probe).color;
      probe.remove();
      return colour;
    });

    expect(await page.getByRole('radio', { name: 'Bug' }).evaluate((radio) => getComputedStyle(radio).accentColor)).toBe(primary);
  });
});
