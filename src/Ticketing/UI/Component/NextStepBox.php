<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Ticketing\Application\MoveTicket\MoveTicket;
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
 * Give it to… is the person picker in assign mode, whose rows submit one hidden form outside its dialog.
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

    /** Renders "Move to another step…" open; the ticket page leaves it closed, the component gallery opens it. */
    public bool $isMoveListOpen = false;

    /**
     * The ticket's team, a canonical uuid, whose people Give it to… lists; empty when no team owns the ticket. A box
     * that shows Give it to… without one fails at PersonPicker's mount check.
     */
    public string $teamId = '';

    /**
     * Whether the viewer is granted TicketPermission::ASSIGN, which TicketMoveController::ASSIGN_ROUTE asks. Workflow
     * also offers give_it_to to a checker on an unassigned ticket, which that route would refuse, so the box shows
     * Give it to… only when this is true.
     */
    public bool $canGive = false;

    public function csrfTokenId(): string
    {
        return TicketMoveController::csrfIdOf($this->ticketKey);
    }

    /**
     * The verbs TicketMoveController makes, in the policy's order: each a move form, and give_it_to the person picker
     * when the viewer may give.
     *
     * @return list<TransitionView>
     */
    public function moveTransitions(): array
    {
        return array_values(array_filter(
            $this->nextStep->transitions,
            fn (TransitionView $transition): bool => $this->isGive($transition)
                ? $this->canGive
                : 1 === preg_match('/\A(?:'.TicketMoveController::VERB_REQUIREMENT.')\z/', $transition->verb),
        ));
    }

    /**
     * Whether the verb is Give it to…, which posts to TicketMoveController::ASSIGN_ROUTE with the person picked.
     */
    public function isGive(TransitionView $transition): bool
    {
        return MoveTicket::GIVE_IT_TO === $transition->verb;
    }

    /**
     * Whether the chip opens the Needs input dialog.
     */
    public function asksWhoItWaitsOn(MoveChoice $choice): bool
    {
        return NextStepChoices::isMoveToNeedsInput($choice);
    }
}
