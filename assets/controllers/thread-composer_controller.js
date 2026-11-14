import { Controller } from '@hotwired/stimulus';

// The ticket thread: keeps the Comment button disabled while the text is empty or only spaces (the button starts
// enabled in the HTML and the field is `required`, so the form still works without JavaScript), and puts focus back
// after a post, an edit or a removal. Turbo disables the button it submits with, which drops focus to the body, and
// the answer's Turbo Stream replaces the message list, so focus goes to the composer after a post and to the changed
// message after an edit or a removal.
export default class extends Controller {
  static targets = ['body', 'submit', 'messages'];

  connect() {
    this.update();
  }

  update() {
    this.submitTarget.disabled = this.bodyTarget.value.trim() === '';
  }

  // turbo:submit-start of any thread form: an edit or remove form sits inside its message.
  rememberMessage(event) {
    this.changedMessageId = event.target.closest('[data-message-id]')?.dataset.messageId ?? null;
  }

  // turbo:submit-end of any thread form; a refused comment keeps its text.
  settleComposer(event) {
    if (event.target !== this.bodyTarget.form) {
      return;
    }
    if (event.detail.success) {
      this.bodyTarget.form.reset();
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
}
