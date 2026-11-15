import { Controller } from '@hotwired/stimulus';
import { PinBoard } from '../review/pin_board.js';
import { beginDrag, hasPassedDragThreshold } from '../review/pin_drag.js';
import { commandForKey } from '../review/pin_keyboard.js';
import { describeMarker, findMarker } from '../review/pin_markup.js';
import { cutNoteField, limitInsertion } from '../review/pin_note_limit.js';
import { noteLength, percentOfPicture } from '../review/pin_position.js';

// Adds, moves, describes and removes the pins of one editable screenshot, by mouse or keyboard. The pins and their
// elements are kept by PinBoard: the markers (PinLayer), the note fields pins[n][note] (PinList) and hidden fields
// pins[n][x] and pins[n][y]. Nothing is sent; every change fires pin-placer:change for the form that later posts them.
// The events are wired by data-action on the form (EditableScreenshot).
export default class extends Controller {
  static targets = ['layer', 'list', 'fields', 'status'];
  static values = { noteMax: { type: Number, default: 1000 } };

  connect() {
    this.board = new PinBoard({
      layer: this.layerTarget,
      list: this.listTarget,
      fields: this.fieldsTarget,
      addButton: this.element.querySelector('[data-pin-add]'),
      say: (message) => {
        this.statusTarget.textContent = message;
      },
      announceChange: () => this.dispatch('change', { detail: { pins: this.board.snapshot() } }),
    });
    this.drag = null;
    this.dragJustEnded = false;
    this.isLimitShown = false;
    this.element.addEventListener('beforeinput', this.onBeforeinput);
  }

  disconnect() {
    this.element.removeEventListener('beforeinput', this.onBeforeinput);
  }

  addAtCentre() {
    this.board.add(50, 50);
    findMarker(this.layerTarget, this.board.selectedNumber)?.focus();
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
      this.board.remove(this.numberOf(removeButton.closest('[data-pin-number]')));
    } else if (marker) {
      this.board.select(this.numberOf(marker));
    } else if (event.target.closest('.screenshot__frame')) {
      const spot = this.spotOf(event.clientX, event.clientY);
      this.board.add(spot.x, spot.y);
      this.board.noteFieldOf(this.board.selectedNumber)?.focus();
    }
  }

  onKeydown(event) {
    const marker = event.target.closest('.pin');
    const number = marker ? this.numberOf(marker) : null;
    const command = marker ? commandForKey(event, this.board.pins.get(number)) : null;
    if (!command) {
      return;
    }
    event.preventDefault();
    if ('remove' === command.kind) {
      this.board.remove(number);
      return;
    }
    this.board.select(number);
    if ('move' === command.kind) {
      this.board.moveTo(number, command.x, command.y);
    } else {
      this.board.noteFieldOf(number)?.focus();
    }
  }

  onInput(event) {
    const field = event.target.closest('textarea.pin-list__note');
    if (!field) {
      return;
    }
    // Fallback for input that beforeinput cannot refuse (IME composition, browsers without it): cut the end, keep the caret.
    cutNoteField(field, this.noteMaxValue);
    this.refreshNote(field);
  }

  // The field has no maxlength: browsers count UTF-16 units, the server counts code points. Registered in connect()
  // because the form's data-action list is printed by EditableScreenshot.
  onBeforeinput = (event) => {
    const field = limitInsertion(event, this.noteMaxValue);
    if (field) {
      this.refreshNote(field);
    }
  };

  refreshNote(field) {
    const isFull = noteLength(field.value) >= this.noteMaxValue;
    if (isFull || this.isLimitShown) {
      this.board.say(isFull ? `A note holds at most ${this.noteMaxValue.toLocaleString('en')} characters. Anything more is cut.` : '');
      this.isLimitShown = isFull;
    }
    const number = this.numberOf(field.closest('[data-pin-number]'));
    describeMarker(findMarker(this.layerTarget, number), number, field.value);
    this.board.announceChange();
  }

  onFocusin(event) {
    const owner = event.target.closest('.pin, .pin-list__item');
    if (owner) {
      this.board.select(this.numberOf(owner));
    }
  }

  onPointerdown(event) {
    this.dragJustEnded = false;
    const marker = event.target.closest('.pin');
    this.drag = beginDrag(event, marker, marker ? this.numberOf(marker) : null);
    if (this.drag) {
      marker.setPointerCapture(event.pointerId);
    }
  }

  onPointermove(event) {
    if (!this.drag || !event.isPrimary || (!this.drag.isMoving && !hasPassedDragThreshold(this.drag, event))) {
      return;
    }
    if (!this.drag.isMoving) {
      this.drag.isMoving = true;
      this.board.select(this.drag.number);
    }
    const spot = this.spotOf(event.clientX, event.clientY);
    this.board.moveTo(this.drag.number, spot.x, spot.y);
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

  numberOf(element) {
    return Number(element.dataset.pinNumber);
  }
}
