<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Workflow\Api\RuleNote;
use App\Workflow\Api\TicketState;

/**
 * No move while a question is open, for everyone, the asked person too: they answer first. It is not one of the
 * rules a team can turn off, and not a guard: the policy asks it once per ticket, before any verb.
 */
final readonly class QuestionOpenPause
{
    /**
     * The same answer for every move, so it is asked once per ticket, also in a step where no verb applies.
     */
    public function noteFor(TicketState $ticket): ?RuleNote
    {
        if (null === $ticket->askedUserId) {
            return null;
        }

        return new RuleNote('Paused until {asked} answers the question below.', ['asked' => $ticket->askedUserId]);
    }
}
