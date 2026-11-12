import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { BEN_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// As Ben, on the seeded stack: make seed gives Beta Bakery the team Crowd, Ben as lead and 2,000 members named
// Person0001 to Person2000, and its project CROWD four open tickets: two for Person0042, one for Person0040, one for
// Ben. "004" matches Person0004, Person0040 to Person0049 and Person1004, twelve people; the picker sorts them by open
// tickets, so Person0042 (2) and Person0040 (1) come before Person0004 (0), whose name sorts first.
test.use({ storageState: BEN_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

// This spec proves P03.T5's keyboard criterion, so a stack without the crowd fails it instead of skipping.
const NO_CROWD = 'The e2e database has no project CROWD: run make seed, which makes the Crowd team in dev.';
const MATCHES_OF_004 = [
  'Person0042',
  'Person0040',
  'Person0004',
  'Person0041',
  'Person0043',
  'Person0044',
  'Person0045',
  'Person0046',
  'Person0047',
  'Person0048',
  'Person0049',
  'Person1004',
];

async function openCrowdTickets(page: Page): Promise<void> {
  await page.goto('/tickets?project=CROWD', { waitUntil: 'networkidle' });
  await expect(page.locator('[data-rail-project="CROWD"]'), NO_CROWD).toHaveAttribute('aria-current', 'true');
}

function personChip(page: Page) {
  return page.locator('[data-person-picker-trigger]');
}

function pickerDialog(page: Page) {
  return page.getByRole('dialog', { name: 'Show tickets of…' });
}

function pickerRows(page: Page) {
  return pickerDialog(page).locator('.pick');
}

async function pickerRowNames(page: Page): Promise<string[]> {
  return pickerDialog(page).locator('.pick__name').allInnerTexts();
}

async function openPickerWithKeyboard(page: Page): Promise<void> {
  await personChip(page).focus();
  await page.keyboard.press('Enter');
  await expect(pickerDialog(page)).toBeVisible();
  await expect(pickerDialog(page).getByRole('searchbox', { name: 'Find a person' })).toBeFocused();
  // The first page of the 2,001 people comes from the server, capped at 20.
  await expect(pickerRows(page)).toHaveCount(20);
  await expect(pickerDialog(page).locator('[data-person-picker-hint]')).toHaveText('Showing 20 of 2,001. Type a name to narrow the list.');
}

async function searchPicker(page: Page, text: string): Promise<void> {
  await page.keyboard.type(text);
  await expect.poll(() => pickerRowNames(page)).toEqual(MATCHES_OF_004);
}

test('Ben picks a person of a big team with the keyboard only', async ({ page }) => {
  await openCrowdTickets(page);
  await expect(personChip(page)).toHaveText('Person ▾');
  await expect(personChip(page)).toHaveAttribute('aria-pressed', 'false');
  const rowCountBefore = await page.locator('.tickets__row').count();
  // Ben's own ticket and Person0040's must be left out by the pick, or the test could not tell a filtered list.
  expect(rowCountBefore).toBe(4);

  await openPickerWithKeyboard(page);
  await searchPicker(page, '004');
  await expect(pickerDialog(page).locator('[data-person-picker-hint]')).toHaveCount(0);
  await expect(pickerRows(page).first().locator('.pick__side')).toHaveText('2 open');

  await page.keyboard.press('ArrowDown');
  await expect(pickerRows(page).nth(0)).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(pickerRows(page).nth(1)).toBeFocused();
  await page.keyboard.press('End');
  await expect(pickerRows(page).nth(MATCHES_OF_004.length - 1)).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(pickerRows(page).nth(MATCHES_OF_004.length - 1)).toBeFocused();
  await page.keyboard.press('Home');
  await expect(pickerRows(page).nth(0)).toBeFocused();
  await page.keyboard.press('ArrowUp');
  await expect(pickerDialog(page).getByRole('searchbox', { name: 'Find a person' })).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(pickerRows(page).nth(0)).toBeFocused();
  const pickedId = await pickerRows(page).nth(0).getAttribute('data-person-pick');

  const loaded = page.waitForResponse((response) => response.request().headers()['turbo-frame'] === 'ticket-list-frame' && response.ok());
  await page.keyboard.press('Enter');
  await loaded;

  await expect(pickerDialog(page)).toBeHidden();
  await expect(page).toHaveURL(new RegExp(`[?&]person=${pickedId}(&|$)`));
  await expect(personChip(page)).toHaveText('Person0042');
  await expect(personChip(page)).toHaveAttribute('aria-pressed', 'true');
  await expect(personChip(page)).toBeFocused();
  await expect(page.locator('.tickets__row')).toHaveCount(2);
  expect((await page.locator('.tickets__row-title').allInnerTexts()).sort()).toEqual(['Oven timer drifts after an hour', 'Proofing log misses the night reading']);
  const assignees = await page.locator('.tickets__row .tickets__assignee').evaluateAll((avatars) => avatars.map((avatar) => avatar.getAttribute('title')));
  expect(assignees).toEqual(['Assigned to Person0042', 'Assigned to Person0042']);
});

test('Escape closes the picker without changing the filter', async ({ page }) => {
  await openCrowdTickets(page);
  const urlBefore = page.url();
  const rowCountBefore = await page.locator('.tickets__row').count();

  await openPickerWithKeyboard(page);
  await searchPicker(page, '004');
  await page.keyboard.press('ArrowDown');
  await expect(pickerRows(page).nth(0)).toBeFocused();
  await page.keyboard.press('Escape');

  await expect(pickerDialog(page)).toBeHidden();
  await expect(personChip(page)).toBeFocused();
  await expect(personChip(page)).toHaveText('Person ▾');
  await expect(personChip(page)).toHaveAttribute('aria-pressed', 'false');
  expect(page.url()).toBe(urlBefore);
  await expect(page.locator('.tickets__row')).toHaveCount(rowCountBefore);
});

test('the open picker has no serious accessibility violations', async ({ page }) => {
  await openCrowdTickets(page);
  await openPickerWithKeyboard(page);

  const { violations } = await new AxeBuilder({ page }).include('#ticket-filters-person-picker').withTags(AXE_TAGS).analyze();
  const serious = violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
  expect(serious).toEqual([]);
});
