import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { existsSync } from 'node:fs';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { composerBody, giveTicketTo, makeTicketForOmar, makeTicketGivenTo, openAs, openThread, threadFrame, TOAST_REGION } from './ticket';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// ThreadController::QUESTION_SENT and QUESTION_ANSWERED for Lena and the step a new ticket for Omar starts in
const QUESTION_SENT = 'Question sent. Lena gets one notification. Ticket paused.';
const QUESTION_ANSWERED = 'Answered. The ticket goes back to To do.';
// ThreadController::COMMENT_ADDED
const COMMENT_ADDED = 'Comment added.';
// Thread::COMMENT_PLACEHOLDER and COMMENT_NOTE
const COMMENT_PLACEHOLDER = 'Write a comment';
const COMMENT_NOTE = 'A comment does not change the status and sends no notification.';
// Thread::WAIT_FOR_THE_OPEN_QUESTION, ASK_PLACEHOLDER and ASK_NOTE for Lena
const WAIT_FOR_THE_OPEN_QUESTION = 'Wait for the open question first.';
const ASK_PLACEHOLDER = 'What do you need to know?';
const ASK_NOTE = 'A question pauses the ticket until Lena answers. They get one notification.';
// TicketPageParts::PAUSED_PILL for Lena
const PAUSED_PILL = 'Waiting · question to Lena';
// ThreadController::FORM_EXPIRED
const FORM_EXPIRED = 'This page was open too long. Reload it and try again.';

// Each test makes its own SHOP ticket, given to Omar by Lena, so a rerun never meets an earlier run's open question and
// the demo seed's tickets stay as they were. tests/Functional/Conversation/QuestionJourneyTest.php proves on the server
// who may ask and answer, and that a second question is refused while one waits.
test.use({ viewport: { width: 1280, height: 800 } });

function statusPill(page: Page) {
  return page.locator('#ticket-state .ticket__state').locator('> :first-child');
}

function askChip(page: Page) {
  return threadFrame(page).locator('.thread__kinds').getByRole('button', { name: 'Ask a question' });
}

function composerSubmit(page: Page) {
  return threadFrame(page).locator('form.thread__composer button[type="submit"]');
}

function composerForm(page: Page) {
  return threadFrame(page).locator('form.thread__composer');
}

function peopleToAsk(page: Page) {
  return threadFrame(page).getByRole('group', { name: 'Whom to ask' });
}

/** The composer as it is for a comment: its texts, its route, Turbo's own frame and no asked person sent. */
async function expectCommentComposer(page: Page, key: string): Promise<void> {
  await expect(threadFrame(page).locator('.thread__kinds').getByRole('button', { name: 'Comment' })).toHaveAttribute('aria-pressed', 'true');
  await expect(askChip(page)).toHaveAttribute('aria-pressed', 'false');
  await expect(composerBody(page)).toHaveAttribute('placeholder', COMMENT_PLACEHOLDER);
  await expect(composerForm(page).locator('label[for="thread-composer-body"]')).toHaveText(COMMENT_PLACEHOLDER);
  await expect(composerForm(page).locator('[data-thread-composer-target="note"]')).toHaveText(COMMENT_NOTE);
  await expect(composerSubmit(page)).toHaveText('Comment');
  await expect(composerForm(page)).toHaveAttribute('action', `/t/${key}/comments`);
  await expect(composerForm(page)).not.toHaveAttribute('data-turbo-frame');
  await expect(peopleToAsk(page)).toBeHidden();
  await expect(composerForm(page).locator('input[name="asked_user_id"]')).toBeDisabled();
}

function questionWithText(page: Page, text: string) {
  return threadFrame(page).locator('li[data-message-id]').filter({ has: page.locator('.thread__body', { hasText: text }) });
}

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).include('#ticket-thread').withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function expectNoSidewaysScroll(page: Page): Promise<void> {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
}

/** As Omar on an opened thread: asks Lena through the composer and waits for the whole page the redirect loads. */
async function askLena(page: Page, key: string, question: string): Promise<void> {
  await askChip(page).click();
  const lenaChip = threadFrame(page).getByRole('group', { name: 'Whom to ask' }).getByRole('button', { name: 'Lena', exact: true });
  await lenaChip.click();
  await expect(lenaChip).toHaveAttribute('aria-pressed', 'true');
  await expect(composerBody(page)).toHaveAttribute('placeholder', ASK_PLACEHOLDER);
  await expect(threadFrame(page).getByText(ASK_NOTE)).toBeVisible();
  await expect(composerBody(page).locator('xpath=ancestor::form')).toHaveAttribute('data-turbo-frame', '_top');
  await composerBody(page).fill(question);
  const asked = page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/questions` && response.request().method() === 'POST');

  await composerSubmit(page).click();

  expect((await asked).status()).toBe(303);
  await expect(page).toHaveURL(new RegExp(`/t/${key}$`));
  await expect(page.locator(TOAST_REGION)).toHaveText(QUESTION_SENT);
}

/** As Lena on an opened thread: answers the question that holds the text through its Answer form. */
async function answerAsLena(page: Page, key: string, question: string, answer: string): Promise<void> {
  const message = questionWithText(page, question);
  await message.locator('details.thread__answer summary').click();
  await message.getByLabel('Your answer').fill(answer);
  const answered = page.waitForResponse((response) => new URL(response.url()).pathname.startsWith(`/t/${key}/messages/`) && response.request().method() === 'POST');

  await message.locator('details.thread__answer button[type="submit"]').click();

  expect((await answered).status()).toBe(303);
  await expect(page).toHaveURL(new RegExp(`/t/${key}$`));
}

/**
 * Answers a question the test left open, so a failed run leaves no paused ticket in Lena's turns. Does nothing when the
 * test answered it.
 */
async function closeQuestionLeftOpen(browser: Browser, key: string, question: string): Promise<void> {
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(lena.page, key);
    if ((await questionWithText(lena.page, question).locator('details.thread__answer').count()) > 0) {
      await answerAsLena(lena.page, key, question, 'Closed after a failed test run');
    }
  } finally {
    await lena.context.close();
  }
}

test('Omar asks Lena, the ticket pauses, and Lena’s answer sends it back to its step', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Basket total skips the delivery fee ${randomUUID().slice(0, 8)}`);
  const question = `Should the fee show before the address is known? ${randomUUID().slice(0, 8)}`;
  const answer = `Yes, show the lowest fee ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await expect(askChip(omar.page)).toBeEnabled();
    await expect(threadFrame(omar.page).getByText(WAIT_FOR_THE_OPEN_QUESTION)).toBeHidden();

    await askLena(omar.page, key, question);

    await expect(statusPill(omar.page)).toHaveText(PAUSED_PILL);
    await expect(omar.page.locator('#ticket-next-step .next-step-box__sentence')).toHaveText('Wait for Lena’s answer. You get one notification.');
    await openThread(omar.page, key);
    await expect(questionWithText(omar.page, question).locator('.thread__meta')).toContainText('question to Lena');
    await expect(questionWithText(omar.page, question).locator('.thread__tags')).toHaveText('Waiting on Lena');
    await expect(questionWithText(omar.page, question).locator('details.thread__answer')).toHaveCount(0);
    await expect(askChip(omar.page)).toBeDisabled();
    await expect(threadFrame(omar.page).getByText(WAIT_FOR_THE_OPEN_QUESTION)).toBeVisible();

    await openThread(lena.page, key);
    await expect(statusPill(lena.page)).toHaveText(PAUSED_PILL);
    await answerAsLena(lena.page, key, question, answer);

    await expect(lena.page.locator(TOAST_REGION)).toHaveText(QUESTION_ANSWERED);
    await expect(statusPill(lena.page)).toHaveText('To do');
    await openThread(lena.page, key);
    const answeredQuestion = questionWithText(lena.page, question);
    await expect(answeredQuestion.locator('.thread__tags')).toHaveText('Answered');
    await expect(answeredQuestion.locator('.thread__reply--answer .thread__body')).toHaveText(answer);
    await expect(answeredQuestion.locator('details.thread__answer')).toHaveCount(0);

    await openThread(omar.page, key);
    await expect(statusPill(omar.page)).toHaveText('To do');
    await expect(askChip(omar.page)).toBeEnabled();
    await expect(threadFrame(omar.page).getByText(WAIT_FOR_THE_OPEN_QUESTION)).toBeHidden();
  } finally {
    await lena.context.close();
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});

// The demo seed has no Shop viewer (Raj manages Acme Foods, Mia is a Shop member), so who may not ask is proven on
// the server only, in QuestionJourneyTest. Mia, on Shop but not asked, shows who may not answer.
test('Mia, who was not asked, gets no Answer form and the server refuses her answer', async ({ browser }) => {
  test.skip(
    !process.env.CI && !existsSync(MIA_STORAGE_STATE),
    'auth.setup.ts skipped Mia: her two-step login is on in this database. QuestionJourneyTest proves an answer from someone not asked is refused.',
  );
  const key = await makeTicketForOmar(browser, `Basket badge hides on scroll ${randomUUID().slice(0, 8)}`);
  const question = `Does the badge hide on every page? ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const mia = await openAs(browser, MIA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await askLena(omar.page, key, question);
    await openThread(mia.page, key);
    const message = questionWithText(mia.page, question);
    await expect(message).toHaveCount(1);
    const messageId = await message.getAttribute('data-message-id');

    await expect(message.locator('details.thread__answer')).toHaveCount(0);
    const csrfToken = await threadFrame(mia.page).locator('form.thread__composer input[name="_csrf_token"]').inputValue();
    const refused = await mia.page.request.post(`/t/${key}/messages/${messageId}/answer`, {
      form: { _csrf_token: csrfToken, body: `Answered anyway ${randomUUID().slice(0, 8)}` },
      headers: { Accept: 'text/vnd.turbo-stream.html' },
      maxRedirects: 0,
    });

    expect(refused.status()).toBe(422);
    expect(await refused.text()).toContain('Only the person asked can answer this question.');
    await openThread(mia.page, key);
    await expect(questionWithText(mia.page, question).locator('.thread__tags')).toHaveText('Waiting on Lena');
    await expect(questionWithText(mia.page, question).locator('.thread__reply--answer')).toHaveCount(0);
    await expect(statusPill(mia.page)).toHaveText(PAUSED_PILL);
  } finally {
    await mia.context.close();
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});

test('Omar chooses Ask, goes back to Comment, and his text posts as a comment', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Coupon field trims the code ${randomUUID().slice(0, 8)}`);
  const comment = `Seen on staging too ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await askChip(omar.page).click();
    await expect(composerForm(omar.page)).toHaveAttribute('action', `/t/${key}/questions`);
    await expect(composerForm(omar.page).locator('input[name="asked_user_id"]')).toBeEnabled();

    await threadFrame(omar.page).locator('.thread__kinds').getByRole('button', { name: 'Comment' }).click();

    await expectCommentComposer(omar.page, key);
    await composerBody(omar.page).fill(comment);
    const posted = omar.page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/comments` && response.request().method() === 'POST');
    await composerSubmit(omar.page).click();
    expect((await posted).ok()).toBe(true);
    await expect(omar.page.locator(TOAST_REGION)).toHaveText(COMMENT_ADDED);
    const message = threadFrame(omar.page).locator('li.thread__message--comment').filter({ has: omar.page.locator('.thread__body', { hasText: comment }) });
    await expect(message).toHaveCount(1);
    await expect(message.locator('.thread__meta')).toContainText('comment');
    await expect(statusPill(omar.page)).toHaveText('To do');
    await expectCommentComposer(omar.page, key);
  } finally {
    await omar.context.close();
  }
});

test('with two people to ask, the second chip chosen is the person asked', async ({ browser }) => {
  // Given to Mia, Shop's tester: Omar, a Shop member not on the ticket, may ask Mia (the assignee, first) or Lena.
  const key = await makeTicketGivenTo(browser, `Checkout button jumps on load ${randomUUID().slice(0, 8)}`, 'Mia');
  const question = `Does it jump on every browser? ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await askChip(omar.page).click();
    const chips = peopleToAsk(omar.page).getByRole('button');
    await expect(chips).toHaveText(['Mia', 'Lena']);
    await expect(chips.nth(0)).toHaveAttribute('aria-pressed', 'true');

    await chips.nth(1).click();

    await expect(chips.nth(0)).toHaveAttribute('aria-pressed', 'false');
    await expect(chips.nth(1)).toHaveAttribute('aria-pressed', 'true');
    await expect(composerSubmit(omar.page)).toHaveText('Ask Lena');
    await expect(threadFrame(omar.page).getByText(ASK_NOTE)).toBeVisible();
    await composerBody(omar.page).fill(question);
    const asked = omar.page.waitForResponse((response) => new URL(response.url()).pathname === `/t/${key}/questions` && response.request().method() === 'POST');
    await composerSubmit(omar.page).click();
    expect((await asked).status()).toBe(303);
    await expect(omar.page).toHaveURL(new RegExp(`/t/${key}$`));
    await expect(statusPill(omar.page)).toHaveText(PAUSED_PILL);
    await openThread(omar.page, key);
    await expect(questionWithText(omar.page, question).locator('.thread__meta')).toContainText('question to Lena');
    await expect(questionWithText(omar.page, question).locator('.thread__tags')).toHaveText('Waiting on Lena');
  } finally {
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});

test('Cancel closes the Answer form, keeps the text and gives focus back to the Answer button', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Delivery slots show past times ${randomUUID().slice(0, 8)}`);
  const question = `Which time zone do the slots use? ${randomUUID().slice(0, 8)}`;
  const draft = `The store's own ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await askLena(omar.page, key, question);
    await openThread(lena.page, key);
    const answer = questionWithText(lena.page, question).locator('details.thread__answer');
    await answer.locator('summary').click();
    await answer.getByLabel('Your answer').fill(draft);

    await answer.getByRole('button', { name: 'Cancel' }).click();

    await expect(answer).not.toHaveAttribute('open');
    await expect(answer.getByLabel('Your answer')).toBeHidden();
    await expect(answer.locator('summary')).toBeFocused();
    await answer.locator('summary').click();
    await expect(answer.getByLabel('Your answer')).toBeFocused();
    await expect(answer.getByLabel('Your answer')).toHaveValue(draft);
  } finally {
    await lena.context.close();
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});

test('a refused answer keeps its text in the open Answer box, shows the toast and puts focus back in the text', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Gift card balance rounds down ${randomUUID().slice(0, 8)}`);
  const question = `Does it round on the receipt too? ${randomUUID().slice(0, 8)}`;
  const draft = `Only on the checkout page ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await askLena(omar.page, key, question);
    await openThread(lena.page, key);
    const answer = questionWithText(lena.page, question).locator('details.thread__answer');
    await answer.locator('summary').click();
    // An expired form is the refusal a browser can cause; QuestionJourneyTest covers the others.
    await answer.locator('input[name="_csrf_token"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'expired';
    });
    await answer.getByLabel('Your answer').fill(draft);
    const refused = lena.page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/answer') && response.request().method() === 'POST');

    await answer.getByRole('button', { name: 'Answer', exact: true }).click();

    expect((await refused).status()).toBe(422);
    await expect(lena.page.locator(TOAST_REGION)).toHaveText(FORM_EXPIRED);
    await expect(lena.page).toHaveURL(new RegExp(`/t/${key}$`));
    await expect(answer).toHaveAttribute('open');
    await expect(answer.getByLabel('Your answer')).toHaveValue(draft);
    await expect(answer.getByLabel('Your answer')).toBeFocused();
    await expect(questionWithText(lena.page, question).locator('.thread__tags')).toHaveText('Waiting on Lena');
    await expect(statusPill(lena.page)).toHaveText(PAUSED_PILL);
  } finally {
    await lena.context.close();
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});

test('Ask a question turns off on Lena’s open tab once Omar’s question reaches it, and her text stays as a comment', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Search drops the last letter ${randomUUID().slice(0, 8)}`);
  const question = `Is it only on the phone app? ${randomUUID().slice(0, 8)}`;
  const lenasDraft = `I was going to ask Omar ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(lena.page, key);
    await askChip(lena.page).click();
    await expect(composerSubmit(lena.page)).toHaveText('Ask Omar');
    await composerBody(lena.page).fill(lenasDraft);
    await peopleToAsk(lena.page).getByRole('button', { name: 'Omar', exact: true }).focus();
    await openThread(omar.page, key);

    await askLena(omar.page, key, question);

    // No navigation on Lena's tab: only the live refresh can bring the open question.
    await expect(questionWithText(lena.page, question)).toHaveCount(1, { timeout: 15_000 });
    await expect(askChip(lena.page)).toBeDisabled();
    // The focused Omar chip is hidden now, so the focus goes to the Comment chip rather than the body.
    await expect(threadFrame(lena.page).locator('.thread__kinds').getByRole('button', { name: 'Comment' })).toBeFocused();
    await expect(threadFrame(lena.page).getByText(WAIT_FOR_THE_OPEN_QUESTION)).toBeVisible();
    await expectCommentComposer(lena.page, key);
    await expect(composerBody(lena.page)).toHaveValue(lenasDraft);
  } finally {
    await lena.context.close();
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});

test('on Omar’s open tab the people to ask follow live refreshes and keep his choice, his focus and his text', async ({ browser }) => {
  // Given to Mia: Omar, a Shop member not on the ticket, may ask Mia (the assignee, first) or Lena (reporter and lead).
  const key = await makeTicketGivenTo(browser, `Saved cards list shows expired ones ${randomUUID().slice(0, 8)}`, 'Mia');
  const omarsDraft = `Which cards count as expired? ${randomUUID().slice(0, 8)}`;
  const lenasComment = `Seen on the phone app too ${randomUUID().slice(0, 8)}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await openThread(omar.page, key);
    await askChip(omar.page).click();
    const chips = peopleToAsk(omar.page).getByRole('button');
    await expect(chips).toHaveText(['Mia', 'Lena']);
    await composerBody(omar.page).fill(omarsDraft);
    await chips.nth(0).focus();
    // A rebuilt chip loses this mark, so it shows the refresh left the chip itself in place, not a copy given focus.
    await chips.nth(0).evaluate((chip) => Object.assign(chip, { keptAcrossRefresh: true }));

    await openThread(lena.page, key);
    await composerBody(lena.page).fill(lenasComment);
    await composerSubmit(lena.page).click();
    await expect(lena.page.locator(TOAST_REGION)).toHaveText(COMMENT_ADDED);

    // No navigation on Omar's tab: only the live refresh can bring Lena's comment.
    await expect(threadFrame(omar.page).locator('.thread__body', { hasText: lenasComment })).toHaveCount(1, { timeout: 15_000 });
    await expect(chips.nth(0)).toBeFocused();
    expect(await omar.page.evaluate(() => (document.activeElement as { keptAcrossRefresh?: boolean } | null)?.keptAcrossRefresh)).toBe(true);
    await expect(chips.nth(0)).toHaveAttribute('aria-pressed', 'true');

    await giveTicketTo(lena.page, key, 'Omar');

    // Mia, the person chosen, is no longer on the ticket and Omar may not ask himself: the first chip left takes over.
    await expect(chips).toHaveText(['Lena'], { timeout: 15_000 });
    await expect(chips.nth(0)).toHaveAttribute('aria-pressed', 'true');
    await expect(chips.nth(0)).toBeFocused();
    await expect(askChip(omar.page)).toHaveAttribute('aria-pressed', 'true');
    await expect(composerSubmit(omar.page)).toHaveText('Ask Lena');
    await expect(composerBody(omar.page)).toHaveValue(omarsDraft);

    await giveTicketTo(lena.page, key, 'Mia');

    // Lena, the person chosen now, is still on the ticket: she stays chosen and keeps the focus beside Mia.
    await expect(chips).toHaveText(['Mia', 'Lena'], { timeout: 15_000 });
    await expect(chips.nth(0)).toHaveAttribute('aria-pressed', 'false');
    await expect(chips.nth(1)).toHaveAttribute('aria-pressed', 'true');
    await expect(chips.nth(1)).toBeFocused();
    await expect(composerSubmit(omar.page)).toHaveText('Ask Lena');
    await expect(composerForm(omar.page)).toHaveAttribute('action', `/t/${key}/questions`);
    await expect(composerBody(omar.page)).toHaveValue(omarsDraft);
  } finally {
    await lena.context.close();
    await omar.context.close();
  }
});

for (const scheme of ['light', 'dark'] as const) {
  test(`the thread with an open question and its Answer form has no serious or critical accessibility violations in ${scheme}`, async ({ browser }) => {
    const key = await makeTicketForOmar(browser, `Voucher field loses focus ${randomUUID().slice(0, 8)}`);
    const question = `Which voucher field do you mean? ${randomUUID().slice(0, 8)}`;
    const omar = await openAs(browser, OMAR_STORAGE_STATE);
    const lena = await openAs(browser, LENA_STORAGE_STATE);
    try {
      await omar.page.emulateMedia({ colorScheme: scheme });
      await lena.page.emulateMedia({ colorScheme: scheme });
      await openThread(omar.page, key);
      await askChip(omar.page).click();
      expect(await seriousViolations(omar.page)).toEqual([]);
      await composerBody(omar.page).fill(question);
      await composerSubmit(omar.page).click();
      await expect(omar.page.locator(TOAST_REGION)).toHaveText(QUESTION_SENT);
      await openThread(omar.page, key);
      expect(await seriousViolations(omar.page)).toEqual([]);

      await openThread(lena.page, key);
      await questionWithText(lena.page, question).locator('details.thread__answer summary').click();
      await expect(questionWithText(lena.page, question).getByLabel('Your answer')).toBeVisible();

      expect(await seriousViolations(lena.page)).toEqual([]);
    } finally {
      await lena.context.close();
      await omar.context.close();
      await closeQuestionLeftOpen(browser, key, question);
    }
  });
}

test('at 360 px the question form and the Answer form scroll nothing sideways', async ({ browser }) => {
  const key = await makeTicketForOmar(browser, `Store opening hours wrap badly ${randomUUID().slice(0, 8)}`);
  // Letters only: a space, a hyphen or a slash would be a place to break the line without any CSS.
  const question = `Whichstoredoyoumean${'openinghours'.repeat(20)}${randomUUID().replaceAll('-', '')}`;
  const omar = await openAs(browser, OMAR_STORAGE_STATE);
  const lena = await openAs(browser, LENA_STORAGE_STATE);
  try {
    await omar.page.setViewportSize({ width: 360, height: 800 });
    await lena.page.setViewportSize({ width: 360, height: 800 });
    await openThread(omar.page, key);
    await askChip(omar.page).click();
    await expect(threadFrame(omar.page).getByRole('group', { name: 'Whom to ask' })).toBeVisible();
    await expectNoSidewaysScroll(omar.page);
    await askLena(omar.page, key, question);

    await openThread(lena.page, key);
    await questionWithText(lena.page, question).locator('details.thread__answer summary').click();
    await expect(questionWithText(lena.page, question).getByLabel('Your answer')).toBeVisible();

    await expectNoSidewaysScroll(lena.page);
  } finally {
    await lena.context.close();
    await omar.context.close();
    await closeQuestionLeftOpen(browser, key, question);
  }
});
