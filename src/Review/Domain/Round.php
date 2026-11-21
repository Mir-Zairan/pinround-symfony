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

    public const string NO_SUCH_PIN = 'This draft round has no such pin.';
    public const string PIN_ON_LATEST_TRY = 'Only a pin on an earlier try can be marked fixed or still wrong.';

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
     * Shows the draft to everyone who may see the ticket as round $number. They see only latestTries() (ADR-0048); the
     * earlier tries and their pins stay in the round for its owner alone, and every try goes when the round does.
     *
     * @param int $number the next free round number of the ticket, which the caller reads; from 1, as 0 is the report
     *
     * @throws DraftHasNoScreenshots when the owner's draft holds no screenshot (ADR-0048); the round stays as it was
     * @throws \DomainException      when the round is not a draft, was deleted, is shared already, the sharer is not
     *                               its owner or the number is below 1; the round stays as it was
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
        if ([] === $this->screenshots) {
            throw new DraftHasNoScreenshots();
        }
        if ($number < 1) {
            throw new \DomainException('A shared draft round gets a number from 1; Round 0 is the report.');
        }

        $this->number = $number;
        $this->sharedAt = $sharedAt;
        ++$this->version;
    }

    /**
     * Adds a picture with no title, summary, pins or verdict after the last one. With $retakeOf it is the next try of
     * that screenshot's chain (ADR-0048) and points at the chain's first try, whichever try $retakeOf names.
     *
     * @param ScreenshotId|null $retakeOf any try of this draft's chain the picture retakes; null for a new screenshot
     *
     * @throws RetakenScreenshotNotFound when $retakeOf is not a screenshot of this draft; the round stays as it was
     * @throws ScreenshotTriesFull       when the chain holds Screenshot::MAX_TRIES tries already; the round stays as it
     *                                   was
     * @throws \DomainException          when the round is not an open unshared draft of $actorId; the round stays as it
     *                                   was
     * @throws \InvalidArgumentException when the round holds MAX_SCREENSHOTS already, as the constructor refuses more,
     *                                   or the media id is not a UUIDv7; the round stays as it was
     */
    public function addScreenshot(UserId $actorId, ScreenshotId $screenshotId, string $mediaId, ?ScreenshotId $retakeOf = null): void
    {
        $this->requireEditableDraft($actorId);
        $firstTryId = null === $retakeOf ? null : $this->firstTryIdOfChainWith($retakeOf);
        if (null !== $firstTryId && \count($this->triesOfChain($firstTryId)) >= Screenshot::MAX_TRIES) {
            throw new ScreenshotTriesFull();
        }
        if (\count($this->screenshots) >= self::MAX_SCREENSHOTS) {
            throw new \InvalidArgumentException(\sprintf('A round holds at most %d screenshots.', self::MAX_SCREENSHOTS));
        }
        // After the highest position, never in a gap a removed picture left, so no stored picture has to move.
        $position = [] === $this->screenshots ? 0 : $this->screenshots[array_key_last($this->screenshots)]->position() + 1;
        $added = Screenshot::of($screenshotId, $mediaId, $position, null, null, []);

        $this->screenshots = [...$this->screenshots, null === $firstTryId ? $added : $added->asTryOf($firstTryId)];
        ++$this->version;
    }

    /**
     * The last try of each chain, which is all anyone but the owner sees of a shared round (ADR-0048); a try keeps its
     * retakeOf, so it may name a first try that is not in this list.
     *
     * @return list<Screenshot> by position
     */
    public function latestTries(): array
    {
        $latestIdByChain = [];
        foreach ($this->screenshots as $screenshot) {
            $latestIdByChain[$screenshot->firstTryId()->toString()] = $screenshot->id();
        }

        return array_values(array_filter(
            $this->screenshots,
            static fn (Screenshot $screenshot): bool => $latestIdByChain[$screenshot->firstTryId()->toString()]->equals($screenshot->id()),
        ));
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
     * Removes the picture with its pins; the others keep their positions. When it was the first try of a chain, the
     * next try becomes the first and the later ones point at it, so the chain stays one chain. A pin of the chain fixed
     * on the removed try loses its mark, and one fixed on a later try names that try by its new number. When it was the
     * latest try, the try before it is the latest now and its pins lose every mark, as a pin on the latest try takes none.
     *
     * @throws \DomainException when the round is not an open unshared draft of $actorId, or has no such picture; the
     *                          round stays as it was
     */
    public function removeScreenshot(UserId $actorId, ScreenshotId $screenshotId): void
    {
        $this->requireEditableDraft($actorId);
        $index = $this->indexOf($screenshotId);
        $removed = $this->screenshots[$index];
        $removedTryNumber = \count(array_filter(
            $this->triesOfChain($removed->firstTryId()),
            static fn (Screenshot $try): bool => $try->position() <= $removed->position(),
        ));
        $wasLatestTry = $this->isLatestTry($removed);

        $remaining = $this->screenshots;
        unset($remaining[$index]);
        $remaining = self::withFixesRenumbered(array_values($remaining), $removed->firstTryId(), $removedTryNumber);
        if ($wasLatestTry) {
            $remaining = self::withMarksClearedOnLatestTry($remaining, $removed->firstTryId());
        }
        $this->screenshots = self::withChainStartedAgain($remaining, $screenshotId);
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
     * Marks a pin on an earlier try of a screenshot as fixed by a later try or still wrong, or clears the mark with null
     * (ADR-0048). A pin on the latest try of its chain takes no mark: no later try can have fixed it yet. Fixed stores
     * the chain's latest try number as it is now, so a later retake leaves "Fixed on try N" as it was.
     *
     * @throws \DomainException when the round is not an open unshared draft of $actorId, has no such pin, or the pin is
     *                          on the latest try of its chain; the round stays as it was
     */
    public function setPinVerdict(UserId $actorId, PinId $pinId, ?PinVerdict $verdict): void
    {
        $this->requireEditableDraft($actorId);
        foreach ($this->screenshots as $index => $screenshot) {
            foreach ($screenshot->pins() as $pinIndex => $pin) {
                if (!$pin->id()->equals($pinId)) {
                    continue;
                }
                if ($this->isLatestTry($screenshot)) {
                    throw new \DomainException(self::PIN_ON_LATEST_TRY);
                }
                $latestTryNumber = \count($this->triesOfChain($screenshot->firstTryId()));
                $pins = $screenshot->pins();
                $pins[$pinIndex] = $pin->withVerdict($verdict, PinVerdict::Fixed === $verdict ? $latestTryNumber : null);
                $this->replaceScreenshotAt($index, $screenshot->withPins(array_values($pins)));
                ++$this->version;

                return;
            }
        }

        throw new \DomainException(self::NO_SUCH_PIN);
    }

    /**
     * Closes the draft for good; it frees its owner's draft slot on the ticket, and its pictures stay in Media (ADR-0017,
     * Delete). A report draft made of it by "Make it a new ticket" holds the same pictures (ADR-0044), so retention may
     * remove a picture only once no round and no report draft points to it.
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

    /**
     * Closes a shared round when the person whose turn it is moves the ticket on, or when the assignee shares the
     * next round over a findings round (ADR-0048); its verdicts no longer change. Who may close it is the caller's
     * rule.
     *
     * @throws \DomainException when the round is not a shared review round or shared draft (Round 0, the checklist
     *                          round and an unshared or deleted draft are not), or is closed already; the round stays
     *                          as it was
     */
    public function close(): void
    {
        if (!$this->isSharedRound()) {
            throw new \DomainException('Only a shared round is closed when the ticket moves on.');
        }
        if (RoundState::Open !== $this->state) {
            throw new \DomainException('This round is closed already.');
        }

        $this->state = RoundState::Closed;
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

    /**
     * An open hand-off round, which blocks every other share on the ticket until it closes (ADR-0048). Round 0 and the
     * checklist round never are.
     */
    public function isOpenSharedRound(): bool
    {
        return $this->isSharedRound() && RoundState::Open === $this->state;
    }

    /**
     * Whether the round is findings for $assigneeId (ADR-0044): a shared draft of anyone else, as FindingsChecklist
     * has it. Open or closed alike.
     */
    public function isFindingsFor(UserId $assigneeId): bool
    {
        return RoundKind::Draft === $this->kind && $this->isSharedRound() && !$this->ownerId->equals($assigneeId);
    }

    /**
     * Whether a move-on verb by $moverId closes this round (ADR-0048): an open shared round closes when the person
     * whose turn it is moves the ticket on, and a findings round also when the assignee does. A round its owner shared
     * outside a move ("Share now") is not closed by that owner's own move. A lead-only move closes nothing, which is the
     * caller's to know.
     *
     * @param bool        $isMoversTurn whether the ticket was $moverId's turn when they moved it
     * @param UserId|null $assigneeId   the ticket's assignee; null when nobody has it
     */
    public function isClosedByMoveOf(UserId $moverId, bool $isMoversTurn, ?UserId $assigneeId): bool
    {
        if (!$this->isOpenSharedRound()) {
            return false;
        }
        if ($this->ownerId->equals($moverId) && DraftDecision::Shared !== $this->decision) {
            return false;
        }

        return $isMoversTurn || (null !== $assigneeId && $moverId->equals($assigneeId) && $this->isFindingsFor($assigneeId));
    }

    /**
     * Whether $sharerId's share of the next round closes this one first (ADR-0048): an open findings round, when the
     * assignee shares.
     *
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody has it
     */
    public function isClosedByShareOf(UserId $sharerId, ?UserId $assigneeId): bool
    {
        return $this->isOpenSharedRound() && null !== $assigneeId && $sharerId->equals($assigneeId) && $this->isFindingsFor($assigneeId);
    }

    /**
     * The assignee when this is their findings round, which is ready for them; null for any other round, which is
     * ready for whoever's turn it is, a person Review does not know.
     *
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody has it
     */
    public function findingsReadyFor(?UserId $assigneeId): ?UserId
    {
        return null !== $assigneeId && $this->isFindingsFor($assigneeId) ? $assigneeId : null;
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
     * A hand-off with a number from 1: a review round, or a draft that was shared, which delete() never touches.
     * ReviewedRound takes verdicts on the same rounds.
     */
    private function isSharedRound(): bool
    {
        return \in_array($this->kind, [RoundKind::Review, RoundKind::Draft], true) && null !== $this->sharedAt;
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
     * @throws RetakenScreenshotNotFound when the round has no such picture
     */
    private function firstTryIdOfChainWith(ScreenshotId $screenshotId): ScreenshotId
    {
        foreach ($this->screenshots as $screenshot) {
            if ($screenshot->id()->equals($screenshotId)) {
                return $screenshot->firstTryId();
            }
        }

        throw new RetakenScreenshotNotFound();
    }

    private function isLatestTry(Screenshot $screenshot): bool
    {
        foreach ($this->triesOfChain($screenshot->firstTryId()) as $try) {
            if ($try->position() > $screenshot->position()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Screenshot> by position, which is the order they were added in
     */
    private function triesOfChain(ScreenshotId $firstTryId): array
    {
        return array_values(array_filter($this->screenshots, static fn (Screenshot $screenshot): bool => $screenshot->firstTryId()->equals($firstTryId)));
    }

    /**
     * The screenshots with the "Fixed on try N" marks of $firstTryId's chain kept in step with its try $removedTryNumber
     * being gone: a mark that named it is cleared, and one that named a later try counts one less.
     *
     * @param list<Screenshot> $screenshots by position, without the removed try
     *
     * @return list<Screenshot>
     */
    private static function withFixesRenumbered(array $screenshots, ScreenshotId $firstTryId, int $removedTryNumber): array
    {
        foreach ($screenshots as $index => $screenshot) {
            if (!$screenshot->firstTryId()->equals($firstTryId)) {
                continue;
            }
            $screenshots[$index] = $screenshot->withPins(array_map(static function (Pin $pin) use ($removedTryNumber): Pin {
                $fixedOnTry = $pin->fixedOnTry();
                if (null === $fixedOnTry || $fixedOnTry < $removedTryNumber) {
                    return $pin;
                }

                return $fixedOnTry === $removedTryNumber ? $pin->withVerdict(null, null) : $pin->withVerdict(PinVerdict::Fixed, $fixedOnTry - 1);
            }, $screenshot->pins()));
        }

        return $screenshots;
    }

    /**
     * The screenshots with no Fixed or Still wrong mark left on the latest try of $firstTryId's chain, if any try of it
     * is left.
     *
     * @param list<Screenshot> $screenshots by position
     *
     * @return list<Screenshot>
     */
    private static function withMarksClearedOnLatestTry(array $screenshots, ScreenshotId $firstTryId): array
    {
        $latestIndex = null;
        foreach ($screenshots as $index => $screenshot) {
            if ($screenshot->firstTryId()->equals($firstTryId)) {
                $latestIndex = $index;
            }
        }
        if (null === $latestIndex) {
            return $screenshots;
        }
        $latest = $screenshots[$latestIndex];
        $screenshots[$latestIndex] = $latest->withPins(array_map(static fn (Pin $pin): Pin => $pin->withVerdict(null, null), $latest->pins()));

        return array_values($screenshots);
    }

    /**
     * The screenshots with the tries of $removedId's chain, if it was a first try, under the earliest of them instead.
     *
     * @param list<Screenshot> $screenshots by position, without $removedId
     *
     * @return list<Screenshot>
     */
    private static function withChainStartedAgain(array $screenshots, ScreenshotId $removedId): array
    {
        $newFirstTryId = null;
        foreach ($screenshots as $index => $screenshot) {
            if (null === $screenshot->retakeOf() || !$screenshot->retakeOf()->equals($removedId)) {
                continue;
            }
            if (null === $newFirstTryId) {
                $newFirstTryId = $screenshot->id();
                $screenshots[$index] = $screenshot->asFirstTry();
            } else {
                $screenshots[$index] = $screenshot->asTryOf($newFirstTryId);
            }
        }

        return $screenshots;
    }

    /**
     * @param list<Screenshot> $screenshots
     *
     * @return list<Screenshot>
     *
     * @throws \InvalidArgumentException when two share a position, or a try points at a screenshot that is not a first
     *                                   try of the round
     */
    private static function byPosition(array $screenshots): array
    {
        $positions = [];
        $firstTryIds = [];
        foreach ($screenshots as $screenshot) {
            if (isset($positions[$screenshot->position()])) {
                throw new \InvalidArgumentException('Two screenshots of one round cannot share a position.');
            }
            $positions[$screenshot->position()] = true;
            if (null === $screenshot->retakeOf()) {
                $firstTryIds[$screenshot->id()->toString()] = true;
            }
        }
        foreach ($screenshots as $screenshot) {
            if (null !== $screenshot->retakeOf() && !isset($firstTryIds[$screenshot->retakeOf()->toString()])) {
                throw new \InvalidArgumentException('A retake points at the first try of its chain in the same round.');
            }
        }
        usort($screenshots, static fn (Screenshot $first, Screenshot $second): int => $first->position() <=> $second->position());

        return $screenshots;
    }
}
