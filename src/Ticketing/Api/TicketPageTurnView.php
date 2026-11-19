<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What the ticket page shows of a ticket's turn: the "Now" and "Next" lines under its title and its People section.
 */
final readonly class TicketPageTurnView
{
    /**
     * @param string|null            $now          null for a ticket no team owns, for which the page shows no lines
     * @param string|null            $next         null for a ticket no team owns, and when nothing comes next
     * @param list<TicketPagePerson> $people       each person once, in the order of their first role
     * @param array<string, string>  $displayNames display names by user id string of the page's people, the turn
     *                                             person, the asker and the asked person of the question that pauses
     *                                             the ticket, and the other people the caller named; a person
     *                                             Identity does not know is left out
     */
    public function __construct(
        public ?string $now,
        public ?string $next,
        public array $people,
        public array $displayNames,
    ) {
    }
}
