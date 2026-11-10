<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;
use App\Workflow\Domain\Transition;

/**
 * No move while a question is open, for everyone, the asked person too: they answer first. It is not one of the
 * rules a team can turn off.
 */
final readonly class QuestionOpenPause implements Guard
{
    public function check(TicketState $ticket, Transition $transition, ActorInTeam $actor, RuleSetView $rules): ?RuleNote
    {
        return $this->noteFor($ticket);
    }

    /**
     * The same answer for every move, so it can be asked once per ticket, also in a step no verb leaves.
     */
    public function noteFor(TicketState $ticket): ?RuleNote
    {
        if (null === $ticket->askedUserId) {
            return null;
        }

        return new RuleNote('Paused until {asked} answers the question below.', ['asked' => $ticket->askedUserId]);
    }
}
