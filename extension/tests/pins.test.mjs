import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MAX_PINS, NOTE_MAX_LENGTH, REFUSALS, accessibleNameOf, checkedPins, percentOf } from '../src/overlay/pins.js';

const pinAt = (x_pct, y_pct, note = '') => ({ x_pct, y_pct, note });

test('a point becomes its percent of the viewport, rounded to hundredths and clamped to 0..100', () => {
    assert.equal(percentOf(320, 1280), 25);
    assert.equal(percentOf(200, 1280), 15.63, '15.625 rounds up');
    assert.equal(percentOf(1, 3), 33.33);
    assert.equal(percentOf(2, 3), 66.67);
    assert.equal(percentOf(-4, 720), 0);
    assert.equal(percentOf(730, 720), 100);
    assert.equal(percentOf(0, 720), 0);
    assert.equal(percentOf(720, 720), 100);
});

test('a percent is refused for a viewport of no size or a point that is no number', () => {
    assert.throws(() => percentOf(10, 0), RangeError);
    assert.throws(() => percentOf(10, -5), RangeError);
    assert.throws(() => percentOf(10, Number.NaN), RangeError);
    assert.throws(() => percentOf(Number.NaN, 720), RangeError);
    assert.throws(() => percentOf(Number.POSITIVE_INFINITY, 720), RangeError);
});

test('the same point keeps its percent at another devicePixelRatio, as CSS pixels do not change with it', () => {
    const cssWidth = 1000;
    for (const devicePixelRatio of [1, 1.25, 2]) {
        const imageWidth = cssWidth * devicePixelRatio;
        assert.equal(percentOf(250, cssWidth), (250 * devicePixelRatio / imageWidth) * 100);
    }
});

test('a pin\'s accessible name is its number and note, or its number alone while the note is blank', () => {
    assert.equal(accessibleNameOf(2, 'Price is wrong'), 'Pin 2: Price is wrong');
    assert.equal(accessibleNameOf(2, '  Price is wrong\n'), 'Pin 2: Price is wrong');
    assert.equal(accessibleNameOf(2, ''), 'Pin 2');
    assert.equal(accessibleNameOf(12, ' \t\r\n'), 'Pin 12');
    assert.equal(accessibleNameOf(1, 'Preis für Käse 🧀'), 'Pin 1: Preis für Käse 🧀');
});

test('valid pins come back as the API takes them: rounded, notes trimmed, nothing else carried', () => {
    const fromPage = [{ x_pct: 0, y_pct: 100, note: '\t Logo is cut off\r\n', element: 'not sent' }, pinAt(33.333333, 66.666666, 'Preis für Käse 🧀')];

    assert.deepEqual(checkedPins(fromPage), [
        { x_pct: 0, y_pct: 100, note: 'Logo is cut off' },
        { x_pct: 33.33, y_pct: 66.67, note: 'Preis für Käse 🧀' },
    ]);
    assert.deepEqual(checkedPins([]), []);
});

test('a pin off the picture or with a coordinate that is no finite number is refused with the plain message', () => {
    for (const [x, y] of [[Number.NaN, 10], [10, Number.NaN], [-1, 10], [10, -1], [100.5, 10], [10, 100.5], [Number.POSITIVE_INFINITY, 10], ['50', 10], [null, 10], [undefined, 10]]) {
        assert.throws(() => checkedPins([pinAt(50, 50), pinAt(x, y)]), { message: REFUSALS.pinOutsidePicture }, `${x}, ${y}`);
    }
    assert.equal(REFUSALS.pinOutsidePicture, 'A pin is outside the picture. Pin the page again.');
});

test('a note is capped at 1000 characters, counted as characters and after the trim', () => {
    assert.equal(NOTE_MAX_LENGTH, 1000);
    assert.equal(checkedPins([pinAt(1, 1, '🧀'.repeat(1000))])[0].note, '🧀'.repeat(1000), '2000 UTF-16 units, 1000 characters');
    assert.equal(checkedPins([pinAt(1, 1, ` ${'a'.repeat(1000)} `)])[0].note.length, 1000);

    assert.throws(() => checkedPins([pinAt(1, 1, 'a'.repeat(1001))]), { message: 'Keep each pin note to 1000 characters or fewer.' });
    assert.throws(() => checkedPins([pinAt(1, 1, 'é'.repeat(1001))]), { message: REFUSALS.noteTooLong });
    assert.throws(() => checkedPins([pinAt(1, 1, `﻿${'a'.repeat(1000)}`)]), { message: REFUSALS.noteTooLong }, 'pinround does not trim a BOM, so it counts');
    assert.throws(() => checkedPins([pinAt(1, 1, `${'a'.repeat(1000)} `)]), { message: REFUSALS.noteTooLong }, 'nor a no-break space');
});

test('a note pinround cannot keep, with a NUL or half a surrogate pair, is refused', () => {
    assert.throws(() => checkedPins([pinAt(1, 1, 'Price\u0000 is wrong')]), { message: 'A pin note holds a character pinround cannot keep. Write the note again.' });
    assert.throws(() => checkedPins([pinAt(1, 1, 'Price \uD83E is wrong')]), { message: REFUSALS.noteUnkeepable });
    assert.equal(checkedPins([pinAt(1, 1, 'Price\tis\r\nwrong')])[0].note, 'Price\tis\r\nwrong', 'tabs and line ends are kept');
});

test('100 pins pass and the 101st is refused with the plain message', () => {
    assert.equal(MAX_PINS, 100);
    const hundred = Array.from({ length: 100 }, (unused, index) => pinAt(index, index));

    assert.equal(checkedPins(hundred).length, 100);
    assert.throws(() => checkedPins([...hundred, pinAt(50, 50)]), { message: 'A picture holds at most 100 pins.' });
});

test('an answer that is no list of pins is refused as unreadable, never taken as no pins', () => {
    for (const answer of [null, undefined, {}, 'pins', [null], [{ x_pct: 1, y_pct: 1 }], [{ x_pct: 1, y_pct: 1, note: 5 }]]) {
        assert.throws(() => checkedPins(answer), { message: 'The pins on this page could not be read. Pin the page again.' }, JSON.stringify(answer));
    }
});
