import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// The demo seed gives Shop one project (SHOP) and leaves APP and DOCS with no team. The specs change links, so they run
// one after the other, and the lead's afterEach puts the links back.
test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openShopTeam(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/teams/shop', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

async function openAddProjectDialog(page: Page) {
  await page.getByRole('button', { name: 'Add project', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Add a project' });
  await expect(dialog).toBeVisible();
  return dialog;
}

function teamSteps(page: Page) {
  return page.locator('.team__step--team');
}

async function addDesignCheckStep(page: Page): Promise<void> {
  const form = page.getByRole('form', { name: 'Add a team step' });
  await form.getByLabel('Step name').fill('Design check');
  await form.locator('label.team__group-choice').filter({ hasText: 'Checking' }).click();
  await form.getByRole('button', { name: 'Add step', exact: true }).click();
}

function projectRows(page: Page) {
  return page.locator('.team__projects > li');
}

async function addProjects(page: Page, keys: string[]): Promise<void> {
  const dialog = await openAddProjectDialog(page);
  for (const key of keys) {
    await dialog.getByRole('checkbox', { name: new RegExp(`^${key} `) }).check();
  }
  await dialog.getByRole('button', { name: keys.length > 1 ? `Add ${keys.length} projects` : 'Add project' }).click();
}

test.describe('as the lead', () => {
  test.use({ storageState: LENA_STORAGE_STATE });

  // Whatever the test did, Shop ends with exactly SHOP and APP and DOCS have no team, as the seed leaves them.
  test.afterEach(async ({ page }) => {
    await openShopTeam(page);
    const removeStepButtons = page.getByRole('button', { name: 'Remove step Design check' });
    while ((await removeStepButtons.count()) > 0) {
      await removeStepButtons.first().click();
      await page.waitForLoadState('networkidle');
    }
    await expect(teamSteps(page)).toHaveCount(0);
    const removeButtons = page.getByRole('button', { name: /^Remove (?!SHOP )\S+ from the team$/ });
    while ((await removeButtons.count()) > 0) {
      await removeButtons.first().click();
      await page.waitForLoadState('networkidle');
    }
    if ((await page.getByRole('button', { name: 'Remove SHOP from the team' }).count()) === 0) {
      await addProjects(page, ['SHOP']);
      await expect(page.getByText('Added SHOP')).toBeVisible();
    }
    await expect(projectRows(page)).toHaveCount(1);
  });

  test('the Add project dialog filters, ignores Enter, counts the ticked projects and links them', async ({ page }) => {
    await openShopTeam(page);
    await expect(projectRows(page)).toHaveCount(1);
    const dialog = await openAddProjectDialog(page);
    const submit = dialog.getByRole('button', { name: 'Add project', exact: true });
    const search = dialog.getByLabel('Find by key or name');
    const noMatch = dialog.getByText('No project matches.');

    await expect(submit).toBeDisabled();
    await expect(noMatch).toBeHidden();

    await search.fill('docs');
    await expect(dialog.getByRole('checkbox')).toHaveCount(1);
    await expect(dialog.getByRole('checkbox', { name: /^DOCS / })).toBeVisible();
    await expect(dialog.getByRole('checkbox', { name: /^APP / })).toBeHidden();

    await search.fill('zzz');
    await expect(noMatch).toBeVisible();

    await search.fill('');
    await expect(noMatch).toBeHidden();
    await expect(dialog.getByRole('checkbox', { name: /^APP / })).toBeVisible();

    await dialog.getByRole('checkbox', { name: /^APP / }).check();
    const noPost = expect(page.waitForRequest((request) => request.method() === 'POST', { timeout: 500 })).rejects.toThrow();
    await search.press('Enter');
    await noPost;
    await expect(dialog).toBeVisible();

    await expect(submit).toBeEnabled();
    await dialog.getByRole('checkbox', { name: /^DOCS / }).check();
    await expect(dialog.getByRole('button', { name: 'Add 2 projects' })).toBeEnabled();

    await dialog.getByRole('button', { name: 'Add 2 projects' }).click();

    await expect(page.getByText(/^Added /)).toContainText(['APP', 'DOCS'].join(', '));
    await expect(projectRows(page)).toHaveCount(3);
    await expect(projectRows(page).filter({ hasText: 'APP' })).toHaveCount(1);
    await expect(projectRows(page).filter({ hasText: 'DOCS' })).toHaveCount(1);
  });

  test('Remove unlinks a project and the dialog then lists it', async ({ page }) => {
    await openShopTeam(page);

    await page.getByRole('button', { name: 'Remove SHOP from the team' }).click();

    await expect(page.getByText('SHOP removed from the team')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Remove SHOP from the team' })).toHaveCount(0);
    await expect(page.getByText('No projects yet.')).toBeVisible();
    const dialog = await openAddProjectDialog(page);
    await expect(dialog.getByRole('checkbox', { name: /^SHOP / })).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    await addProjects(page, ['SHOP']);

    await expect(page.getByText('Added SHOP')).toBeVisible();
    await expect(projectRows(page)).toHaveCount(1);
  });

  test('Add step is disabled while the name is empty and the "in" legend shares a row with the first group chip', async ({ page }) => {
    await openShopTeam(page);
    const form = page.getByRole('form', { name: 'Add a team step' });
    const addStep = form.getByRole('button', { name: 'Add step', exact: true });

    await expect(addStep).toBeDisabled();
    await form.getByLabel('Step name').fill('   ');
    await expect(addStep).toBeDisabled();
    await form.getByLabel('Step name').fill('Design check');
    await expect(addStep).toBeEnabled();

    const legend = await form.locator('legend').boundingBox();
    const firstChip = await form.locator('.chip').first().boundingBox();
    expect(legend).not.toBeNull();
    expect(firstChip).not.toBeNull();
    expect(firstChip!.x).toBeGreaterThan(legend!.x + legend!.width - 1);
    expect(Math.abs(firstChip!.y + firstChip!.height / 2 - (legend!.y + legend!.height / 2))).toBeLessThan(legend!.height);
  });

  test('Add a team step puts Design check under Checking, highlighted, and its x removes it', async ({ page }) => {
    await openShopTeam(page);
    await expect(page.getByRole('form', { name: 'Add a team step' })).toBeVisible();
    await expect(teamSteps(page)).toHaveCount(0);

    await addDesignCheckStep(page);

    await expect(page.getByText('Added “Design check” to Checking.')).toBeVisible();
    const checking = page.locator('.team__group').filter({ hasText: 'Checking' });
    await expect(checking.locator('.team__step--team', { hasText: 'Design check' })).toHaveCount(1);
    await expect(teamSteps(page)).toHaveCount(1);

    await page.getByRole('button', { name: 'Remove step Design check' }).click();

    await expect(page.getByText('Removed the team step.')).toBeVisible();
    await expect(teamSteps(page)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Remove step Design check' })).toHaveCount(0);
  });

  test('the Team page has no serious or critical accessibility violations with the dialog closed and open', async ({ page }) => {
    await openShopTeam(page);
    expect(await seriousViolations(page)).toEqual([]);

    await openAddProjectDialog(page);

    expect(await seriousViolations(page)).toEqual([]);
  });

  test('How work moves here has no serious or critical accessibility violations with a team step added', async ({ page }) => {
    await openShopTeam(page);
    await addDesignCheckStep(page);
    await expect(teamSteps(page)).toHaveCount(1);

    const { violations } = await new AxeBuilder({ page }).include('.team__flow').withTags(AXE_TAGS).analyze();

    expect(violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')).toEqual([]);
  });

  test.describe('narrow viewport', () => {
    test.use({ viewport: { width: 360, height: 800 } });

    test('the Team page has no horizontal scroll at 360px', async ({ page }) => {
      await openShopTeam(page);
      await expect(projectRows(page)).toHaveCount(1);

      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

      await openAddProjectDialog(page);

      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    });

    test('How work moves here has no horizontal scroll at 360px with a team step added', async ({ page }) => {
      await openShopTeam(page);
      await addDesignCheckStep(page);
      await expect(teamSteps(page)).toHaveCount(1);

      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    });
  });
});

test.describe('as a member who is not the lead', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });

  test('the page offers no Remove and no Add project and says only the lead can change projects', async ({ page }) => {
    await openShopTeam(page);

    await expect(page.getByText('Only the team lead can change projects and steps.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add project' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Remove / })).toHaveCount(0);
    await expect(projectRows(page)).toHaveCount(1);
  });

  test('the page offers no Add a team step and no x on steps', async ({ page }) => {
    await openShopTeam(page);

    await expect(page.getByRole('heading', { name: 'How work moves here' })).toBeVisible();
    await expect(page.getByRole('form', { name: 'Add a team step' })).toHaveCount(0);
    await expect(page.getByText('Add a team step')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Remove step / })).toHaveCount(0);
  });
});
