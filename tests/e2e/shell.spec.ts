import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { LENA_STORAGE_STATE } from './auth';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];
// The shell is the logged-in page: every test here starts as Lena (auth.setup.ts).
test.use({ storageState: LENA_STORAGE_STATE });

const LIGHT_SURFACE = 'rgb(244, 251, 248)';
const DARK_SURFACE = 'rgb(14, 21, 19)';
// Wide enough for the longest count each one shows in a seeded run (three digits).
const FIXED_COUNT_WIDTHS = { '.home__section-note': '160px' };

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openShell(page: Page): Promise<void> {
  await page.goto('/');
  await page.evaluate(() => document.fonts.ready);
}

for (const scheme of ['light', 'dark'] as const) {
  test.describe(`${scheme} colour scheme`, () => {
    test.use({ colorScheme: scheme, viewport: { width: 1280, height: 800 } });

    test(`shell has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await openShell(page);
      expect(await seriousViolations(page)).toEqual([]);
    });

    test(`shell looks the same as the ${scheme} baseline`, async ({ page }) => {
      test.skip(!!process.env.CI, 'Baselines come from a developer machine and CI fonts render differently; run make e2e locally.');
      await openShell(page);
      // Home's date line changes every day; the counts and the Needs you list grow with what other specs do as Lena
      // (pings, reports, tickets), and a toast one of them flashed can still show on her shared session.
      const changingRegions = [
        '.home__date',
        '.bell__trigger',
        '.home__title',
        '.home__section-note',
        '.home__needs',
        '.home__also-waiting',
        '.toast',
      ].map((selector) => page.locator(selector));
      // A mask takes the size of the count under it, so "top 10 of 59" growing to "of 66" moved the mask's edge. The
      // counts get a fixed width through the CSSOM, which the CSP allows where an injected stylesheet (stylePath) is blocked.
      for (const [selector, width] of Object.entries(FIXED_COUNT_WIDTHS)) {
        await page.locator(selector).evaluateAll((counts, fixedWidth) => {
          for (const count of counts as HTMLElement[]) {
            count.style.flex = 'none';
            count.style.width = fixedWidth;
          }
        }, width);
      }
      // My reports' badge shows only while one of Lena's reports needs her answer, which other specs make or settle,
      // and a mask cannot hide a badge that is missing: the menu items after it move. The demo banner shows only on a
      // dev stack with PINROUND_DEMO_SKIP_2FA on (ADR-0049) and pushes the whole page down. The baseline has neither.
      await page.locator('.nav__badge, .demo-mode-banner').evaluateAll((varying) => {
        for (const element of varying) {
          element.remove();
        }
      });
      await expect(page).toHaveScreenshot(`shell-${scheme}.png`, { mask: changingRegions });
    });
  });
}

test.describe('narrow viewport', () => {
  test.use({ viewport: { width: 360, height: 800 } });

  test('shell does not scroll horizontally at 360px', async ({ page }) => {
    await openShell(page);
    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    console.log(`360px: scrollWidth=${scrollWidth} clientWidth=${clientWidth}`);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });

  test('scroll padding covers the wrapped sticky header at 360px', async ({ page }) => {
    await openShell(page);
    const { headerHeight, scrollPaddingTop } = await page.evaluate(() => ({
      headerHeight: document.querySelector('.site-header')!.getBoundingClientRect().height,
      scrollPaddingTop: parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop),
    }));
    console.log(`360px: headerHeight=${headerHeight} scrollPaddingTop=${scrollPaddingTop}`);
    expect(scrollPaddingTop).toBeGreaterThan(0);
    expect(headerHeight).toBeLessThanOrEqual(scrollPaddingTop);
  });
});

for (const viewport of [
  { width: 1440, height: 900 },
  { width: 1280, height: 800 },
  { width: 390, height: 844 },
  { width: 360, height: 800 },
]) {
  test.describe(`${viewport.width}x${viewport.height}`, () => {
    test.use({ viewport });

    test(`the logo lines up with the page content at ${viewport.width}px`, async ({ page }) => {
      await openShell(page);
      const { logoLeft, contentLeft } = await page.evaluate(() => {
        const content = document.querySelector('.page')!;
        return {
          logoLeft: document.querySelector('.site-header .logo')!.getBoundingClientRect().left,
          contentLeft: content.getBoundingClientRect().left + parseFloat(getComputedStyle(content).paddingLeft),
        };
      });

      expect(logoLeft).toBeCloseTo(contentLeft, 0);
    });

    test(`shell has no serious or critical accessibility violations at ${viewport.width}px`, async ({ page }) => {
      await openShell(page);
      expect(await seriousViolations(page)).toEqual([]);
    });
  });
}

test('page requests only the app origin and loads the fonts locally', async ({ page, baseURL }) => {
  const origin = new URL(baseURL!).origin;
  const foreignRequests: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).origin !== origin) {
      foreignRequests.push(request.url());
    }
  });
  await page.goto('/', { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);

  expect(foreignRequests).toEqual([]);
  expect(await page.evaluate(() => document.fonts.check('15px "Atkinson Hyperlegible Next"'))).toBe(true);
});

test.describe('theme override', () => {
  test('data-theme dark wins over a light system scheme', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await openShell(page);
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    await expect(page.locator('body')).toHaveCSS('background-color', DARK_SURFACE);
  });

  test('data-theme light wins over a dark system scheme', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await openShell(page);
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
    await expect(page.locator('body')).toHaveCSS('background-color', LIGHT_SURFACE);
  });
});

test('first Tab stop is the skip link with a 2px solid focus outline', async ({ page }) => {
  await openShell(page);
  await page.keyboard.press('Tab');
  const skipLink = page.locator('.skip-link');
  await expect(skipLink).toBeFocused();
  await expect(skipLink).toHaveCSS('outline-width', '2px');
  await expect(skipLink).toHaveCSS('outline-style', 'solid');
});
