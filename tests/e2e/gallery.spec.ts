import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

async function seriousViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`);
}

async function openGallery(page: Page, theme: 'light' | 'dark'): Promise<void> {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto(`/_gallery?theme=${theme}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
}

for (const scheme of ['light', 'dark'] as const) {
  test.describe(`gallery in ${scheme}`, () => {
    test.use({ colorScheme: scheme, viewport: { width: 1280, height: 800 } });

    test(`has no serious or critical accessibility violations in ${scheme}`, async ({ page }) => {
      await openGallery(page, scheme);
      expect(await seriousViolations(page)).toEqual([]);
    });

    test(`looks the same as the ${scheme} baseline`, async ({ page }) => {
      test.skip(!!process.env.CI, 'Baselines come from a developer machine and CI fonts render differently; run make e2e locally.');
      await openGallery(page, scheme);
      // The demo banner shows only on a dev stack with PINROUND_DEMO_SKIP_2FA on (ADR-0049) and pushes the whole page
      // down; the baseline has none, as in shell.spec.ts.
      await page.locator('.demo-mode-banner').evaluateAll((banners) => {
        for (const banner of banners) {
          banner.remove();
        }
      });
      await expect(page).toHaveScreenshot(`gallery-${scheme}.png`, { fullPage: true });
    });
  });
}

test('the person picker opens with its sample rows and sends no request', async ({ page }) => {
  await openGallery(page, 'light');
  const requests: string[] = [];
  page.on('request', (request) => requests.push(request.url()));

  await page.locator('#gallery-person-picker ~ .gallery-row').getByRole('button', { name: 'Omar' }).click();

  const picker = page.getByRole('dialog', { name: 'Show tickets of…' });
  await expect(picker).toBeVisible();
  await expect(picker.locator('.pick')).toHaveText([/Omar\s*3 open/, /Mia\s*1 open/, /Lena\s*0 open/]);
  await picker.getByRole('button', { name: 'Cancel' }).click();
  await expect(picker).toBeHidden();
  expect(requests).toEqual([]);
});

// Sizes from docs/04-prototype/prototype-guide.md "Component classes".
test.describe('component sizes match the prototype classes', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  type Measured = { width: number; height: number; fontSize: number; radius: number };

  async function measure(page: Page, selector: string): Promise<Measured> {
    return page.locator(selector).first().evaluate((element) => {
      const box = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      return {
        width: box.width,
        height: box.height,
        fontSize: parseFloat(style.fontSize),
        radius: parseFloat(style.borderTopLeftRadius),
      };
    });
  }

  test.beforeEach(async ({ page }) => {
    await openGallery(page, 'light');
  });

  test('buttons are a 40px pill and a 32px small pill', async ({ page }) => {
    const medium = await measure(page, '.btn:not(.btn-sm)');
    const small = await measure(page, '.btn-sm');

    expect(medium.height).toBe(40);
    expect(medium.fontSize).toBe(14);
    expect(medium.radius).toBeGreaterThanOrEqual(20);
    expect(small.height).toBe(32);
    expect(small.fontSize).toBe(13);
    expect(small.radius).toBeGreaterThanOrEqual(16);
  });

  test('a chip is 34px high with a 10px radius', async ({ page }) => {
    const chip = await measure(page, '.chip');

    expect(chip.height).toBe(34);
    expect(chip.radius).toBe(10);
  });

  test('a segmented toggle is a 32px pill', async ({ page }) => {
    const segment = await measure(page, '.seg');

    expect(segment.height).toBe(32);
    expect(segment.radius).toBeGreaterThanOrEqual(16);
  });

  // 12px, not the prototype's 11px: ux-principles.md says text is never below 12px.
  for (const selector of ['.tag', '.aichip']) {
    test(`${selector} is 12px bold with a 6px radius`, async ({ page }) => {
      const label = await measure(page, selector);
      const weight = await page.locator(selector).first().evaluate((element) => getComputedStyle(element).fontWeight);

      expect(label.fontSize).toBe(12);
      expect(Number(weight)).toBeGreaterThanOrEqual(700);
      expect(label.radius).toBe(6);
    });
  }

  test('a status pill is a 30px pill with a 12px label and a decorative dot', async ({ page }) => {
    const pill = await measure(page, '.pill');
    const dot = await measure(page, '.pill__dot');

    expect(pill.height).toBe(30);
    expect(pill.fontSize).toBe(12);
    expect(pill.radius).toBeGreaterThanOrEqual(15);
    expect([dot.width, dot.height]).toEqual([8, 8]);
  });

  test('each status pill dot has its own group colour', async ({ page }) => {
    const colours = await page
      .locator('.pill__dot')
      .evaluateAll((dots) => dots.map((dot) => getComputedStyle(dot).backgroundColor));

    expect(colours).toHaveLength(5);
    expect(new Set(colours).size).toBe(5);
  });

  test('a card has a 16px radius', async ({ page }) => {
    expect((await measure(page, '.card')).radius).toBe(16);
  });

  test('avatars are 32px and 40px circles', async ({ page }) => {
    const regular = await measure(page, '.av:not(.av-lg)');
    const large = await measure(page, '.av-lg');

    expect([regular.width, regular.height]).toEqual([32, 32]);
    expect([large.width, large.height]).toEqual([40, 40]);
  });

  test('avatars in a stack overlap by 8px', async ({ page }) => {
    const [first, second] = await page
      .locator('.stack')
      .nth(1)
      .locator('.av')
      .evaluateAll((avatars) => avatars.slice(0, 2).map((avatar) => avatar.getBoundingClientRect()));

    expect(first.right - second.left).toBe(8);
  });

  test('a switch is 44 by 26px', async ({ page }) => {
    const switchControl = await measure(page, '.sw');

    expect([switchControl.width, switchControl.height]).toEqual([44, 26]);
  });
});
