import { Controller } from '@hotwired/stimulus';

// The ticket thread: keeps the composer's button disabled while the text is empty or only spaces (the button starts
// enabled in the HTML and the field is `required`, so the form still works without JavaScript), and puts focus back
// after a post, an edit or a removal. Turbo disables the button it submits with, which drops focus to the body, and
// the answer's Turbo Stream replaces the message list, so focus goes to the composer after a post and to the changed
// message after an edit or a removal.
//
// The composer writes a comment or asks a question: each kind chip and person chip carries the form's action, texts
// and asked person for its choice. A question's answer redirects to the whole page, as asking pauses the ticket and
// changes its header. ask-choices, on the same element, keeps Ask a question and the people in step with the list.
// The review round page's "Ask <lead>" link opens the thread page with ?ask=<user id> (Review's RoundPanel::ASK_PARAMETER).
const ASK_PARAMETER = 'ask';

export default class extends Controller {
  static targets = ['body', 'submit', 'messages', 'label', 'note', 'commentChip', 'askChip', 'people', 'person', 'askedUser'];

  connect() {
    this.askThePersonInTheAddress();
  }

  // Starts a question to the person the address names, while they may be asked; otherwise the composer stays a comment.
  askThePersonInTheAddress() {
    const askedUserId = new URLSearchParams(window.location.search).get(ASK_PARAMETER);
    if (askedUserId === null || !this.hasAskChipTarget || this.askChipTarget.disabled) {
      return;
    }
    const person = this.personTargets.find((each) => each.dataset.userId === askedUserId);
    if (person) {
      this.ask(person);
      this.focusBodyOnceLoaded();
    }
  }

  // The "Ask <lead>" link ends in the composer form's #id. While the page loads, the browser focuses a fragment's
  // target, or the page itself when the target (a form) takes no focus, which would undo a focus given any sooner.
  // Focus a person moved on their own before the load ended stays where they put it.
  focusBodyOnceLoaded() {
    if (document.readyState === 'complete') {
      this.bodyTarget.focus();
      return;
    }
    window.addEventListener('load', () => {
      if (document.activeElement === null || document.activeElement === document.body) {
        this.bodyTarget.focus();
      }
    }, { once: true });
  }

  bodyTargetConnected() {
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
