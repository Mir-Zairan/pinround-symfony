<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Api\CheckItemChange;
use App\Review\Application\Checklist\RoundChecklistCommands;
use App\Review\Domain\Round;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Records the createFromReport and createFromFindings calls and makes nothing. Like the real command it answers null
 * for a ticket it has been asked about before, and 0 items for a round, as for a self-check round; it refuses the other
 * commands, which no caller of it uses.
 */
final class RecordingChecklistCommands implements RoundChecklistCommands
{
    /** @var list<array{string, string}> the ticket id and time of each call */
    public array $created = [];

    /** @var list<array{string, string}> the round's id and the time of each createFromFindings call */
    public array $findingsCreated = [];

    /** @var list<Round> the round of each createFromFindings call, as the caller passed it */
    public array $findingsRounds = [];

    public function createFromReport(TicketId $ticketId, \DateTimeImmutable $at): ?string
    {
        $isRepeat = [] !== array_filter($this->created, static fn (array $call): bool => $call[0] === $ticketId->toString());
        $this->created[] = [$ticketId->toString(), $at->format('Y-m-d H:i:s')];

        return $isRepeat ? null : \sprintf('0197a3c0-0000-7000-8000-%012d', \count($this->created));
    }

    public function createFromFindings(Round $sharedDraft, \DateTimeImmutable $at): int
    {
        $this->findingsCreated[] = [$sharedDraft->id()->toString(), $at->format('Y-m-d H:i:s')];
        $this->findingsRounds[] = $sharedDraft;

        return 0;
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
