import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type Request, type Route } from '@playwright/test';
import { useAiSettingsInEachTest } from './ai-settings';
import { OMAR_STORAGE_STATE } from './auth';
import { chipOf, openNewTicketForm, type TicketForm } from './new-ticket-form';

// "Check with AI" on the New ticket form (intake-check_controller.js over IntakeCheck's frame). The polls are answered
// here, and so is every press but the refused one, so no check is queued on the shared async_ai queue and the spec
// needs no AI provider; the answers carry the markup IntakeCheck.html.twig renders.

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

/** frame_poll.js POLL_INTERVAL_MS and MAX_POLLS. */
const POLL_INTERVAL_MS = 3000;
const MAX_POLLS = 60;

const ORGANIZATION_SLUG = 'acme-foods';
const CLOUD_AI = { on: true, who: 'everyone', where: 'cloud' };
const AI_OFF = { on: false, who: 'everyone', where: 'cloud' };

const TITLE = 'Pay button does nothing';
const DETAIL = 'Pressing Pay on the last checkout step does nothing.\nNo error shows.';
const SUMMARY = 'Pay does nothing on the last checkout step.';
const DUPLICATE_KEY = 'SHOP-1';
const DUPLICATE_TITLE = 'Checkout fails on the last step';
const CHECK_ID = '01936b7e-0000-7000-8000-000000000001';

/** IntakeCheck's lines and labels. */
const NOTE = 'AI can look for duplicates, missing info and risk, and suggest type and priority.';
const RUNNING_LINE = 'The AI is reading your report…';
const FAILED_LINE = 'The AI answer failed. Try again.';
const FORM_EXPIRED = 'The page was open too long. Reload it and try again.';
/** NewTicketController::FORM_EXPIRED. */
const MAKE_TICKET_FORM_EXPIRED = 'The page was open too long. Try again.';
const UNANSWERED_LINE = 'The check did not go through. Try again.';
const DUPLICATES_HEADING = 'The AI thinks this may already be reported';
const CHECK_LABEL = 'Check with AI';
const CHECK_AGAIN_LABEL = 'Check again with AI';
const RUNNING_LABEL = 'Thinking…';

const CHECK_FRAME = '#intake-check';
const CHECK_STATE = '#intake-check .intake-check__state';
const CHECK_TOKEN = 'input[name="_intake_check_token"]';
const MARK = '.intake-check__mark';

// A day that is not today, so nothing here leans on the real clock.
const FROZEN_AT = new Date('2026-03-04T09:00:00Z');

type Suggestion = { type: string; priority: string };
type FrameAnswer = { state: 'running' } | { state: 'failed' } | ({ state: 'done' } & Suggestion);

function frameAnswer(answer: FrameAnswer, token: string): string {
  const pollUrl = `/tickets/new/check/${CHECK_ID}`;
  const tokenField = `<input type="hidden" name="_intake_check_token" value="${token}">`;
  const readyLabel = answer.state === 'done' ? CHECK_AGAIN_LABEL : CHECK_LABEL;
  const button = (label: string, isOff: boolean) => `<button class="btn btn-tonal btn-sm intake-check__button" data-intake-check-target="button"
      data-action="intake-check#check" type="button"${isOff ? ' disabled aria-busy="true"' : ''}>${label}</button>`;
  let body: string;
  let suggestionValues = '';
  if (answer.state === 'running') {
    body = `<p class="intake-check__line">${RUNNING_LINE}</p>${tokenField}${button(RUNNING_LABEL, true)}`;
  } else if (answer.state === 'failed') {
    body = `<p class="intake-check__line intake-check__failed">${FAILED_LINE}</p>${tokenField}${button(CHECK_LABEL, false)}`;
  } else {
    suggestionValues = ` data-intake-check-type-value="${answer.type}" data-intake-check-priority-value="${answer.priority}"`;
    body = `<p class="intake-check__line intake-check__summary"><strong>In short:</strong> ${SUMMARY}</p>${tokenField}${button(CHECK_AGAIN_LABEL, false)}
      <section class="intake-check__duplicates" aria-labelledby="intake-check-duplicates-heading">
        <h3 id="intake-check-duplicates-heading" class="intake-check__duplicates-heading">${DUPLICATES_HEADING}</h3>
        <ul class="intake-check__duplicate-list"><li class="intake-check__duplicate"><span class="intake-check__duplicate-key">${DUPLICATE_KEY}</span>
          <a href="/t/${DUPLICATE_KEY}" data-turbo-frame="_top">${DUPLICATE_TITLE}</a></li></ul>
      </section>`;
  }
  return `<turbo-frame id="intake-check" class="intake-check" aria-live="polite"><div class="intake-check__state" tabindex="-1" autofocus
      data-controller="intake-check" data-action="visibilitychange@document->intake-check#followVisibility"
      data-intake-check-state-value="${answer.state}" data-intake-check-request-url-value="/tickets/new/check"
      data-intake-check-ready-label-value="${readyLabel}" data-intake-check-running-label-value="${RUNNING_LABEL}"
      data-intake-check-mark-label-value="AI suggestion" data-intake-check-unanswered-line-value="${UNANSWERED_LINE}"
      data-intake-check-id-value="${CHECK_ID}" data-intake-check-url-value="${pollUrl}"${suggestionValues}>${body}</div></turbo-frame>`;
}

function isPress(request: Request): boolean {
  return request.method() === 'POST' && new URL(request.url()).pathname === '/tickets/new/check';
}

function isPoll(request: Request): boolean {
  return request.method() === 'GET' && new URL(request.url()).pathname === `/tickets/new/check/${CHECK_ID}`;
}

/**
 * Answers each press with the running frame and each poll with the next of pollAnswers, its last one after that.
 * Resolves to the presses and polls the route saw.
 */
async function answerPressesAndPolls(page: Page, token: string, pollAnswers: FrameAnswer[]): Promise<{ presses: Request[]; polls: Request[] }> {
  const presses: Request[] = [];
  const polls: Request[] = [];
  await page.route('**/tickets/new/check**', async (route: Route) => {
    const request = route.request();
    if (isPress(request)) {
      presses.push(request);
      await route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: frameAnswer({ state: 'running' }, token) });
      return;
    }
    if (!isPoll(request)) {
      await route.continue();
      return;
    }
    polls.push(request);
    const answer = pollAnswers[Math.min(polls.length, pollAnswers.length) - 1];
    await route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: frameAnswer(answer, token) });
  });
  return { presses, polls };
}

/** Holds each press until release runs, then answers it with reply; isHolding says whether a press has arrived. */
async function holdPresses(page: Page, reply: (route: Route) => Promise<void>): Promise<{ isHolding: () => boolean; release: () => void }> {
  const press: { release: (() => void) | null } = { release: null };
  await page.route('**/tickets/new/check', async (route) => {
    await new Promise<void>((resolve) => {
      press.release = resolve;
    });
    await reply(route);
  });
  return { isHolding: () => press.release !== null, release: () => press.release?.() };
}

/** The value of one field of a multipart/form-data body, as FormData posts it; null when the body has no such field. */
function postedField(request: Request, name: string): string | null {
  const body = request.postData() ?? '';
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const field = new RegExp(`name="${escapedName}"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--`).exec(body);
  return field === null ? null : field[1];
}

/**
 * Opens the New ticket form and stops the page's clock; from here only runFor moves the polls. beforePause runs on the
 * open form while the clock still runs, for steps that load a page, whose load waits on timers.
 */
async function openFormWithPausedClock(page: Page, beforePause?: (form: TicketForm) => Promise<void>): Promise<{ form: TicketForm; token: string }> {
  await page.clock.install({ time: FROZEN_AT });
  const form = await openNewTicketForm(page);
  await beforePause?.(form);
  await page.clock.pauseAt(new Date(FROZEN_AT.getTime() + 60_000));
  const token = await form.form.locator(CHECK_TOKEN).getAttribute('value');
  if (token === null || token === '') {
    throw new Error('The idle frame carries no check token.');
  }
  return { form, token };
}

/**
 * Records the clock's time of each poll the page starts with fetch. The route handler only sees a poll some real time
 * after the page sent it, so its count can neither show that a runFor sent no poll nor which runFor sent one.
 */
async function recordPollTimes(page: Page): Promise<() => Promise<number[]>> {
  const pollTimes = await page.evaluateHandle((checkId) => {
    const startedAtMs: number[] = [];
    const pageFetch = window.fetch.bind(window);
    window.fetch = (input, init) => {
      const url = new URL(input instanceof window.Request ? input.url : String(input), window.location.href);
      if ((init?.method ?? 'GET').toUpperCase() === 'GET' && url.pathname === `/tickets/new/check/${checkId}`) {
        startedAtMs.push(Date.now());
      }
      return pageFetch(input, init);
    };
    return startedAtMs;
  }, CHECK_ID);
  return () => pollTimes.evaluate((startedAtMs) => [...startedAtMs]);
}

/**
 * Presses the check button with Enter, so the focus is on it, and waits until the controller swapped in the frame the
 * press answered with; the controller swaps it itself, with no Turbo render, so no animation frame has to run. Resolves
 * to the clock's time, in ms, when the new content went in: the first poll timer started then.
 */
async function pressCheck(page: Page, label: string, expectedState: string): Promise<number> {
  const swap = await page.locator(CHECK_FRAME).evaluateHandle((frame) => {
    const sign: { swappedAtMs: number | null } = { swappedAtMs: null };
    const observer = new MutationObserver(() => {
      sign.swappedAtMs = Date.now();
      observer.disconnect();
    });
    observer.observe(frame, { childList: true });
    return sign;
  });
  await page.locator(CHECK_FRAME).getByRole('button', { name: label }).press('Enter');
  // Waits for the swap, not only the state: a press after the last poll starts from a state that already says running.
  await expect.poll(() => swap.evaluate((sign) => sign.swappedAtMs), { message: 'The press swapped no frame in.' }).not.toBeNull();
  await expect(page.locator(CHECK_STATE)).toHaveAttribute('data-intake-check-state-value', expectedState);
  const swappedAtMs = await swap.evaluate((sign) => sign.swappedAtMs);
  if (swappedAtMs === null) {
    throw new Error('The press swapped no frame in.');
  }
  return swappedAtMs;
}

/** Moves the paused clock to the next poll, then waits until the page has handled its answer. */
async function nextPoll(page: Page, polls: Request[], expectedCount: number): Promise<void> {
  await page.clock.runFor(POLL_INTERVAL_MS);
  await expect.poll(() => polls.length).toBe(expectedCount);
  await expect(page.locator(CHECK_FRAME)).toHaveAttribute('data-answered-polls', String(expectedCount));
}

function radio(form: TicketForm, name: 'type' | 'priority', value: string) {
  return form.form.locator(`input[type="radio"][name="${name}"][value="${value}"]`);
}

/** Every field the form would post, as name and value pairs, in the form's order. */
async function formFieldsOf(form: TicketForm): Promise<[string, string][]> {
  return form.form.evaluate((formElement) => [...new FormData(formElement as HTMLFormElement).entries()].map(([name, value]): [string, string] => [name, String(value)]));
}

/** The names of the fields whose value differs between the two lists of formFieldsOf, which must name the same fields. */
function changedFieldNames(before: [string, string][], after: [string, string][]): string[] {
  expect(after.map(([name]) => name)).toEqual(before.map(([name]) => name));
  return before.filter(([, value], index) => after[index][1] !== value).map(([name]) => name);
}

/** The values of the radios of that group whose label carries the ✦ mark. */
async function markedValues(form: TicketForm, name: 'type' | 'priority'): Promise<string[]> {
  return form.form.locator(`label:has(${MARK}) input[name="${name}"]`).evaluateAll((radios) => radios.map((field) => (field as HTMLInputElement).value));
}

/** axe-core settles each rule on a setTimeout, which a paused clock never fires, so the clock runs again first. */
async function seriousViolations(page: Page): Promise<string[]> {
  await page.clock.resume();
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

test.describe('"Check with AI" on the New ticket form', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });
  useAiSettingsInEachTest(ORGANIZATION_SLUG, CLOUD_AI);

  test('shows the button with a title, marks and selects the suggestion, keeps the person\'s own pick and shows the duplicates', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { presses, polls } = await answerPressesAndPolls(page, token, [{ state: 'running' }, { state: 'done', type: 'story', priority: 'major' }]);
    const pollTimes = await recordPollTimes(page);
    const frame = page.locator(CHECK_FRAME);
    const checkButton = frame.getByRole('button', { name: CHECK_LABEL });
    await expect(frame.locator('.intake-check__line')).toHaveText(NOTE);
    await expect(checkButton).toBeHidden();

    await form.title.fill(TITLE);
    await expect(checkButton).toBeVisible();
    await form.title.fill('   ');
    await expect(checkButton).toBeHidden();
    await form.title.fill(TITLE);
    await form.description.fill(DETAIL);
    await chipOf(form, 'priority', 'minor').click();
    const project = await form.form.locator('select[name="project_id"]').inputValue();
    await page.evaluate(() => {
      (window as Window & { beforeTheCheck?: boolean }).beforeTheCheck = true;
    });
    const fieldsBeforeTheCheck = await formFieldsOf(form);

    const swappedAtMs = await pressCheck(page, CHECK_LABEL, 'running');
    expect(presses).toHaveLength(1);
    expect(presses[0].headers()['turbo-frame']).toBe('intake-check');
    expect([postedField(presses[0], 'title'), postedField(presses[0], 'description'), postedField(presses[0], 'project_id')]).toEqual([TITLE, DETAIL.replace('\n', '\r\n'), project]);
    expect(postedField(presses[0], '_intake_check_token')).toBe(token);
    await expect(frame.locator('.intake-check__line')).toHaveText(RUNNING_LINE);
    await expect(frame.getByRole('button', { name: RUNNING_LABEL })).toBeDisabled();
    await expect(page.locator(CHECK_STATE)).toBeFocused();

    await page.clock.runFor(POLL_INTERVAL_MS - 1);
    expect(await pollTimes()).toEqual([]);
    await page.clock.runFor(1);
    await expect.poll(() => polls.length).toBe(1);
    await expect(frame).toHaveAttribute('data-answered-polls', '1');
    expect(await pollTimes()).toEqual([swappedAtMs + POLL_INTERVAL_MS]);
    expect(polls[0].headers()['turbo-frame']).toBe('intake-check');

    await nextPoll(page, polls, 2);
    await expect(frame.locator('.intake-check__summary')).toHaveText(`In short: ${SUMMARY}`);
    await expect(page.locator(CHECK_STATE)).toBeFocused();
    expect(await markedValues(form, 'type')).toEqual(['story']);
    expect(await markedValues(form, 'priority')).toEqual(['major']);
    await expect(radio(form, 'type', 'story')).toBeChecked();
    await expect(radio(form, 'priority', 'minor')).toBeChecked();
    await expect(radio(form, 'priority', 'major')).not.toBeChecked();
    await expect(form.form.locator(`label:has(${MARK})`).first()).toContainText('✦');

    const duplicates = frame.getByRole('region', { name: DUPLICATES_HEADING });
    await expect(duplicates.getByRole('link', { name: DUPLICATE_TITLE })).toHaveAttribute('href', `/t/${DUPLICATE_KEY}`);
    await expect(frame.getByRole('button', { name: CHECK_AGAIN_LABEL })).toBeEnabled();
    expect(await page.evaluate(() => (window as Window & { beforeTheCheck?: boolean }).beforeTheCheck)).toBe(true);
    await expect(form.title).toHaveValue(TITLE);
    await expect(form.description).toHaveValue(DETAIL);
    await expect(form.form.locator('select[name="project_id"]')).toHaveValue(project);
    // The AI never sets any other field, such as who sees the ticket: only its Type pick went in.
    expect(changedFieldNames(fieldsBeforeTheCheck, await formFieldsOf(form))).toEqual(['type']);

    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(await pollTimes()).toHaveLength(2);
    expect(await seriousViolations(page)).toEqual([]);
  });

  test('"Check again with AI" replaces the AI\'s own pick and its marks, but not the person\'s', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { presses, polls } = await answerPressesAndPolls(page, token, [
      { state: 'done', type: 'story', priority: 'major' },
      { state: 'done', type: 'task', priority: 'critical' },
    ]);
    await form.title.fill(TITLE);
    await pressCheck(page, CHECK_LABEL, 'running');
    await nextPoll(page, polls, 1);
    await expect(radio(form, 'type', 'story')).toBeChecked();
    await expect(radio(form, 'priority', 'major')).toBeChecked();
    await chipOf(form, 'priority', 'normal').click();

    await pressCheck(page, CHECK_AGAIN_LABEL, 'running');
    expect(presses).toHaveLength(2);
    await expect(form.form.locator(MARK)).toHaveCount(0);
    await nextPoll(page, polls, 2);

    expect(await markedValues(form, 'type')).toEqual(['task']);
    expect(await markedValues(form, 'priority')).toEqual(['critical']);
    await expect(radio(form, 'type', 'task')).toBeChecked();
    await expect(radio(form, 'priority', 'normal')).toBeChecked();
  });

  test('a failed check shows its sentence and the button again', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { polls } = await answerPressesAndPolls(page, token, [{ state: 'failed' }]);
    await form.title.fill(TITLE);
    await pressCheck(page, CHECK_LABEL, 'running');

    await nextPoll(page, polls, 1);

    const frame = page.locator(CHECK_FRAME);
    await expect(frame.locator('.intake-check__failed')).toHaveText(FAILED_LINE);
    await expect(frame.getByRole('button', { name: CHECK_LABEL })).toBeEnabled();
    await expect(form.form.locator(MARK)).toHaveCount(0);
    await expect(radio(form, 'type', 'bug')).toBeChecked();
    await expect(radio(form, 'priority', 'normal')).toBeChecked();
  });

  test('after a refused "Create ticket" the check keeps the Priority the person picked before it', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page, async (openForm) => {
      await openForm.title.fill(TITLE);
      await chipOf(openForm, 'priority', 'minor').click();
      await openForm.form.locator('input[name="_csrf_token"]').evaluate((field) => {
        (field as HTMLInputElement).value = 'a-token-from-a-page-open-too-long';
      });
      const refused = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/tickets/new');
      await openForm.submit.click();
      expect((await refused).status()).toBe(422);
      await page.waitForLoadState('networkidle');
    });
    await expect(page.locator('.login__error[role="alert"]')).toHaveText(MAKE_TICKET_FORM_EXPIRED);
    await expect(form.title).toHaveValue(TITLE);
    await expect(radio(form, 'priority', 'minor')).toBeChecked();
    const { polls } = await answerPressesAndPolls(page, token, [{ state: 'done', type: 'story', priority: 'major' }]);

    await pressCheck(page, CHECK_LABEL, 'running');
    await nextPoll(page, polls, 1);

    expect(await markedValues(form, 'type')).toEqual(['story']);
    expect(await markedValues(form, 'priority')).toEqual(['major']);
    await expect(radio(form, 'type', 'story')).toBeChecked();
    await expect(radio(form, 'priority', 'minor')).toBeChecked();
  });

  test('a press the app refuses shows its sentence, keeps the button and takes the marks away', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { polls } = await answerPressesAndPolls(page, token, [{ state: 'done', type: 'story', priority: 'major' }]);
    await form.title.fill(TITLE);
    await pressCheck(page, CHECK_LABEL, 'running');
    await nextPoll(page, polls, 1);
    await expect(form.form.locator(MARK)).toHaveCount(2);
    await page.unroute('**/tickets/new/check**');
    await form.form.locator(CHECK_TOKEN).evaluate((field) => {
      (field as HTMLInputElement).value = 'a-token-from-a-page-open-too-long';
    });
    const refused = page.waitForResponse((response) => isPress(response.request()));

    await page.locator(CHECK_FRAME).getByRole('button', { name: CHECK_AGAIN_LABEL }).press('Enter');

    expect((await refused).status()).toBe(422);
    const frame = page.locator(CHECK_FRAME);
    await expect(frame.locator('.intake-check__notice')).toHaveText(FORM_EXPIRED);
    await expect(frame.getByRole('button', { name: CHECK_LABEL })).toBeEnabled();
    await expect(page.locator(CHECK_STATE)).toHaveAttribute('data-intake-check-state-value', 'idle');
    await expect(page.locator(CHECK_STATE)).toBeFocused();
    await expect(form.form.locator(MARK)).toHaveCount(0);
    await expect(form.title).toHaveValue(TITLE);
  });

  test('a press that gets no frame back says so, gives the button back and takes the marks away', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { polls } = await answerPressesAndPolls(page, token, [{ state: 'done', type: 'story', priority: 'major' }]);
    await form.title.fill(TITLE);
    await pressCheck(page, CHECK_LABEL, 'running');
    await nextPoll(page, polls, 1);
    await expect(form.form.locator(MARK)).toHaveCount(2);
    await page.unroute('**/tickets/new/check**');
    await page.route('**/tickets/new/check', (route) => route.abort('connectionfailed'));

    await page.locator(CHECK_FRAME).getByRole('button', { name: CHECK_AGAIN_LABEL }).press('Enter');

    const frame = page.locator(CHECK_FRAME);
    await expect(frame.locator('.intake-check__unanswered')).toHaveText(UNANSWERED_LINE);
    const button = frame.getByRole('button', { name: CHECK_AGAIN_LABEL });
    await expect(button).toBeEnabled();
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await expect(button).toBeFocused();
    await expect(frame.locator('.intake-check__summary')).toHaveText(`In short: ${SUMMARY}`);
    await expect(form.form.locator(MARK)).toHaveCount(0);
  });

  test('a press leaves the focus where the person put it while the press ran', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const press = await holdPresses(page, (route) => route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: frameAnswer({ state: 'running' }, token) }));
    await form.title.fill(TITLE);
    await page.locator(CHECK_FRAME).getByRole('button', { name: CHECK_LABEL }).press('Enter');
    await expect.poll(() => press.isHolding()).toBe(true);

    await form.description.focus();
    press.release();

    await expect(page.locator(CHECK_STATE)).toHaveAttribute('data-intake-check-state-value', 'running');
    await expect(form.description).toBeFocused();
  });

  test('a press that gets no frame back leaves the focus in a field the person moved to while it ran', async ({ page }) => {
    const { form } = await openFormWithPausedClock(page);
    const press = await holdPresses(page, (route) => route.abort('connectionfailed'));
    await form.title.fill(TITLE);
    await page.locator(CHECK_FRAME).getByRole('button', { name: CHECK_LABEL }).press('Enter');
    await expect.poll(() => press.isHolding()).toBe(true);

    await form.description.focus();
    press.release();

    await expect(page.locator(CHECK_FRAME).locator('.intake-check__unanswered')).toHaveText(UNANSWERED_LINE);
    await expect(page.locator(CHECK_FRAME).getByRole('button', { name: CHECK_LABEL })).toBeEnabled();
    await expect(form.description).toBeFocused();
  });

  test('a press that gets no frame back leaves the focus on a link in the check the person moved to while it ran', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { polls } = await answerPressesAndPolls(page, token, [{ state: 'done', type: 'story', priority: 'major' }]);
    await form.title.fill(TITLE);
    await pressCheck(page, CHECK_LABEL, 'running');
    await nextPoll(page, polls, 1);
    await page.unroute('**/tickets/new/check**');
    const press = await holdPresses(page, (route) => route.abort('connectionfailed'));
    const frame = page.locator(CHECK_FRAME);
    await frame.getByRole('button', { name: CHECK_AGAIN_LABEL }).press('Enter');
    await expect.poll(() => press.isHolding()).toBe(true);

    const duplicateLink = frame.getByRole('link', { name: DUPLICATE_TITLE });
    await duplicateLink.focus();
    press.release();

    await expect(frame.locator('.intake-check__unanswered')).toHaveText(UNANSWERED_LINE);
    await expect(frame.getByRole('button', { name: CHECK_AGAIN_LABEL })).toBeEnabled();
    await expect(duplicateLink).toBeFocused();
  });

  test('after 60 polls that still say running the button is offered again, polling stops, and a press checks again', async ({ page }) => {
    test.slow();
    const { form, token } = await openFormWithPausedClock(page);
    const { presses, polls } = await answerPressesAndPolls(page, token, [{ state: 'running' }]);
    const pollTimes = await recordPollTimes(page);
    await form.title.fill(TITLE);
    const swappedAtMs = await pressCheck(page, CHECK_LABEL, 'running');

    for (let poll = 1; poll <= MAX_POLLS; poll += 1) {
      await nextPoll(page, polls, poll);
    }

    const button = page.locator(CHECK_FRAME).getByRole('button', { name: CHECK_LABEL });
    await expect(button).toBeEnabled();
    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(await pollTimes()).toEqual(Array.from({ length: MAX_POLLS }, (_, index) => swappedAtMs + (index + 1) * POLL_INTERVAL_MS));
    expect(polls).toHaveLength(MAX_POLLS);

    await pressCheck(page, CHECK_LABEL, 'running');
    expect(presses).toHaveLength(2);
    expect(postedField(presses[1], '_intake_check_token')).toBe(token);
  });

  test('a hidden page sends no polls and goes on polling when it shows again', async ({ page }) => {
    const { form, token } = await openFormWithPausedClock(page);
    const { polls } = await answerPressesAndPolls(page, token, [{ state: 'running' }]);
    const pollTimes = await recordPollTimes(page);
    await form.title.fill(TITLE);
    await pressCheck(page, CHECK_LABEL, 'running');

    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.runFor(3 * POLL_INTERVAL_MS);
    expect(await pollTimes()).toEqual([]);

    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await nextPoll(page, polls, 1);
  });
});

test.describe('"Check with AI" when the admin turned AI help off', () => {
  test.use({ storageState: OMAR_STORAGE_STATE });
  useAiSettingsInEachTest(ORGANIZATION_SLUG, AI_OFF);

  test('the person sees why, and no button to press', async ({ page }) => {
    await page.clock.install({ time: FROZEN_AT });
    const form = await openNewTicketForm(page);
    await form.title.fill(TITLE);

    const frame = page.locator(CHECK_FRAME);
    await expect(frame.locator('.intake-check__ai-off')).toHaveText('Your admin turned AI help off.');
    await expect(frame.getByRole('button', { name: CHECK_LABEL })).toHaveCount(0);
    await expect(form.submit).toBeEnabled();
  });
});
