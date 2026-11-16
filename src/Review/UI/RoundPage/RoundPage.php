<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * What the review round page shows of one round: the ticket line, the header, the area tabs, the selected area and
 * its side panel.
 */
final readonly class RoundPage
{
    /**
     * @param string            $header   for example "Round 2 · shot today, 09:12 · Ready for Mia"
     * @param list<AreaTab>     $tabs     by position; none for a round with no screen areas
     * @param SelectedArea|null $selected null exactly when $tabs is empty
     * @param RoundPanel|null   $panel    null exactly when $tabs is empty
     */
    public function __construct(
        public string $ticketKey,
        public string $ticketTitle,
        public string $header,
        public array $tabs,
        public ?SelectedArea $selected,
        public ?RoundPanel $panel,
    ) {
    }
}
