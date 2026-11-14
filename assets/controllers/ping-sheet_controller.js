import { Controller } from '@hotwired/stimulus';

// One person's ping sheet on the ticket page. A refused ping that keeps the Ping button (pings paused, the form
// expired, a message that is no text, a person who cannot see the ticket) answers with a Turbo Stream that writes the
// refusal into the sheet's alert, as the toast region sits behind the modal. Turbo disables the Send ping button
// while it posts, which drops focus to the body, so a refusal puts focus back in the message. A sent ping, or one too
// soon, replaces the whole sheet with the pinged state, which Turbo focuses (_pinged.html.twig).
export default class extends Controller {
  static targets = ['message', 'refusal'];

  // turbo:submit-start, so a second refusal with the same text is announced again, and the sheet's close event, so
  // the sheet opens again without an old refusal.
  clearRefusal() {
    this.refusalTarget.replaceChildren();
  }

  // turbo:submit-end. The stream answer has not run yet; when it replaces the sheet, focus moves on from the message.
  settle(event) {
    if (!event.detail.success) {
      this.messageTarget.focus();
    }
  }
}
