<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * The pins of a ticket's Round 0 and who reported it, as Checklists::reportPinsOf reads them to build its checklist.
 */
final readonly class ReportPins
{
    /**
     * @param list<PinId> $pinIds by screenshot position, then pin number
     */
    public function __construct(private UserId $reporterId, private array $pinIds)
    {
    }

    public function reporterId(): UserId
    {
        return $this->reporterId;
    }

    /**
     * @return list<PinId> by screenshot position, then pin number
     */
    public function pinIds(): array
    {
        return $this->pinIds;
    }
}
