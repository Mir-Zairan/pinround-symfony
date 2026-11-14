import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
const TOAST_REGION = '#toast-region';
// ThreadController::COMMENT_ADDED, MESSAGE_EDITED, MESSAGE_REMOVED
const COMMENT_ADDED = 'Comment added.';
const MESSAGE_EDITED = 'Message edited.';
const MESSAGE_REMOVED = 'Message removed.';
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
  await page.getByLabel('Title').fill(`Talk about the basket ${randomUUID().slice(0, 8)}`);
  await page.getByLabel('Give it to').selectOption({ label: 'Omar' });
  await page.getByRole('button', { name: 'Make ticket' }).click();
  await expect(page).toHaveURL(/\/t\/SHOP-\d+$/);
  const key = new URL(page.url()).pathname.replace('/t/', '');
  await expect(page.locator(TOAST_REGION)).toHaveText(`Ticket ${key} made.`);
  return key;
}

function threadFrame(page: Page) {
  return page.locator('turbo-frame#ticket-thread');
}

function composerBody(page: Page) {
  return threadFrame(page).locator('#thread-composer-body');
}

function commentButton(page: Page) {
  // The "Comment" kind chip above the text has the same name; the submit button is the last control of the form.
  return threadFrame(page).locator('form.thread__composer button[type="submit"]');
}

function messageWithText(page: Page, text: string) {
  return threadFrame(page).locator('li[data-message-id]').filter({ has: page.locator('.thread__body', { hasText: text }) });
}

/** Opens the ticket and scrolls to its thread, whose lazy frame loads from GET /t/{key}/thread once it is in view. */
async function openThread(page: Page, key: string): Promise<void> {
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/thread` && response.request().method() === 'GET');
  await page.goto(`/t/${key}`);
  await page.getByRole('heading', { level: 2, name: 'Questions and comments' }).scrollIntoViewIfNeeded();
  expect((await frameLoaded).status()).toBe(200);
  await expect(composerBody(page)).toBeVisible();
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.waitForLoadState('networkidle');
}

async function postComment(page: Page, text: string): Promise<void> {
  await composerBody(page).fill(text);
  await commentButton(page).click();
  await expect(page.locator(TOAST_REGION)).toHaveText(COMMENT_ADDED);
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
