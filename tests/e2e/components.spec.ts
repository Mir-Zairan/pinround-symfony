import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openGallery(page: Page): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/_gallery', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

function isInsideDialog(page: Page): Promise<boolean> {
  return page.evaluate(() => document.activeElement?.closest('dialog') != null);
}

// With a modal dialog open the page behind is inert, so focus is in the dialog or has left the document
// for the browser chrome (activeElement is then the body). It must never land on page content.
function isInsideDialogOrBrowserChrome(page: Page): Promise<boolean> {
  return page.evaluate(() => document.activeElement === document.body || document.activeElement?.closest('dialog') != null);
}

test.describe('dialog', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('opening moves focus inside the dialog and Tab never leaves it', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await opener.focus();
    await page.keyboard.press('Enter');

    const dialog = page.getByRole('dialog', { name: 'Share this round?' });
    await expect(dialog).toBeVisible();
    expect(await isInsideDialog(page)).toBe(true);

    for (let step = 0; step < 8; step++) {
      await page.keyboard.press('Tab');
      expect(await isInsideDialogOrBrowserChrome(page), `Tab ${step + 1} reached page content`).toBe(true);
    }
    for (let step = 0; step < 8; step++) {
      await page.keyboard.press('Shift+Tab');
      expect(await isInsideDialogOrBrowserChrome(page), `Shift+Tab ${step + 1} reached page content`).toBe(true);
    }
  });

  test('Escape closes the dialog and focus returns to the opener', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await opener.click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');

    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('the close button closes the dialog and focus returns to the opener', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await opener.click();
    await page.getByRole('button', { name: 'Close' }).click();

    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('Cancel closes the dialog and focus returns to the opener', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await opener.click();
    await page.getByRole('button', { name: 'Cancel' }).click();

    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('a click on the backdrop closes the dialog and a click inside does not', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await opener.click();
    const dialog = page.getByRole('dialog');

    await dialog.getByRole('heading', { name: 'Share this round?' }).click();
    await expect(dialog).toBeVisible();

    await page.mouse.click(5, 5);
    await expect(dialog).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('the open dialog has no serious or critical accessibility violations', async ({ page }) => {
    await openGallery(page);
    await page.getByRole('button', { name: 'Open dialog' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    expect(await seriousViolations(page)).toEqual([]);
  });
});

test.describe('menu', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  const trigger = (page: Page) => page.getByRole('button', { name: 'More actions' });
  const items = (page: Page) => page.getByRole('menuitem');

  test('Enter opens the menu and focuses the first item', async ({ page }) => {
    await openGallery(page);
    await trigger(page).focus();
    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');

    await page.keyboard.press('Enter');

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');
    await expect(items(page).first()).toBeFocused();
  });

  test('Space opens the menu and focuses the first item', async ({ page }) => {
    await openGallery(page);
    await trigger(page).focus();

    await page.keyboard.press('Space');

    await expect(items(page).first()).toBeFocused();
  });

  test('ArrowDown on the trigger opens the menu and focuses the first item', async ({ page }) => {
    await openGallery(page);
    await trigger(page).focus();

    await page.keyboard.press('ArrowDown');

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');
    await expect(items(page).first()).toBeFocused();
  });

  test('a mouse click opens the menu and keeps focus on the trigger', async ({ page }) => {
    await openGallery(page);

    await trigger(page).click();

    await expect(items(page)).toHaveCount(3);
    await expect(items(page).first()).toBeVisible();
    await expect(trigger(page)).toBeFocused();
  });

  test('arrow keys, Home and End move focus between items', async ({ page }) => {
    await openGallery(page);
    await trigger(page).focus();
    await page.keyboard.press('ArrowDown');

    await page.keyboard.press('ArrowDown');
    await expect(items(page).nth(1)).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(items(page).nth(2)).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(items(page).nth(0)).toBeFocused();
    await page.keyboard.press('ArrowUp');
    await expect(items(page).nth(2)).toBeFocused();
    await page.keyboard.press('Home');
    await expect(items(page).nth(0)).toBeFocused();
    await page.keyboard.press('End');
    await expect(items(page).nth(2)).toBeFocused();
  });

  test('Escape closes the menu and focus returns to the trigger', async ({ page }) => {
    await openGallery(page);
    await trigger(page).focus();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');

    await page.keyboard.press('Escape');

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(items(page).first()).toBeHidden();
    await expect(trigger(page)).toBeFocused();
  });

  test('a click outside closes the menu', async ({ page }) => {
    await openGallery(page);
    await trigger(page).click();
    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');

    await page.getByRole('heading', { name: 'Component gallery' }).click();

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(items(page).first()).toBeHidden();
  });

  test('Tab closes the menu and moves focus out of it', async ({ page }) => {
    await openGallery(page);
    await trigger(page).focus();
    await page.keyboard.press('ArrowDown');

    await page.keyboard.press('Tab');

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(items(page).first()).toBeHidden();
    await expect(trigger(page)).not.toBeFocused();
  });

  test('choosing an item closes the menu and focus returns to the trigger', async ({ page }) => {
    await openGallery(page);
    await trigger(page).click();

    await items(page).nth(1).click();

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(trigger(page)).toBeFocused();
  });

  test('the open menu has no serious or critical accessibility violations', async ({ page }) => {
    await openGallery(page);
    await trigger(page).click();
    await expect(items(page).first()).toBeVisible();

    expect(await seriousViolations(page)).toEqual([]);
  });
});
