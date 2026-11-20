import type { Locator, Page } from '@playwright/test';

/** Where a pin's ring centre sits, as a percent of its layer's width and height, as the browser lays it out. */
export async function spotInLayer(marker: Locator): Promise<{ x: number; y: number }> {
  return marker.evaluate((pin) => {
    const layer = pin.closest('.pin-layer');
    if (!layer) {
      throw new Error('The pin is not inside a pin layer.');
    }
    const ring = pin.getBoundingClientRect();
    const box = layer.getBoundingClientRect();
    if (0 === box.width || 0 === box.height) {
      throw new Error('The pin layer has no size, so no spot can be read from it.');
    }
    return {
      x: ((ring.left + ring.width / 2 - box.left) / box.width) * 100,
      y: ((ring.top + ring.height / 2 - box.top) / box.height) * 100,
    };
  });
}

/**
 * Collects every Content Security Policy violation the page reports from now on, also inside Turbo frames and live
 * component re-renders, which stay in the same document: the violated directive and the blocked source of each.
 */
export async function recordCspViolations(page: Page): Promise<() => Promise<string[]>> {
  await page.addInitScript(() => {
    const violations: string[] = [];
    (window as unknown as { cspViolations: string[] }).cspViolations = violations;
    document.addEventListener('securitypolicyviolation', (event) => {
      violations.push(`${event.violatedDirective} ${event.blockedURI}`);
    });
  });
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if ('error' === message.type() && /Content Security Policy/i.test(message.text())) {
      consoleErrors.push(message.text());
    }
  });
  return async () => [...(await page.evaluate(() => (window as unknown as { cspViolations?: string[] }).cspViolations ?? [])), ...consoleErrors];
}
