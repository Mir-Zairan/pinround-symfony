import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { composerBody, makeTicketForOmar, openAs, openThread, threadFrame, TOAST_REGION } from './ticket';

// The Mercure hub's path, as in live-updates.spec.ts.
const HUB_PATH = '/.well-known/mercure';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// ThreadController::COMMENT_ADDED, MESSAGE_EDITED, MESSAGE_REMOVED, REPLY_ADDED and COMMENT_RESOLVED
const COMMENT_ADDED = 'Comment added.';
const MESSAGE_EDITED = 'Message edited.';
const MESSAGE_REMOVED = 'Message removed.';
const REPLY_ADDED = 'Reply added.';
const COMMENT_RESOLVED = 'Resolved. It folds away for everyone.';
// Thread::REPLY_PLACEHOLDER, REPLY_NOTE for Omar's message and REPLY_NOTE_ON_YOUR_OWN
const REPLY_PLACEHOLDER = 'Write a reply';
const REPLY_NOTE_TO_OMAR = 'Replies stay under this message. Omar gets one notification.';
const REPLY_NOTE_ON_YOUR_OWN = 'Replies stay under this message.';
// ThreadController::FORM_EXPIRED
const FORM_EXPIRED = 'This page was open too long. Reload it and try again.';
// --space-12, the column gap of .ticket__section that the frame and Thread's wrappers carry on
const SECTION_GAP_PX = 12;
// Thread::EMPTY_THREAD and ThreadFrame::SHOW_THREAD, the frame's link before it loads
const EMPTY_THREAD = 'Nothing yet. Ask a question when you need someone’s answer, or leave a comment.';
const SHOW_THREAD = 'Show questions and comments';

// Lena comments on a SHOP ticket she makes in each test, so every test starts from an empty thread and a rerun never
// meets an earlier run's messages. tests/Functional/Conversation proves who may comment, edit and remove on the server.
test.use({ storageState: LENA_STORAGE_STATE, viewport: { width: 1280, height: 800 } });

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).include('#ticket-thread').withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

/** As Lena, who leads Shop: a new SHOP ticket with a title no earlier run used. Returns its key. */
async function makeTicket(page: Page): Promise<string> {
  await page.goto('/tickets/new');
  const shopValue = await page.locator('select[name="project_id"] option', { hasText: /^SHOP · / }).getAttribute('value');
  expect(shopValue, 'the seeded SHOP project is missing: run make seed').not.toBeNull();
  await page.getByLabel('Project').selectOption(shopValue ?? '');
  await page.getByLabel('Title', { exact: true }).fill(`Talk about the basket ${randomUUID().slice(0, 8)}`);
  await page.getByLabel('Give it to').selectOption({ label: 'Omar' });
  await page.getByRole('button', { name: 'Make ticket' }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+$/);
  const key = new URL(page.url()).pathname.replace('/t/', '');
  await expect(page.locator(TOAST_REGION)).toHaveText(`Ticket ${key} made.`);
  return key;
}

function commentButton(page: Page) {
  // The "Comment" kind chip above the text has the same name; the submit button is the last control of the form.
  return threadFrame(page).locator('form.thread__composer button[type="submit"]');
}

// The message's own body: a reply's body sits deeper, so a reply that quotes the text never matches.
function messageWithText(page: Page, text: string) {
  return threadFrame(page).locator('li[data-message-id]').filter({ has: page.locator('.thread__message-main > .thread__body', { hasText: text }) });
}

async function postComment(page: Page, text: string): Promise<void> {
  await composerBody(page).fill(text);
  await commentButton(page).click();
  await expect(page.locator(TOAST_REGION)).toHaveText(COMMENT_ADDED);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
}

test.describe('the thread of a new ticket', () => {
  let key = '';

  test.beforeEach(async ({ page }) => {
    key = await makeTicket(page);
  });

  test('loads in the lazy frame of the ticket page', async ({ page }) => {
    await openThread(page, key);

    await expect(threadFrame(page)).toHaveAttribute('loading', 'lazy');
    await expect(threadFrame(page).getByText(SHOW_THREAD)).toHaveCount(0);
    await expect(threadFrame(page).getByText(EMPTY_THREAD)).toBeVisible();
    await expect(threadFrame(page).locator('li[data-message-id]')).toHaveCount(0);
    await expect(threadFrame(page).getByLabel('Write a comment')).toBeVisible();
  });

  test('the list and the composer keep the section gap inside the frame', async ({ page }) => {
    await openThread(page, key);
    await postComment(page, 'Spacing check');

    const content = threadFrame(page).locator('.thread__content');
    for (const part of [content, content.locator('.thread__list')]) {
      expect(await part.evaluate((element) => getComputedStyle(element).rowGap)).toBe(`${SECTION_GAP_PX}px`);
    }
    const list = await content.locator('.thread__list').boundingBox();
    const composer = await content.locator('form.thread__composer').boundingBox();
    expect(list).not.toBeNull();
    expect(composer).not.toBeNull();
    expect((composer?.y ?? 0) - ((list?.y ?? 0) + (list?.height ?? 0))).toBeCloseTo(SECTION_GAP_PX, 0);
  });

  test('Comment is disabled while the text is empty or only spaces', async ({ page }) => {
    await openThread(page, key);

    await expect(commentButton(page)).toHaveText('Comment');
    await expect(commentButton(page)).toBeDisabled();
    await composerBody(page).fill('   \n  ');
    await expect(commentButton(page)).toBeDisabled();
    await composerBody(page).fill('Looks right to me');
    await expect(commentButton(page)).toBeEnabled();
    await composerBody(page).fill('');
    await expect(commentButton(page)).toBeDisabled();
  });

  test('posting shows the comment and the toast and puts focus back in an empty composer', async ({ page }) => {
    const text = `The basket total adds tax twice ${randomUUID().slice(0, 8)}`;
    await openThread(page, key);

    await postComment(page, text);

    await expect(messageWithText(page, text)).toHaveCount(1);
    await expect(messageWithText(page, text).locator('.thread__meta')).toContainText('Lena');
    await expect(composerBody(page)).toBeFocused();
    await expect(composerBody(page)).toHaveValue('');
    await expect(commentButton(page)).toBeDisabled();
  });

  test('a refused comment keeps its text, shows the toast and puts focus back in the composer', async ({ page }) => {
    const text = `Kept after a refusal ${randomUUID().slice(0, 8)}`;
    await openThread(page, key);
    // An expired form is the refusal a browser can cause; tests/Functional/Conversation covers the others, which
    // answer with the same 422 Turbo Stream.
    await threadFrame(page).locator('form.thread__composer input[name="_csrf_token"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'expired';
    });
    await composerBody(page).fill(text);
    const answer = page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/comments` && response.request().method() === 'POST');

    await commentButton(page).click();

    expect((await answer).status()).toBe(422);
    await expect(page.locator(TOAST_REGION)).toHaveText(FORM_EXPIRED);
    await expect(composerBody(page)).toHaveValue(text);
    await expect(composerBody(page)).toBeFocused();
    await expect(commentButton(page)).toBeEnabled();
    await expect(threadFrame(page).locator('li[data-message-id]')).toHaveCount(0);
  });

  test('Save on an edit and Remove each put focus on the changed message', async ({ page }) => {
    const text = `First thought ${randomUUID().slice(0, 8)}`;
    const editedText = `Second thought ${randomUUID().slice(0, 8)}`;
    await openThread(page, key);
    await postComment(page, text);
    const messageId = await messageWithText(page, text).getAttribute('data-message-id');
    expect(messageId).not.toBeNull();
    const message = threadFrame(page).locator(`li[data-message-id="${messageId}"]`);

    await message.getByText('Edit', { exact: true }).click();
    await message.getByLabel('Edit your message').fill(editedText);
    await message.getByRole('button', { name: 'Save' }).click();

    await expect(page.locator(TOAST_REGION)).toHaveText(MESSAGE_EDITED);
    await expect(message.locator('.thread__body')).toHaveText(editedText);
    await expect(message.locator('.thread__edited')).toHaveText('edited');
    await expect(message).toBeFocused();

    await message.getByRole('button', { name: 'Remove' }).click();

    await expect(page.locator(TOAST_REGION)).toHaveText(MESSAGE_REMOVED);
    await expect(message.locator('.thread__body')).toHaveText('Message removed');
    await expect(message).toBeFocused();
  });

  test('a refused edit keeps its text in the open Edit box, shows the toast and puts focus back in the text', async ({ page }) => {
    const text = `Before the edit ${randomUUID().slice(0, 8)}`;
    const editedText = `Kept after a refused edit ${randomUUID().slice(0, 8)}`;
    await openThread(page, key);
    await postComment(page, text);
    const box = messageWithText(page, text).locator('details.thread__edit');
    await box.locator('summary').click();
    await expect(box.getByLabel('Edit your message')).toBeFocused();
    // An expired form is the refusal a browser can cause; tests/Functional/Conversation covers the others.
    await box.locator('input[name="_csrf_token"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'expired';
    });
    await box.getByLabel('Edit your message').fill(editedText);
    const answer = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/edit') && response.request().method() === 'POST');

    await box.getByRole('button', { name: 'Save' }).click();

    expect((await answer).status()).toBe(422);
    await expect(page.locator(TOAST_REGION)).toHaveText(FORM_EXPIRED);
    await expect(box).toHaveAttribute('open');
    await expect(box.getByLabel('Edit your message')).toHaveValue(editedText);
    await expect(box.getByLabel('Edit your message')).toBeFocused();
    await expect(messageWithText(page, text).locator('.thread__body')).toHaveText(text);
  });

  test('a comment that holds a script shows as text and runs nothing', async ({ page }) => {
    const script = '<script>alert(1)</script>';
    const dialogs: string[] = [];
    page.on('dialog', async (dialog) => {
      dialogs.push(dialog.message());
      await dialog.dismiss();
    });
    await openThread(page, key);

    await postComment(page, script);
    await expect(messageWithText(page, script).locator('.thread__body')).toHaveText(script);
    await openThread(page, key);

    await expect(messageWithText(page, script).locator('.thread__body')).toHaveText(script);
    await expect(threadFrame(page).locator('.thread__body script')).toHaveCount(0);
    expect(dialogs).toEqual([]);
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`the thread with one comment has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await openThread(page, key);
      await postComment(page, 'Checked on a phone too');
      await expect(threadFrame(page).locator('li[data-message-id]')).toHaveCount(1);

      expect(await seriousViolations(page)).toEqual([]);
    });
  }
});

test.describe('the thread at 360 px', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('a long unbroken link with its edit form open scrolls nothing sideways, in the frame and on the thread page', async ({ page }) => {
    const key = await makeTicket(page);
    // Letters only after the last slash: a hyphen or a slash would be a place to break the line without any CSS.
    const longLink = `https://shop.example.com/basket/${'taxtwice'.repeat(40)}${randomUUID().replaceAll('-', '')}`;
    await openThread(page, key);
    await postComment(page, longLink);
    const message = messageWithText(page, longLink);
    await expect(message.locator('.thread__body a')).toHaveCount(1);

    await message.getByText('Edit', { exact: true }).click();

    await expect(message.getByLabel('Edit your message')).toBeVisible();
    await expectNoSidewaysScroll(page);

    await page.goto(`/t/${key}/thread`);
    const pageMessage = messageWithText(page, longLink);
    await pageMessage.getByText('Edit', { exact: true }).click();

    await expect(pageMessage.getByLabel('Edit your message')).toBeVisible();
    await expectNoSidewaysScroll(page);
  });
});

function replyBox(page: Page, text: string) {
  return messageWithText(page, text).locator('details.thread__reply-box');
}

/** On an opened thread: replies to the message that holds the text through its Reply box. */
async function replyTo(page: Page, key: string, text: string, reply: string): Promise<void> {
  const box = replyBox(page, text);
  await box.locator(':scope > summary').click();
  await box.getByLabel(REPLY_PLACEHOLDER).fill(reply);
  const replied = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/replies') && response.request().method() === 'POST');

  await box.getByRole('button', { name: 'Reply', exact: true }).click();

  expect(new URL((await replied).url()).pathname).toMatch(new RegExp(`^/t/${key}/messages/[0-9a-f-]+/replies$`));
  await expect(page.locator(TOAST_REGION)).toHaveText(REPLY_ADDED);
}

/** On an opened thread: resolves the comment that holds the text. */
async function resolve(page: Page, text: string): Promise<void> {
  await messageWithText(page, text).locator('.thread__resolve-form').getByRole('button', { name: 'Resolve' }).click();
  await expect(page.locator(TOAST_REGION)).toHaveText(COMMENT_RESOLVED);
}

function foldOf(page: Page, text: string) {
  return messageWithText(page, text).locator('details.thread__fold');
}

test('Lena replies to Omar’s comment, and the reply shows under it with no actions of its own', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Basket count lags one item ${randomUUID().slice(0, 8)}`);
  const comment = `Seen after adding two items fast ${randomUUID().slice(0, 8)}`;
  const reply = `Only on the phone app ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await postComment(omar.page, comment);
    await openThread(lena.page, key);
    const box = replyBox(lena.page, comment);
    await expect(box.locator(':scope > summary')).toHaveText('Reply');

    await box.locator(':scope > summary').click();

    await expect(box.getByLabel(REPLY_PLACEHOLDER)).toBeFocused();
    await expect(box.locator('.thread__note')).toHaveText(REPLY_NOTE_TO_OMAR);
    await box.getByLabel(REPLY_PLACEHOLDER).fill(reply);
    await box.getByRole('button', { name: 'Reply', exact: true }).click();
    await expect(lena.page.locator(TOAST_REGION)).toHaveText(REPLY_ADDED);

    const message = messageWithText(lena.page, comment);
    await expect(message.locator('.thread__reply .thread__body')).toHaveText([reply]);
    await expect(message.locator('.thread__reply .thread__meta')).toContainText('Lena');
    await expect(message.locator('.thread__reply').locator('button, summary, form')).toHaveCount(0);
    await expect(message.locator('details.thread__reply-box > summary')).toHaveCount(1);
    await expect(message).toBeFocused();

    await openThread(omar.page, key);
    await expect(messageWithText(omar.page, comment).locator('.thread__reply .thread__body')).toHaveText([reply]);
  } finally {
    await lena.context.close();
    await omar.context.close();
  }
});

test('Cancel closes the Reply box, keeps the text and gives focus back to the Reply button', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Promo banner covers the menu ${randomUUID().slice(0, 8)}`);
  const comment = `Only below 400 px wide ${randomUUID().slice(0, 8)}`;
  const draft = `Also on tablets ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await postComment(omar.page, comment);
    const box = replyBox(omar.page, comment);
    await box.locator(':scope > summary').click();
    await expect(box.locator('.thread__note')).toHaveText(REPLY_NOTE_ON_YOUR_OWN);
    await box.getByLabel(REPLY_PLACEHOLDER).fill(draft);

    await box.getByRole('button', { name: 'Cancel' }).click();

    await expect(box).not.toHaveAttribute('open');
    await expect(box.getByLabel(REPLY_PLACEHOLDER)).toBeHidden();
    await expect(box.locator(':scope > summary')).toBeFocused();
    await box.locator(':scope > summary').press('Enter');
    await expect(box.getByLabel(REPLY_PLACEHOLDER)).toBeFocused();
    await expect(box.getByLabel(REPLY_PLACEHOLDER)).toHaveValue(draft);
  } finally {
    await omar.context.close();
  }
});

test('a refused reply keeps its text in the open Reply box, shows the toast and puts focus back in the text', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Search shows sold-out items ${randomUUID().slice(0, 8)}`);
  const comment = `Only for the shoe category ${randomUUID().slice(0, 8)}`;
  const draft = `Also for bags ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await postComment(omar.page, comment);
    const box = replyBox(omar.page, comment);
    await box.locator(':scope > summary').click();
    // An expired form is the refusal a browser can cause; tests/Functional/Conversation covers the others.
    await box.locator('input[name="_csrf_token"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'expired';
    });
    await box.getByLabel(REPLY_PLACEHOLDER).fill(draft);
    const answer = omar.page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/replies') && response.request().method() === 'POST');

    await box.getByRole('button', { name: 'Reply', exact: true }).click();

    expect((await answer).status()).toBe(422);
    await expect(omar.page.locator(TOAST_REGION)).toHaveText(FORM_EXPIRED);
    await expect(box).toHaveAttribute('open');
    await expect(box.getByLabel(REPLY_PLACEHOLDER)).toHaveValue(draft);
    await expect(box.getByLabel(REPLY_PLACEHOLDER)).toBeFocused();
    await expect(messageWithText(omar.page, comment).locator('.thread__reply')).toHaveCount(0);
  } finally {
    await omar.context.close();
  }
});

test('Omar resolves his comment, it folds into one line for Lena too, and show and Hide open and close it', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Address form forgets the flat number ${randomUUID().slice(0, 8)}`);
  const comment = `Fixed by the new form ${randomUUID().slice(0, 8)}`;
  const reply = `Checked on staging ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await postComment(omar.page, comment);
    await openThread(lena.page, key);
    await replyTo(lena.page, key, comment, reply);
    await openThread(omar.page, key);

    await resolve(omar.page, comment);

    const fold = foldOf(omar.page, comment);
    await expect(fold).toHaveCount(1);
    await expect(fold).not.toHaveAttribute('open');
    await expect(fold.locator('.thread__fold-line')).toHaveText(`✓ Resolved: ${comment} · 1 reply · show`);
    await expect(fold.locator('.thread__fold-hide')).toBeHidden();
    await expect(fold.locator('.thread__fold-message')).toBeHidden();

    await openThread(lena.page, key);
    const lenasFold = foldOf(lena.page, comment);
    await expect(lenasFold).not.toHaveAttribute('open');
    await lenasFold.locator(':scope > summary').click();

    await expect(lenasFold.locator('.thread__fold-message .thread__reply .thread__body')).toHaveText([reply]);
    await expect(lenasFold.locator('.thread__fold-hide')).toBeVisible();
    await expect(lenasFold.locator('.thread__fold-line')).toBeHidden();
    await expect(lenasFold.locator('.thread__resolve-form')).toHaveCount(0);

    await lenasFold.getByText('Hide', { exact: true }).click();

    await expect(lenasFold).not.toHaveAttribute('open');
    await expect(lenasFold.locator('.thread__fold-message')).toBeHidden();
    await expect(lenasFold.locator('.thread__fold-line')).toBeVisible();
  } finally {
    await lena.context.close();
    await omar.context.close();
  }
});

for (const scheme of ['light', 'dark'] as const) {
  test(`a thread with a reply, an open Reply box and an open fold has no serious or critical accessibility violations in ${scheme}`, async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Map pin drifts on zoom ${randomUUID().slice(0, 8)}`);
    const resolvedComment = `Pin fixed in the last build ${randomUUID().slice(0, 8)}`;
    const openComment = `Zoom still slow on old phones ${randomUUID().slice(0, 8)}`;
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    try {
      await omar.page.emulateMedia({ colorScheme: scheme });
      await openThread(omar.page, key);
      await postComment(omar.page, resolvedComment);
      await postComment(omar.page, openComment);
      await replyTo(omar.page, key, openComment, `Measured two seconds ${randomUUID().slice(0, 8)}`);
      await resolve(omar.page, resolvedComment);
      await openThread(omar.page, key);
      expect(await seriousViolations(omar.page)).toEqual([]);

      await foldOf(omar.page, resolvedComment).locator(':scope > summary').click();
      await replyBox(omar.page, openComment).locator(':scope > summary').click();
      await expect(replyBox(omar.page, openComment).getByLabel(REPLY_PLACEHOLDER)).toBeVisible();

      expect(await seriousViolations(omar.page)).toEqual([]);
    } finally {
      await omar.context.close();
    }
  });
}

test('at 360 px a long reply, the Reply box and a resolved fold scroll nothing sideways', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Receipt email wraps badly ${randomUUID().slice(0, 8)}`);
  // Letters only: a space, a hyphen or a slash would be a place to break the line without any CSS.
  const comment = `Receipt${'linewrapping'.repeat(20)}${randomUUID().replaceAll('-', '')}`;
  const reply = `Seenonthe${'phoneapptoo'.repeat(20)}${randomUUID().replaceAll('-', '')}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  // A layout check: with the outbox relay and notify worker running, the signal of Omar's own comment can reload the
  // thread just as he opens the Reply box and close it again. live-updates.spec.ts covers the signals.
  await omar.page.route((url) => url.pathname === HUB_PATH, (route) => route.abort());
  try {
    await omar.page.setViewportSize({ width: 360, height: 800 });
    await openThread(omar.page, key);
    await postComment(omar.page, comment);
    await replyTo(omar.page, key, comment, reply);
    await replyBox(omar.page, comment).locator(':scope > summary').click();
    await expect(replyBox(omar.page, comment).getByLabel(REPLY_PLACEHOLDER)).toBeVisible();
    await expectNoSidewaysScroll(omar.page);

    await resolve(omar.page, comment);
    await expect(foldOf(omar.page, comment).locator('.thread__fold-line')).toBeVisible();
    await expectNoSidewaysScroll(omar.page);
    await foldOf(omar.page, comment).locator(':scope > summary').click();
    await expect(foldOf(omar.page, comment).locator('.thread__fold-message .thread__reply .thread__body')).toBeVisible();

    await expectNoSidewaysScroll(omar.page);
  } finally {
    await omar.context.close();
  }
});
