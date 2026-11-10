<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;
use App\Workflow\Domain\Transition;

/**
 * One rule that can block a move (workflow-engine.md, "Rules (guards)"). A guard only applies its rule; whether the
 * actor may use the verb at all is the catalogue's who may, so a guard is also asked about a person who may not, and
 * its note then explains the rule to them.
 *
 * Guards live in Application, not Domain, because they read Workflow's Api types.
 */
interface Guard
{
    /**
     * @return RuleNote|null null when the rule allows the move, else the note that says why not
     */
    public function check(TicketState $ticket, Transition $transition, ActorInTeam $actor, RuleSetView $rules): ?RuleNote;
}
