export const STEP_PERCENT = 1;
export const BIG_STEP_PERCENT = 5;

/** Past this x the note's bubble opens to the left of the ring; PinView::BUBBLE_FLIP_PERCENT is the same number. */
export const BUBBLE_FLIP_PERCENT = 60;

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

/** The number of code points, which is what the server's mb_strlen counts (an emoji is 1, not 2). */
export function noteLength(note) {
  return [...note].length;
}

/** The text cut to at most maxLength code points. */
export function cutNote(note, maxLength) {
  const codePoints = [...note];
  return codePoints.length <= maxLength ? note : codePoints.slice(0, maxLength).join('');
}

/** The pin number next to the given one in the list (the next, else the previous), or undefined when it stands alone. */
export function neighbourNumber(numbers, number) {
  const index = numbers.indexOf(number);
  return numbers[index + 1] ?? numbers[index - 1];
}
