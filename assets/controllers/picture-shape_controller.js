import { Controller } from '@hotwired/stimulus';

// Gives a screenshot's frame the shape of its picture while the picture is not shown (still processing, or refused), so
// the pin layer over it has the size the pins were placed on. By script, for the same reason as pin-layer: the CSP
// allows no style attribute, and a style element from a Turbo frame or live component request has a nonce the page
// does not know.
export default class extends Controller {
  static values = { width: Number, height: Number };

  widthValueChanged() {
    this.applyShape();
  }

  heightValueChanged() {
    this.applyShape();
  }

  applyShape() {
    this.element.style.setProperty('max-width', `${this.widthValue}px`);
    this.element.style.setProperty('aspect-ratio', `${this.widthValue} / ${this.heightValue}`);
  }
}
