import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// The person picker inside a `dialog` controller, which opens it as a modal, keeps Tab inside, closes it on Escape and
// gives focus back to whatever trigger opened it (the Filter chip or the Assign button). This one asks the
// PersonPickerSearch component for its rows when the dialog opens (its own request, so the page renders none) with the
// person pressed now, which the parent's re-renders keep in the pressedPerson value so the search never re-renders with
// them; it puts focus in the search field and moves focus between the rows with the arrow keys. Enter or a click on a
// row picks: in Filter mode the row emits the pick up to the parent component; in Assign mode it submits the give form,
// outside the dialog, with the person's id.
export default class extends Controller {
  static targets = ['search', 'row'];
  static values = { pressedPerson: String };

  async open() {
    this.searchTarget.focus();
    const component = await getComponent(this.searchTarget.closest('[data-controller~="live"]'));
    component.action('open', { pressedPerson: this.pressedPersonValue });
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
