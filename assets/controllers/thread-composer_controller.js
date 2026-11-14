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

  // When Turbo renders a page or frame again it puts the permanent composer back only after the new list has connected,
  // when following the list finds no composer to change, so the composer follows the list again once it is back.
  bodyTargetConnected() {
    this.update();
    if (this.hasMessagesTarget) {
      this.followMessages(this.messagesTarget);
    }
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

  // toggle of an Answer or Reply box: an opened box takes the focus to its text, so the keyboard user writes at once.
  focusReplyBox(event) {
    const box = event.currentTarget;
    if (box.open) {
      box.querySelector('textarea')?.focus();
    }
  }

  // Cancel in an Answer or Reply box: folds it away, keeps what was typed and puts focus back on its button, which
  // would otherwise drop to the body with the hidden Cancel (WCAG 2.4.3).
  closeReplyBox(event) {
    const box = event.currentTarget.closest('details');
    box.open = false;
    box.querySelector('summary').focus();
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
    this.followMessages(messages);
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

  followMessages(messages) {
    this.renewAskChoices(messages.dataset.askChoices);
    this.followOpenQuestion(messages.dataset.hasOpenQuestion === 'true');
  }

  // The markup is the server's Twig render, escaped like the rest of the page. Unchanged people are left alone, so a
  // focused chip keeps focus on every live refresh (WCAG 2.4.3). A question being written stays with the person chosen
  // while they are still there to ask, else with the first; a focused chip hands focus to the same person, else the first.
  renewAskChoices(markup) {
    if (markup === undefined || !this.hasAskChoicesTarget) {
      return;
    }
    const freshChoices = document.createElement('template');
    freshChoices.innerHTML = markup;
    if (this.describeChoices(freshChoices.content) === this.describeChoices(this.askChoicesTarget)) {
      return;
    }
    const askedUserId = this.isAsking() && this.hasAskedUserTarget ? this.askedUserTarget.value : null;
    const focusedUserId = this.personTargets.find((each) => each === document.activeElement)?.dataset.userId ?? null;
    this.askChoicesTarget.replaceChildren(freshChoices.content);
    if (askedUserId === null) {
      return;
    }
    const person = this.personTargets.find((each) => each.dataset.userId === askedUserId) ?? this.personTargets[0];
    if (person === undefined) {
      this.writeComment();
      if (focusedUserId !== null) {
        this.commentChipTarget.focus();
      }
      return;
    }
    this.ask(person);
    if (focusedUserId !== null) {
      (this.personTargets.find((each) => each.dataset.userId === focusedUserId) ?? this.personTargets[0]).focus();
    }
  }

  // What the people chips offer, leaving out which one is pressed and whether they show: that is the composer's state.
  describeChoices(root) {
    const people = [...root.querySelectorAll('[data-thread-composer-target~="person"]')].map((person) =>
      [person.dataset.userId, person.dataset.note, person.dataset.button, person.textContent.trim()].join('\u0000'),
    );
    return JSON.stringify([root.querySelector('[data-thread-composer-target~="askedUser"]') !== null, people]);
  }

  // A Turbo Stream renders only the list, so mayAsk may be older than the people: nobody left to ask turns Ask off too.
  followOpenQuestion(hasOpenQuestion) {
    if (!this.hasAskChipTarget) {
      return;
    }
    const hadAskFocus = document.activeElement === this.askChipTarget || this.personTargets.includes(document.activeElement);
    this.askChipTarget.disabled = !this.mayAskValue || !this.hasPersonTarget || hasOpenQuestion;
    if (this.hasWaitNoteTarget) {
      this.waitNoteTarget.hidden = !(this.mayAskValue && hasOpenQuestion);
    }
    if (this.askChipTarget.disabled && this.isAsking()) {
      this.writeComment();
    }
    // A disabled Ask chip and hidden people drop the focus they held to the body (WCAG 2.4.3).
    if (this.askChipTarget.disabled && hadAskFocus) {
      this.commentChipTarget.focus();
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
