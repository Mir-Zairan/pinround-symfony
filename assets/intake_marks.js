/** The New ticket form's radio groups the AI suggests a value for; it never touches any other field (ADR-0020). */
const SUGGESTED_FIELDS = ['type', 'priority'];

/**
 * On the form, the radio groups the person picked themselves, space-separated; it outlives the frame's swaps, and the
 * server writes it when it shows a refused form again.
 */
const PERSON_PICKED_ATTRIBUTE = 'data-intake-check-person-picked';

const MARK_CLASS = 'intake-check__mark';

/**
 * For the form's change event: notes the radio group the person picked on the form. Only the person's own choice
 * counts; selecting a radio from script fires no change event, and a synthetic one is not trusted.
 */
export function notePersonPick(form, event) {
  const field = event.target;
  if (!event.isTrusted || !(field instanceof HTMLInputElement) || field.type !== 'radio' || !SUGGESTED_FIELDS.includes(field.name)) {
    return;
  }
  const picked = new Set(personPickedFields(form));
  picked.add(field.name);
  form.setAttribute(PERSON_PICKED_ATTRIBUTE, [...picked].join(' '));
}

/**
 * Marks each suggested radio with ✦, whose screen reader text is markLabel, and selects it unless the person picked
 * that group themselves; the marks of an earlier suggestion go first.
 *
 * @param {HTMLFormElement} form
 * @param {{type: string, priority: string}} suggestedValues
 * @param {string} markLabel
 */
export function markSuggestions(form, suggestedValues, markLabel) {
  clearMarks(form);
  const personPicked = personPickedFields(form);
  for (const fieldName of SUGGESTED_FIELDS) {
    const suggestedRadio = radiosOf(form, fieldName).find((radio) => radio.value === suggestedValues[fieldName]);
    if (suggestedRadio === undefined) {
      continue;
    }
    suggestedRadio.closest('label')?.append(markFor(markLabel));
    if (!personPicked.includes(fieldName)) {
      suggestedRadio.checked = true;
    }
  }
}

export function clearMarks(form) {
  for (const fieldName of SUGGESTED_FIELDS) {
    for (const radio of radiosOf(form, fieldName)) {
      radio.closest('label')?.querySelector(`.${MARK_CLASS}`)?.remove();
    }
  }
}

function personPickedFields(form) {
  return (form.getAttribute(PERSON_PICKED_ATTRIBUTE) ?? '').split(' ').filter((name) => name !== '');
}

function radiosOf(form, fieldName) {
  return [...form.querySelectorAll('input[type="radio"]')].filter((radio) => radio.name === fieldName);
}

// "✦" after the label; screen readers hear the mark's label instead.
function markFor(markLabel) {
  const mark = document.createElement('span');
  mark.className = MARK_CLASS;
  const sign = document.createElement('span');
  sign.setAttribute('aria-hidden', 'true');
  sign.textContent = '✦';
  const label = document.createElement('span');
  label.className = 'visually-hidden';
  label.textContent = markLabel;
  mark.append(' ', sign, label);
  return mark;
}
