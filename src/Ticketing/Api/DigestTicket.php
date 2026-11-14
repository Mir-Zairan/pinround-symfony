<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;

/**
 * What an email may say about a ticket: its key, its team, and its title only when the ticket is for everyone.
 */
final readonly class DigestTicket
{
    /**
     * @param string|null $title null when the ticket is restricted to its team, whoever reads it
     */
    public function __construct(
        public TicketId $ticketId,
        public string $key,
        public ?TeamId $teamId,
        public ?string $title,
    ) {
    }
}
