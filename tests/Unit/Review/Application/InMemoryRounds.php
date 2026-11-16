<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Like DbalRounds: ids come once each, a round id met again is a LogicException, and a round whose number the ticket
 * has already, or an open unshared draft of an owner who has one on the ticket, is RoundSlotTaken and not stored. It
 * keeps a copy of each round as it was added or saved, so a change is stored only by save(), which writes only over the
 * version the round was read at (RoundChanged otherwise). Reads, numbers and saves see the tenant's rounds only and
 * need its TenantContext.
 */
final class InMemoryRounds implements Rounds
{
    /** @var list<Round> as added or last saved */
    public array $stored = [];

    /** Round and pin ids handed out, so a test can show a refusal came before the round or any pin was built. */
    public int $issuedRoundIds = 0;
    public int $issuedPinIds = 0;

    /** @var list<string> the ticket ids nextFreeNumberOf was asked for, in order: each stands for the lock DbalRounds takes */
    public array $numberedTicketIds = [];

    private int $issuedIds = 0;

    public function __construct(private readonly ?TenantContext $tenantContext = null)
    {
    }

    public function nextIdentity(): RoundId
    {
        ++$this->issuedRoundIds;

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
            $isSecondOpenDraft = self::isOpenUnsharedDraft($round) && self::isOpenUnsharedDraft($existing) && $existing->ownerId()->equals($round->ownerId());
            if ($isSameNumber || $isSecondOpenDraft) {
                throw new RoundSlotTaken('The ticket has this round already.');
            }
        }

        $this->stored[] = self::copyOf($round);
    }

    public function get(RoundId $id): ?Round
    {
        $organizationId = $this->requireTenant();
        foreach ($this->stored as $existing) {
            if ($existing->organizationId()->equals($organizationId) && $existing->id()->equals($id)) {
                return self::copyOf($existing);
            }
        }

        return null;
    }

    public function openUnsharedDraftOf(TicketId $ticketId, UserId $ownerId): ?Round
    {
        $organizationId = $this->requireTenant();
        foreach ($this->stored as $existing) {
            if ($existing->organizationId()->equals($organizationId) && $existing->ticketId()->equals($ticketId)
                && $existing->ownerId()->equals($ownerId) && self::isOpenUnsharedDraft($existing)) {
                return self::copyOf($existing);
            }
        }

        return null;
    }

    public function nextFreeNumberOf(TicketId $ticketId): int
    {
        $organizationId = $this->requireTenant();
        $this->numberedTicketIds[] = $ticketId->toString();
        $highest = 0;
        foreach ($this->stored as $existing) {
            if ($existing->organizationId()->equals($organizationId) && $existing->ticketId()->equals($ticketId)) {
                $highest = max($highest, $existing->number() ?? 0);
            }
        }

        return $highest + 1;
    }

    public function save(Round $round, \DateTimeImmutable $savedAt): void
    {
        $organizationId = $this->requireTenant();
        $storedIndex = null;
        foreach ($this->stored as $index => $existing) {
            if ($existing->organizationId()->equals($organizationId) && $existing->id()->equals($round->id())) {
                $storedIndex = $index;
            }
        }
        if (null === $storedIndex || $this->stored[$storedIndex]->version() !== $round->savedVersion()) {
            throw new RoundChanged('The round changed since it was read.');
        }
        foreach ($this->stored as $index => $existing) {
            if ($index !== $storedIndex && null !== $round->number() && $existing->number() === $round->number()
                && $existing->organizationId()->equals($round->organizationId()) && $existing->ticketId()->equals($round->ticketId())) {
                throw new RoundSlotTaken('The ticket has this round already.');
            }
        }
        $storedPictures = [];
        foreach ($this->stored[$storedIndex]->screenshots() as $screenshot) {
            $storedPictures[$screenshot->id()->toString()] = [$screenshot->mediaId(), $screenshot->position()];
        }
        foreach ($round->screenshots() as $screenshot) {
            $storedPicture = $storedPictures[$screenshot->id()->toString()] ?? null;
            if (null !== $storedPicture && $storedPicture !== [$screenshot->mediaId(), $screenshot->position()]) {
                throw new \LogicException(\sprintf('Screenshot %s keeps its picture and position once stored.', $screenshot->id()->toString()));
            }
        }

        $stored = $this->stored;
        $stored[$storedIndex] = self::copyOf($round);
        $this->stored = array_values($stored);
    }

    private function requireTenant(): OrganizationId
    {
        if (null === $this->tenantContext) {
            throw new \LogicException('InMemoryRounds reads and saves under a TenantContext; none was given.');
        }

        return $this->tenantContext->require();
    }

    private static function isOpenUnsharedDraft(Round $round): bool
    {
        return $round->isUnsharedDraft() && RoundState::Open === $round->state();
    }

    /**
     * The round as a fresh read gives it, at its version; it shares nothing that can change with $round.
     */
    private static function copyOf(Round $round): Round
    {
        return Round::restored(
            $round->id(),
            $round->organizationId(),
            $round->ticketId(),
            $round->kind(),
            $round->ownerId(),
            $round->number(),
            $round->sharedAt(),
            $round->state(),
            $round->version(),
            $round->screenshots(),
            $round->createdAt(),
            $round->deletedAt(),
            $round->decision(),
            $round->decidedAt(),
        );
    }

    private function nextUuid(int $base): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-%012x', $base + ++$this->issuedIds);
    }
}
