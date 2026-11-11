import { Controller } from '@hotwired/stimulus';

// One step of the tickets list. The button collapses or expands its rows at once and saves the choice in the
// background; the server renders the saved state on the next load. A failed save is logged and the step stays as shown.
export default class extends Controller {
  static targets = ['toggle', 'rows'];
  static values = { url: String, token: String };

  toggle() {
    const willCollapse = this.toggleTarget.getAttribute('aria-expanded') === 'true';
    this.toggleTarget.setAttribute('aria-expanded', String(!willCollapse));
    this.rowsTarget.hidden = willCollapse;
    this.#save(willCollapse);
  }

  async #save(isCollapsed) {
    const body = new FormData();
    body.set('_csrf_token', this.tokenValue);
    body.set('collapsed', isCollapsed ? '1' : '0');
    try {
      const response = await fetch(this.urlValue, {
        method: 'POST',
        body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        redirect: 'manual',
      });
      if (response.status !== 204) {
        throw new Error(`The step state was not saved (${response.status}).`);
      }
    } catch (error) {
      console.warn(error);
    }
  }
}
