<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\ScreenshotId;

/**
 * Like DbalRounds: ids come once each, a round id met again is a LogicException, and a round whose number the ticket
 * has already, or an unshared draft of an owner who has one on the ticket, is RoundSlotTaken and not stored.
 */
final class InMemoryRounds implements Rounds
{
    /** @var list<Round> */
    public array $stored = [];

    /** Pin ids handed out, so a test can show a refusal came before any pin was built. */
    public int $issuedPinIds = 0;

    private int $issuedIds = 0;

    public function nextIdentity(): RoundId
    {
        return new RoundId($this->nextUuid(0x8000));
    }

    public function nextScreenshotIdentity(): ScreenshotId
    {
        return new ScreenshotId($this->nextUuid(0x8100));
    }

    public function nextPinIdentity(): PinId
    {
        ++$this->issuedPinIds;

        return new PinId($this->nextUuid(0x8200));
    }

    public function add(Round $round): void
    {
        foreach ($this->stored as $existing) {
            if ($existing->id()->equals($round->id())) {
                throw new \LogicException(\sprintf('Round %s is added already; a round is added once.', $round->id()->toString()));
            }
        }
        foreach ($this->stored as $existing) {
            if (!$existing->organizationId()->equals($round->organizationId()) || !$existing->ticketId()->equals($round->ticketId())) {
                continue;
            }
            $isSameNumber = null !== $round->number() && $existing->number() === $round->number();
            $isSecondOpenDraft = $round->isUnsharedDraft() && $existing->isUnsharedDraft() && $existing->ownerId()->equals($round->ownerId());
            if ($isSameNumber || $isSecondOpenDraft) {
                throw new RoundSlotTaken('The ticket has this round already.');
            }
        }

        $this->stored[] = $round;
    }

    private function nextUuid(int $base): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-%012x', $base + ++$this->issuedIds);
    }
}
