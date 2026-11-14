import { Controller } from '@hotwired/stimulus';

const STEP_PERCENT = 1;
const BIG_STEP_PERCENT = 5;
const BUBBLE_FLIP_PERCENT = 60;
const DRAG_THRESHOLD_PX = 3;
const ARROW_DIRECTIONS = {
  ArrowLeft: [-1, 0],
  ArrowRight: [1, 0],
  ArrowUp: [0, -1],
  ArrowDown: [0, 1],
};

export function clampPercent(value) {
  return Math.min(100, Math.max(0, value));
}

export function roundPercent(value) {
  return Math.round(value * 10) / 10;
}

/** A pointer position as a percent of the picture's size on one axis: clamped to 0-100, rounded to 0.1. */
export function percentOfPicture(clientPosition, pictureStart, pictureSize) {
  if (!(pictureSize > 0)) {
    return 0;
  }
  return roundPercent(clampPercent(((clientPosition - pictureStart) / pictureSize) * 100));
}

export function movePercent(value, delta) {
  return roundPercent(clampPercent(value + delta));
}

export function arrowStep(shiftKey) {
  return shiftKey ? BIG_STEP_PERCENT : STEP_PERCENT;
}

/** The note on one line, as the server's PinView::singleLineNote() writes it for the accessible name and bubble. */
export function singleLineNote(note) {
  return note.replace(/[\p{Cc}\p{Zl}\p{Zp}\s]+/gu, ' ').trim();
}

/** The text cut to at most maxLength UTF-16 units, never in the middle of a surrogate pair. */
export function cutNote(note, maxLength) {
  if (note.length <= maxLength) {
    return note;
  }
  const cut = note.slice(0, maxLength);
  const lastUnit = cut.charCodeAt(cut.length - 1);
  return lastUnit >= 0xd800 && lastUnit <= 0xdbff ? cut.slice(0, -1) : cut;
}

// Adds, moves, describes and removes the pins of one editable screenshot, by mouse or keyboard. Pins live only as
// percent positions: the markers (PinLayer), the note fields (PinList) and hidden x and y fields in `fields`, named
// pins[n][x] and pins[n][y]. The note is the field pins[n][note] of the list. Nothing is sent; every change fires
// pin-placer:change with the pins, for the form that later posts them. Numbers are never reused or renumbered.
export default class extends Controller {
  static targets = ['layer', 'list', 'fields', 'status'];
  static values = { noteMax: { type: Number, default: 1000 } };

  connect() {
    this.pins = new Map();
    this.lastNumber = 0;
    this.selectedNumber = null;
    this.drag = null;
    this.isLimitShown = false;

    for (const marker of this.layerTarget.querySelectorAll('.pin')) {
      const number = Number(marker.dataset.pinNumber);
      this.pins.set(number, { x: Number(marker.dataset.xPercent), y: Number(marker.dataset.yPercent) });
      this.lastNumber = Math.max(this.lastNumber, number);
      if (marker.classList.contains('pin--selected')) {
        this.selectedNumber = number;
      }
    }
    this.syncFields();

    this.abort = new AbortController();
    const { signal } = this.abort;
    this.element.addEventListener('click', (event) => this.onClick(event), { signal });
    this.element.addEventListener('keydown', (event) => this.onKeydown(event), { signal });
    this.element.addEventListener('input', (event) => this.onInput(event), { signal });
    this.element.addEventListener('focusin', (event) => this.onFocusin(event), { signal });
    this.element.addEventListener('pointerdown', (event) => this.onPointerdown(event), { signal });
    this.element.addEventListener('pointermove', (event) => this.onPointermove(event), { signal });
    this.element.addEventListener('pointerup', (event) => this.onPointerend(event), { signal });
    this.element.addEventListener('pointercancel', (event) => this.onPointerend(event), { signal });
  }

  disconnect() {
    this.abort.abort();
  }

  addAtCentre() {
    this.addPin(50, 50);
    this.markerOf(this.selectedNumber)?.focus();
  }

  onClick(event) {
    const removeButton = event.target.closest('[data-pin-remove]');
    if (removeButton) {
      this.removePin(Number(removeButton.closest('[data-pin-number]').dataset.pinNumber));
      return;
    }
    const marker = event.target.closest('.pin');
    if (marker) {
      this.select(Number(marker.dataset.pinNumber));
      return;
    }
    if (this.dragJustEnded) {
      this.dragJustEnded = false;
      return;
    }
    if (event.target.closest('.screenshot__frame')) {
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
    const number = Number(marker.dataset.pinNumber);
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
    } else if ('Delete' === event.key) {
      event.preventDefault();
      this.removePin(number);
    }
  }

  onInput(event) {
    const field = event.target.closest('textarea.pin-list__note');
    if (!field) {
      return;
    }
    const number = Number(field.closest('[data-pin-number]').dataset.pinNumber);
    if (field.value.length > this.noteMaxValue) {
      field.value = cutNote(field.value, this.noteMaxValue);
    }
    if (field.value.length >= this.noteMaxValue) {
      this.say(`A note holds at most ${this.noteMaxValue.toLocaleString('en')} characters. Anything more is cut.`);
      this.isLimitShown = true;
    } else if (this.isLimitShown) {
      this.say('');
      this.isLimitShown = false;
    }
    this.describeMarker(number, field.value);
    this.announceChange();
  }

  onFocusin(event) {
    const item = event.target.closest('.pin-list__item');
    if (item) {
      this.select(Number(item.dataset.pinNumber));
    }
  }

  onPointerdown(event) {
    const marker = event.target.closest('.pin');
    if (!marker || !event.isPrimary || 0 !== event.button) {
      return;
    }
    this.dragJustEnded = false;
    this.drag = { number: Number(marker.dataset.pinNumber), startX: event.clientX, startY: event.clientY, isMoving: false };
    marker.setPointerCapture(event.pointerId);
  }

  onPointermove(event) {
    if (!this.drag || !event.isPrimary) {
      return;
    }
    if (!this.drag.isMoving) {
      const travelled = Math.hypot(event.clientX - this.drag.startX, event.clientY - this.drag.startY);
      if (travelled < DRAG_THRESHOLD_PX) {
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

  spotOf(clientX, clientY) {
    const box = this.layerTarget.getBoundingClientRect();
    return { x: percentOfPicture(clientX, box.left, box.width), y: percentOfPicture(clientY, box.top, box.height) };
  }

  addPin(x, y) {
    const number = ++this.lastNumber;
    this.pins.set(number, { x, y });
    this.layerTarget.append(this.buildMarker(number));
    this.positionMarker(number);
    this.listTarget.append(this.buildListItem(number));
    this.select(number);
    this.syncFields();
    this.say(`Pin ${number} added. Move it with the arrow keys, press Enter to write its note, Delete to remove it.`);
    this.announceChange();
  }

  moveTo(number, x, y) {
    this.pins.set(number, { x, y });
    this.positionMarker(number);
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
    this.markerOf(number)?.remove();
    this.listItemOf(number)?.remove();
    if (this.selectedNumber === number) {
      this.selectedNumber = null;
    }
    this.syncFields();
    this.say(`Pin ${number} removed.`);
    this.announceChange();
    const next = this.markerOf(neighbour);
    if (next) {
      this.select(neighbour);
      next.focus();
    } else {
      this.element.querySelector('[data-pin-add]')?.focus();
    }
  }

  select(number) {
    this.selectedNumber = number;
    for (const marker of this.layerTarget.querySelectorAll('.pin')) {
      const isSelected = Number(marker.dataset.pinNumber) === number;
      marker.classList.toggle('pin--selected', isSelected);
      marker.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    }
    for (const item of this.listTarget.querySelectorAll('.pin-list__item')) {
      item.classList.toggle('pin-list__item--selected', Number(item.dataset.pinNumber) === number);
    }
  }

  // CSSOM, not a style attribute: the CSP forbids inline style attributes but allows script to set properties. It also
  // wins over the rule PinLayer prints for a pin that came with the page.
  positionMarker(number) {
    const marker = this.markerOf(number);
    const { x, y } = this.pins.get(number);
    marker.style.setProperty('left', `${x}%`);
    marker.style.setProperty('top', `${y}%`);
    marker.dataset.xPercent = x.toFixed(2);
    marker.dataset.yPercent = y.toFixed(2);
    marker.classList.toggle('pin--bubble-left', x > BUBBLE_FLIP_PERCENT);
  }

  describeMarker(number, note) {
    const marker = this.markerOf(number);
    const oneLine = singleLineNote(note);
    marker.setAttribute('aria-label', '' === oneLine ? `Pin ${number}` : `Pin ${number}: ${oneLine}`);
    let bubble = marker.querySelector('.pin__bubble');
    if ('' === oneLine) {
      bubble?.remove();
      return;
    }
    if (!bubble) {
      bubble = document.createElement('span');
      bubble.className = 'pin__bubble';
      bubble.setAttribute('aria-hidden', 'true');
      marker.append(bubble);
    }
    bubble.textContent = oneLine;
  }

  buildMarker(number) {
    const marker = document.createElement('button');
    marker.type = 'button';
    marker.className = `pin pin--n${number}`;
    marker.setAttribute('aria-label', `Pin ${number}`);
    marker.setAttribute('aria-pressed', 'false');
    marker.dataset.pinPlacerTarget = 'pin';
    marker.dataset.pinNumber = String(number);
    const tab = document.createElement('span');
    tab.className = 'pin__tab';
    tab.setAttribute('aria-hidden', 'true');
    tab.textContent = String(number);
    marker.append(tab);
    return marker;
  }

  buildListItem(number) {
    const item = document.createElement('li');
    item.className = 'pin-list__item';
    item.value = number;
    item.dataset.pinNumber = String(number);
    const label = document.createElement('span');
    label.className = 'pin-list__number';
    label.textContent = String(number);
    const field = document.createElement('textarea');
    field.className = 'pin-list__note';
    field.name = `pins[${number}][note]`;
    field.rows = 2;
    field.maxLength = this.noteMaxValue;
    field.setAttribute('aria-label', `Note for pin ${number}`);
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-sm btn-text pin-list__remove';
    remove.dataset.pinRemove = '';
    remove.setAttribute('aria-label', `Remove pin ${number}`);
    remove.textContent = 'Remove';
    item.append(label, field, remove);
    return item;
  }

  syncFields() {
    const inputs = [];
    for (const [number, { x, y }] of this.pins) {
      for (const [axis, value] of [['x', x], ['y', y]]) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `pins[${number}][${axis}]`;
        input.value = value.toFixed(1);
        inputs.push(input);
      }
    }
    this.fieldsTarget.replaceChildren(...inputs);
  }

  announceChange() {
    const pins = [...this.pins].map(([number, { x, y }]) => ({
      number,
      x,
      y,
      note: this.noteFieldOf(number)?.value ?? '',
    }));
    this.dispatch('change', { detail: { pins } });
  }

  say(message) {
    this.statusTarget.textContent = message;
  }

  markerOf(number) {
    return this.layerTarget.querySelector(`.pin[data-pin-number="${number}"]`);
  }

  listItemOf(number) {
    return this.listTarget.querySelector(`.pin-list__item[data-pin-number="${number}"]`);
  }

  noteFieldOf(number) {
    return this.listItemOf(number)?.querySelector('textarea');
  }
}
