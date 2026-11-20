<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Who moves a ticket to another step and how, as read before the move: what Review needs to know which shared round
 * the move closes (ADR-0048), which it cannot work out alone.
 */
final readonly class MoveFacts
{
    /**
     * @param UserId      $moverId        who moves the ticket, also the owner of the draft a move asks about
     * @param UserId|null $assigneeId     the ticket's assignee; null when nobody has it
     * @param bool        $isLeadOnlyMove true for a verb only the team's lead may use ("Plan it", "Go on", "Put on hold")
     *                                    and for "Move to another step…"; such a move closes no round
     * @param bool        $isMoversTurn   whether the ticket was the mover's turn before the move
     */
    public function __construct(
        public UserId $moverId,
        public ?UserId $assigneeId,
        public bool $isLeadOnlyMove,
        public bool $isMoversTurn,
    ) {
    }
}
