<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One round of a ticket with its screenshots (FR-RND-03, FR-RND-14). A round has a number exactly when it is shared:
 * the report is Round 0 from the start, a draft gets the next free number when its owner shares it (ADR-0017). Until
 * then only its owner sees it and changes it. Every accepted change adds one to the version; a refused one changes
 * nothing.
 */
final class Round
{
    /** Phase 5 design notes: 50 screenshots per round. */
    public const int MAX_SCREENSHOTS = 50;

    public const int REPORT_NUMBER = 0;

    /**
     * @param list<Screenshot> $screenshots by position
     */
    private function __construct(
        private readonly RoundId $id,
        private readonly OrganizationId $organizationId,
        private readonly TicketId $ticketId,
        private readonly RoundKind $kind,
        private readonly UserId $ownerId,
        private ?int $number,
        private ?\DateTimeImmutable $sharedAt,
        private RoundState $state,
        private int $version,
        private readonly int $savedVersion,
        private array $screenshots,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $deletedAt,
        private ?DraftDecision $decision,
        private ?\DateTimeImmutable $decidedAt,
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

        return new self($id, $organizationId, $ticketId, RoundKind::Report, $reporterId, self::REPORT_NUMBER, $reportedAt, RoundState::Open, 1, 1, self::byPosition($screenshots), $reportedAt, null, null, null);
    }

    /**
     * A private, empty draft round of its owner, with no number until it is shared.
     */
    public static function draft(RoundId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): self
    {
        return new self($id, $organizationId, $ticketId, RoundKind::Draft, $ownerId, null, null, RoundState::Open, 1, 1, [], $startedAt, null, null, null);
    }

    /**
     * A stored round as Rounds reads it back; its version is the one Rounds::save writes over.
     *
     * @param list<Screenshot> $screenshots in any order
     *
     * @throws \InvalidArgumentException when two screenshots share a position, the version is below 1, or a number
     *                                   comes without a share time, a decision without its time, or the reverse
     */
    public static function restored(
        RoundId $id,
        OrganizationId $organizationId,
        TicketId $ticketId,
        RoundKind $kind,
        UserId $ownerId,
        ?int $number,
        ?\DateTimeImmutable $sharedAt,
        RoundState $state,
        int $version,
        array $screenshots,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $deletedAt,
        ?DraftDecision $decision,
        ?\DateTimeImmutable $decidedAt,
    ): self {
        if ($version < 1) {
            throw new \InvalidArgumentException('A round version starts at 1.');
        }
        if ((null === $number) !== (null === $sharedAt) || (null === $decision) !== (null === $decidedAt)) {
            throw new \InvalidArgumentException('A round has a number exactly when it is shared, and a decision exactly when it has its time.');
        }

        return new self($id, $organizationId, $ticketId, $kind, $ownerId, $number, $sharedAt, $state, $version, $version, self::byPosition($screenshots), $createdAt, $deletedAt, $decision, $decidedAt);
    }

    /**
     * Shows the draft to everyone who may see the ticket as round $number.
     *
     * @param int $number the next free round number of the ticket, which the caller reads; from 1, as 0 is the report
     *
     * @throws \DomainException when the round is not a draft, was deleted, is shared already, the sharer is not its
     *                          owner or the number is below 1; the round stays as it was
     */
    public function share(UserId $sharerId, int $number, \DateTimeImmutable $sharedAt): void
    {
        if (RoundKind::Draft !== $this->kind) {
            throw new \DomainException('Only a draft round can be shared.');
        }
        if (null !== $this->deletedAt) {
            throw new \DomainException('This draft round was deleted.');
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
        ++$this->version;
    }

    /**
     * Adds a picture with no title, summary, pins or verdict after the last one.
     *
     * @throws \DomainException          when the round is not an open unshared draft of $actorId; the round stays as it
     *                                   was
     * @throws \InvalidArgumentException when the round holds MAX_SCREENSHOTS already, as the constructor refuses more,
     *                                   or the media id is not a UUIDv7; the round stays as it was
     */
    public function addScreenshot(UserId $actorId, ScreenshotId $screenshotId, string $mediaId): void
    {
        $this->requireEditableDraft($actorId);
        if (\count($this->screenshots) >= self::MAX_SCREENSHOTS) {
            throw new \InvalidArgumentException(\sprintf('A round holds at most %d screenshots.', self::MAX_SCREENSHOTS));
        }
        // After the highest position, never in a gap a removed picture left, so no stored picture has to move.
        $position = [] === $this->screenshots ? 0 : $this->screenshots[array_key_last($this->screenshots)]->position() + 1;

        $this->screenshots = [...$this->screenshots, Screenshot::of($screenshotId, $mediaId, $position, null, null, [])];
        ++$this->version;
    }

    /**
     * The round's screenshot of that picture, the first by position when it holds the picture twice, or null.
     *
     * @param string $mediaId in any case
     */
    public function screenshotOfMedia(string $mediaId): ?Screenshot
    {
        $lowercaseMediaId = strtolower($mediaId);
        foreach ($this->screenshots as $screenshot) {
            if ($screenshot->mediaId() === $lowercaseMediaId) {
                return $screenshot;
            }
        }

        return null;
    }

    /**
     * @throws \DomainException when the round is not an open unshared draft of $actorId; the round stays as it is
     */
    public function refuseUnlessEditableBy(UserId $actorId): void
    {
        $this->requireEditableDraft($actorId);
    }

    /**
     * Removes the picture with its pins; the others keep their positions.
     *
     * @throws \DomainException when the round is not an open unshared draft of $actorId, or has no such picture; the
     *                          round stays as it was
     */
    public function removeScreenshot(UserId $actorId, ScreenshotId $screenshotId): void
    {
        $this->requireEditableDraft($actorId);
        $index = $this->indexOf($screenshotId);

        $remaining = $this->screenshots;
        unset($remaining[$index]);
        $this->screenshots = array_values($remaining);
        ++$this->version;
    }

    /**
     * Sets the picture's title and summary, trimmed; a blank one is none.
     *
     * @throws \DomainException          when the round is not an open unshared draft of $actorId, or has no such
     *                                   picture; the round stays as it was
     * @throws \InvalidArgumentException when the title is over Screenshot::TITLE_MAX_LENGTH or the summary over
     *                                   Screenshot::SUMMARY_MAX_LENGTH characters; the round stays as it was
     */
    public function editScreenshotText(UserId $actorId, ScreenshotId $screenshotId, ?string $title, ?string $summary): void
    {
        $this->requireEditableDraft($actorId);
        $index = $this->indexOf($screenshotId);

        $this->replaceScreenshotAt($index, $this->screenshots[$index]->withText($title, $summary));
        ++$this->version;
    }

    /**
     * Puts $pins on the picture in place of the pins it had.
     *
     * @param list<Pin> $pins new pins, each with an id the round has not had
     *
     * @throws \DomainException          when the round is not an open unshared draft of $actorId, or has no such
     *                                   picture; the round stays as it was
     * @throws \InvalidArgumentException when there are more than Screenshot::MAX_PINS pins or two share a number; the
     *                                   round stays as it was
     */
    public function replacePins(UserId $actorId, ScreenshotId $screenshotId, array $pins): void
    {
        $this->requireEditableDraft($actorId);
        $index = $this->indexOf($screenshotId);

        $this->replaceScreenshotAt($index, $this->screenshots[$index]->withPins($pins));
        ++$this->version;
    }

    /**
     * Sets the owner's verdict on the picture, or clears it with null.
     *
     * @throws \DomainException when the round is not an open unshared draft of $actorId, or has no such picture; the
     *                          round stays as it was
     */
    public function setVerdict(UserId $actorId, ScreenshotId $screenshotId, ?ScreenshotVerdict $verdict): void
    {
        $this->requireEditableDraft($actorId);
        $index = $this->indexOf($screenshotId);

        $this->replaceScreenshotAt($index, $this->screenshots[$index]->withVerdict($verdict));
        ++$this->version;
    }

    /**
     * Closes the draft for good; it frees its owner's draft slot on the ticket, and its pictures are left for
     * retention to remove (ADR-0017, Delete).
     *
     * @throws \DomainException when the round is not an open unshared draft of $actorId; the round stays as it was
     */
    public function delete(UserId $actorId, \DateTimeImmutable $deletedAt): void
    {
        $this->requireEditableDraft($actorId);

        $this->state = RoundState::Closed;
        $this->deletedAt = $deletedAt;
        ++$this->version;
    }

    /**
     * Records the owner's hand-off choice (P05.T9), once per draft: Shared after the draft was shared, KeptPrivate
     * while it is not.
     *
     * @throws \DomainException when the round is not a draft, was deleted, $actorId is not its owner, a choice was
     *                          recorded already, or the choice does not match whether it is shared; the round stays as
     *                          it was
     */
    public function decide(UserId $actorId, DraftDecision $decision, \DateTimeImmutable $decidedAt): void
    {
        if (RoundKind::Draft !== $this->kind) {
            throw new \DomainException('Only a draft round is shared or kept private.');
        }
        if (!$actorId->equals($this->ownerId)) {
            throw new \DomainException('Only the person who owns a draft round can decide about it.');
        }
        if (null !== $this->deletedAt) {
            throw new \DomainException('This draft round was deleted.');
        }
        if (null !== $this->decision) {
            throw new \DomainException('You decided about this draft round already.');
        }
        if (DraftDecision::Shared === $decision && null === $this->sharedAt) {
            throw new \DomainException('Share the draft round before recording that you shared it.');
        }
        if (DraftDecision::KeptPrivate === $decision && null !== $this->sharedAt) {
            throw new \DomainException('A shared draft round cannot be kept private.');
        }

        $this->decision = $decision;
        $this->decidedAt = $decidedAt;
        ++$this->version;
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
     * The version the stored round had when this one was made or read; Rounds::save writes only over that version.
     */
    public function savedVersion(): int
    {
        return $this->savedVersion;
    }

    /**
     * @return list<Screenshot> by position
     */
    public function screenshots(): array
    {
        return $this->screenshots;
    }

    /**
     * How many pictures have the verdict "Looks right", for the draft's status line ("1 of 2 look right").
     */
    public function screenshotsThatLookRight(): int
    {
        return \count(array_filter($this->screenshots, static fn (Screenshot $screenshot): bool => ScreenshotVerdict::LooksRight === $screenshot->verdict()));
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Set exactly when the owner deleted the unshared draft.
     */
    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function decision(): ?DraftDecision
    {
        return $this->decision;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /**
     * @throws \DomainException when the round is not a draft, $actorId is not its owner, or it was deleted, is shared
     *                          or is closed
     */
    private function requireEditableDraft(UserId $actorId): void
    {
        if (RoundKind::Draft !== $this->kind) {
            throw new \DomainException('Only a draft round can be changed this way.');
        }
        if (!$actorId->equals($this->ownerId)) {
            throw new \DomainException('Only the person who owns a draft round can change it.');
        }
        if (null !== $this->deletedAt) {
            throw new \DomainException('This draft round was deleted.');
        }
        if (null !== $this->sharedAt) {
            throw new \DomainException('This draft round is shared, so it no longer changes.');
        }
        if (RoundState::Open !== $this->state) {
            throw new \DomainException('This draft round is closed.');
        }
    }

    /**
     * @throws \DomainException when the round has no such picture
     */
    private function indexOf(ScreenshotId $screenshotId): int
    {
        foreach ($this->screenshots as $index => $screenshot) {
            if ($screenshot->id()->equals($screenshotId)) {
                return $index;
            }
        }

        throw new \DomainException('This draft round has no such screenshot.');
    }

    private function replaceScreenshotAt(int $index, Screenshot $screenshot): void
    {
        $screenshots = $this->screenshots;
        $screenshots[$index] = $screenshot;
        $this->screenshots = array_values($screenshots);
    }

    /**
     * @param list<Screenshot> $screenshots
     *
     * @return list<Screenshot>
     *
     * @throws \InvalidArgumentException when two share a position
     */
    private static function byPosition(array $screenshots): array
    {
        $positions = [];
        foreach ($screenshots as $screenshot) {
            if (isset($positions[$screenshot->position()])) {
                throw new \InvalidArgumentException('Two screenshots of one round cannot share a position.');
            }
            $positions[$screenshot->position()] = true;
        }
        usort($screenshots, static fn (Screenshot $first, Screenshot $second): int => $first->position() <=> $second->position());

        return $screenshots;
    }
}
