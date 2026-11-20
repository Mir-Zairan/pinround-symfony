import { Controller } from '@hotwired/stimulus';
import { describeMarker, findMarker } from '../review/pin_markup.js';

// Shows one screenshot of a ScreenshotEditor at a time: its thumbnails are tabs (arrow keys, Home and End), and every
// panel stays in the form, so the fields of hidden ones still post. Remove and Keep it turn a screenshot's remove field
// on and off; "Add screenshot" and "Remove screenshot" also fire screenshot-editor:add and screenshot-editor:remove
// (detail.index) for the page, which removes a screenshot that has no remove field itself.
export default class extends Controller {
  static targets = ['tab', 'panel', 'tabState', 'removeField', 'status'];

  show(event) {
    this.select(this.tabTargets.indexOf(event.currentTarget));
  }

  moveBetweenTabs(event) {
    const current = this.tabTargets.indexOf(event.target.closest('[role="tab"]'));
    if (-1 === current) {
      return;
    }
    const last = this.tabTargets.length - 1;
    const next = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: last }[event.key];
    if (undefined === next) {
      return;
    }
    event.preventDefault();
    const wrapped = next > last ? 0 : next < 0 ? last : next;
    this.select(wrapped);
    this.tabTargets[wrapped].focus();
  }

  select(index) {
    this.tabTargets.forEach((tab, tabIndex) => {
      const isShown = tabIndex === index;
      tab.setAttribute('aria-selected', isShown ? 'true' : 'false');
      tab.tabIndex = isShown ? 0 : -1;
    });
    this.panelTargets.forEach((panel, panelIndex) => {
      panel.hidden = panelIndex !== index;
    });
  }

  add() {
    this.dispatch('add');
  }

  remove(event) {
    const index = this.indexOfPanelAround(event.target);
    if (this.removeFieldOf(index)) {
      this.markRemoved(index, true);
      this.statusTarget.textContent = `Screenshot ${index + 1} removed. It goes when you save.`;
      this.panelTargets[index].querySelector('.screenshot-editor__removed button')?.focus();
    }
    this.dispatch('remove', { detail: { index } });
  }

  keep(event) {
    const index = this.indexOfPanelAround(event.target);
    this.markRemoved(index, false);
    this.statusTarget.textContent = `Screenshot ${index + 1} stays.`;
    this.panelTargets[index].querySelector('.screenshot-editor__remove')?.focus();
  }

  // The pins of a draft stay put, so no pin-placer keeps their markers in step with their notes; this does.
  describePin(event) {
    const field = event.target.closest('[data-screenshot-editor-pin-note]');
    const layer = field?.closest('[role="tabpanel"]')?.querySelector('.pin-layer');
    if (!layer) {
      return;
    }
    const number = Number(field.closest('[data-pin-number]').dataset.pinNumber);
    const marker = findMarker(layer, number);
    if (marker) {
      describeMarker(marker, number, field.value);
    }
  }

  markRemoved(index, isRemoved) {
    const panel = this.panelTargets[index];
    this.removeFieldOf(index).disabled = !isRemoved;
    panel.querySelector('.screenshot-editor__kept').hidden = isRemoved;
    panel.querySelector('.screenshot-editor__removed').hidden = !isRemoved;
    this.tabTargets[index].classList.toggle('screenshot-editor__thumb--removed', isRemoved);
    this.tabStateTargets[index].textContent = isRemoved ? 'Removed' : '';
  }

  removeFieldOf(index) {
    return this.panelTargets[index].querySelector('[data-screenshot-editor-target="removeField"]');
  }

  indexOfPanelAround(element) {
    return this.panelTargets.indexOf(element.closest('[data-screenshot-editor-target="panel"]'));
  }
}
