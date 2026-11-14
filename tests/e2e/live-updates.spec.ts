import { expect, test, type Page, type Request } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { composerBody, makeTicketForOmar, openAs, openThread, threadFrame, TOAST_REGION } from './ticket';

// ThreadController::COMMENT_ADDED, MESSAGE_EDITED, MESSAGE_REMOVED
const COMMENT_ADDED = 'Comment added.';
const MESSAGE_EDITED = 'Message edited.';
const MESSAGE_REMOVED = 'Message removed.';
// phase-04-conversation-and-pings.md, Definition of done: a change in one browser shows in the other within 3 s.
const SHOWS_WITHIN_MS = 3000;
// RefreshTicketPagesHandler::WINDOW_MILLISECONDS: a change within 2 s of the last signal is published when a delayed
// try runs one window later, so it may show that much later.
const COALESCED_SHOWS_WITHIN_MS = 2000 + SHOWS_WITHIN_MS;
const TOKEN_PATH = '/realtime/token';
const HUB_PATH = '/.well-known/mercure';

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

    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: SHOWS_WITHIN_MS });
    await expect(composerBody(omar.page)).toHaveValue(draft);
    await lena.context.close();
    await omar.context.close();
  });

  test("an edit Omar left open keeps its text: the thread waits until he closes it, then shows Lena's comments", async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Gift wrap price shows twice ${randomUUID().slice(0, 8)}`);
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    await openFollowedThread(omar.page, key);
    await openThread(lena.page, key);
    const ownText = `Gift wrap adds its price to the total ${randomUUID().slice(0, 8)}`;
    const editDraft = `Gift wrap adds its price twice ${randomUUID().slice(0, 8)}`;
    const firstReply = `Only on the basket page ${randomUUID().slice(0, 8)}`;
    const secondReply = `And on the order email ${randomUUID().slice(0, 8)}`;
    const ownRefresh = omar.page.waitForResponse((response) => pathOf(response.request()) === `/t/${key}/thread`);
    await postComment(omar.page, ownText);
    await ownRefresh;
    const ownMessage = messageWithText(omar.page, ownText);
    await ownMessage.getByText('Edit', { exact: true }).click();
    await ownMessage.getByLabel('Edit your message').fill(editDraft);
    await omar.page.getByRole('heading', { level: 2, name: 'Questions and comments' }).click();
    await expect(ownMessage.getByLabel('Edit your message')).not.toBeFocused();
    const requests = recordRequests(omar.page);
    // A signal fetches the page for its marked parts even when the thread waits, after the thread's own reload would start.
    const signalHandled = omar.page.waitForResponse((response) => pathOf(response.request()) === `/t/${key}` && response.request().resourceType() === 'fetch');

    await postComment(lena.page, firstReply);
    await signalHandled;

    expect(requests.filter((request) => pathOf(request) === `/t/${key}/thread`).map((request) => request.url())).toEqual([]);
    await expect(ownMessage.getByLabel('Edit your message')).toHaveValue(editDraft);
    await expect(messageWithText(omar.page, firstReply)).toHaveCount(0);

    await ownMessage.getByText('Edit', { exact: true }).click();
    await expect(ownMessage.getByLabel('Edit your message')).toBeHidden();
    await postComment(lena.page, secondReply);

    await expect(messageWithText(omar.page, firstReply)).toHaveCount(1);
    await expect(messageWithText(omar.page, secondReply)).toHaveCount(1);
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
    await expect(messageWithText(omar.page, text)).toHaveCount(1, { timeout: SHOWS_WITHIN_MS });
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
