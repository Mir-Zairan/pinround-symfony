<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Api\VerbFor;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;

/**
 * The short task of workflow-engine.md, "Your next step box" (the verb sentences), without the final full stop.
 */
final readonly class PlainVerbFor implements VerbFor
{
    public function person(TicketState $ticket, Turn $turn, NowNextContext $context): ?string
    {
        $words = new SentenceWords($context);

        return match ($turn->kind) {
            null => null,
            NeedsYouKind::Answer => 'Answer '.$words->possessive($context->askerId, 'the').' question',
            NeedsYouKind::Check => 'Check the new ticket',
            NeedsYouKind::Review => 'Review '.$words->possessive($ticket->assigneeId, 'the').' code',
            NeedsYouKind::Test => 'Test it',
            NeedsYouKind::Assign => 'Pick a developer',
            NeedsYouKind::Input => 'Give input',
            NeedsYouKind::Decide => 'Decide: go on or stop',
            NeedsYouKind::Build => $this->buildVerb($ticket),
        };
    }

    private function buildVerb(TicketState $ticket): string
    {
        if (CoreStep::Backlog->value === $ticket->coreCode) {
            return 'Plan it';
        }

        $isTodo = CoreStep::Todo->value === $ticket->coreCode
            || (null === $ticket->coreCode && Group::Todo->value === $ticket->groupCode);

        return $isTodo ? 'Start building' : 'Build it';
    }
}
