<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The "Now: …" and "Next: …" sentences under a ticket's title (workflow-engine.md, "Now and Next sentences"). Pure.
 * Both sentences use first names, "you" for the viewer, and the step's name, never its code.
 */
interface NowNext
{
    /**
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function now(TicketState $ticket, Turn $turn, NowNextContext $context): string;

    /**
     * @return string|null null when nothing comes next: the ticket is done, or it is in a team or company step, whose
     *                     next sentence depends on the step it moves on to
     *
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function next(TicketState $ticket, Turn $turn, NowNextContext $context): ?string;
}
