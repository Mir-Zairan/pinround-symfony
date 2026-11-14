import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { BEN_STORAGE_STATE, LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const SHOWN_AT_FIRST = 6;

// The People section's show-all toggle and search run in team-people_controller.js; TeamPageTest proves the counts,
// levels, sort and leaks on the server. make seed gives Beta Bakery the team Crowd, Ben as lead and 2,000 developers
// named Person0001 to Person2000, so "person004" matches Person0040 to Person0049 and nobody else; Acme Foods' Shop has
// six people or fewer.
test.use({ viewport: { width: 1280, height: 800 } });

const NO_CROWD = 'The e2e database has no team Crowd: run make seed, which makes the Crowd team in dev.';

async function openTeam(page: Page, slug: string): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/teams/${slug}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

function peopleSection(page: Page) {
  return page.getByRole('region', { name: /^People / });
}

function personRows(page: Page) {
  return page.locator('#team-people-list > li');
}

function shownPersonRows(page: Page) {
  return personRows(page).filter({ visible: true });
}

// Found by class, not by role and name: the label changes with each click and the button leaves the role tree while hidden.
function peopleToggle(page: Page) {
  return peopleSection(page).locator('.team__people-toggle');
}

function peopleSearch(page: Page) {
  return peopleSection(page).getByRole('searchbox', { name: 'Find a person' });
}

async function peopleCount(page: Page): Promise<number> {
  return Number(await peopleSection(page).locator('.team__count').innerText());
}

test.describe('a team of more than six people', () => {
  test.use({ storageState: BEN_STORAGE_STATE });

  test.beforeEach(async ({ page }) => {
    await openTeam(page, 'crowd');
    await expect(page.getByRole('heading', { name: 'Crowd', level: 1 }), NO_CROWD).toBeVisible();
  });

  test('shows six people, then all of them with "Show all N", then six again with "Show fewer"', async ({ page }) => {
    const total = await peopleCount(page);
    expect(total).toBeGreaterThan(SHOWN_AT_FIRST);
    await expect(personRows(page)).toHaveCount(total);
    const toggle = peopleToggle(page);

    await expect(shownPersonRows(page)).toHaveCount(SHOWN_AT_FIRST);
    await expect(toggle).toHaveAccessibleName(`Show all ${total}`);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await toggle.click();

    await expect(shownPersonRows(page)).toHaveCount(total);
    await expect(toggle).toHaveText('Show fewer');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(personRows(page).last()).toBeVisible();

    await toggle.click();

    await expect(shownPersonRows(page)).toHaveCount(SHOWN_AT_FIRST);
    await expect(toggle).toHaveText(`Show all ${total}`);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(personRows(page).nth(SHOWN_AT_FIRST)).toBeHidden();
  });

  test('the search narrows the rows by name as the user types and clearing it brings back the first six', async ({ page }) => {
    const total = await peopleCount(page);
    const toggle = peopleToggle(page);
    const noMatch = peopleSection(page).getByText('No one in this team matches.');
    const firstSixNames = await shownPersonRows(page).locator('.team__person-name').allInnerTexts();
    expect(firstSixNames).toHaveLength(SHOWN_AT_FIRST);

    await peopleSearch(page).pressSequentially('person004');

    const tenMatches = Array.from({ length: 10 }, (_, digit) => `Person004${digit}`);
    await expect.poll(async () => (await shownPersonRows(page).locator('.team__person-name').allInnerTexts()).sort()).toEqual(tenMatches);
    await expect(toggle).toHaveAttribute('hidden', '');
    await expect(noMatch).toBeHidden();

    await peopleSearch(page).pressSequentially('2');

    await expect(shownPersonRows(page)).toHaveCount(1);
    await expect(shownPersonRows(page).locator('.team__person-name')).toHaveText('Person0042');

    await peopleSearch(page).fill('person0042 zzz');

    await expect(shownPersonRows(page)).toHaveCount(0);
    await expect(noMatch).toBeVisible();

    await peopleSearch(page).clear();

    await expect(shownPersonRows(page).locator('.team__person-name')).toHaveText(firstSixNames);
    await expect(noMatch).toBeHidden();
    await expect(toggle).not.toHaveAttribute('hidden');
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveText(`Show all ${total}`);
  });

  test('"Show all N" is off the screen while a search runs', async ({ page }) => {
    await peopleSearch(page).pressSequentially('person004');
    await expect(peopleToggle(page)).toHaveAttribute('hidden', '');

    await expect(peopleToggle(page)).toBeHidden({ timeout: 1_000 });
  });

  test('each row has a workload bar holding the count shown beside the name', async ({ page }) => {
    const rows = shownPersonRows(page);
    await expect(rows).toHaveCount(SHOWN_AT_FIRST);

    for (const row of await rows.all()) {
      const bar = row.locator('progress');
      await expect(bar).toHaveCount(1);
      await expect(bar).toBeVisible();
      const load = await row.locator('.team__person-load').innerText();
      await expect(bar).toHaveAttribute('value', load);
      expect(Number(await bar.getAttribute('max'))).toBeGreaterThanOrEqual(Number(load));
      const box = await bar.boundingBox();
      expect(box).not.toBeNull();
      expect(box!.width).toBeGreaterThan(0);
      expect(box!.height).toBeGreaterThan(0);
    }
    await test.info().attach('people-section', { body: await peopleSection(page).screenshot(), contentType: 'image/png' });
  });

  // The bar is aria-hidden, so the words beside the count carry the level its colour shows.
  test('each row names in words the level its bar shows in colour', async ({ page }) => {
    const rows = shownPersonRows(page);
    await expect(rows).toHaveCount(SHOWN_AT_FIRST);

    for (const row of await rows.all()) {
      const barClass = (await row.locator('progress').getAttribute('class')) ?? '';
      const expectedLevel = barClass.includes('team__meter--too-much') ? ['Too much'] : barClass.includes('team__meter--busy') ? ['Busy'] : [];
      expect(await row.locator('.team__person-level').allInnerTexts()).toEqual(expectedLevel);
    }
    await expect(peopleSection(page).locator('.team__people-note')).not.toContainText(/red|amber/i);
  });

  // Only the controls and the first rows: axe takes over 30 seconds on the whole list of 2,001 people, shown or hidden.
  test('the search, the toggle and the first rows have no serious or critical accessibility violations', async ({ page }) => {
    const seriousOf = async (): Promise<string[]> => {
      const { violations } = await new AxeBuilder({ page })
        .include('.team__people-search')
        .include('.team__people-toggle')
        .include('#team-people-list > li:nth-child(-n+50)')
        .withTags(AXE_TAGS)
        .analyze();
      return violations
        .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
        .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
    };
    expect(await seriousOf()).toEqual([]);

    await peopleSearch(page).fill('person004');
    await expect(shownPersonRows(page)).toHaveCount(10);

    expect(await seriousOf()).toEqual([]);
  });
});

test.describe('a team of six people or fewer', () => {
  test.use({ storageState: LENA_STORAGE_STATE });

  test('shows every person with no toggle and no search box', async ({ page }) => {
    await openTeam(page, 'shop');

    const total = await peopleCount(page);
    expect(total).toBeGreaterThan(0);
    expect(total).toBeLessThanOrEqual(SHOWN_AT_FIRST);
    await expect(personRows(page)).toHaveCount(total);
    await expect(shownPersonRows(page)).toHaveCount(total);
    await expect(peopleSection(page).getByRole('button', { name: /^Show (all|fewer)/ })).toHaveCount(0);
    await expect(peopleSection(page).getByRole('searchbox')).toHaveCount(0);
    for (const row of await shownPersonRows(page).all()) {
      await expect(row.locator('progress')).toBeVisible();
    }
  });
});
