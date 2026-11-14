import { Controller } from '@hotwired/stimulus';

// The ticket thread: keeps the composer's button disabled while the text is empty or only spaces (the button starts
// enabled in the HTML and the field is `required`, so the form still works without JavaScript), and puts focus back
// after a post, an edit or a removal. Turbo disables the button it submits with, which drops focus to the body, and
// the answer's Turbo Stream replaces the message list, so focus goes to the composer after a post and to the changed
// message after an edit or a removal.
//
// The composer writes a comment or asks a question: each kind chip and person chip carries the form's action, texts
// and asked person for its choice. A question's answer redirects to the whole page, as asking pauses the ticket and
// changes its header. The composer is permanent, so a live reload does not render it again: the message list says
// whether a question is open, which turns Ask a question off.
export default class extends Controller {
  static targets = ['body', 'submit', 'messages', 'label', 'note', 'commentChip', 'askChip', 'people', 'person', 'askedUser', 'waitNote'];
  static values = { mayAsk: Boolean };

  connect() {
    this.update();
  }

  update() {
    this.submitTarget.disabled = this.bodyTarget.value.trim() === '';
  }

  writeComment() {
    const chip = this.commentChipTarget;
    this.showKind(chip, chip.dataset.note, chip.dataset.button);
    this.bodyTarget.form.removeAttribute('data-turbo-frame');
    if (this.hasPeopleTarget) {
      this.peopleTarget.hidden = true;
      this.askedUserTarget.disabled = true;
    }
  }

  askQuestion() {
    if (this.askChipTarget.disabled || !this.hasPeopleTarget) {
      return;
    }
    const chosen = this.personTargets.find((person) => person.getAttribute('aria-pressed') === 'true') ?? this.personTargets[0];
    this.peopleTarget.hidden = false;
    this.askedUserTarget.disabled = false;
    this.bodyTarget.form.dataset.turboFrame = '_top';
    this.choose(chosen);
  }

  choosePerson(event) {
    this.choose(event.currentTarget);
  }

  // Cancel in an answer form: folds it away and keeps what was typed.
  closeAnswer(event) {
    event.currentTarget.closest('details').open = false;
  }

  // turbo:submit-start of any thread form: an edit or remove form sits inside its message.
  rememberMessage(event) {
    this.changedMessageId = event.target.closest('[data-message-id]')?.dataset.messageId ?? null;
  }

  // turbo:submit-end of any thread form; a refused comment or question keeps its text.
  settleComposer(event) {
    if (event.target !== this.bodyTarget.form) {
      return;
    }
    if (event.detail.success) {
      this.bodyTarget.form.reset();
      if (this.isAsking()) {
        this.writeComment();
      }
    }
    this.update();
    this.bodyTarget.focus();
  }

  messagesTargetConnected(messages) {
    this.followOpenQuestion(messages.dataset.hasOpenQuestion === 'true');
    const messageId = this.changedMessageId;
    this.changedMessageId = null;
    if (!messageId) {
      return;
    }
    const message = messages.querySelector(`[data-message-id="${CSS.escape(messageId)}"]`);
    if (message) {
      message.tabIndex = -1;
      message.focus();
    }
  }

  followOpenQuestion(hasOpenQuestion) {
    if (!this.hasAskChipTarget) {
      return;
    }
    this.askChipTarget.disabled = !this.mayAskValue || hasOpenQuestion;
    if (this.hasWaitNoteTarget) {
      this.waitNoteTarget.hidden = !(this.mayAskValue && hasOpenQuestion);
    }
    if (this.askChipTarget.disabled && this.isAsking()) {
      this.writeComment();
    }
  }

  isAsking() {
    return this.hasAskChipTarget && this.askChipTarget.getAttribute('aria-pressed') === 'true';
  }

  choose(person) {
    for (const each of this.personTargets) {
      each.setAttribute('aria-pressed', String(each === person));
    }
    this.askedUserTarget.value = person.dataset.userId;
    this.showKind(this.askChipTarget, person.dataset.note, person.dataset.button);
  }

  showKind(kindChip, note, button) {
    for (const chip of [this.commentChipTarget, this.askChipTarget]) {
      chip.setAttribute('aria-pressed', String(chip === kindChip));
    }
    this.bodyTarget.form.action = kindChip.dataset.formAction;
    this.bodyTarget.placeholder = kindChip.dataset.placeholder;
    this.labelTarget.textContent = kindChip.dataset.placeholder;
    this.noteTarget.textContent = note;
    this.submitTarget.textContent = button;
  }
}
