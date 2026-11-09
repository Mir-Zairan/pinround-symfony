import { Controller } from '@hotwired/stimulus';

// Looks like x@y.z; the server still decides whether the address is valid.
const LOOKS_LIKE_AN_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Keeps the Invite button disabled until the email field looks like an address. The button starts enabled
// in the HTML and is disabled on connect, so the form still works without JavaScript.
export default class extends Controller {
  static targets = ['field', 'submit'];

  connect() {
    this.update();
  }

  update() {
    this.submitTarget.disabled = !LOOKS_LIKE_AN_EMAIL.test(this.fieldTarget.value.trim());
  }
}
