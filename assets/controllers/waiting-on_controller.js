import { Controller } from '@hotwired/stimulus';

// The Needs input chip's dialog, inside a `dialog` controller that opens it and gives focus back to the chip. The chip's
// form still posts without JavaScript, with empty waiting-on fields, and the ticket then waits on its reporter; with it,
// the dialog's choice is written into those fields and the chip's form is posted as before.
export default class extends Controller {
  static targets = ['moveForm', 'userField', 'labelField', 'choice', 'someoneElse', 'label'];

  connect() {
    this.chooseWhoItWaitsOn();
  }

  // A disabled field is not validated, so its required and pattern hold only while "Someone else" is chosen.
  chooseWhoItWaitsOn(event) {
    const isSomeoneElse = this.someoneElseTarget.checked;
    this.labelTarget.disabled = !isSomeoneElse;
    if (isSomeoneElse && event?.target === this.someoneElseTarget) {
      this.labelTarget.focus();
    }
  }

  move(event) {
    event.preventDefault();
    const chosen = this.choiceTargets.find((choice) => choice.checked);
    if (chosen === undefined) {
      return;
    }
    const isSomeoneElse = chosen === this.someoneElseTarget;
    this.userFieldTarget.value = isSomeoneElse ? '' : chosen.value;
    this.labelFieldTarget.value = isSomeoneElse ? this.labelTarget.value.trim() : '';
    event.target.closest('dialog').close();
    this.moveFormTarget.requestSubmit();
  }
}
