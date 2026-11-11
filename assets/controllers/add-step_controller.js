import { Controller } from '@hotwired/stimulus';

// The "Add a team step" form of the team page: keeps the submit button disabled while the step name is empty. The
// button starts enabled in the HTML and the field is `required`, so the form still works without JavaScript.
export default class extends Controller {
  static targets = ['name', 'submit'];

  connect() {
    this.update();
  }

  update() {
    if (!this.hasNameTarget || !this.hasSubmitTarget) {
      return;
    }
    this.submitTarget.disabled = this.nameTarget.value.trim() === '';
  }
}
