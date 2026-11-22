<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Insight\Api\TurnWait;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Domain\MovedFrom;

/**
 * The latest row of a ticket's tkt_ticket_event, of any type, as Undo reads it.
 */
final readonly class LastTicketChange
{
    /**
     * @param string|null    $toStepId       the step a move entered; null for a row that is no move
     * @param MovedFrom|null $movedFrom      what a move changed, as it was before; null for a row that is no move, a give, an
     *                                       Undo, or a move written before Undo existed
     * @param TurnWait|null  $turnWaitBefore whose turn it was before the move and since when; null as $movedFrom is,
     *                                       and for a move of a ticket that had no turn row or written before it was kept
     */
    public function __construct(
        public UserId $actorId,
        public ?string $toStepId,
        public \DateTimeImmutable $at,
        public ?MovedFrom $movedFrom,
        public ?TurnWait $turnWaitBefore = null,
    ) {
    }
}
