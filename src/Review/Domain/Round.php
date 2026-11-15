<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One round of a ticket with its screenshots (FR-RND-03, FR-RND-14). A round has a number exactly when it is shared:
 * the report is Round 0 from the start, a draft gets the next free number when its owner shares it (ADR-0017). Until
 * then only its owner sees it.
 */
final class Round
{
    /** Phase 5 design notes: 50 screenshots per round. */
    public const int MAX_SCREENSHOTS = 50;

    public const int REPORT_NUMBER = 0;

    /**
     * @param list<Screenshot> $screenshots
     */
    private function __construct(
        private readonly RoundId $id,
        private readonly OrganizationId $organizationId,
        private readonly TicketId $ticketId,
        private readonly RoundKind $kind,
        private readonly UserId $ownerId,
        private ?int $number,
        private ?\DateTimeImmutable $sharedAt,
        private readonly RoundState $state,
        private readonly int $version,
        private readonly array $screenshots,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Round 0 of a ticket: the reporter's screenshots and pins, shared from the start.
     *
     * @param list<Screenshot> $screenshots
     *
     * @throws \InvalidArgumentException when there is no screenshot, more than MAX_SCREENSHOTS, or two at one position
     */
    public static function report(RoundId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $reporterId, array $screenshots, \DateTimeImmutable $reportedAt): self
    {
        if ([] === $screenshots) {
            throw new \InvalidArgumentException('A report round needs at least one screenshot.');
        }
        if (\count($screenshots) > self::MAX_SCREENSHOTS) {
            throw new \InvalidArgumentException(\sprintf('A round holds at most %d screenshots.', self::MAX_SCREENSHOTS));
        }
        $positions = [];
        foreach ($screenshots as $screenshot) {
            if (isset($positions[$screenshot->position()])) {
                throw new \InvalidArgumentException('Two screenshots of one round cannot share a position.');
            }
            $positions[$screenshot->position()] = true;
        }
        usort($screenshots, static fn (Screenshot $first, Screenshot $second): int => $first->position() <=> $second->position());

        return new self($id, $organizationId, $ticketId, RoundKind::Report, $reporterId, self::REPORT_NUMBER, $reportedAt, RoundState::Open, 1, $screenshots, $reportedAt);
    }

    /**
     * A private, empty draft round of its owner, with no number until it is shared.
     */
    public static function draft(RoundId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): self
    {
        return new self($id, $organizationId, $ticketId, RoundKind::Draft, $ownerId, null, null, RoundState::Open, 1, [], $startedAt);
    }

    /**
     * Shows the draft to everyone who may see the ticket as round $number.
     *
     * @param int $number the next free round number of the ticket, which the caller reads; from 1, as 0 is the report
     *
     * @throws \DomainException when the round is not a draft, is shared already, the sharer is not its owner or the
     *                          number is below 1; the round stays as it was
     */
    public function share(UserId $sharerId, int $number, \DateTimeImmutable $sharedAt): void
    {
        if (RoundKind::Draft !== $this->kind) {
            throw new \DomainException('Only a draft round can be shared.');
        }
        if (null !== $this->sharedAt) {
            throw new \DomainException('This draft round is shared already.');
        }
        if (!$sharerId->equals($this->ownerId)) {
            throw new \DomainException('Only the person who owns a draft round can share it.');
        }
        if ($number < 1) {
            throw new \DomainException('A shared draft round gets a number from 1; Round 0 is the report.');
        }

        $this->number = $number;
        $this->sharedAt = $sharedAt;
    }

    public function id(): RoundId
    {
        return $this->id;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function ticketId(): TicketId
    {
        return $this->ticketId;
    }

    public function kind(): RoundKind
    {
        return $this->kind;
    }

    public function ownerId(): UserId
    {
        return $this->ownerId;
    }

    /**
     * Null exactly while the round is an unshared draft.
     */
    public function number(): ?int
    {
        return $this->number;
    }

    public function sharedAt(): ?\DateTimeImmutable
    {
        return $this->sharedAt;
    }

    public function isUnsharedDraft(): bool
    {
        return RoundKind::Draft === $this->kind && null === $this->sharedAt;
    }

    public function state(): RoundState
    {
        return $this->state;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return list<Screenshot> by position
     */
    public function screenshots(): array
    {
        return $this->screenshots;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
