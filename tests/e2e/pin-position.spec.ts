import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { beginDrag, hasPassedDragThreshold } from '../../assets/review/pin_drag.js';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { commandForKey } from '../../assets/review/pin_keyboard.js';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { fittingInsertion } from '../../assets/review/pin_note_limit.js';
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

test.describe('commandForKey', () => {
  const pin = { x: 50, y: 50 };

  test('moves one percent by an arrow and five with Shift, in the arrow direction', () => {
    expect(commandForKey({ key: 'ArrowRight', shiftKey: false }, pin)).toEqual({ kind: 'move', x: 51, y: 50 });
    expect(commandForKey({ key: 'ArrowLeft', shiftKey: false }, pin)).toEqual({ kind: 'move', x: 49, y: 50 });
    expect(commandForKey({ key: 'ArrowDown', shiftKey: true }, pin)).toEqual({ kind: 'move', x: 50, y: 55 });
    expect(commandForKey({ key: 'ArrowUp', shiftKey: true }, pin)).toEqual({ kind: 'move', x: 50, y: 45 });
  });

  test('stops at the edge of the picture', () => {
    expect(commandForKey({ key: 'ArrowLeft', shiftKey: true }, { x: 2, y: 0 })).toEqual({ kind: 'move', x: 0, y: 0 });
    expect(commandForKey({ key: 'ArrowUp', shiftKey: false }, { x: 2, y: 0 })).toEqual({ kind: 'move', x: 2, y: 0 });
    expect(commandForKey({ key: 'ArrowRight', shiftKey: true }, { x: 98, y: 100 })).toEqual({ kind: 'move', x: 100, y: 100 });
    expect(commandForKey({ key: 'ArrowDown', shiftKey: false }, { x: 98, y: 100 })).toEqual({ kind: 'move', x: 98, y: 100 });
  });

  test('asks to write a note on Enter and to remove on Delete or Backspace', () => {
    expect(commandForKey({ key: 'Enter', shiftKey: false }, pin)).toEqual({ kind: 'write' });
    expect(commandForKey({ key: 'Delete', shiftKey: false }, pin)).toEqual({ kind: 'remove' });
    expect(commandForKey({ key: 'Backspace', shiftKey: false }, pin)).toEqual({ kind: 'remove' });
  });

  test('ignores any other key, so Tab and letters keep their own behaviour', () => {
    expect(commandForKey({ key: 'Tab', shiftKey: false }, pin)).toBeNull();
    expect(commandForKey({ key: 'a', shiftKey: false }, pin)).toBeNull();
    expect(commandForKey({ key: ' ', shiftKey: false }, pin)).toBeNull();
    expect(commandForKey({ key: 'Escape', shiftKey: true }, pin)).toBeNull();
  });
});

test.describe('beginDrag', () => {
  const marker = {};
  const press = { isPrimary: true, button: 0, clientX: 10, clientY: 20 };

  test('starts at the press position with the pin number, not yet moving', () => {
    expect(beginDrag(press, marker, 3)).toEqual({ number: 3, startX: 10, startY: 20, isMoving: false });
  });

  test('is null for a press that is not on a marker', () => {
    expect(beginDrag(press, null, null)).toBeNull();
  });

  test('is null for a non-primary pointer and for any button but the left one', () => {
    expect(beginDrag({ ...press, isPrimary: false }, marker, 3)).toBeNull();
    expect(beginDrag({ ...press, button: 1 }, marker, 3)).toBeNull();
    expect(beginDrag({ ...press, button: 2 }, marker, 3)).toBeNull();
  });
});

test.describe('hasPassedDragThreshold', () => {
  const drag = { number: 1, startX: 100, startY: 100, isMoving: false };

  test('is false under 3 px in any direction', () => {
    expect(hasPassedDragThreshold(drag, { clientX: 100, clientY: 100 })).toBe(false);
    expect(hasPassedDragThreshold(drag, { clientX: 102.9, clientY: 100 })).toBe(false);
    expect(hasPassedDragThreshold(drag, { clientX: 100, clientY: 97.1 })).toBe(false);
    expect(hasPassedDragThreshold(drag, { clientX: 102, clientY: 102 })).toBe(false);
  });

  test('is true at exactly 3 px, along an axis or on the diagonal', () => {
    expect(hasPassedDragThreshold(drag, { clientX: 103, clientY: 100 })).toBe(true);
    expect(hasPassedDragThreshold(drag, { clientX: 100, clientY: 97 })).toBe(true);
    expect(hasPassedDragThreshold(drag, { clientX: 103, clientY: 104 })).toBe(true);
  });
});

test.describe('fittingInsertion', () => {
  const full = '0123456789'.repeat(100);

  test('lets everything in while there is room', () => {
    expect(fittingInsertion('abc', 1, 1, 'XY', 1000)).toBe('XY');
    expect(fittingInsertion(full, 0, 0, '', 1000)).toBe('');
  });

  test('refuses all of it in a full note, wherever the caret is', () => {
    expect(fittingInsertion(full, 500, 500, 'Z', 1000)).toBe('');
    expect(fittingInsertion(full, 0, 0, 'Z', 1000)).toBe('');
    expect(fittingInsertion(full, 1000, 1000, 'Z', 1000)).toBe('');
  });

  test('counts the selected text as room, because the insertion replaces it', () => {
    expect(fittingInsertion(full, 10, 13, 'ABC', 1000)).toBe('ABC');
    expect(fittingInsertion(full, 10, 13, 'ABCD', 1000)).toBe('ABC');
  });

  test('cuts a long paste to what fits and never splits an emoji', () => {
    expect(fittingInsertion(full.slice(0, 995), 500, 500, 'ABCDEFGHIJ', 1000)).toBe('ABCDE');
    expect(fittingInsertion(full.slice(0, 999), 10, 10, '😀😀', 1000)).toBe('😀');
    expect(fittingInsertion(full, 10, 10, '😀', 1000)).toBe('');
  });

  test('counts code points on both sides of the selection, so an emoji before the caret is one', () => {
    const note = '😀'.repeat(999);
    expect(fittingInsertion(note, 1998, 1998, 'ab', 1000)).toBe('a');
    expect(fittingInsertion(note, 0, 0, 'ab', 1000)).toBe('a');
  });
});
