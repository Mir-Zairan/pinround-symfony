import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { existsSync } from 'node:fs';
import { LENA_STORAGE_STATE, MIA_STORAGE_STATE, OMAR_STORAGE_STATE } from './auth';
import { composerBody, makeTicketForOmar, openAs, openThread, threadFrame, TOAST_REGION } from './ticket';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// ThreadController::QUESTION_SENT and QUESTION_ANSWERED for Lena and the step a new ticket for Omar starts in
const QUESTION_SENT = 'Question sent. Lena gets one notification. Ticket paused.';
const QUESTION_ANSWERED = 'Answered. The ticket goes back to To do.';
// Thread::WAIT_FOR_THE_OPEN_QUESTION, ASK_PLACEHOLDER and ASK_NOTE for Lena
const WAIT_FOR_THE_OPEN_QUESTION = 'Wait for the open question first.';
const ASK_PLACEHOLDER = 'What do you need to know?';
const ASK_NOTE = 'A question pauses the ticket until Lena answers. They get one notification.';
// TicketPageParts::PAUSED_PILL for Lena
const PAUSED_PILL = 'Waiting · question to Lena';

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
