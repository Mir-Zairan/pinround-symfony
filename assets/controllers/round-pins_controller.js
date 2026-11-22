import { Controller } from '@hotwired/stimulus';

// The round page (round.html.twig): ties each pin's marker on a picture to its row in the side panel. A click on a
// marker or on a row selects both, rings the marker and outlines the row; a marker's row is scrolled into view. Each
// picture's pins are numbered from 1, so a marker and a row match on their picture's side ("before" or "after") and
// their number. A click on a row's own buttons is left to them.
export default class extends Controller {
  select(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) {
      return;
    }
    const marker = target.closest('.pin[data-pin-number]');
    if (marker) {
      const side = marker.closest('[data-pin-list]')?.dataset.pinList;
      if (side) {
        this.#show(side, marker.dataset.pinNumber, true);
      }
      return;
    }
    const row = target.closest('li[data-pin-list][data-pin-number]');
    if (row && !target.closest('button, a, input, textarea, select, form')) {
      this.#show(row.dataset.pinList, row.dataset.pinNumber, false);
    }
  }

  #show(side, number, isScrollingToRow) {
    for (const figure of this.element.querySelectorAll('figure[data-pin-list]')) {
      for (const ring of figure.querySelectorAll('.pin[data-pin-number]')) {
        const isSelected = figure.dataset.pinList === side && ring.dataset.pinNumber === number;
        ring.classList.toggle('pin--selected', isSelected);
        ring.setAttribute('aria-pressed', String(isSelected));
      }
    }
    for (const row of this.element.querySelectorAll('li[data-pin-list][data-pin-number]')) {
      const isSelected = row.dataset.pinList === side && row.dataset.pinNumber === number;
      row.classList.toggle('round-pin--selected', isSelected);
      if (isSelected && isScrollingToRow) {
        row.scrollIntoView({ block: 'nearest' });
      }
    }
  }
}
