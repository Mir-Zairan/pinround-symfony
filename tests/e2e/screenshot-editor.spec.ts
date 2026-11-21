import { expect, test, type Locator, type Page } from '@playwright/test';

test.use({ viewport: { width: 1280, height: 900 } });

const NOTE_FULL_MESSAGE = 'A note holds at most 1,000 characters. Anything more is cut.';

// 1,000 code points but 1,500 UTF-16 units: a limit that counts units, as the browser's maxlength does, cuts it.
const FULL_NOTE = 'é価😀🙂'.repeat(250);

type EditorEvent = { type: string; detail: unknown };

async function openEditorExample(page: Page) {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/_gallery', { waitUntil: 'networkidle' });
  const form = page.locator('form#gallery-screenshot-editor-form');
  const editor = form.locator('#gallery-screenshot-editor');
  await editor.scrollIntoViewIfNeeded();
  const panel = (number: number) => editor.locator(`#gallery-screenshot-editor-panel-${number}`);
  return {
    form,
    editor,
    panel,
    tab: (number: number) => editor.getByRole('tab', { name: `Screenshot ${number} of 3` }),
    status: editor.locator('[data-screenshot-editor-target="status"]'),
    addScreenshot: editor.getByRole('button', { name: 'Add screenshot' }),
    removeScreenshot: (number: number) => panel(number).getByRole('button', { name: 'Remove screenshot' }),
    keepIt: (number: number) => panel(number).getByRole('button', { name: 'Keep it' }),
  };
}

type EditorExample = Awaited<ReturnType<typeof openEditorExample>>;

async function expectShown({ tab, panel }: EditorExample, shown: number): Promise<void> {
  for (const number of [1, 2, 3]) {
    const isShown = number === shown;
    await expect(tab(number)).toHaveAttribute('aria-selected', isShown ? 'true' : 'false');
    await expect(tab(number)).toHaveAttribute('tabindex', isShown ? '0' : '-1');
    if (isShown) {
      await expect(panel(number)).toBeVisible();
    } else {
      await expect(panel(number)).toBeHidden();
    }
  }
}

/** What the form around the editor would post, in document order. */
async function postedFields(form: Locator): Promise<[string, string][]> {
  return form.evaluate((element: HTMLFormElement) => [...new FormData(element).entries()].map(([name, value]): [string, string] => [name, String(value)]));
}

async function postedValueOf(form: Locator, name: string): Promise<string | undefined> {
  return (await postedFields(form)).find(([postedName]) => postedName === name)?.[1];
}

async function postedRemoveFields(form: Locator): Promise<[string, string][]> {
  return (await postedFields(form)).filter(([name]) => name.endsWith('[remove]'));
}

test('the first screenshot shows and only its thumbnail is in the tab order', async ({ page }) => {
  const example = await openEditorExample(page);

  await expectShown(example, 1);

  await example.tab(1).focus();
  await page.keyboard.press('Tab');
  await expect(example.addScreenshot).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(example.tab(1)).toBeFocused();
});

test('a click on a thumbnail shows its screenshot and the hidden ones still post their fields', async ({ page }) => {
  const example = await openEditorExample(page);

  await example.tab(3).click();

  await expectShown(example, 3);
  expect(await postedValueOf(example.form, 'screenshots[0][title]')).toBe('Checkout page');
  expect(await postedValueOf(example.form, 'screenshots[1][pins][0]')).toBe('The quantity does not update.');
  expect(await postedRemoveFields(example.form)).toEqual([]);
});

test('the arrow keys move between thumbnails, wrap at both ends and move the tab stop along', async ({ page }) => {
  const example = await openEditorExample(page);
  await example.tab(1).focus();

  for (const [key, expected] of [
    ['ArrowRight', 2],
    ['ArrowRight', 3],
    ['ArrowRight', 1],
    ['ArrowLeft', 3],
    ['ArrowLeft', 2],
  ] as const) {
    await page.keyboard.press(key);
    await expect(example.tab(expected), `${key} lands on screenshot ${expected}`).toBeFocused();
    await expectShown(example, expected);
  }

  await page.keyboard.press('Tab');
  await expect(example.addScreenshot).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(example.tab(2)).toBeFocused();
});

test('Home and End jump to the first and the last thumbnail', async ({ page }) => {
  const example = await openEditorExample(page);
  await example.tab(1).focus();

  await page.keyboard.press('End');
  await expect(example.tab(3)).toBeFocused();
  await expectShown(example, 3);

  await page.keyboard.press('Home');
  await expect(example.tab(1)).toBeFocused();
  await expectShown(example, 1);

  await example.tab(2).click();
  await page.keyboard.press('End');
  await expect(example.tab(3)).toBeFocused();

  await example.tab(2).click();
  await page.keyboard.press('Home');
  await expect(example.tab(1)).toBeFocused();
  await expectShown(example, 1);
});

test('the up and down arrows leave the shown screenshot as it is', async ({ page }) => {
  const example = await openEditorExample(page);
  await example.tab(2).click();

  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowUp');

  await expect(example.tab(2)).toBeFocused();
  await expectShown(example, 2);
});

test('Remove screenshot hides its fields and posts its remove field, and Keep it takes that back', async ({ page }) => {
  const { form, panel, tab, status, removeScreenshot, keepIt } = await openEditorExample(page);
  await tab(2).click();
  const title = form.locator('[name="screenshots[1][title]"]');
  await expect(title).toBeVisible();

  await removeScreenshot(2).click();

  await expect(title).toBeHidden();
  await expect(panel(2).getByLabel('Note for pin 1')).toBeHidden();
  await expect(panel(2).getByText('Removed. It goes when you save.')).toBeVisible();
  await expect(keepIt(2)).toBeFocused();
  await expect(status).toHaveText('Screenshot 2 removed. It goes when you save.');
  await expect(tab(2)).toContainText('Removed');
  await expect(tab(2)).toHaveClass(/screenshot-editor__thumb--removed/);
  await expect(tab(1)).not.toHaveClass(/screenshot-editor__thumb--removed/);
  expect(await postedRemoveFields(form)).toEqual([['screenshots[1][remove]', '1']]);

  await keepIt(2).click();

  await expect(title).toBeVisible();
  await expect(panel(2).getByText('Removed. It goes when you save.')).toBeHidden();
  await expect(removeScreenshot(2)).toBeFocused();
  await expect(status).toHaveText('Screenshot 2 stays.');
  await expect(tab(2)).not.toContainText('Removed');
  await expect(tab(2)).not.toHaveClass(/screenshot-editor__thumb--removed/);
  expect(await postedRemoveFields(form)).toEqual([]);
});

test('Add screenshot and Remove screenshot fire the events the page acts on', async ({ page }) => {
  const { form, editor, tab, addScreenshot, removeScreenshot } = await openEditorExample(page);
  await form.evaluate((element) => {
    const events: EditorEvent[] = [];
    (window as unknown as { editorEvents: EditorEvent[] }).editorEvents = events;
    for (const type of ['screenshot-editor:add', 'screenshot-editor:remove']) {
      element.addEventListener(type, (event) => events.push({ type, detail: (event as CustomEvent).detail }));
    }
  });
  const firedEvents = () => page.evaluate(() => (window as unknown as { editorEvents: EditorEvent[] }).editorEvents);

  await addScreenshot.click();
  await tab(3).click();
  await removeScreenshot(3).click();

  expect(await firedEvents()).toEqual([
    { type: 'screenshot-editor:add', detail: {} },
    { type: 'screenshot-editor:remove', detail: { index: 2 } },
  ]);
  await expect(editor.getByRole('tab'), 'The page adds screenshots; the editor adds none itself.').toHaveCount(3);
});

test('a note of 1,000 multibyte characters is kept whole and anything more is cut', async ({ page }) => {
  const { form, panel, tab, status } = await openEditorExample(page);
  await tab(2).click();
  const note = panel(2).getByLabel('Note for pin 1');
  expect({ codePoints: [...FULL_NOTE].length, utf16Units: FULL_NOTE.length }).toEqual({ codePoints: 1000, utf16Units: 1500 });

  await note.fill(FULL_NOTE);

  expect(await note.inputValue()).toBe(FULL_NOTE);
  expect(await postedValueOf(form, 'screenshots[1][pins][0]')).toBe(FULL_NOTE);
  await expect(status).toHaveText(NOTE_FULL_MESSAGE);
  await expect(panel(2).getByRole('button', { name: /^Pin 1\b/ })).toHaveAttribute('aria-label', `Pin 1: ${FULL_NOTE}`);

  await page.keyboard.insertText('😀');
  expect(await note.inputValue()).toBe(FULL_NOTE);

  await note.fill(`${FULL_NOTE}😀`);
  expect(await note.inputValue()).toBe(FULL_NOTE);
  await expect(status).toHaveText(NOTE_FULL_MESSAGE);

  await note.fill('The quantity does not update.');
  await expect(status).toHaveText('');
});

test('a summary of 600 multibyte characters, its limit, is kept whole', async ({ page }) => {
  const { form, panel } = await openEditorExample(page);
  const fullSummary = 'é価'.repeat(300);

  await panel(1).getByLabel('What this picture shows · optional').fill(fullSummary);

  expect(await postedValueOf(form, 'screenshots[0][summary]')).toBe(fullSummary);
});
