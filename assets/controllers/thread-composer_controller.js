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
// whether a question is open, which turns Ask a question off, and carries whom the viewer may ask now, which replaces
// the composer's people so they follow a reassignment.
export default class extends Controller {
  static targets = ['body', 'submit', 'messages', 'label', 'note', 'commentChip', 'askChip', 'askChoices', 'people', 'person', 'askedUser', 'waitNote'];
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
    if (this.askChipTarget.disabled || !this.hasPersonTarget) {
      return;
    }
    this.ask(this.personTargets.find((person) => person.getAttribute('aria-pressed') === 'true') ?? this.personTargets[0]);
  }

  ask(person) {
    this.peopleTarget.hidden = false;
    this.askedUserTarget.disabled = false;
    this.bodyTarget.form.dataset.turboFrame = '_top';
    this.choose(person);
  }

  choosePerson(event) {
    this.choose(event.currentTarget);
  }

  // Cancel in an answer form: folds it away, keeps what was typed and puts focus back on its Answer button, which
  // would otherwise drop to the body with the hidden Cancel (WCAG 2.4.3).
  closeAnswer(event) {
    const answer = event.currentTarget.closest('details');
    answer.open = false;
    answer.querySelector('summary').focus();
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
    this.renewAskChoices(messages.dataset.askChoices);
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

  // The markup is the server's Twig render, escaped like the rest of the page. A question being written stays with the
  // person chosen while they are still there to ask, else with the first.
  renewAskChoices(markup) {
    if (markup === undefined || !this.hasAskChoicesTarget) {
      return;
    }
    const askedUserId = this.isAsking() && this.hasAskedUserTarget ? this.askedUserTarget.value : null;
    const freshChoices = document.createElement('template');
    freshChoices.innerHTML = markup;
    this.askChoicesTarget.replaceChildren(freshChoices.content);
    if (askedUserId === null) {
      return;
    }
    const person = this.personTargets.find((each) => each.dataset.userId === askedUserId) ?? this.personTargets[0];
    if (person === undefined) {
      this.writeComment();
    } else {
      this.ask(person);
    }
  }

  // A Turbo Stream renders only the list, so mayAsk may be older than the people: nobody left to ask turns Ask off too.
  followOpenQuestion(hasOpenQuestion) {
    if (!this.hasAskChipTarget) {
      return;
    }
    this.askChipTarget.disabled = !this.mayAskValue || !this.hasPersonTarget || hasOpenQuestion;
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
