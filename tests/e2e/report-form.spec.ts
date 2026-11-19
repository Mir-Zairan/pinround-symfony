import { expect, test, type Browser, type Locator, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { makeTicketForOmar, openAs, TOAST_REGION } from './ticket';

// report-form_controller.js on Report a problem, as Lena, who leads Shop and Mobile. The demo seed gives only Shop a
// project, so beforeAll gives Mobile the project TAB through the team page once; it stays for later runs. The tickets
// this spec makes go to TAB or are new SHOP tickets with titles no earlier run used, so no other spec sees them.
// ReportControllerTest proves the server's refusals; this spec proves what the controller shows, posts and fetches.
const MOBILE_PROJECT_KEY = 'TAB';
const MOBILE_PROJECT_NAME = 'Tablet app';
const DETAIL = 'Open the stock page on the warehouse tablet and scan one item.';

test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

/** Eight consonants from a random uuid: a word no seeded or earlier title holds, which the search reads as one word. */
function randomWord(): string {
  return randomUUID()
    .replace(/-/g, '')
    .slice(0, 8)
    .replace(/[0-9a-f]/g, (digit) => 'bcdfghjklmnpqrst'[parseInt(digit, 16)]);
}

/** As Lena: makes TAB, owned by Mobile, unless an earlier run did; fails when TAB is there but not Mobile's. */
async function ensureMobileProject(browser: Browser): Promise<void> {
  const owners = runAsDatabaseOwner(`
    SELECT coalesce(t.name, '(no team)') FROM tkt_project p
    JOIN org_organization o ON o.organization_id = p.organization_id
    LEFT JOIN org_team t ON t.organization_id = p.organization_id AND t.id = p.team_id
    WHERE o.slug = 'acme-foods' AND p.key = '${MOBILE_PROJECT_KEY}'`);
  if (owners.length > 0) {
    expect(owners, `${MOBILE_PROJECT_KEY} belongs to another team: link it to Mobile on /teams/mobile`).toEqual(['Mobile']);
    return;
  }
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);
  await page.goto('/teams/mobile');
  await page.getByRole('button', { name: 'Add project', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Add a project' });
  const newProject = dialog.getByRole('form', { name: 'New project' });
  await newProject.getByLabel('Key').fill(MOBILE_PROJECT_KEY);
  await newProject.getByLabel('Name').fill(MOBILE_PROJECT_NAME);
  await newProject.getByRole('button', { name: 'Create project' }).click();
  await expect(page.locator(TOAST_REGION)).toHaveText(`Added ${MOBILE_PROJECT_KEY}`);
  await context.close();
}

async function openReportForm(page: Page) {
  await page.goto('/report');
  const form = page.locator('form.report__form');
  return {
    form,
    title: form.getByLabel('What is wrong, in one line'),
    detail: form.getByLabel('More detail · optional'),
    duplicates: form.locator('[data-report-form-target="duplicates"]'),
    previewLine: form.locator('[data-report-form-target="previewLine"]'),
    preview: form.locator('[data-report-form-target="preview"]'),
    submit: form.getByRole('button', { name: 'Create ticket' }),
  };
}

function teamRadio(form: Locator, teamName: string): Locator {
  return form.getByRole('radio', { name: teamName, exact: true });
}

/** The team's id, or "none" for Not sure: the value of its chip, which data-team on its groups repeats. */
async function teamIdOf(form: Locator, teamName: string): Promise<string> {
  const teamId = await teamRadio(form, teamName).getAttribute('value');
  expect(teamId, `no ${teamName} chip on Report a problem`).toBeTruthy();
  return teamId ?? '';
}

function giveToOf(form: Locator, teamId: string): Locator {
  return form.locator(`[data-report-form-target="giveTo"][data-team="${teamId}"]`);
}

function projectsOf(form: Locator, teamId: string): Locator {
  return form.locator(`[data-report-form-target="projects"][data-team="${teamId}"]`);
}

/** Clicks the chip's label, as a person does: the radio inside it is visually hidden. */
async function pickChip(group: Locator, name: string): Promise<void> {
  await group.locator('label.report__chip').filter({ has: group.page().getByRole('radio', { name, exact: true }) }).click();
}

async function expectAllDisabledAndUnchecked(group: Locator): Promise<void> {
  const radios = group.getByRole('radio', { includeHidden: true });
  const count = await radios.count();
  expect(count).toBeGreaterThan(0);
  for (let index = 0; index < count; index++) {
    await expect(radios.nth(index)).toBeDisabled();
    await expect(radios.nth(index)).not.toBeChecked();
  }
}

test.beforeAll(async ({ browser }) => {
  await ensureMobileProject(browser);
});

test('picking another team shows only its Give it to and Project chips, and the ticket goes where they say', async ({ page }) => {
  const report = await openReportForm(page);
  const { form } = report;
  const mobileId = await teamIdOf(form, 'Mobile');
  const shopId = await teamIdOf(form, 'Shop');
  const notSureId = await teamIdOf(form, 'Not sure');
  // Own teams come first by name, so Mobile is the one picked when the page opens.
  await expect(teamRadio(form, 'Mobile')).toBeChecked();
  await expect(giveToOf(form, mobileId)).toBeVisible();
  await expect(giveToOf(form, shopId)).toBeHidden();
  await expect(projectsOf(form, mobileId).getByRole('radio', { name: MOBILE_PROJECT_KEY, exact: true })).toBeChecked();
  await expect(projectsOf(form, shopId)).toBeHidden();
  await expect(projectsOf(form, notSureId)).toBeHidden();
  await pickChip(giveToOf(form, mobileId), 'Omar');
  await expect(giveToOf(form, mobileId).getByRole('radio', { name: 'Omar', exact: true })).toBeChecked();

  await pickChip(form, 'Shop');

  await expect(giveToOf(form, shopId)).toBeVisible();
  await expect(giveToOf(form, shopId).getByRole('radio', { name: 'The team (Lena decides)' })).toBeChecked();
  await expect(giveToOf(form, mobileId)).toBeHidden();
  await expectAllDisabledAndUnchecked(giveToOf(form, mobileId));
  await expect(projectsOf(form, shopId)).toBeVisible();
  // The first chip, not SHOP by name: team-page.spec.ts links APP and DOCS to Shop for a moment, and they sort first.
  await expect(projectsOf(form, shopId).getByRole('radio').first()).toBeChecked();
  await expect(projectsOf(form, mobileId)).toBeHidden();
  await expectAllDisabledAndUnchecked(projectsOf(form, mobileId));

  await pickChip(form, 'Not sure');

  await expect(form.locator('[data-report-form-target="giveTo"]:visible')).toHaveCount(0);
  await expectAllDisabledAndUnchecked(giveToOf(form, shopId));
  await expect(projectsOf(form, notSureId)).toBeVisible();
  await expect(projectsOf(form, notSureId).getByRole('radio').first()).toBeChecked();
  await expect(projectsOf(form, shopId)).toBeHidden();

  await pickChip(form, 'Mobile');

  // Back on Mobile, "Give it to" starts again from the team: Omar, picked before, is no longer picked.
  await expect(giveToOf(form, mobileId).getByRole('radio', { name: 'The team (Lena decides)' })).toBeChecked();
  await expect(giveToOf(form, mobileId).getByRole('radio', { name: 'Omar', exact: true })).not.toBeChecked();
  await expect(projectsOf(form, mobileId).getByRole('radio', { name: MOBILE_PROJECT_KEY, exact: true })).toBeChecked();
  await expectAllDisabledAndUnchecked(projectsOf(form, notSureId));
  await pickChip(giveToOf(form, mobileId), 'Omar');
  // Mostly words of this run, so the titles of earlier runs share too few words to be shown as duplicates.
  const title = `Rotating ${randomWord()} ${randomWord()} hides ${randomWord()}`;
  await report.title.fill(title);
  await report.detail.fill(DETAIL);

  await report.submit.click();

  await expect(page).toHaveURL(new RegExp(`/t/${MOBILE_PROJECT_KEY}-\\d+$`));
  const key = new URL(page.url()).pathname.replace('/t/', '');
  await expect(page.locator(TOAST_REGION)).toHaveText(`Ticket ${key} made. Nobody needs to check it.`);
  const made = runAsDatabaseOwner(`
    SELECT p.key, coalesce(team.name, '(no team)'), coalesce(assignee.email, '(nobody)'), t.title FROM tkt_ticket t
    JOIN org_organization o ON o.organization_id = t.organization_id
    JOIN tkt_project p ON p.organization_id = t.organization_id AND p.id = t.project_id
    LEFT JOIN org_team team ON team.organization_id = t.organization_id AND team.id = t.team_id
    LEFT JOIN idn_user assignee ON assignee.id = t.assignee_id
    WHERE o.slug = 'acme-foods' AND t.key = '${key}'`);
  expect(made).toEqual([`${MOBILE_PROJECT_KEY}|Mobile|omar@acme-foods.example|${title}`]);
});

test.describe('the live preview', () => {
  // The typed title is "Pallet" and three words of this run. The match holds all four in its title, so the word rule
  // keeps it. The near miss holds two in its title and the other two in its description: the search finds it, and the
  // word rule must leave it out. Titles of earlier runs share only "Pallet", too few to be shown.
  const [first, second, third] = [randomWord(), randomWord(), randomWord()];
  const typedTitle = `Pallet ${first} ${second} ${third}`;
  const matchTitle = `Pallet ${first} ${second} ${third} on reload`;
  const nearMissTitle = `Pallet ${first} labels print twice`;
  let matchKey = '';
  let nearMissKey = '';

  test.beforeAll(async ({ browser }) => {
    matchKey = await makeTicketForOmar(browser, matchTitle);
    nearMissKey = await makeTicketForOmar(browser, nearMissTitle);
    runAsDatabaseOwner(`UPDATE tkt_ticket SET description = 'Also seen with ${second} and ${third}.'
      WHERE key = '${nearMissKey}' AND organization_id = (SELECT organization_id FROM org_organization WHERE slug = 'acme-foods')`);
  });

  test('typing fills the duplicates box and the "When you send" line from the preview, and clearing empties the box', async ({ page }) => {
    const report = await openReportForm(page);
    await expect(report.previewLine).toBeVisible();
    await expect(report.preview).toHaveText(/^It becomes ticket TAB-\d+ right away\. /);
    const nextKey = /ticket (TAB-\d+)/.exec(await report.preview.innerText())?.[1] ?? '';
    await expect(report.preview).toHaveText(`It becomes ticket ${nextKey} right away. You take a quick look, because little detail and no pins.`);
    await expect(report.duplicates.locator('section')).toHaveCount(0);

    await report.title.fill(typedTitle);

    await expect(report.preview).toHaveText(
      `It becomes ticket ${nextKey} right away. You take a quick look, because maybe the same as an older ticket, little detail and no pins.`,
    );
    await expect(report.duplicates.getByRole('heading', { name: 'Maybe already reported (found by matching words)' })).toBeVisible();
    await expect(report.duplicates.locator('.report-duplicates__key')).toHaveText([matchKey]);
    await expect(report.duplicates.getByRole('link')).toHaveText([matchTitle]);
    await expect(report.duplicates.getByRole('link')).toHaveAttribute('href', `/t/${matchKey}`);
    await expect(report.duplicates).not.toContainText(nearMissKey);

    await report.detail.fill(DETAIL);

    await expect(report.preview).toHaveText(`It becomes ticket ${nextKey} right away. You take a quick look, because maybe the same as an older ticket.`);
    await expect(report.duplicates.locator('.report-duplicates__key')).toHaveText([matchKey]);

    await report.title.fill('');

    await expect(report.preview).toHaveText(`It becomes ticket ${nextKey} right away. Lena plans it like any other ticket. Nobody needs to check it.`);
    await expect(report.duplicates.locator('section')).toHaveCount(0);
    await expect(report.previewLine).toBeVisible();
  });

  test('picking a type or a priority asks the preview nothing, as its words do not depend on them', async ({ page }) => {
    // The page's clock runs the 400 ms wait at once, so no test waits on the real clock.
    await page.clock.install();
    const report = await openReportForm(page);
    const previewCalls: string[] = [];
    page.on('request', (request) => {
      if (new URL(request.url()).pathname === '/report/preview') {
        previewCalls.push(request.url());
      }
    });

    let answered = page.waitForResponse((response) => new URL(response.url()).pathname === '/report/preview');
    await report.detail.fill(DETAIL);
    await page.clock.runFor(500);
    await answered;
    await expect(report.preview).toHaveText(/Nobody needs to check it\.$/);

    await pickChip(report.form, 'Story');
    await pickChip(report.form, 'Major');
    await page.clock.runFor(500);
    answered = page.waitForResponse((response) => new URL(response.url()).pathname === '/report/preview');
    await report.title.fill(`Password ${randomWord()} shown`);
    await page.clock.runFor(500);
    await answered;

    await expect(report.preview).toHaveText(/It mentions security, so only the team and admins can see it\.$/);
    expect(previewCalls, 'one call for the detail and one for the title, none for the type or the priority').toHaveLength(2);
  });
});
