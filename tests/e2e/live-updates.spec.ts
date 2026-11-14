import { errors, expect, test, type Browser, type BrowserContext, type Locator, type Page, type Request, type Response } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { LENA_EMAIL, LENA_STORAGE_STATE, MIA_EMAIL, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { bellButton, bellPopover, openBellPopover } from './bell';
import { composerBody, makeTicketForOmar, openAs, openThread, threadFrame, TOAST_REGION } from './ticket';

// ThreadController::COMMENT_ADDED, MESSAGE_EDITED, MESSAGE_REMOVED
const COMMENT_ADDED = 'Comment added.';
const MESSAGE_EDITED = 'Message edited.';
const MESSAGE_REMOVED = 'Message removed.';
// phase-04-conversation-and-pings.md, Definition of done: a change in one browser shows in the other within 3 s.
const SHOWS_WITHIN_MS = 3000;
// RefreshTicketPagesHandler::WINDOW_MILLISECONDS: a change within 2 s of the last signal (the ticket's own creation counts) is
// sent by a try one window later, so it may show that much later.
const COALESCED_SHOWS_WITHIN_MS = 2000 + SHOWS_WITHIN_MS;
const TOKEN_PATH = '/realtime/token';
const HUB_PATH = '/.well-known/mercure';
const STATE_CHANGE_KEY = 'e2e-ticket-state-change';
const REPO_ROOT = path.join(__dirname, '..', '..');

// Lena and Omar each have a browser of their own on one SHOP ticket that Lena makes for the test, so the hub, the
// outbox relay and the worker that publishes (compose.yaml) all run between them. The signal carries no content: what
// Omar sees comes from his own page fetching the thread again. tests/Functional/Ticketing/TicketPageTest proves which
// topic the page follows and that a non-viewer gets 404.

function messageWithText(page: Page, text: string) {
  return threadFrame(page).locator('li[data-message-id]').filter({ has: page.locator('.thread__body', { hasText: text }) });
}

async function postComment(page: Page, text: string): Promise<void> {
  await composerBody(page).fill(text);
  await threadFrame(page).locator('form.thread__composer button[type="submit"]').click();
  await expect(page.locator(TOAST_REGION)).toHaveText(COMMENT_ADDED);
}

/** Marks the document, so a check later proves the page was not loaded again. */
async function markThisLoad(page: Page): Promise<void> {
  await page.evaluate(() => document.documentElement.setAttribute('data-e2e-load', 'first'));
}

async function expectSameLoad(page: Page): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('data-e2e-load', 'first');
}

/** Every request the page sends from now on, to count the cookie asks and the streams it opens. */
function recordRequests(page: Page): Request[] {
  const requests: Request[] = [];
  page.on('request', (request) => requests.push(request));
  return requests;
}

function pathOf(request: Request): string {
  return new URL(request.url()).pathname;
}

function tokenAsks(requests: Request[]): Request[] {
  return requests.filter((request) => pathOf(request) === TOKEN_PATH && request.method() === 'POST');
}

function hubStreams(requests: Request[]): Request[] {
  return requests.filter((request) => pathOf(request) === HUB_PATH);
}

/** Opens the thread and waits until the hub's stream answers, so a change after this reaches the page. */
async function openFollowedThread(page: Page, key: string): Promise<void> {
  const streamOpened = page.waitForResponse((response) => pathOf(response.request()) === HUB_PATH);
  await openThread(page, key);
  expect((await streamOpened).status()).toBe(200);
}

/** A Turbo visit, as a link inside the app would make one, resolved when Turbo has rendered the new page. */
async function turboVisit(page: Page, path: string): Promise<void> {
  await page.evaluate(
    (url) =>
      new Promise<void>((resolve) => {
        document.addEventListener('turbo:load', () => resolve(), { once: true });
        (window as unknown as { Turbo: { visit(location: string): void } }).Turbo.visit(url);
      }),
    path,
  );
}

/**
 * Waits for the one signal the ticket's creation sends, which may reach Omar's page only after it opened, and resolves
 * once that page has fetched and shown it, or once SHOWS_WITHIN_MS has passed with none, as the stream opened after it.
 * Started as soon as the ticket is made, so the wait is never shorter than that bound. Every later signal then comes
 * from a change the test makes.
 */
async function settleCreationSignal(page: Page, key: string): Promise<void> {
  let creationFetch: Request;
  try {
    creationFetch = await page.waitForRequest((sent) => isPageFetch(sent, key), { timeout: SHOWS_WITHIN_MS });
  } catch (error) {
    if (error instanceof errors.TimeoutError) {
      return;
    }
    throw error;
  }
  const response = await creationFetch.response();
  await response?.finished();
  await threadLoaded(page);
  await nextTaskOf(page);
}

/** Lena makes a ticket for Omar, who opens its thread once no signal of the creation is left to come. */
async function openNewTicketAsOmar(browser: Browser, title: string): Promise<{ key: string; omar: { context: BrowserContext; page: Page } }> {
  const key = await makeTicketForOmar(browser, title);
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const creationSignalSettled = settleCreationSignal(omar.page, key);
  await openFollowedThread(omar.page, key);
  await creationSignalSettled;
  return { key, omar };
}

/**
 * Omar comments and waits until the signal of his comment has been fetched and the thread it reloaded is shown, so the
 * next signal fetch is of someone else's change. Returns his message.
 */
async function postOwnCommentAndSettle(page: Page, key: string, text: string): Promise<Locator> {
  const ownRefresh = page.waitForResponse((response) => pathOf(response.request()) === `/t/${key}/thread`);
  const ownSignalFetch = pageFetchAfterNow(page, key);
  await postComment(page, text);
  await ownRefresh;
  expect((await ownSignalFetch).status()).toBe(200);
  await threadLoaded(page);
  return messageWithText(page, text);
}

/** Omar comments, opens the edit form of his comment and types in it, then clicks away: the open edit holds the thread. */
async function holdThreadWithOpenEdit(page: Page, key: string, draft: string): Promise<{ message: Locator; editField: Locator }> {
  const message = await postOwnCommentAndSettle(page, key, `A note to edit later ${randomUUID().slice(0, 8)}`);
  await message.getByText('Edit', { exact: true }).click();
  const editField = message.getByLabel('Edit your message');
  await editField.fill(draft);
  await page.getByRole('heading', { level: 2, name: 'Questions and comments' }).click();
  await expect(editField).not.toBeFocused();
  return { message, editField };
}

/** The answer to the next fetch of the ticket page that starts from now on, which only a signal sends. */
async function pageFetchAfterNow(page: Page, key: string): Promise<Response> {
  const request = await page.waitForRequest((sent) => isPageFetch(sent, key));
  const response = await request.response();
  if (response === null) {
    throw new Error(`The fetch of /t/${key} got no answer.`);
  }
  return response;
}

/** Resolves once the thread's frame has shown the answer to its latest load (Turbo's FrameElement.loaded). */
async function threadLoaded(page: Page): Promise<void> {
  await threadFrame(page).evaluate((frame) => (frame as HTMLElement & { loaded: Promise<void> }).loaded);
}

/** Whether request is a fetch of the ticket page, which only a signal sends. */
function isPageFetch(request: Request, key: string): boolean {
  return pathOf(request) === `/t/${key}` && request.resourceType() === 'fetch';
}

function pageFetches(requests: Request[], key: string): Request[] {
  return requests.filter((request) => isPageFetch(request, key));
}

function threadLoads(requests: Request[], key: string): Request[] {
  return requests.filter((request) => pathOf(request) === `/t/${key}/thread`);
}

/** Resolves once the page has run a task queued after now, so the script's work on an answer already read is done. */
async function nextTaskOf(page: Page): Promise<void> {
  await page.evaluate(() => new Promise<void>((resolve) => setTimeout(resolve, 0)));
}

type ThreadRenderPause = Window & { resumeThreadRender?: () => void };

/**
 * Pauses the next render of the thread's frame through Turbo's own pause (turbo:before-frame-render's resume), after
 * the frame's own listeners, live-updates' among them, have run.
 */
async function pauseNextThreadRender(page: Page): Promise<void> {
  await page.evaluate(() => {
    const pausing = (event: Event) => {
      if ((event.target as Element).id !== 'ticket-thread') {
        return;
      }
      document.removeEventListener('turbo:before-frame-render', pausing);
      event.preventDefault();
      (window as ThreadRenderPause).resumeThreadRender = (event as CustomEvent<{ resume: () => void }>).detail.resume;
    };
    document.addEventListener('turbo:before-frame-render', pausing);
  });
}

async function threadRenderPaused(page: Page, timeout: number): Promise<void> {
  await page.waitForFunction(() => (window as ThreadRenderPause).resumeThreadRender !== undefined, undefined, { timeout });
}

async function resumeThreadRender(page: Page): Promise<void> {
  await page.evaluate(() => (window as ThreadRenderPause).resumeThreadRender?.());
}

function lenasPeopleRow(page: Page) {
  return page.locator('#ticket-people .people__row').filter({ has: page.locator('.people__name', { hasText: /^Lena$/ }) });
}

/** As a lead: picks a step from "Move to another step…" that asks nothing more, and waits for the move's answer. */
async function moveFromList(page: Page, stepName: string): Promise<void> {
  const answer = page.waitForResponse((response) => response.url().includes('/move-to/') && response.request().method() === 'POST');
  const moveList = page.locator('#ticket-next-step details.next-step-box__move-to');
  await moveList.getByText('Move to another step…').click();
  await moveList.getByRole('button', { name: stepName, exact: true }).click();
  expect((await answer).status()).toBe(200);
}

/**
 * Runs SQL as the database's superuser, which row-level security does not limit, and returns the rows it printed, one
 * per line with '|' between columns; statements split by ';' run in one transaction. No app path removes a team member,
 * so the test does it here.
 * .env.local exists only on a dev machine; COMPOSE_FILE picks CI's compose files.
 */
function runAsDatabaseOwner(sql: string): string[] {
  const envFileOptions = ['.env', '.env.dev', '.env.local'].filter((file) => existsSync(path.join(REPO_ROOT, file))).flatMap((file) => ['--env-file', file]);
  const printed = execFileSync(
    'docker',
    ['compose', ...envFileOptions, 'exec', '-T', 'postgres', 'psql', '-U', 'pinround', '-d', 'pinround', '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-c', sql],
    { cwd: REPO_ROOT, encoding: 'utf8' },
  );
  return printed.split('\n').filter((line) => line !== '');
}

/** The throwaway team a ticket went to and where the ticket stood before, as SQL literals, for putBackAndDropTeam. */
type TeamMove = { organizationId: string; ticketKey: string; teamId: string; earlierTeamId: string; earlierVisibility: string };

/**
 * Makes a throwaway team whose lead and only member is Mia and gives Lena's ticket to it, seen by its team only, so
 * taking Mia out of it changes no seeded team: ticket-move.spec.ts, which may run at the same time, needs her in Shop.
 * Mia is none of the ticket's people, as Lena made it for Omar, so only that team shows it to her.
 */
function giveToTeamOfOnlyMia(ticketKey: string): TeamMove {
  const suffix = randomUUID().slice(0, 8);
  const rows = runAsDatabaseOwner(`
    WITH ticket AS (
      SELECT t.organization_id, t.id, t.team_id, t.visibility FROM tkt_ticket t
      JOIN idn_user lena ON lena.id = t.reporter_id AND lena.email = '${LENA_EMAIL}'
      WHERE t.key = '${ticketKey}'
    ), team AS (
      INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at, slug)
      SELECT ticket.organization_id, uuidv7(), 'Only Mia ${suffix}', mia.id, now(), 'only-mia-${suffix}'
      FROM ticket JOIN idn_user mia ON mia.email = '${MIA_EMAIL}'
      RETURNING organization_id, id, lead_user_id
    ), member AS (
      INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at)
      SELECT organization_id, id, lead_user_id, 'lead', 'tester', now() FROM team
    )
    UPDATE tkt_ticket t SET team_id = team.id, visibility = 'team'
    FROM team, ticket WHERE t.organization_id = ticket.organization_id AND t.id = ticket.id
    RETURNING quote_literal(ticket.organization_id), quote_literal(team.id), quote_nullable(ticket.team_id), quote_literal(ticket.visibility)`);
  expect(rows, `Lena's ticket ${ticketKey} or Mia is missing: run make seed`).toHaveLength(1);
  const [organizationId, teamId, earlierTeamId, earlierVisibility] = rows[0].split('|');
  return { organizationId, ticketKey, teamId, earlierTeamId, earlierVisibility };
}

function removeMiaFromTeam(move: TeamMove): void {
  const rows = runAsDatabaseOwner(`
    DELETE FROM org_team_member WHERE organization_id = ${move.organizationId} AND team_id = ${move.teamId} RETURNING user_id`);
  expect(rows, 'the throwaway team lost its only member before the test removed her').toHaveLength(1);
}

/** Runs in finally, so it also cleans up after a test that stopped before removing Mia. */
function putBackAndDropTeam(move: TeamMove): void {
  runAsDatabaseOwner(`
    UPDATE tkt_ticket SET team_id = ${move.earlierTeamId}, visibility = ${move.earlierVisibility}
    WHERE organization_id = ${move.organizationId} AND key = '${move.ticketKey}';
    DELETE FROM org_team_member WHERE organization_id = ${move.organizationId} AND team_id = ${move.teamId};
    DELETE FROM org_team WHERE organization_id = ${move.organizationId} AND id = ${move.teamId}`);
}

test.describe('two people on one ticket', () => {
  test("Lena's comment, edit and removal each show on Omar's page without a reload", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Basket badge counts removed items ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
    await openThread(lena.page, key);
    await markThisLoad(omar.page);
    const text = `The badge says 3 after I remove one ${randomUUID().slice(0, 8)}`;
    const editedText = `The badge says 3 after I remove two ${randomUUID().slice(0, 8)}`;

    await postComment(lena.page, text);

    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: SHOWS_WITHIN_MS });
    const messageId = await messageWithText(lena.page, text).getAttribute('data-message-id');
    expect(messageId).not.toBeNull();
    const lenasMessage = threadFrame(lena.page).locator(`li[data-message-id="${messageId}"]`);
    const omarsCopy = threadFrame(omar.page).locator(`li[data-message-id="${messageId}"]`);

    await lenasMessage.getByText('Edit', { exact: true }).click();
    await lenasMessage.getByLabel('Edit your message').fill(editedText);
    await lenasMessage.getByRole('button', { name: 'Save' }).click();
    await expect(lena.page.locator(TOAST_REGION)).toHaveText(MESSAGE_EDITED);

    await expect(omarsCopy.locator('.thread__body')).toHaveText(editedText, { timeout: COALESCED_SHOWS_WITHIN_MS });
    await expect(omarsCopy.locator('.thread__edited')).toHaveText('edited');

    await lenasMessage.getByRole('button', { name: 'Remove' }).click();
    await expect(lena.page.locator(TOAST_REGION)).toHaveText(MESSAGE_REMOVED);

    await expect(omarsCopy.locator('.thread__body')).toHaveText('Message removed', { timeout: COALESCED_SHOWS_WITHIN_MS });
    await expectSameLoad(omar.page);
    await lena.context.close();
    await omar.context.close();
  });

  test("Omar's move shows on Lena's page without a reload", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Basket keeps a sold-out item ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(lena.page, key);
    await openThread(omar.page, key);
    await markThisLoad(lena.page);
    const lenasStatus = lena.page.locator('#ticket-state .ticket__state');
    await expect(lenasStatus).toContainText('To do');

    await omar.page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button', { name: 'Start work' }).click();
    await expect(omar.page.locator(TOAST_REGION)).toHaveText('Started. Status is In progress.');

    await expect(lenasStatus).toContainText('In progress', { timeout: SHOWS_WITHIN_MS });
    await expectSameLoad(lena.page);
    await lena.context.close();
    await omar.context.close();
  });

  test("a draft Omar is typing stays in the composer when Lena's comment shows", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Coupon box clears on resize ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
    await openThread(lena.page, key);
    const draft = `Half a thought about the coupon ${randomUUID().slice(0, 8)}`;
    const text = `It clears when the window gets narrow ${randomUUID().slice(0, 8)}`;
    await composerBody(omar.page).fill(draft);

    await postComment(lena.page, text);

    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: COALESCED_SHOWS_WITHIN_MS });
    await expect(composerBody(omar.page)).toHaveValue(draft);
    await lena.context.close();
    await omar.context.close();
  });

  test("an edit Omar left open keeps its text: the thread waits until he closes it, then shows Lena's comment", async ({ browser }) => {
    const { key, omar } = await openNewTicketAsOmar(browser, `Gift wrap price shows twice ${randomUUID().slice(0, 8)}`);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    const editDraft = `Gift wrap adds its price twice ${randomUUID().slice(0, 8)}`;
    const reply = `Only on the basket page ${randomUUID().slice(0, 8)}`;
    const { message: ownMessage, editField } = await holdThreadWithOpenEdit(omar.page, key, editDraft);
    const requests = recordRequests(omar.page);
    // Omar's own signal is fetched already, so the next signal fetch is of the one that carries Lena's comment.
    const lenasSignalFetch = pageFetchAfterNow(omar.page, key);

    await postComment(lena.page, reply);
    expect((await lenasSignalFetch).status()).toBe(200);

    // The thread's reload, had it not waited, would have started with that fetch.
    expect(threadLoads(requests, key).map((request) => request.url())).toEqual([]);
    await expect(editField).toHaveValue(editDraft);
    await expect(messageWithText(omar.page, reply)).toHaveCount(0);

    const requestsAfterClose = recordRequests(omar.page);
    const editToggle = ownMessage.locator('summary', { hasText: 'Edit' });
    await editToggle.click();
    await expect(editField).toBeHidden();

    await expect(messageWithText(omar.page, reply)).toHaveCount(1, { timeout: SHOWS_WITHIN_MS });
    // The end of the hold reloaded the thread alone; a later signal would have fetched the page with it.
    expect(threadLoads(requestsAfterClose, key)).toHaveLength(1);
    expect(pageFetches(requestsAfterClose, key).map((request) => request.url())).toEqual([]);
    // The reload replaced the Edit toggle Omar clicked; its copy has the focus.
    await expect(editToggle).toBeFocused();
    await lena.context.close();
    await omar.context.close();
  });

  test("the ping sheet Omar has open keeps its note: People waits until he closes it, then shows Lena's move", async ({ browser }) => {
    const { key, omar } = await openNewTicketAsOmar(browser, `Store picker forgets the town ${randomUUID().slice(0, 8)}`);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    await markThisLoad(omar.page);
    const note = `Could you check the town list ${randomUUID().slice(0, 8)}`;
    const sheet = omar.page.getByRole('dialog', { name: 'Ping Lena' });
    const lenasTurn = lenasPeopleRow(omar.page).locator('.people__turn');
    await expect(lenasTurn).toHaveCount(0);
    const pingButton = omar.page.getByRole('button', { name: 'Ping Lena' });
    await pingButton.click();
    await sheet.getByLabel('Message').fill(note);
    // Focus on a button of the sheet: only the open dialog holds People, not a field being typed in.
    await omar.page.keyboard.press('Tab');
    await expect(sheet.getByLabel('Message')).not.toBeFocused();

    await moveFromList(lena.page, 'Code review');

    // The state part comes from the same fetch as People, so once it shows the move People was held.
    await expect(omar.page.locator('#ticket-state .ticket__state')).toContainText('Code review', { timeout: COALESCED_SHOWS_WITHIN_MS });
    await expect(sheet).toBeVisible();
    await expect(sheet.getByLabel('Message')).toHaveValue(note);
    await expect(lenasTurn).toHaveCount(0);

    const requestsAfterClose = recordRequests(omar.page);
    await sheet.getByRole('button', { name: 'Cancel' }).click();
    // The sheet hands focus back to the Ping button inside People, which must not hold it.
    await expect(sheet).toBeHidden();

    await expect(lenasTurn).toHaveCount(1, { timeout: SHOWS_WITHIN_MS });
    // The end of the hold swapped People alone; a later signal would have reloaded the thread with it.
    expect(pageFetches(requestsAfterClose, key)).toHaveLength(1);
    expect(threadLoads(requestsAfterClose, key).map((request) => request.url())).toEqual([]);
    // The swap replaced the Ping button the sheet gave focus back to; its copy has the focus.
    await expect(pingButton).toBeFocused();
    await expectSameLoad(omar.page);
    await lena.context.close();
    await omar.context.close();
  });

  test("focus Omar moves elsewhere while the thread renders Lena's comment stays where he moved it", async ({ browser }) => {
    const { key, omar } = await openNewTicketAsOmar(browser, `Basket undo restores the wrong item ${randomUUID().slice(0, 8)}`);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    const ownMessage = await postOwnCommentAndSettle(omar.page, key, `Undo brings back the last item ${randomUUID().slice(0, 8)}`);
    const removeButton = ownMessage.getByRole('button', { name: 'Remove' });
    await removeButton.focus();
    await pauseNextThreadRender(omar.page);
    const reply = `It brings back the first one ${randomUUID().slice(0, 8)}`;

    await postComment(lena.page, reply);
    // The reload noted the focused Remove button as its render began; the render now waits.
    await threadRenderPaused(omar.page, COALESCED_SHOWS_WITHIN_MS);
    const backLink = omar.page.locator('.ticket__back');
    await backLink.focus();
    await resumeThreadRender(omar.page);

    await expect(messageWithText(omar.page, reply)).toHaveCount(1);
    await threadLoaded(omar.page);
    await nextTaskOf(omar.page);
    await expect(backLink).toBeFocused();
    await expect(removeButton).not.toBeFocused();
    await lena.context.close();
    await omar.context.close();
  });

  test("a button whose label Lena's move changes does not hand Omar's focus to the button now in its place", async ({ browser }) => {
    const { key, omar } = await openNewTicketAsOmar(browser, `Basket count skips gift cards ${randomUUID().slice(0, 8)}`);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    const omarsVerbs = omar.page.locator('#ticket-next-step .next-step-box__verbs').getByRole('button');
    await expect(omarsVerbs).toHaveText(['Start work']);
    await omarsVerbs.first().focus();

    await moveFromList(lena.page, 'In progress');

    // The swapped box has a button where Start work was, so a lookup by place alone would focus it.
    await expect(omarsVerbs.first()).toHaveText('Send to code review', { timeout: COALESCED_SHOWS_WITHIN_MS });
    await expect(omarsVerbs.first()).not.toBeFocused();
    expect(await omar.page.evaluate(() => document.activeElement === document.body)).toBe(true);
    await lena.context.close();
    await omar.context.close();
  });
});

test.describe("the signal of Omar's own change", () => {
  test("the page his comment form redirects to shows its toast, though his page fetched the signal's refresh first", async ({ browser }) => {
    const { key, omar } = await openNewTicketAsOmar(browser, `The receipt prints twice ${randomUUID().slice(0, 8)}`);
    const ownSignalFetch = pageFetchAfterNow(omar.page, key);
    // The comment's redirect reaches the browser only once the refresh its signal sends has its answer, so that answer
    // is read with the toast still in his session. A route sees no redirected request, so the redirect itself waits.
    await omar.page.route(`**/t/${key}/comments`, async (route) => {
      const redirect = await route.fetch({ maxRedirects: 0 });
      await (await ownSignalFetch).finished();
      await route.fulfill({ response: redirect });
    });
    const composer = threadFrame(omar.page).locator('form.thread__composer');
    await composer.evaluate((form) => form.setAttribute('data-turbo', 'false'));
    await composerBody(omar.page).fill(`Seen on the second till too ${randomUUID().slice(0, 8)}`);

    await composer.locator('button[type="submit"]').click();

    await omar.page.waitForURL(`**/t/${key}/thread`);
    expect((await ownSignalFetch).status()).toBe(200);
    await expect(omar.page.locator(TOAST_REGION)).toHaveText(COMMENT_ADDED);
    await omar.context.close();
  });
});

test.describe('a refetch that fails', () => {
  test("after Omar's session ended, Lena's comment sends his page to the login page with none of it shown", async ({ browser }) => {
    const { key, omar } = await openNewTicketAsOmar(browser, `Opening hours show the wrong day ${randomUUID().slice(0, 8)}`);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    const reply = `Sunday shows as Monday ${randomUUID().slice(0, 8)}`;
    // The open edit holds the thread, so only the page's fetch, not the frame's, can reach the login page.
    await holdThreadWithOpenEdit(omar.page, key, `Opening hours start a day late ${randomUUID().slice(0, 8)}`);
    // The open stream does not need the cookies again.
    await omar.context.clearCookies();

    await postComment(lena.page, reply);

    await expect(omar.page).toHaveURL(/\/login$/, { timeout: COALESCED_SHOWS_WITHIN_MS });
    await expect(omar.page.getByRole('button', { name: 'Log in' })).toBeVisible();
    await expect(omar.page.getByText(reply)).toHaveCount(0);
    await lena.context.close();
    await omar.context.close();
  });

  test("after Mia was taken out of the ticket's team, Lena's comment sends her page to the 404 page with none of the ticket shown", async ({ browser }) => {
    test.skip(
      !process.env.CI && !existsSync(MIA_STORAGE_STATE),
      'auth.setup.ts skipped Mia: her two-step login is on in this database. TicketPageTest proves a non-viewer gets 404.',
    );
    const title = `Delivery slot picker skips Sunday ${randomUUID().slice(0, 8)}`;
    const key = await makeTicketForOmar(browser, title);
    const move = giveToTeamOfOnlyMia(key);
    try {
      const mia = await openAs(browser, MIA_STORAGE_STATE);
      const lena = await openAs(browser, LENA_STORAGE_STATE);
      const creationSignalSettled = settleCreationSignal(mia.page, key);
      await openFollowedThread(mia.page, key);
      await creationSignalSettled;
      await expect(mia.page.getByRole('heading', { level: 1, name: title })).toBeVisible();
      await openThread(lena.page, key);
      await markThisLoad(mia.page);
      // The signal refetches the page and reloads the thread frame; each answer of 404 loads its own URL for real, and
      // whichever loads last is the page Mia sees.
      const refetchedPaths = [`/t/${key}`, `/t/${key}/thread`];
      const refetches = refetchedPaths.map((refetchedPath) =>
        mia.page.waitForResponse((response) => pathOf(response.request()) === refetchedPath && response.request().resourceType() === 'fetch'),
      );
      const documentStatuses: number[] = [];
      mia.page.on('response', (response) => {
        if (response.request().resourceType() === 'document') {
          documentStatuses.push(response.status());
        }
      });
      const comment = `Delivery date shows a day late ${randomUUID().slice(0, 8)}`;

      removeMiaFromTeam(move);
      await postComment(lena.page, comment);

      expect((await Promise.all(refetches)).map((response) => response.status())).toEqual([404, 404]);
      await expect.poll(() => documentStatuses.length).toBeGreaterThan(0);
      await mia.page.waitForLoadState('load');
      expect(documentStatuses.every((status) => status === 404), `Mia's tab loaded pages answered ${documentStatuses.join(', ')}`).toBe(true);
      await expect(mia.page).toHaveURL(new RegExp(`/t/${key}(/thread)?$`));
      await expect(mia.page.locator('html')).not.toHaveAttribute('data-e2e-load', 'first');
      await expect(mia.page.getByText(title)).toHaveCount(0);
      await expect(mia.page.getByText(comment)).toHaveCount(0);
      await expect(mia.page.locator('#ticket-state, turbo-frame#ticket-thread')).toHaveCount(0);
      await lena.context.close();
      await mia.context.close();
    } finally {
      putBackAndDropTeam(move);
    }
  });

  test('a page answer of 404 loads that page for real, with none of the change shown', async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Store map pin sits in the sea ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
    await openThread(lena.page, key);
    await markThisLoad(omar.page);
    // Kept in the tab's session storage, which the page loaded next can still read.
    await omar.page.evaluate((storageKey) => {
      const header = document.getElementById('ticket-state')?.parentElement;
      if (header === null || header === undefined) {
        throw new Error('The page has no ticket state.');
      }
      const stateText = () => document.getElementById('ticket-state')?.textContent ?? '';
      const firstText = stateText();
      new MutationObserver(() => {
        if (stateText() !== firstText) {
          sessionStorage.setItem(storageKey, stateText());
        }
      }).observe(header, { childList: true, subtree: true, characterData: true });
    }, STATE_CHANGE_KEY);
    // As TicketShowController answers a viewer who may no longer see the ticket (TicketPageTest proves the real 404),
    // in a page that happens to carry a part's id, so a swap from it before the page loads would show.
    await omar.page.route(
      (url) => url.pathname === `/t/${key}`,
      (route) =>
        route.fulfill({
          status: 404,
          contentType: 'text/html',
          body: '<!doctype html><title>Not found</title><h1>Page not found</h1><div id="ticket-state">Something broke</div>',
        }),
    );

    await moveFromList(lena.page, 'Code review');

    await expect(omar.page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible({ timeout: COALESCED_SHOWS_WITHIN_MS });
    await expect(omar.page).toHaveURL(new RegExp(`/t/${key}$`));
    await expect(omar.page.locator('html')).not.toHaveAttribute('data-e2e-load', 'first');
    // The ticket's state on the page before the 404 one never changed.
    expect(await omar.page.evaluate((storageKey) => sessionStorage.getItem(storageKey), STATE_CHANGE_KEY)).toBeNull();
    await lena.context.close();
    await omar.context.close();
  });

  test('a page answer of 500 leaves the page as it was', async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Store map zooms out on load ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
    await openThread(lena.page, key);
    await markThisLoad(omar.page);
    const omarsStatus = omar.page.locator('#ticket-state .ticket__state');
    await expect(omarsStatus).toContainText('To do');
    // An error page that happens to carry a part's id, so a swap from it would show.
    const failedFetch = omar.page.waitForResponse((response) => pathOf(response.request()) === `/t/${key}` && response.status() === 500);
    await omar.page.route(
      (url) => url.pathname === `/t/${key}`,
      (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '<!doctype html><title>Error</title><div id="ticket-state">Something broke</div>' }),
      { times: 1 },
    );

    await moveFromList(lena.page, 'Code review');
    await (await failedFetch).finished();
    await nextTaskOf(omar.page);

    await expect(omarsStatus).toContainText('To do');
    await expect(omar.page.getByText('Something broke')).toHaveCount(0);
    await expectSameLoad(omar.page);
    await lena.context.close();
    await omar.context.close();
  });
});

test.describe('a lost stream', () => {
  test.use({ storageState: OMAR_STORAGE_STATE, viewport: { width: 1280, height: 800 }, colorScheme: 'light' });

  test('after 60 s without the hub the page says updates are paused and links a reload of itself', async ({ browser, page }) => {
    const key = await makeTicketForOmar(browser, `Basket total rounds down ${randomUUID().slice(0, 8)}`);
    await page.clock.install();
    await page.route(`**${HUB_PATH}**`, (route) => route.abort());
    await page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    const banner = page.locator('.live-updates__paused');
    await expect(banner).toBeHidden();

    // live-updates_controller.js PAUSED_AFTER_MS
    await page.clock.runFor(60_000);

    await expect(banner).toBeVisible();
    await expect(banner).toHaveText('Updates paused. Reload to see the latest.');
    // The region was there, empty, before the line showed in it, so the line is announced as it appears.
    await expect(page.locator('.live-updates')).toHaveRole('status');
    await expect(page.getByRole('status').filter({ hasText: 'Updates paused.' })).toHaveCount(1);
    // Above the page it follows, aligned with main's content.
    const [bannerBox, mainBox] = [await banner.boundingBox(), await page.locator('main#main').boundingBox()];
    expect(bannerBox !== null && mainBox !== null && bannerBox.y + bannerBox.height <= mainBox.y).toBe(true);
    expect(bannerBox?.x).toBe((mainBox?.x ?? 0) + 24);
    // --waiting-container and --on-waiting-container of the light theme (tokens.css)
    await expect(banner).toHaveCSS('background-color', 'rgb(255, 224, 136)');
    await expect(banner).toHaveCSS('color', 'rgb(36, 26, 0)');
    await expect(banner.getByRole('link', { name: 'Reload' })).toHaveAttribute('href', page.url());
  });

  test('a dropped stream is opened again after a random wait of half to all of its 5 s step', async ({ browser, page }) => {
    const key = await makeTicketForOmar(browser, `Basket shows an old price ${randomUUID().slice(0, 8)}`);
    // The lowest draw, so the wait is half the step: a fixed 5 s, or a draw from 0, would miss the bounds below.
    await page.addInitScript(() => {
      Math.random = () => 0;
    });
    const streamAskedAtMs: number[] = [];
    await page.route(`**${HUB_PATH}**`, (route) => {
      streamAskedAtMs.push(Date.now());
      return route.abort();
    });

    await page.goto(`/t/${key}`);

    await expect.poll(() => streamAskedAtMs.length, { timeout: 5000 + SHOWS_WITHIN_MS }).toBeGreaterThanOrEqual(2);
    const waitedMs = streamAskedAtMs[1] - streamAskedAtMs[0];
    // live-updates_controller.js FIRST_REOPEN_STEP_MS / 2, plus the cookie ask before the stream.
    expect(waitedMs).toBeGreaterThanOrEqual(2500);
    expect(waitedMs).toBeLessThan(4500);
  });
});

// The tabs of one browser share one subscriber cookie, and each page asks for it with its own topics: Omar's home page
// asks for one with no ticket in it. The hub reads the cookie only as a stream connects.
test.describe('two tabs of one browser', () => {
  test("Omar's ticket tab reconnects with its own cookie after his home tab asked for one without the ticket", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Gift card balance shows twice ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const homeTab = await omar.context.newPage();
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    const ticketTabStreams: Response[] = [];
    omar.page.on('response', (response) => {
      if (pathOf(response.request()) === HUB_PATH) {
        ticketTabStreams.push(response);
      }
    });
    let streamHeld: () => void = () => undefined;
    const isStreamHeld = new Promise<void>((resolve) => {
      streamHeld = resolve;
    });
    let homeTabAsked: () => void = () => undefined;
    const hasHomeTabAsked = new Promise<void>((resolve) => {
      homeTabAsked = resolve;
    });
    // The first stream ends as soon as it is answered, as a dropped connection does, and only once the home tab's
    // cookie is the one the browser holds; its retry would have the browser reconnect after 100 ms.
    await omar.page.route(
      `**${HUB_PATH}**`,
      async (route) => {
        streamHeld();
        await hasHomeTabAsked;
        await route.fulfill({ status: 200, contentType: 'text/event-stream', body: 'retry: 100\n\n' });
      },
      { times: 1 },
    );

    const ticketTabOpened = openThread(omar.page, key);
    await isStreamHeld;
    const homeTabCookie = homeTab.waitForResponse((response) => pathOf(response.request()) === TOKEN_PATH);
    await homeTab.goto('/');
    expect((await homeTabCookie).status()).toBe(204);
    homeTabAsked();
    await ticketTabOpened;
    // live-updates_controller.js FIRST_REOPEN_STEP_MS, then the hub's answer.
    await expect.poll(() => ticketTabStreams.length, { timeout: 5000 + SHOWS_WITHIN_MS }).toBe(2);
    expect(ticketTabStreams[1].status()).toBe(200);
    const text = `Still following after the drop ${randomUUID().slice(0, 8)}`;

    await postComment(lena.page, text);

    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: COALESCED_SHOWS_WITHIN_MS });
    await lena.context.close();
    await omar.context.close();
  });

  test("a tab Omar opens while his ticket tab connects asks for its cookie only once that tab's stream has connected", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Gift card expiry date is blank ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const homeTab = await omar.context.newPage();
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openThread(lena.page, key);
    const order: string[] = [];
    omar.page.on('response', (response) => {
      if (pathOf(response.request()) === HUB_PATH) {
        order.push('ticket tab stream answered');
      }
    });
    homeTab.on('request', (request) => {
      if (pathOf(request) === TOKEN_PATH) {
        order.push('home tab asked');
      }
    });
    let streamHeld: () => void = () => undefined;
    const isStreamHeld = new Promise<void>((resolve) => {
      streamHeld = resolve;
    });
    // Held until the home tab asks for its cookie, or for 3 s when it waits, as it should, for this stream to connect.
    await omar.page.route(
      `**${HUB_PATH}**`,
      async (route) => {
        streamHeld();
        try {
          await homeTab.waitForRequest((request) => pathOf(request) === TOKEN_PATH, { timeout: 3000 });
        } catch (error) {
          if (!(error instanceof errors.TimeoutError)) {
            throw error;
          }
        }
        await route.continue();
      },
      { times: 1 },
    );

    const ticketTabOpened = openThread(omar.page, key);
    await isStreamHeld;
    const homeTabCookie = homeTab.waitForResponse((response) => pathOf(response.request()) === TOKEN_PATH);
    await homeTab.goto('/');
    expect((await homeTabCookie).status()).toBe(204);
    await ticketTabOpened;

    expect(order).toEqual(['ticket tab stream answered', 'home tab asked']);
    const text = `Still following beside the home tab ${randomUUID().slice(0, 8)}`;
    await postComment(lena.page, text);
    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: COALESCED_SHOWS_WITHIN_MS });
    await lena.context.close();
    await omar.context.close();
  });

  test("a cookie ask of Omar's ticket tab that never answers lets his home tab ask once it gives up", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Gift card form hangs on submit ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const homeTab = await omar.context.newPage();
    let askHeld: () => void = () => undefined;
    const isAskHeld = new Promise<void>((resolve) => {
      askHeld = resolve;
    });
    let releaseAsk: () => void = () => undefined;
    const isAskReleased = new Promise<void>((resolve) => {
      releaseAsk = resolve;
    });
    // Unanswered until the test ends, as a token route that hangs would leave it; the tab gave up on it by then.
    await omar.page.route(
      `**${TOKEN_PATH}`,
      async (route) => {
        askHeld();
        await isAskReleased;
        await route.abort().catch(() => undefined);
      },
      { times: 1 },
    );
    try {
      await omar.page.goto(`/t/${key}`);
      await isAskHeld;
      const homeTabCookie = homeTab.waitForResponse((response) => pathOf(response.request()) === TOKEN_PATH, {
        // live-updates_controller.js MAX_CONNECT_WAIT_MS for the ask, then as long for the stream without a cookie.
        timeout: 2 * 5000 + SHOWS_WITHIN_MS,
      });

      await homeTab.goto('/');

      expect((await homeTabCookie).status()).toBe(204);
    } finally {
      releaseAsk();
      await omar.context.close();
    }
  });
});

test.describe('a stream that reconnects', () => {
  test("Lena's comment sent while Omar's stream was down shows once it reconnects", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Basket total skips the voucher ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    const creationSignalSettled = settleCreationSignal(lena.page, key);
    await openFollowedThread(lena.page, key);
    await creationSignalSettled;
    const omarsStreams: Response[] = [];
    omar.page.on('response', (response) => {
      if (pathOf(response.request()) === HUB_PATH) {
        omarsStreams.push(response);
      }
    });
    let lenasSignalSent: () => void = () => undefined;
    const isLenasSignalSent = new Promise<void>((resolve) => {
      lenasSignalSent = resolve;
    });
    let streamAsks = 0;
    // The first stream ends as soon as it is answered, as a dropped connection does; the one that reopens it waits
    // until the hub has sent the signal of Lena's comment, so Omar's page never hears it.
    await omar.page.route(`**${HUB_PATH}**`, async (route) => {
      streamAsks += 1;
      if (streamAsks === 1) {
        await route.fulfill({ status: 200, contentType: 'text/event-stream', body: 'retry: 100\n\n' });
        return;
      }
      await isLenasSignalSent;
      await route.continue();
    });
    await openThread(omar.page, key);
    await expect.poll(() => omarsStreams.length).toBe(1);
    const text = `The voucher drops off at checkout ${randomUUID().slice(0, 8)}`;
    // Lena's page follows the ticket too, so its fetch proves the hub sent the signal.
    const lenasSignalFetch = pageFetchAfterNow(lena.page, key);

    await postComment(lena.page, text);
    expect((await lenasSignalFetch).status()).toBe(200);
    await expect(messageWithText(omar.page, text)).toHaveCount(0);
    lenasSignalSent();

    // live-updates_controller.js FIRST_REOPEN_STEP_MS at most, then the hub's answer.
    await expect.poll(() => omarsStreams.length, { timeout: 5000 + SHOWS_WITHIN_MS }).toBe(2);
    expect(omarsStreams[1].status()).toBe(200);
    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: SHOWS_WITHIN_MS });
    await lena.context.close();
    await omar.context.close();
  });
});

test.describe('the stream across Turbo visits', () => {
  test('a visit back to the same ticket keeps the stream and the cookie; a visit to another ticket opens a new stream', async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Saved basket loses its note ${randomUUID().slice(0, 8)}`);
    const otherKey = await makeTicketForOmar(browser, `Saved basket loses its date ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    const requests = recordRequests(omar.page);
    await openFollowedThread(omar.page, key);
    await openThread(lena.page, key);
    expect(tokenAsks(requests)).toHaveLength(1);
    expect(hubStreams(requests)).toHaveLength(1);

    await turboVisit(omar.page, `/t/${key}`);

    expect(tokenAsks(requests)).toHaveLength(1);
    expect(hubStreams(requests)).toHaveLength(1);
    await omar.page.getByRole('heading', { level: 2, name: 'Questions and comments' }).scrollIntoViewIfNeeded();
    const text = `Still following after the visit ${randomUUID().slice(0, 8)}`;
    await postComment(lena.page, text);
    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: COALESCED_SHOWS_WITHIN_MS });
    expect(tokenAsks(requests)).toHaveLength(1);
    expect(hubStreams(requests)).toHaveLength(1);

    const newStream = omar.page.waitForResponse((response) => pathOf(response.request()) === HUB_PATH);
    await turboVisit(omar.page, `/t/${otherKey}`);
    expect((await newStream).status()).toBe(200);

    expect(tokenAsks(requests)).toHaveLength(2);
    expect(hubStreams(requests)).toHaveLength(2);
    await expect(omar.page.locator('[data-controller="live-updates"]')).toHaveCount(1);
    await lena.context.close();
    await omar.context.close();
  });
});

/** The badge's count on the open page, 0 when it shows none. */
async function badgeCountOf(page: Page): Promise<number> {
  const spokenCount = page.locator('#bell-badge .visually-hidden');
  if ((await spokenCount.count()) === 0) {
    return 0;
  }
  return Number(/^(\d+) open$/.exec((await spokenCount.innerText()).trim())?.[1] ?? Number.NaN);
}

/** The badge's count as the server renders it now, from a fresh Home page in the page's session. */
async function serverBadgeCountOf(page: Page): Promise<number> {
  const home = await page.request.get('/');
  expect(home.status()).toBe(200);
  const spokenCount = await page.evaluate(
    (html) => new DOMParser().parseFromString(html, 'text/html').querySelector('#bell-badge .visually-hidden')?.textContent ?? null,
    await home.text(),
  );
  return spokenCount === null ? 0 : Number(/^(\d+) open$/.exec(spokenCount.trim())?.[1] ?? Number.NaN);
}

test.describe("the bell's push", () => {
  test("Mia's ping shows on Omar's badge within 3 s, and his open popover loads again with her card, without a reload", async ({ browser }) => {
    test.skip(
      !process.env.CI && !existsSync(MIA_STORAGE_STATE),
      'auth.setup.ts skipped Mia: her two-step login is on in this database. RefreshBellHandlerTest proves the push.',
    );
    // Lena giving Omar the ticket notifies him too; its push may come at any time before Mia's, so the badge is
    // compared with the server's count once Mia's card shows rather than with a count read before her ping.
    const key = await makeTicketForOmar(browser, `Gift card balance shows twice ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const streamOpened = omar.page.waitForResponse((response) => pathOf(response.request()) === HUB_PATH);
    await omar.page.goto('/', { waitUntil: 'networkidle' });
    expect((await streamOpened).status()).toBe(200);
    await openBellPopover(omar.page);
    await markThisLoad(omar.page);
    const miasCard = bellPopover(omar.page).getByRole('article', { name: `Mia pinged you on ${key}` });
    await expect(miasCard).toHaveCount(0);
    const mia = await openAs(browser, MIA_STORAGE_STATE);
    await mia.page.goto(`/t/${key}`, { waitUntil: 'networkidle' });
    await mia.page.getByRole('button', { name: 'Ping Omar' }).click();
    const sheet = mia.page.getByRole('dialog', { name: 'Ping Omar' });
    const message = `Can you check the gift card total? ${randomUUID().slice(0, 8)}`;
    await sheet.getByLabel('Message').fill(message);
    const pingAnswer = mia.page.waitForResponse((response) => response.url().includes(`/t/${key}/ping/`) && response.request().method() === 'POST');

    await sheet.getByRole('button', { name: 'Send ping' }).click();

    expect((await pingAnswer).status()).toBe(200);
    await expect(miasCard).toBeVisible({ timeout: SHOWS_WITHIN_MS });
    await expect(miasCard.getByText(`“${message}”`)).toBeVisible();
    await expect(bellButton(omar.page)).toHaveAttribute('aria-expanded', 'true');
    const serverCount = await serverBadgeCountOf(omar.page);
    expect(serverCount, "the server counts Mia's ping").toBeGreaterThan(0);
    await expect.poll(() => badgeCountOf(omar.page)).toBe(serverCount);
    await expectSameLoad(omar.page);
    await mia.context.close();
    await omar.context.close();
  });
});
