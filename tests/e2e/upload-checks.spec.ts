import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { carriesFiles, MAX_BYTES, pickedFile, refusalFor, WRONG_SIZE_MESSAGE, WRONG_TYPE_MESSAGE } from '../../assets/review/upload_checks.js';

// Pure helpers, run in Node with no page and no app.

const picture = (type: string, size: number, name = 'Café ☕ 画面.png') => ({ type, size, name });

test.describe('refusalFor', () => {
  test('lets PNG, JPEG and WebP of 1 byte up to exactly 15 MB through', () => {
    for (const type of ['image/png', 'image/jpeg', 'image/webp']) {
      expect(refusalFor(picture(type, 1))).toBeNull();
      expect(refusalFor(picture(type, MAX_BYTES))).toBeNull();
    }
    expect(MAX_BYTES).toBe(15 * 1024 * 1024);
  });

  test('refuses a GIF, and any type that is not exactly one of the three, with the type message', () => {
    for (const type of ['image/gif', 'image/PNG', 'image/png; charset=binary', 'application/pdf', '']) {
      expect(refusalFor(picture(type, 1000))).toBe(WRONG_TYPE_MESSAGE);
    }
  });

  test('refuses 15 MB + 1 and an empty file with the size message', () => {
    expect(refusalFor(picture('image/png', MAX_BYTES + 1))).toBe(WRONG_SIZE_MESSAGE);
    expect(refusalFor(picture('image/png', 0))).toBe(WRONG_SIZE_MESSAGE);
  });

  test('names the type first when both are wrong, as the server does', () => {
    expect(refusalFor(picture('image/gif', 0))).toBe(WRONG_TYPE_MESSAGE);
  });

  test('uses the plain texts the server sends', () => {
    expect(WRONG_TYPE_MESSAGE).toBe('This file is not a PNG, JPEG or WebP screenshot.');
    expect(WRONG_SIZE_MESSAGE).toBe('This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.');
  });
});

test.describe('pickedFile', () => {
  test('takes the first picture of a clipboard that also holds other files', () => {
    const text = picture('text/plain', 5);
    const first = picture('image/png', 5, 'first.png');
    const second = picture('image/jpeg', 5, 'second.jpg');

    expect(pickedFile([text, first, second])).toBe(first);
  });

  test('takes the first file when none is a picture, so the type check can refuse it', () => {
    const pdf = picture('application/pdf', 5);

    expect(pickedFile([pdf])).toBe(pdf);
  });

  test('returns null for no files, null and undefined', () => {
    expect(pickedFile([])).toBeNull();
    expect(pickedFile(null)).toBeNull();
    expect(pickedFile(undefined)).toBeNull();
  });
});

test.describe('carriesFiles', () => {
  test('takes a drag that carries files, alone or with other types', () => {
    expect(carriesFiles(['Files'])).toBe(true);
    expect(carriesFiles(['text/uri-list', 'Files'])).toBe(true);
  });

  test('reads a DOMStringList-like list the way it reads an array', () => {
    const domStringList = { 0: 'text/plain', 1: 'Files', length: 2 };
    expect(carriesFiles(domStringList)).toBe(true);
  });

  test('leaves a drag of text or a link, an empty drag and no types to the browser', () => {
    expect(carriesFiles(['text/plain'])).toBe(false);
    expect(carriesFiles(['text/plain', 'text/html', 'text/uri-list'])).toBe(false);
    expect(carriesFiles(['files'])).toBe(false);
    expect(carriesFiles([])).toBe(false);
    expect(carriesFiles(null)).toBe(false);
    expect(carriesFiles(undefined)).toBe(false);
  });
});
