import { Controller } from '@hotwired/stimulus';

// The ticket thread's composer: keeps the Comment button disabled while the text is empty or only spaces. The
// button starts enabled in the HTML and the field is `required`, so the form still works without JavaScript.
export default class extends Controller {
  static targets = ['body', 'submit'];

  connect() {
    this.update();
  }

  update() {
    this.submitTarget.disabled = this.bodyTarget.value.trim() === '';
  }
}
