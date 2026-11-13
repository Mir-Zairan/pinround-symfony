<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Ticketing\UI\NextStep;
use App\Ticketing\UI\NextStepChoices;
use App\Ticketing\UI\TicketMoveController;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\TransitionView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's "Your next step" box (ticket-page.md, Your next step box): it only shows the NextStep that
 * NextStepChoices worked out for the viewer. Each verb and each step of the move list is a POST form to
 * TicketMoveController with the ticket's form token and the version the page read, so a move made meanwhile is refused.
 * The forms opt in to Turbo (data-turbo="true", as Drive is off), so the answer is a Turbo Stream; without script
 * they post and are redirected back.
 */
#[AsTwigComponent(name: 'NextStepBox', template: '@Ticketing/components/NextStepBox.html.twig')]
final class NextStepBox
{
    public string $ticketKey;

    public int $version;

    public NextStep $nextStep;

    /**
     * Set when a move's Turbo Stream renders the box: the form that had focus is replaced with it, and Turbo focuses
     * the stream's [autofocus] element, so keyboard and screen reader users land on the box rather than the page top.
     */
    public bool $isHeadingAutofocused = false;

    public function csrfTokenId(): string
    {
        return TicketMoveController::csrfIdOf($this->ticketKey);
    }

    /**
     * The verbs TicketMoveController makes. Give it to… opens the person picker, which comes with P03.T8, so until then
     * it has no button rather than a form its route would not find.
     *
     * @return list<TransitionView>
     */
    public function moveTransitions(): array
    {
        return array_values(array_filter(
            $this->nextStep->transitions,
            static fn (TransitionView $transition): bool => 1 === preg_match('/\A(?:'.TicketMoveController::VERB_REQUIREMENT.')\z/', $transition->verb),
        ));
    }

    public function asksWhoItWaitsOn(MoveChoice $choice): bool
    {
        return NextStepChoices::NEEDS_INPUT === $choice->step->coreCode();
    }
}
