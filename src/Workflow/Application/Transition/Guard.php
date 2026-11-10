<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;
use App\Workflow\Domain\Transition;

/**
 * One rule that can block a move (workflow-engine.md, "Rules (guards)"). A guard only applies its rule; whether the
 * actor may use the verb at all is decided before it is asked.
 *
 * Guards live in Application, not Domain, because they read Workflow's Api and Organization's Api types.
 */
interface Guard
{
    /**
     * @param MemberJob|null $actorJob the actor's job in the ticket's team; null when they have none there
     *
     * @return RuleNote|null null when the rule allows the move, else the note that says why not
     */
    public function check(TicketState $ticket, Transition $transition, ActorContext $actor, ?MemberJob $actorJob, RuleSetView $rules): ?RuleNote;
}
