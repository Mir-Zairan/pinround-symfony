import { arrowStep, movePercent } from './pin_position.js';

const ARROW_DIRECTIONS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

/**
 * What a key press on a focused marker asks for: { kind: 'move', x, y } with the new percent position, { kind: 'write' }
 * for Enter, { kind: 'remove' } for Delete or Backspace, or null for any other key.
 */
export function commandForKey(event, pin) {
  const direction = ARROW_DIRECTIONS[event.key];
  if (direction) {
    const step = arrowStep(event.shiftKey);
    return { kind: 'move', x: movePercent(pin.x, direction[0] * step), y: movePercent(pin.y, direction[1] * step) };
  }
  if ('Enter' === event.key) {
    return { kind: 'write' };
  }
  return 'Delete' === event.key || 'Backspace' === event.key ? { kind: 'remove' } : null;
}
