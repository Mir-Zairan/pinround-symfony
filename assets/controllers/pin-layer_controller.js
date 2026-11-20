import { Controller } from '@hotwired/stimulus';
import { placeMarker } from '../review/pin_markup.js';

// Places every pin of a layer (PinLayer) at its data-x-percent and data-y-percent, by script on every way the layer
// reaches the page: the CSP allows no style attribute, and a nonce'd style element printed by a Turbo frame or a live
// component request carries a nonce the page's CSP does not know, so the browser drops it.
//
// A live component re-render keeps a pin's element and can give it other percents, so they are watched and the pin is
// placed again; so is its style, in case a morph drops the spot set here.
export default class extends Controller {
  static targets = ['pin'];

  connect() {
    this.observer = new MutationObserver((records) => {
      for (const { target } of records) {
        if (this.pinTargets.includes(target)) {
          this.placeAtItsPercents(target);
        }
      }
    });
    this.observer.observe(this.element, { subtree: true, attributes: true, attributeFilter: ['style', 'data-x-percent', 'data-y-percent'] });
  }

  disconnect() {
    this.observer.disconnect();
  }

  pinTargetConnected(marker) {
    this.placeAtItsPercents(marker);
  }

  placeAtItsPercents(marker) {
    const x = Number(marker.dataset.xPercent);
    const y = Number(marker.dataset.yPercent);
    // placeMarker writes the style and the percents again, which the observer reports; a pin already there is left be.
    if (marker.style.getPropertyValue('left') === `${x}%` && marker.style.getPropertyValue('top') === `${y}%`) {
      return;
    }
    placeMarker(marker, x, y);
  }
}
