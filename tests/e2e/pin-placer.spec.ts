import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

test.use({ viewport: { width: 1280, height: 900 } });

async function openEditableExample(page: Page) {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/_gallery', { waitUntil: 'networkidle' });
  const form = page.locator('form.pin-placer');
  await form.scrollIntoViewIfNeeded();
  return {
    form,
    frame: form.locator('.screenshot__frame'),
    addPin: form.getByRole('button', { name: 'Add pin' }),
    status: form.getByRole('status'),
    marker: (number: number) => form.getByRole('button', { name: new RegExp(`^Pin ${number}\\b`) }),
    note: (number: number) => form.getByLabel(`Note for pin ${number}`),
    field: (name: string) => form.locator(`input[name="${name}"]`),
  };
}

async function tabTo(page: Page, selector: string): Promise<void> {
  for (let presses = 0; presses < 200; presses++) {
    if (await page.evaluate((target) => document.activeElement?.matches(target) ?? false, selector)) {
      return;
    }
    await page.keyboard.press('Tab');
  }
  throw new Error(`Tab never reached ${selector}`);
}

async function relativeCentre(marker: ReturnType<Page['locator']>, frame: ReturnType<Page['locator']>) {
  const pin = (await marker.boundingBox())!;
  const picture = (await frame.boundingBox())!;
  return {
    x: ((pin.x + pin.width / 2 - picture.x) / picture.width) * 100,
    y: ((pin.y + pin.height / 2 - picture.y) / picture.height) * 100,
  };
}

test('the coordinate maths rounds to 0.1 and clamps to 0-100', async ({ page }) => {
  await page.goto('/_gallery', { waitUntil: 'networkidle' });

  const results = await page.evaluate(async () => {
    const maths = await import('controllers/pin-placer_controller.js');
    return {
      known: maths.percentOfPicture(100 + 33.333, 100, 100),
      leftEdge: maths.percentOfPicture(40, 100, 800),
      rightEdge: maths.percentOfPicture(990, 100, 800),
      emptyPicture: maths.percentOfPicture(50, 0, 0),
      moved: maths.movePercent(50, 1),
      movedBig: maths.movePercent(50, -5),
      movedPastEdge: maths.movePercent(99.5, 5),
      movedPastStart: maths.movePercent(0.5, -1),
      step: [maths.arrowStep(false), maths.arrowStep(true)],
      oneLine: maths.singleLineNote('  a\r\nb c  '),
      cut: maths.cutNote('ab😀', 3),
    };
  });

  expect(results).toEqual({
    known: 33.3,
    leftEdge: 0,
    rightEdge: 100,
    emptyPicture: 0,
    moved: 51,
    movedBig: 45,
    movedPastEdge: 100,
    movedPastStart: 0,
    step: [1, 5],
    oneLine: 'a b c',
    cut: 'ab',
  });
});

test('a click adds pin 1 at the clicked percent and it keeps its spot when the window resizes', async ({ page }) => {
  const { form, frame, marker, field } = await openEditableExample(page);
  const picture = (await frame.boundingBox())!;

  await frame.click({ position: { x: picture.width * 0.25, y: picture.height * 0.4 } });

  await expect(marker(1)).toBeVisible();
  expect(Number(await field('pins[1][x]').inputValue())).toBeCloseTo(25, 0);
  expect(Number(await field('pins[1][y]').inputValue())).toBeCloseTo(40, 0);
  await expect(form.getByLabel('Note for pin 1')).toBeFocused();
  const before = await relativeCentre(marker(1), frame);

  await page.setViewportSize({ width: 600, height: 900 });

  const after = await relativeCentre(marker(1), frame);
  expect((await frame.boundingBox())!.width).toBeLessThan(picture.width);
  expect(after.x).toBeCloseTo(before.x, 0);
  expect(after.y).toBeCloseTo(before.y, 0);
});

test('a click on the very edge puts the pin on 0 or 100, never outside', async ({ page }) => {
  const { frame, field } = await openEditableExample(page);

  await frame.click({ position: { x: 0, y: 0 } });

  expect(await field('pins[1][x]').inputValue()).toBe('0.0');
  expect(await field('pins[1][y]').inputValue()).toBe('0.0');
});

test('a person can drag a pin with the mouse', async ({ page }) => {
  const { frame, addPin, marker, field } = await openEditableExample(page);
  await addPin.click();
  const picture = (await frame.boundingBox())!;
  const pin = (await marker(1).boundingBox())!;

  await page.mouse.move(pin.x + pin.width / 2, pin.y + pin.height / 2);
  await page.mouse.down();
  await page.mouse.move(picture.x + picture.width * 0.8, picture.y + picture.height * 0.2, { steps: 5 });
  await page.mouse.up();

  expect(Number(await field('pins[1][x]').inputValue())).toBeCloseTo(80, 0);
  expect(Number(await field('pins[1][y]').inputValue())).toBeCloseTo(20, 0);
  await expect(frame.locator('.pin')).toHaveCount(1);
});

test('the keyboard alone adds, moves, describes and removes pins and numbers stay stable', async ({ page }) => {
  const { form, frame, marker, note, field, status } = await openEditableExample(page);
  const changes = await page.evaluateHandle(() => {
    const events: unknown[] = [];
    document.addEventListener('pin-placer:change', (event) => events.push((event as CustomEvent).detail));
    return events;
  });

  await tabTo(page, '[data-pin-add]');
  await page.keyboard.press('Enter');

  await expect(marker(1)).toBeFocused();
  await expect(status).toContainText('Pin 1 added');
  expect(await field('pins[1][x]').inputValue()).toBe('50.0');
  expect(await field('pins[1][y]').inputValue()).toBe('50.0');

  await page.keyboard.press('ArrowRight');
  expect(await field('pins[1][x]').inputValue()).toBe('51.0');
  await page.keyboard.press('Shift+ArrowDown');
  expect(await field('pins[1][y]').inputValue()).toBe('55.0');
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.press('ArrowUp');
  expect(await field('pins[1][x]').inputValue()).toBe('50.0');
  expect(await field('pins[1][y]').inputValue()).toBe('54.0');
  await page.keyboard.press('Shift+ArrowLeft');
  expect(await field('pins[1][x]').inputValue()).toBe('45.0');

  await page.keyboard.press('Enter');
  await expect(note(1)).toBeFocused();
  await page.keyboard.type('Label is cut off');
  await expect(form.getByRole('button', { name: 'Pin 1: Label is cut off', exact: true })).toBeVisible();
  expect(await form.locator('[name="pins[1][note]"]').inputValue()).toBe('Label is cut off');

  const last = (await changes.jsonValue()) as { pins: { number: number; x: number; y: number; note: string }[] }[];
  expect(last.at(-1)!.pins).toEqual([{ number: 1, x: 45, y: 54, note: 'Label is cut off' }]);

  await form.getByRole('button', { name: 'Add pin' }).click();
  await form.getByRole('button', { name: 'Add pin' }).click();
  await expect(frame.locator('.pin')).toHaveCount(3);

  await marker(2).focus();
  await page.keyboard.press('Delete');

  await expect(frame.locator('.pin')).toHaveCount(2);
  await expect(marker(2)).toHaveCount(0);
  await expect(marker(1)).toHaveCount(1);
  await expect(marker(3)).toHaveCount(1);
  await expect(form.locator('input[name^="pins["][type="hidden"]')).toHaveCount(4);
  await expect(marker(3)).toBeFocused();

  await form.getByRole('button', { name: 'Add pin' }).click();
  await expect(marker(4)).toBeVisible();
  await expect(marker(2)).toHaveCount(0);

  await marker(1).focus();
  await page.keyboard.press('Delete');
  await expect(marker(1)).toHaveCount(0);
  await expect(form.getByLabel('Note for pin 1')).toHaveCount(0);
});

test('a mouse user can remove a pin from the list', async ({ page }) => {
  const { form, addPin, marker, field } = await openEditableExample(page);
  await addPin.click();

  await form.getByRole('button', { name: 'Remove pin 1' }).click();

  await expect(marker(1)).toHaveCount(0);
  await expect(field('pins[1][x]')).toHaveCount(0);
  await expect(addPin).toBeFocused();
});

test('a note past 1,000 characters is cut and the page says so', async ({ page }) => {
  const { form, addPin, note, status, marker } = await openEditableExample(page);
  await addPin.click();

  await note(1).fill('x'.repeat(1100));

  expect(await note(1).inputValue()).toHaveLength(1000);
  await expect(status).toHaveText('A note holds at most 1,000 characters. Anything more is cut.');
  expect(await form.locator('[name="pins[1][note]"]').inputValue()).toHaveLength(1000);
  await expect(marker(1)).toHaveAttribute('aria-label', `Pin 1: ${'x'.repeat(1000)}`);

  await note(1).fill('short');
  await expect(status).toHaveText('');
});

test('the note bubble shows on focus and while the pin is selected', async ({ page }) => {
  const { form, addPin, note, marker } = await openEditableExample(page);
  await addPin.click();
  await note(1).fill('Check this');
  await addPin.click();
  const bubble = form.locator('.pin[data-pin-number="1"] .pin__bubble');

  await expect(bubble).toBeHidden();
  await marker(1).focus();
  await expect(bubble).toBeVisible();
  await expect(marker(1)).toHaveAttribute('aria-pressed', 'true');
});

test('the editable screenshot has no serious or critical accessibility violations', async ({ page }) => {
  const { form, addPin, note } = await openEditableExample(page);
  await addPin.click();
  await note(1).fill('Label is cut off');
  await form.getByRole('button', { name: 'Add pin' }).click();

  const { violations } = await new AxeBuilder({ page }).include('form.pin-placer').withTags(AXE_TAGS).analyze();

  expect(
    violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`),
  ).toEqual([]);
});
