<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * One open ticket of a whose-turn group: what the row of the Whose turn view shows.
 */
final readonly class WaitingTicket
{
    /**
     * @param string $key  the ticket's key, for example SHOP-12
     * @param string $kind the turn kind of the ticket's turn row (answer, check, review, test, assign, input, decide,
     *                     build), which the page words with the turn verbs
     */
    public function __construct(
        public TicketId $id,
        public string $key,
        public string $title,
        public string $kind,
        public \DateTimeImmutable $waitingSince,
    ) {
    }
}
