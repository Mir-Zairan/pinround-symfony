import { Controller } from '@hotwired/stimulus';

// Keeps the composer's Ask a question in step with the message list. The composer is permanent, so a live reload does
// not render it again: the list says whether a question is open, which turns Ask a question off, and carries whom the
// viewer may ask now, which replaces the composer's people so they follow a reassignment. It sits on the same element
// as thread-composer, which owns the chips and what the composer is writing.
export default class extends Controller {
  static targets = ['messages', 'choices', 'waitNote'];
  static values = { mayAsk: Boolean };

  // When Turbo renders a page or frame again it puts the permanent composer back only after the new list has connected,
  // when following the list finds no composer to change, so the composer follows the list again once it is back.
  choicesTargetConnected() {
    if (this.hasMessagesTarget) {
      this.followMessages(this.messagesTarget);
    }
  }

  messagesTargetConnected(messages) {
    this.followMessages(messages);
  }

  followMessages(messages) {
    this.renewAskChoices(messages.dataset.askChoices);
    this.followOpenQuestion(messages.dataset.hasOpenQuestion === 'true');
  }

  // The markup is the server's Twig render, escaped like the rest of the page. Unchanged people are left alone, so a
  // focused chip keeps focus on every live refresh (WCAG 2.4.3). A question being written stays with the person chosen
  // while they are still there to ask, else with the first; a focused chip hands focus to the same person, else the first.
  renewAskChoices(markup) {
    if (markup === undefined || !this.hasChoicesTarget) {
      return;
    }
    const freshChoices = document.createElement('template');
    freshChoices.innerHTML = markup;
    if (this.describeChoices(freshChoices.content) === this.describeChoices(this.choicesTarget)) {
      return;
    }
    const composer = this.composer;
    const askedUserId = composer.isAsking() && composer.hasAskedUserTarget ? composer.askedUserTarget.value : null;
    const focusedUserId = composer.personTargets.find((each) => each === document.activeElement)?.dataset.userId ?? null;
    this.choicesTarget.replaceChildren(freshChoices.content);
    if (askedUserId === null) {
      return;
    }
    const person = composer.personTargets.find((each) => each.dataset.userId === askedUserId) ?? composer.personTargets[0];
    if (person === undefined) {
      composer.writeComment();
      if (focusedUserId !== null) {
        composer.commentChipTarget.focus();
      }
      return;
    }
    composer.ask(person);
    if (focusedUserId !== null) {
      (composer.personTargets.find((each) => each.dataset.userId === focusedUserId) ?? composer.personTargets[0]).focus();
    }
  }

  // What the people chips offer, leaving out which one is pressed and whether they show: that is the composer's state.
  describeChoices(root) {
    const people = [...root.querySelectorAll('[data-thread-composer-target~="person"]')].map((person) =>
      [person.dataset.userId, person.dataset.note, person.dataset.button, person.textContent.trim()].join('\u0000'),
    );
    return JSON.stringify([root.querySelector('[data-thread-composer-target~="askedUser"]') !== null, people]);
  }

  // A Turbo Stream renders only the list, so mayAsk may be older than the people: nobody left to ask turns Ask off too.
  followOpenQuestion(hasOpenQuestion) {
    const composer = this.composer;
    if (!composer.hasAskChipTarget) {
      return;
    }
    const askChip = composer.askChipTarget;
    const hadAskFocus = document.activeElement === askChip || composer.personTargets.includes(document.activeElement);
    askChip.disabled = !this.mayAskValue || !composer.hasPersonTarget || hasOpenQuestion;
    if (this.hasWaitNoteTarget) {
      this.waitNoteTarget.hidden = !(this.mayAskValue && hasOpenQuestion);
    }
    if (askChip.disabled && composer.isAsking()) {
      composer.writeComment();
    }
    // A disabled Ask chip and hidden people drop the focus they held to the body (WCAG 2.4.3).
    if (askChip.disabled && hadAskFocus) {
      composer.commentChipTarget.focus();
    }
  }

  get composer() {
    return this.application.getControllerForElementAndIdentifier(this.element, 'thread-composer');
  }
}
