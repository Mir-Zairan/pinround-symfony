import { cutNote, noteLength } from './pin_position.js';

const LINE_BREAK_INPUT_TYPES = ['insertLineBreak', 'insertParagraph'];

/** The text a beforeinput event is about to insert; '' for a deletion. */
export function insertedText(event) {
  if (LINE_BREAK_INPUT_TYPES.includes(event.inputType)) {
    return '\n';
  }
  return event.data ?? event.dataTransfer?.getData('text/plain') ?? '';
}

/**
 * The text of an insertion that fits into the note once the selected text is replaced, cut by code points; '' when the
 * note is full. The note is the field's text, the selection its start and end in UTF-16 units, as the browser gives them.
 */
export function fittingInsertion(note, selectionStart, selectionEnd, inserted, maxLength) {
  const keptLength = noteLength(note.slice(0, selectionStart)) + noteLength(note.slice(selectionEnd));
  return cutNote(inserted, Math.max(0, maxLength - keptLength));
}

/**
 * Refuses the part of a beforeinput insertion into a note field that does not fit, writing the part that does fit in its
 * place (a long paste fills the note). Returns the field when it changed or refused something, else null.
 */
export function limitInsertion(event, maxLength) {
  const field = event.target.closest?.('textarea.pin-list__note');
  if (!field || !event.cancelable || event.inputType.startsWith('delete')) {
    return null;
  }
  const { selectionStart, selectionEnd } = field;
  const inserted = insertedText(event);
  const fitting = fittingInsertion(field.value, selectionStart, selectionEnd, inserted, maxLength);
  if (fitting === inserted) {
    return null;
  }
  event.preventDefault();
  if ('' !== fitting) {
    field.setRangeText(fitting, selectionStart, selectionEnd, 'end');
  }
  return field;
}

/** Cuts a note field past maxLength code points at the end and keeps the caret where it was (or at the new end). */
export function cutNoteField(field, maxLength) {
  const cut = cutNote(field.value, maxLength);
  if (cut === field.value) {
    return;
  }
  const caret = Math.min(field.selectionStart, cut.length);
  field.value = cut;
  field.setSelectionRange(caret, caret);
}
