import {
  buildListItem,
  buildMarker,
  buildPositionFields,
  findListItem,
  findMarker,
  markSelected,
  placeMarker,
  readPins,
} from './pin_markup.js';
import { neighbourNumber } from './pin_position.js';

/**
 * The pins of one editable screenshot and the elements that show them: markers in `layer`, note fields in `list` (with
 * `notePlaceholder` when not empty), hidden position fields in `fields`. `say` writes to the live region;
 * `announceChange` runs after every change. Numbers are never reused or renumbered, until clear() starts a new picture.
 */
export class PinBoard {
  constructor({ layer, list, fields, addButton, say, announceChange, notePlaceholder = '' }) {
    Object.assign(this, { layer, list, fields, addButton, say, announceChange, notePlaceholder });
    const { pins, lastNumber, selectedNumber } = readPins(layer);
    this.pins = pins;
    this.lastNumber = lastNumber;
    this.selectedNumber = selectedNumber;
    this.syncFields();
  }

  add(x, y) {
    const number = ++this.lastNumber;
    this.pins.set(number, { x, y });
    this.layer.append(buildMarker(number));
    placeMarker(findMarker(this.layer, number), x, y);
    this.list.append(buildListItem(number, this.notePlaceholder));
    this.select(number);
    this.syncFields();
    this.say(`Pin ${number} added. Move it with the arrow keys, press Enter to write its note, Delete to remove it.`);
    this.announceChange();
    return number;
  }

  moveTo(number, x, y) {
    this.pins.set(number, { x, y });
    placeMarker(findMarker(this.layer, number), x, y);
    this.syncFields();
    this.announceChange();
  }

  remove(number) {
    if (!this.pins.has(number)) {
      return;
    }
    const neighbour = neighbourNumber([...this.pins.keys()], number);
    this.pins.delete(number);
    findMarker(this.layer, number)?.remove();
    findListItem(this.list, number)?.remove();
    this.syncFields();
    this.say(`Pin ${number} removed.`);
    this.announceChange();
    const next = findMarker(this.layer, neighbour);
    if (next) {
      next.focus();
    } else {
      this.selectedNumber = null;
      this.addButton?.focus();
    }
  }

  /** Removes every pin, for a picture that is removed or replaced; the next pin is number 1 again. Says nothing. */
  clear() {
    for (const number of this.pins.keys()) {
      findMarker(this.layer, number)?.remove();
      findListItem(this.list, number)?.remove();
    }
    this.pins.clear();
    this.lastNumber = 0;
    this.selectedNumber = null;
    this.syncFields();
    this.announceChange();
  }

  select(number) {
    this.selectedNumber = number;
    markSelected(this.layer, this.list, number);
  }

  noteFieldOf(number) {
    return findListItem(this.list, number)?.querySelector('textarea');
  }

  /** Every pin with its percent position and the note typed so far, in number order of creation. */
  snapshot() {
    return [...this.pins].map(([number, { x, y }]) => ({ number, x, y, note: this.noteFieldOf(number)?.value ?? '' }));
  }

  syncFields() {
    this.fields.replaceChildren(...buildPositionFields(this.pins));
  }
}
