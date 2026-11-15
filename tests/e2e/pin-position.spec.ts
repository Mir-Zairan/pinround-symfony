import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { cutNote, movePercent, neighbourNumber, noteLength, singleLineNote } from '../../assets/review/pin_position.js';

// Pure helpers, run in Node with no page and no app.

test.describe('noteLength', () => {
  test('counts code points, so an emoji is one, not two UTF-16 units', () => {
    expect(noteLength('')).toBe(0);
    expect(noteLength('abc')).toBe(3);
    expect(noteLength('😀')).toBe(1);
    expect(noteLength('a😀b')).toBe(3);
    expect('😀'.length).toBe(2);
  });

  test('counts a combining mark and a joined emoji by their code points, as mb_strlen does', () => {
    expect(noteLength('é')).toBe(2);
    expect(noteLength('👨‍👩‍👧')).toBe(5);
  });

  test('counts a byte order mark and a CRLF as characters', () => {
    expect(noteLength('﻿a\r\nb')).toBe(5);
  });
});

test.describe('cutNote', () => {
  test('returns the text itself at or under the limit', () => {
    expect(cutNote('abc', 3)).toBe('abc');
    expect(cutNote('abc', 4)).toBe('abc');
    expect(cutNote('', 0)).toBe('');
  });

  test('cuts plain text past the limit', () => {
    expect(cutNote('abcd', 3)).toBe('abc');
    expect(cutNote('abc', 0)).toBe('');
  });

  test('keeps a full note of emoji at the limit and cuts one more, without splitting a surrogate pair', () => {
    const atLimit = '😀'.repeat(1000);
    expect(atLimit.length).toBe(2000);
    expect(cutNote(atLimit, 1000)).toBe(atLimit);
    const cut = cutNote(`${atLimit}😀`, 1000);
    expect(cut).toBe(atLimit);
    expect(noteLength(cut)).toBe(1000);
    expect(cutNote('a😀b', 2)).toBe('a😀');
  });

  test('counts a combining mark as its own code point', () => {
    expect(cutNote('éé', 2)).toBe('é');
    expect(cutNote('éé', 3)).toBe('ée');
  });

  test('cuts multibyte text by code points', () => {
    expect(cutNote('日本語のメモ', 3)).toBe('日本語');
  });
});

test.describe('singleLineNote', () => {
  test('leaves a one-line note as it is', () => {
    expect(singleLineNote('header is cut off')).toBe('header is cut off');
    expect(singleLineNote('')).toBe('');
  });

  test('turns CRLF, lone CR, LF and U+2028 into one space each run', () => {
    expect(singleLineNote('a\r\nb')).toBe('a b');
    expect(singleLineNote('a\rb')).toBe('a b');
    expect(singleLineNote('a\nb')).toBe('a b');
    expect(singleLineNote('a b')).toBe('a b');
    expect(singleLineNote('a b')).toBe('a b');
    expect(singleLineNote('a\r\n\r\n\nb')).toBe('a b');
  });

  test('turns control characters such as ESC and NUL into a space', () => {
    expect(singleLineNote('a\u001B[31mb')).toBe('a [31mb');
    expect(singleLineNote('a\u0000b')).toBe('a b');
    expect(singleLineNote('a\tb')).toBe('a b');
  });

  test('trims a missing or present final newline and a leading byte order mark', () => {
    expect(singleLineNote('a\nb')).toBe('a b');
    expect(singleLineNote('a\nb\n')).toBe('a b');
    expect(singleLineNote('\n\na\r\n')).toBe('a');
    expect(singleLineNote('﻿a')).toBe('a');
  });

  test('keeps multibyte text and gives an empty line for only whitespace and controls', () => {
    expect(singleLineNote('日本\n語 😀')).toBe('日本 語 😀');
    expect(singleLineNote('\r\n \u001B ')).toBe('');
  });
});

test.describe('movePercent', () => {
  test('moves by the step in either direction', () => {
    expect(movePercent(50, 1)).toBe(51);
    expect(movePercent(50, 5)).toBe(55);
    expect(movePercent(50, -1)).toBe(49);
    expect(movePercent(50, -5)).toBe(45);
  });

  test('clamps at 0 and 100', () => {
    expect(movePercent(0, -1)).toBe(0);
    expect(movePercent(2, -5)).toBe(0);
    expect(movePercent(100, 1)).toBe(100);
    expect(movePercent(98, 5)).toBe(100);
    expect(movePercent(10, -50)).toBe(0);
    expect(movePercent(90, 50)).toBe(100);
  });

  test('allows the edges themselves', () => {
    expect(movePercent(1, -1)).toBe(0);
    expect(movePercent(99, 1)).toBe(100);
    expect(movePercent(0, 0)).toBe(0);
    expect(movePercent(100, 0)).toBe(100);
  });

  test('rounds to one decimal so repeated steps do not drift', () => {
    expect(movePercent(0.1, 0.2)).toBe(0.3);
    expect(movePercent(50.04, 0.05)).toBe(50.1);
    expect(movePercent(33.3, -0.1)).toBe(33.2);
  });
});

test.describe('neighbourNumber', () => {
  test('is the next pin, else the previous one, else undefined', () => {
    expect(neighbourNumber([1, 2, 4], 2)).toBe(4);
    expect(neighbourNumber([1, 2, 4], 4)).toBe(2);
    expect(neighbourNumber([3], 3)).toBeUndefined();
  });
});
