<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\DraftFacts;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Rounds over another Rounds, in which a second request gets in between the first one's read and its save: the lost
 * races of the draft commands. Each interference is off until a test sets it.
 */
final class InterferingRounds implements Rounds
{
    /** @var (\Closure(Rounds): void)|null runs once, right after the next successful get(), with the rounds underneath */
    public ?\Closure $afterNextGet = null;

    /** Answers nextFreeNumberOf with this number whatever the ticket holds, as a read made before another share committed. */
    public ?int $staleNextNumber = null;

    /** Answers openUnsharedDraftOf and openUnsharedDraftFactsOf with none, as a read made before another request started its draft. */
    public bool $hidesOpenDrafts = false;

    public function __construct(private readonly Rounds $inner)
    {
    }

    public function nextIdentity(): RoundId
    {
        return $this->inner->nextIdentity();
    }

    public function nextScreenshotIdentity(): ScreenshotId
    {
        return $this->inner->nextScreenshotIdentity();
    }

    public function nextPinIdentity(): PinId
    {
        return $this->inner->nextPinIdentity();
    }

    public function add(Round $round): void
    {
        $this->inner->add($round);
    }

    public function get(RoundId $id): ?Round
    {
        $round = $this->inner->get($id);
        if (null !== $round && null !== $this->afterNextGet) {
            $interference = $this->afterNextGet;
            $this->afterNextGet = null;
            $interference($this->inner);
        }

        return $round;
    }

    public function openUnsharedDraftOf(TicketId $ticketId, UserId $ownerId): ?Round
    {
        return $this->hidesOpenDrafts ? null : $this->inner->openUnsharedDraftOf($ticketId, $ownerId);
    }

    public function openUnsharedDraftFactsOf(TicketId $ticketId, UserId $ownerId): ?DraftFacts
    {
        return $this->hidesOpenDrafts ? null : $this->inner->openUnsharedDraftFactsOf($ticketId, $ownerId);
    }

    public function nextFreeNumberOf(TicketId $ticketId): int
    {
        return $this->staleNextNumber ?? $this->inner->nextFreeNumberOf($ticketId);
    }

    public function openSharedRoundsOf(TicketId $ticketId): array
    {
        return $this->inner->openSharedRoundsOf($ticketId);
    }

    public function save(Round $round, \DateTimeImmutable $savedAt): void
    {
        $this->inner->save($round, $savedAt);
    }
}
