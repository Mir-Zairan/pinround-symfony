import { Controller } from '@hotwired/stimulus';
import {
  buildListItem,
  buildMarker,
  buildPositionFields,
  describeMarker,
  findListItem,
  findMarker,
  markSelected,
  placeMarker,
} from '../review/pin_markup.js';
import { arrowStep, cutNote, movePercent, noteLength, percentOfPicture } from '../review/pin_position.js';

const DRAG_THRESHOLD_PX = 3;
const ARROW_DIRECTIONS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

// Adds, moves, describes and removes the pins of one editable screenshot, by mouse or keyboard. Pins live only as
// percent positions: the markers (PinLayer), the note fields pins[n][note] (PinList) and hidden fields pins[n][x] and
// pins[n][y] in `fields`. Nothing is sent; every change fires pin-placer:change for the form that later posts them.
// Numbers are never reused or renumbered. The events are wired by data-action on the form (EditableScreenshot).
export default class extends Controller {
  static targets = ['layer', 'list', 'fields', 'status'];
  static values = { noteMax: { type: Number, default: 1000 } };

  connect() {
    this.pins = new Map();
    this.lastNumber = 0;
    this.selectedNumber = null;
    this.drag = null;
    this.dragJustEnded = false;
    this.isLimitShown = false;
    for (const marker of this.layerTarget.querySelectorAll('.pin')) {
      const number = this.numberOf(marker);
      this.pins.set(number, { x: Number(marker.dataset.xPercent), y: Number(marker.dataset.yPercent) });
      this.lastNumber = Math.max(this.lastNumber, number);
      if (marker.classList.contains('pin--selected')) {
        this.selectedNumber = number;
      }
    }
    this.syncFields();
  }

  addAtCentre() {
    this.addPin(50, 50);
    findMarker(this.layerTarget, this.selectedNumber)?.focus();
  }

  onClick(event) {
    // The click that ends a drag lands on the dragged marker (pointer capture), so the flag is cleared for any target.
    const isEndOfDrag = this.dragJustEnded;
    this.dragJustEnded = false;
    if (isEndOfDrag) {
      return;
    }
    const removeButton = event.target.closest('[data-pin-remove]');
    const marker = event.target.closest('.pin');
    if (removeButton) {
      this.removePin(this.numberOf(removeButton.closest('[data-pin-number]')));
    } else if (marker) {
      this.select(this.numberOf(marker));
    } else if (event.target.closest('.screenshot__frame')) {
      const spot = this.spotOf(event.clientX, event.clientY);
      this.addPin(spot.x, spot.y);
      this.noteFieldOf(this.selectedNumber)?.focus();
    }
  }

  onKeydown(event) {
    const marker = event.target.closest('.pin');
    if (!marker) {
      return;
    }
    const number = this.numberOf(marker);
    const direction = ARROW_DIRECTIONS[event.key];
    if (direction) {
      event.preventDefault();
      const pin = this.pins.get(number);
      const step = arrowStep(event.shiftKey);
      this.select(number);
      this.moveTo(number, movePercent(pin.x, direction[0] * step), movePercent(pin.y, direction[1] * step));
    } else if ('Enter' === event.key) {
      event.preventDefault();
      this.select(number);
      this.noteFieldOf(number)?.focus();
    } else if ('Delete' === event.key || 'Backspace' === event.key) {
      event.preventDefault();
      this.removePin(number);
    }
  }

  onInput(event) {
    const field = event.target.closest('textarea.pin-list__note');
    if (!field) {
      return;
    }
    if (noteLength(field.value) > this.noteMaxValue) {
      field.value = cutNote(field.value, this.noteMaxValue);
    }
    const isFull = noteLength(field.value) >= this.noteMaxValue;
    if (isFull || this.isLimitShown) {
      this.say(isFull ? `A note holds at most ${this.noteMaxValue.toLocaleString('en')} characters. Anything more is cut.` : '');
      this.isLimitShown = isFull;
    }
    const number = this.numberOf(field.closest('[data-pin-number]'));
    describeMarker(findMarker(this.layerTarget, number), number, field.value);
    this.announceChange();
  }

  onFocusin(event) {
    const owner = event.target.closest('.pin, .pin-list__item');
    if (owner) {
      this.select(this.numberOf(owner));
    }
  }

  onPointerdown(event) {
    this.dragJustEnded = false;
    const marker = event.target.closest('.pin');
    if (marker && event.isPrimary && 0 === event.button) {
      this.drag = { number: this.numberOf(marker), startX: event.clientX, startY: event.clientY, isMoving: false };
      marker.setPointerCapture(event.pointerId);
    }
  }

  onPointermove(event) {
    if (!this.drag || !event.isPrimary) {
      return;
    }
    if (!this.drag.isMoving) {
      if (Math.hypot(event.clientX - this.drag.startX, event.clientY - this.drag.startY) < DRAG_THRESHOLD_PX) {
        return;
      }
      this.drag.isMoving = true;
      this.select(this.drag.number);
    }
    const spot = this.spotOf(event.clientX, event.clientY);
    this.moveTo(this.drag.number, spot.x, spot.y);
  }

  onPointerend(event) {
    if (this.drag && event.isPrimary) {
      this.dragJustEnded = this.drag.isMoving;
      this.drag = null;
    }
  }

  addPin(x, y) {
    const number = ++this.lastNumber;
    this.pins.set(number, { x, y });
    this.layerTarget.append(buildMarker(number));
    placeMarker(findMarker(this.layerTarget, number), x, y);
    this.listTarget.append(buildListItem(number, this.noteMaxValue));
    this.select(number);
    this.syncFields();
    this.say(`Pin ${number} added. Move it with the arrow keys, press Enter to write its note, Delete to remove it.`);
    this.announceChange();
  }

  moveTo(number, x, y) {
    this.pins.set(number, { x, y });
    placeMarker(findMarker(this.layerTarget, number), x, y);
    this.syncFields();
    this.announceChange();
  }

  removePin(number) {
    if (!this.pins.has(number)) {
      return;
    }
    const numbers = [...this.pins.keys()];
    const neighbour = numbers[numbers.indexOf(number) + 1] ?? numbers[numbers.indexOf(number) - 1];
    this.pins.delete(number);
    findMarker(this.layerTarget, number)?.remove();
    findListItem(this.listTarget, number)?.remove();
    this.syncFields();
    this.say(`Pin ${number} removed.`);
    this.announceChange();
    const next = findMarker(this.layerTarget, neighbour);
    if (next) {
      next.focus();
    } else {
      this.selectedNumber = null;
      this.element.querySelector('[data-pin-add]')?.focus();
    }
  }

  select(number) {
    this.selectedNumber = number;
    markSelected(this.layerTarget, this.listTarget, number);
  }

  spotOf(clientX, clientY) {
    const box = this.layerTarget.getBoundingClientRect();
    return { x: percentOfPicture(clientX, box.left, box.width), y: percentOfPicture(clientY, box.top, box.height) };
  }

  syncFields() {
    this.fieldsTarget.replaceChildren(...buildPositionFields(this.pins));
  }

  announceChange() {
    const pins = [...this.pins].map(([number, { x, y }]) => ({ number, x, y, note: this.noteFieldOf(number)?.value ?? '' }));
    this.dispatch('change', { detail: { pins } });
  }

  say(message) {
    this.statusTarget.textContent = message;
  }

  numberOf(element) {
    return Number(element.dataset.pinNumber);
  }

  noteFieldOf(number) {
    return findListItem(this.listTarget, number)?.querySelector('textarea');
  }
}
