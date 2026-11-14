import { expect, test, type Locator, type Page, type Request, type Response } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { composerBody, makeTicketForOmar, openAs, openThread, threadFrame, TOAST_REGION } from './ticket';

// ThreadController::COMMENT_ADDED, MESSAGE_EDITED, MESSAGE_REMOVED
const COMMENT_ADDED = 'Comment added.';
const MESSAGE_EDITED = 'Message edited.';
const MESSAGE_REMOVED = 'Message removed.';
// phase-04-conversation-and-pings.md, Definition of done: a change in one browser shows in the other within 3 s.
const SHOWS_WITHIN_MS = 3000;
// RefreshTicketPagesHandler::WINDOW_MILLISECONDS: a change within 2 s of the last signal (the ticket's own creation counts) is
// try runs one window later, so it may show that much later.
const COALESCED_SHOWS_WITHIN_MS = 2000 + SHOWS_WITHIN_MS;
const TOKEN_PATH = '/realtime/token';
const HUB_PATH = '/.well-known/mercure';
const STATE_CHANGE_KEY = 'e2e-ticket-state-change';

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
 * Omar comments, opens the edit form of his comment and types in it, then clicks away: the open edit holds the thread.
 * It first waits until the signal of his own comment has been fetched and the thread it reloaded is shown, so the next
 * signal fetch is of someone else's change.
 */
async function holdThreadWithOpenEdit(page: Page, key: string, draft: string): Promise<{ message: Locator; editField: Locator }> {
  const ownText = `A note to edit later ${randomUUID().slice(0, 8)}`;
  const ownRefresh = page.waitForResponse((response) => pathOf(response.request()) === `/t/${key}/thread`);
  const ownSignalFetch = pageFetchAfterNow(page, key);
  await postComment(page, ownText);
  await ownRefresh;
  expect((await ownSignalFetch).status()).toBe(200);
  await threadLoaded(page);
  const message = messageWithText(page, ownText);
  await message.getByText('Edit', { exact: true }).click();
  const editField = message.getByLabel('Edit your message');
  await editField.fill(draft);
  await page.getByRole('heading', { level: 2, name: 'Questions and comments' }).click();
  await expect(editField).not.toBeFocused();
  return { message, editField };
}

/** The answer to the next fetch of the ticket page that starts from now on, which only a signal sends. */
async function pageFetchAfterNow(page: Page, key: string): Promise<Response> {
  const request = await page.waitForRequest((sent) => pathOf(sent) === `/t/${key}` && sent.resourceType() === 'fetch');
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

/** The fetches of the ticket page in requests, which only a signal sends. */
function pageFetches(requests: Request[], key: string): Request[] {
  return requests.filter((request) => pathOf(request) === `/t/${key}` && request.resourceType() === 'fetch');
}

function threadLoads(requests: Request[], key: string): Request[] {
  return requests.filter((request) => pathOf(request) === `/t/${key}/thread`);
}

/** Resolves once the page has run a task queued after now, so the script's work on an answer already read is done. */
async function nextTaskOf(page: Page): Promise<void> {
  await page.evaluate(() => new Promise<void>((resolve) => setTimeout(resolve, 0)));
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
    const key = await makeTicketForOmar(browser, `Gift wrap price shows twice ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
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
    const key = await makeTicketForOmar(browser, `Store picker forgets the town ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
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
});

test.describe('a refetch that fails', () => {
  test("after Omar's session ended, Lena's comment sends his page to the login page with none of it shown", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Opening hours show the wrong day ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
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
    await expect(banner).toHaveRole('status');
    // --waiting-container and --on-waiting-container of the light theme (tokens.css)
    await expect(banner).toHaveCSS('background-color', 'rgb(255, 224, 136)');
    await expect(banner).toHaveCSS('color', 'rgb(36, 26, 0)');
    await expect(banner.getByRole('link', { name: 'Reload' })).toHaveAttribute('href', page.url());
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
