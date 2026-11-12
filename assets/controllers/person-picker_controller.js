import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// The person picker inside a `dialog` controller, which opens it as a modal, keeps Tab inside, closes it on Escape and
// gives focus back to the chip. This one asks the PersonPickerSearch component for its rows when the dialog opens
// (its own request, so the page renders none), puts focus in the search field, and moves focus between the rows with
// the arrow keys; Enter on a row follows it, which the row hands to the parent component.
export default class extends Controller {
  static targets = ['search', 'row'];

  async open() {
    this.searchTarget.focus();
    const component = await getComponent(this.searchTarget.closest('[data-controller~="live"]'));
    component.action('open');
  }

  moveFromSearch(event) {
    if (event.key === 'ArrowDown' && this.rowTargets.length > 0) {
      event.preventDefault();
      this.rowTargets[0].focus();
    }
  }

  moveBetweenRows(event) {
    const rows = this.rowTargets;
    const index = rows.indexOf(event.target);
    if (index === -1) {
      return;
    }
    const nextIndex = {
      ArrowDown: Math.min(index + 1, rows.length - 1),
      ArrowUp: index - 1,
      Home: 0,
      End: rows.length - 1,
    }[event.key];
    if (nextIndex === undefined) {
      return;
    }
    event.preventDefault();
    if (nextIndex < 0) {
      this.searchTarget.focus();
    } else {
      rows[nextIndex].focus();
    }
  }
}
