import { Controller } from '@hotwired/stimulus';

// The People section of the Team page: the server renders every person and hides those past the first
// `shownAtFirst`. "Show all N" / "Show fewer" toggles the rest; a search shows every person whose name holds all the
// typed words, whatever the cut, and hides the toggle while it runs. No request is made.
export default class extends Controller {
  static targets = ['search', 'person', 'noMatch', 'toggle'];

  static values = { shownAtFirst: Number, isShowingAll: Boolean };

  connect() {
    this.render();
  }

  filter() {
    this.render();
  }

  toggle() {
    this.isShowingAllValue = !this.isShowingAllValue;
    this.render();
  }

  render() {
    const words = this.hasSearchTarget ? this.searchTarget.value.toLowerCase().split(/\s+/).filter(Boolean) : [];
    const isSearching = words.length > 0;
    let matches = 0;
    this.personTargets.forEach((person, index) => {
      const isMatch = words.every((word) => person.dataset.search.includes(word));
      if (isMatch) {
        matches += 1;
      }
      person.hidden = isSearching ? !isMatch : !this.isShowingAllValue && index >= this.shownAtFirstValue;
    });
    if (this.hasNoMatchTarget) {
      this.noMatchTarget.hidden = !isSearching || matches > 0;
    }
    if (this.hasToggleTarget) {
      this.toggleTarget.hidden = isSearching;
      this.toggleTarget.setAttribute('aria-expanded', String(this.isShowingAllValue));
      this.toggleTarget.textContent = this.isShowingAllValue
        ? this.toggleTarget.dataset.showFewerLabel
        : this.toggleTarget.dataset.showAllLabel;
    }
  }
}
