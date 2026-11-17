import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { isSubmitHeldFor } from '../../assets/review/submit_hold.js';

// Pure helpers, run in Node with no page and no app.

test.describe('isSubmitHeldFor', () => {
  test('holds the submit while the picture uploads and while it processes', () => {
    expect(isSubmitHeldFor('uploading', false)).toBe(true);
    expect(isSubmitHeldFor('pending', false)).toBe(true);
  });

  test('lets the submit through once the wait is over, even while the picture is still pending', () => {
    expect(isSubmitHeldFor('pending', true)).toBe(false);
    expect(isSubmitHeldFor('uploading', true)).toBe(false);
  });

  test('lets the submit through with no picture, a ready one or a rejected one', () => {
    for (const mediaState of [undefined, '', 'ready', 'rejected', 'Uploading', 'pending ']) {
      expect(isSubmitHeldFor(mediaState, false)).toBe(false);
    }
  });
});
