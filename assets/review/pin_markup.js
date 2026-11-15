import { BUBBLE_FLIP_PERCENT, singleLineNote } from './pin_position.js';

/** The marker button of a pin added in the browser; the server prints the same markup for a pin that came with the page. */
export function buildMarker(number) {
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

/** The list item with the note field and Remove button of a pin added in the browser. */
export function buildListItem(number) {
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

/** The accessible name and the bubble of a marker, from the one-line form of its note. */
export function describeMarker(marker, number, note) {
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

/** The hidden x and y fields of every pin, named pins[n][x] and pins[n][y], with two decimals as the database keeps. */
export function buildPositionFields(pins) {
  const inputs = [];
  for (const [number, { x, y }] of pins) {
    for (const [axis, value] of [['x', x], ['y', y]]) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = `pins[${number}][${axis}]`;
      input.value = value.toFixed(2);
      inputs.push(input);
    }
  }
  return inputs;
}

export function findMarker(layer, number) {
  return layer.querySelector(`.pin[data-pin-number="${number}"]`);
}

export function findListItem(list, number) {
  return list.querySelector(`.pin-list__item[data-pin-number="${number}"]`);
}

/** Marks one pin as selected on its marker (also aria-pressed) and its list item, and clears the others. */
export function markSelected(layer, list, number) {
  for (const marker of layer.querySelectorAll('.pin')) {
    const isSelected = Number(marker.dataset.pinNumber) === number;
    marker.classList.toggle('pin--selected', isSelected);
    marker.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
  }
  for (const item of list.querySelectorAll('.pin-list__item')) {
    item.classList.toggle('pin-list__item--selected', Number(item.dataset.pinNumber) === number);
  }
}

// CSSOM, not a style attribute: the CSP forbids inline style attributes but allows script to set properties. It also
// wins over the rule PinLayer prints for a pin that came with the page.
export function placeMarker(marker, x, y) {
  marker.style.setProperty('left', `${x}%`);
  marker.style.setProperty('top', `${y}%`);
  marker.dataset.xPercent = x.toFixed(2);
  marker.dataset.yPercent = y.toFixed(2);
  marker.classList.toggle('pin--bubble-left', x > BUBBLE_FLIP_PERCENT);
}

/** The pins the server printed into the layer: their positions by number, the highest number and the selected one. */
export function readPins(layer) {
  const pins = new Map();
  let lastNumber = 0;
  let selectedNumber = null;
  for (const marker of layer.querySelectorAll('.pin')) {
    const number = Number(marker.dataset.pinNumber);
    pins.set(number, { x: Number(marker.dataset.xPercent), y: Number(marker.dataset.yPercent) });
    lastNumber = Math.max(lastNumber, number);
    if (marker.classList.contains('pin--selected')) {
      selectedNumber = number;
    }
  }
  return { pins, lastNumber, selectedNumber };
}
