<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\SharedKernel\Domain\TeamId;

/**
 * Where one ticket counts in ins_project_counts: the row of its project, team and visibility, as done or open.
 */
final readonly class CountedTicket
{
    /**
     * @param string $projectId  the uuid of the ticket's project
     * @param string $visibility 'all' or 'team', as tkt_ticket.visibility
     * @param bool   $isDone     whether its step's group is done, as the tickets list's "Show done" splits them
     */
    public function __construct(
        public string $projectId,
        public TeamId $teamId,
        public string $visibility,
        public bool $isDone,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->projectId === $other->projectId && $this->teamId->equals($other->teamId)
            && $this->visibility === $other->visibility && $this->isDone === $other->isDone;
    }

    /**
     * Orders count rows by project, team and visibility, byte by byte; whether done or open does not matter, as both
     * are counted in one row.
     *
     * @return int below 0, 0 or above 0 when this row comes before, is, or comes after the other
     */
    public function compareCountRow(self $other): int
    {
        return [strtolower($this->projectId), strtolower($this->teamId->toString()), $this->visibility]
            <=> [strtolower($other->projectId), strtolower($other->teamId->toString()), $other->visibility];
    }
}
