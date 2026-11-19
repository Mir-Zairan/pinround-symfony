import { Controller } from '@hotwired/stimulus';

// On Report a problem's form (report.html.twig). Picking a team shows that team's "Give it to" and project chips and
// hides the others; the radios of a hidden group are disabled, so they are not posted. "Give it to" goes back to "The
// team decides", and the first project of the team is picked, as report-a-problem.md asks.
export default class extends Controller {
  static targets = ['team', 'giveTo', 'projects'];

  chooseTeam() {
    const chosen = this.teamTargets.find((radio) => radio.checked)?.value ?? '';
    for (const group of [...this.giveToTargets, ...this.projectsTargets]) {
      const isShown = group.dataset.team === chosen;
      group.hidden = !isShown;
      for (const radio of group.querySelectorAll('input[type="radio"]')) {
        radio.disabled = !isShown;
        radio.checked = false;
      }
      if (isShown) {
        const first = group.querySelector('input[type="radio"]');
        if (first !== null) {
          first.checked = true;
        }
      }
    }
  }
}
