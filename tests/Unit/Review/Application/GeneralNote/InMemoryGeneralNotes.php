<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\GeneralNote;

use App\Review\Domain\GeneralNote;
use App\Review\Domain\GeneralNoteId;
use App\Review\Domain\GeneralNotes;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\SharedKernel\Application\TenantContext;

/**
 * Like DbalGeneralNotes: every call needs the tenant and sees its rows only; adding a note to a round the tenant has
 * not fails as the foreign key does, with a LogicException, and a second note with the same id as the primary key
 * does. Each call is logged by name, so a test can show the round was locked first.
 */
final class InMemoryGeneralNotes implements GeneralNotes
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, array<string, ReviewedRound>> by organization id, then round id */
    private array $rounds = [];

    /** @var array<string, array<string, GeneralNote>> by organization id, then note id */
    private array $notes = [];

    private int $nextId = 0;

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function addRound(string $organizationId, ReviewedRound $round): void
    {
        $this->rounds[$organizationId][$round->id->toString()] = $round;
    }

    /**
     * @return array<string, array<string, GeneralNote>> every stored note
     */
    public function snapshot(): array
    {
        return $this->notes;
    }

    /**
     * @return list<GeneralNote> the tenant-free view a test asserts on, in the order they were added
     */
    public function notesIn(string $organizationId): array
    {
        return array_values($this->notes[$organizationId] ?? []);
    }

    public function nextId(): GeneralNoteId
    {
        ++$this->nextId;

        return new GeneralNoteId(\sprintf('0197a3c0-0000-7000-8000-%012d', 900000 + $this->nextId));
    }

    public function lockRound(RoundId $roundId): ?ReviewedRound
    {
        $this->calls[] = 'lockRound';

        return $this->rounds[$this->tenant()][$roundId->toString()] ?? null;
    }

    public function countOn(RoundId $roundId): int
    {
        $this->calls[] = 'countOn';

        return \count(array_filter($this->notes[$this->tenant()] ?? [], static fn (GeneralNote $note): bool => $note->roundId->equals($roundId)));
    }

    public function add(GeneralNote $note): void
    {
        $this->calls[] = 'add';
        $organizationId = $this->tenant();
        if (!isset($this->rounds[$organizationId][$note->roundId->toString()])) {
            throw new \LogicException(\sprintf('Round %s is not the tenant\'s.', $note->roundId->toString()));
        }
        if (isset($this->notes[$organizationId][$note->id->toString()])) {
            throw new \LogicException(\sprintf('Note %s exists already.', $note->id->toString()));
        }
        $this->notes[$organizationId][$note->id->toString()] = $note;
    }

    private function tenant(): string
    {
        return $this->tenantContext->require()->toString();
    }
}
