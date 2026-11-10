<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Transition;

/**
 * The rule review_before_test: while it is on, a ticket does not go from In progress straight to Testing.
 */
final readonly class ReviewBeforeTest implements Guard
{
    public function check(TicketState $ticket, Transition $transition, ActorContext $actor, ?MemberJob $actorJob, RuleSetView $rules): ?RuleNote
    {
        if (!$rules->reviewBeforeTest || !$transition->startsFrom(CoreStep::Progress) || CoreStep::Test !== $transition->to) {
            return null;
        }

        return new RuleNote('Team rule: code review comes before testing.');
    }
}
