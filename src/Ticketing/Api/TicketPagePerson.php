<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One person of the ticket page's People section and what they do on the ticket.
 */
final readonly class TicketPagePerson
{
    /**
     * @param non-empty-list<TicketRole> $roles in the order assignee, reporter, reviewer, tester
     */
    public function __construct(
        public UserId $userId,
        public array $roles,
    ) {
    }
}
