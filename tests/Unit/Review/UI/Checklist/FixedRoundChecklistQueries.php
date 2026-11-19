<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Checklist;

use App\Review\Application\Checklist\RoundChecklist;
use App\Review\Application\Checklist\RoundChecklistQueries;
use App\SharedKernel\Domain\TicketId;

/**
 * Like RoundChecklistQueries: the rounds asked for that have items, in the order given.
 */
final readonly class FixedRoundChecklistQueries implements RoundChecklistQueries
{
    /**
     * @param array<string, RoundChecklist> $roundChecklists
     */
    public function __construct(private array $roundChecklists)
    {
    }

    public function forRounds(TicketId $ticketId, array $roundIds): array
    {
        return array_intersect_key($this->roundChecklists, array_flip($roundIds));
    }
}
