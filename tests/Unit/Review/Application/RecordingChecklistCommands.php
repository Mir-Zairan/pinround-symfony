<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Api\CheckItemChange;
use App\Review\Api\ChecklistCommands;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Records the createFromReport calls and makes nothing. Like the real command it answers null for a ticket it has
 * been asked about before, and it refuses the other commands, which no caller of it uses.
 */
final class RecordingChecklistCommands implements ChecklistCommands
{
    /** @var list<array{string, string}> the ticket id and time of each call */
    public array $created = [];

    public function createFromReport(TicketId $ticketId, \DateTimeImmutable $at): ?string
    {
        $isRepeat = [] !== array_filter($this->created, static fn (array $call): bool => $call[0] === $ticketId->toString());
        $this->created[] = [$ticketId->toString(), $at->format('Y-m-d H:i:s')];

        return $isRepeat ? null : \sprintf('0197a3c0-0000-7000-8000-%012d', \count($this->created));
    }

    public function markFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        throw new \LogicException('Not used.');
    }

    public function markOk(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        throw new \LogicException('Not used.');
    }

    public function markNotFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        throw new \LogicException('Not used.');
    }
}
