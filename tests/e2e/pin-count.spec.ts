import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { pinCountText } from '../../assets/review/pin_count.js';

// Pure helpers, run in Node with no page and no app.

test.describe('pinCountText', () => {
  test('says pins are optional when there are none', () => {
    expect(pinCountText(0)).toBe('Pins are optional, but they help the developer find the problem fast.');
  });

  test('counts one pin in the singular', () => {
    expect(pinCountText(1)).toBe('1 pin. Each pin becomes a checklist item for the developer and the tester.');
  });

  test('counts more pins in the plural', () => {
    expect(pinCountText(2)).toBe('2 pins. Each pin becomes a checklist item for the developer and the tester.');
    expect(pinCountText(100)).toBe('100 pins. Each pin becomes a checklist item for the developer and the tester.');
  });
});
