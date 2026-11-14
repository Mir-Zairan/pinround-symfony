<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * One row of the ticket page's People section (ticket-page.md, People).
 */
final readonly class TicketPerson
{
    /**
     * @param string $userId      the person's user uuid
     * @param string $name        the full name, for the avatar's initials
     * @param string $roleLine    the person's roles on the ticket and their job, for example "Assignee · Developer"
     * @param bool   $isViewer    whether the person is the one looking, whose name gets "(you)"
     * @param bool   $isTheirTurn whether the ticket's turn is this person's now
     */
    public function __construct(
        public string $userId,
        public string $name,
        public string $firstName,
        public string $roleLine,
        public bool $isViewer,
        public bool $isTheirTurn,
    ) {
    }
}
