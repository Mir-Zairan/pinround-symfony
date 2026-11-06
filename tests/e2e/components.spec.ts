import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';

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

// Chromium restores focus to whatever had it when showModal() ran. Focusing another element first and then
// clicking the opener from script makes that native target wrong, so only the controller can put focus back.
async function openFromElsewhere(page: Page, opener: Locator): Promise<void> {
  await page.getByRole('button', { name: 'More actions' }).focus();
  await opener.evaluate((button) => (button as HTMLElement).click());
}

test.describe('dialog', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('a press that starts inside the dialog and ends on the backdrop keeps it open', async ({ page }) => {
    await openGallery(page);
    await page.getByRole('button', { name: 'Open dialog' }).click();
    const dialog = page.getByRole('dialog');
    const box = (await dialog.getByRole('heading', { name: 'Share this round?' }).boundingBox())!;

    await page.mouse.move(box.x + 4, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(5, 5);
    await page.mouse.up();

    await expect(dialog).toBeVisible();
  });

  test('a press that starts on the backdrop and ends inside the dialog keeps it open', async ({ page }) => {
    await openGallery(page);
    await page.getByRole('button', { name: 'Open dialog' }).click();
    const dialog = page.getByRole('dialog');
    const box = (await dialog.getByRole('heading', { name: 'Share this round?' }).boundingBox())!;

    await page.mouse.move(5, 5);
    await page.mouse.down();
    await page.mouse.move(box.x + 4, box.y + box.height / 2);
    await page.mouse.up();

    await expect(dialog).toBeVisible();
  });

  test('removing the dialog controller while it is open closes the dialog and leaves the page usable', async ({ page }) => {
    await openGallery(page);
    await page.getByRole('button', { name: 'Open dialog' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    // Only the controller goes; the <dialog> stays in the page, so the browser cannot drop it from the top layer itself.
    await page.evaluate(() => document.querySelector('[data-controller="dialog"]')!.removeAttribute('data-controller'));

    await expect(page.getByRole('dialog')).toBeHidden();
    await page.getByRole('button', { name: 'More actions' }).click();
    await expect(page.getByRole('button', { name: 'More actions' })).toHaveAttribute('aria-expanded', 'true');
  });

  test('connecting resets a dialog that is open without being modal and a menu that is open', async ({ page }) => {
    await openGallery(page);

    await page.evaluate(() => {
      const wrapper = document.createElement('div');
      wrapper.innerHTML = `
        <div id="restored-dialog" data-controller="dialog">
          <dialog id="restored-dialog-box" open data-dialog-target="dialog"><p>Restored</p></dialog>
        </div>
        <div id="restored-menu" data-controller="menu">
          <button id="restored-menu-trigger" type="button" aria-expanded="true" data-menu-target="trigger">Restored menu</button>
          <div id="restored-menu-list" role="menu" data-menu-target="list"><button type="button" role="menuitem" data-menu-target="item">One</button></div>
        </div>`;
      document.querySelector('main')!.append(wrapper);
    });

    await expect(page.locator('#restored-dialog-box')).not.toHaveAttribute('open', '');
    await expect(page.locator('#restored-menu-trigger')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#restored-menu-list')).toBeHidden();
  });

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
    await openFromElsewhere(page, opener);
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');

    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('the close button closes the dialog and focus returns to the opener', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await openFromElsewhere(page, opener);
    await page.getByRole('button', { name: 'Close' }).click();

    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('Cancel closes the dialog and focus returns to the opener', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await openFromElsewhere(page, opener);
    await page.getByRole('button', { name: 'Cancel' }).click();

    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('a click on the backdrop closes the dialog and a click inside does not', async ({ page }) => {
    await openGallery(page);
    const opener = page.getByRole('button', { name: 'Open dialog' });
    await openFromElsewhere(page, opener);
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

  test('Escape closes a menu opened with the mouse and focus stays on the trigger', async ({ page }) => {
    await openGallery(page);
    await trigger(page).click();
    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('Escape');

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(items(page).first()).toBeHidden();
    await expect(trigger(page)).toBeFocused();
  });

  test('Tab closes a menu opened with the mouse', async ({ page }) => {
    await openGallery(page);
    await trigger(page).click();

    await page.keyboard.press('Tab');

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(items(page).first()).toBeHidden();
  });

  test('clicking the trigger twice opens and closes the menu', async ({ page }) => {
    await openGallery(page);

    await trigger(page).click();
    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');
    await trigger(page).click();

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

  test('moving focus out of the open menu from script closes it', async ({ page }) => {
    await openGallery(page);
    await trigger(page).click();
    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');

    await page.getByRole('button', { name: 'Open dialog' }).focus();

    await expect(trigger(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(items(page).first()).toBeHidden();
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

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  const hasNoHorizontalScroll = (page: Page) =>
    page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

  // A modal dialog sits in the top layer and never widens the page, so its own box has to be checked.
  const lieWithinViewport = async (locator: Locator) => {
    const box = (await locator.boundingBox())!;
    return box.x >= 0 && box.x + box.width <= 360;
  };

  test('the open dialog and the open menu stay within 360px', async ({ page }) => {
    await openGallery(page);
    await page.getByRole('button', { name: 'Open dialog' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect(await hasNoHorizontalScroll(page)).toBe(true);
    expect(await lieWithinViewport(page.getByRole('dialog'))).toBe(true);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'More actions' }).click();
    await expect(page.getByRole('menuitem').first()).toBeVisible();
    expect(await hasNoHorizontalScroll(page)).toBe(true);
    expect(await lieWithinViewport(page.getByRole('menu'))).toBe(true);
  });
});
