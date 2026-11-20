<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Media\Api\UploadedMedia;
use App\Review\Api\AddedDraftScreenshot;
use App\Review\Api\DraftScreenshotCommands;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\Checklist\RoundChecklistCommands;
use App\Review\Application\Checklist\TodoStep;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\DraftHasNoScreenshots;
use App\Review\Domain\Pin;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketTurnQueries;

/**
 * RoundCommands, DraftScreenshotCommands, DraftRoundContents and LockedRoundClosing on the Round aggregate; the
 * contracts are on {@see RoundCommands}, {@see DraftScreenshotCommands}, {@see DraftRoundContents} and
 * {@see LockedRoundClosing}.
 */
final readonly class UseCaseRoundCommands implements RoundCommands, DraftScreenshotCommands, DraftRoundContents, LockedRoundClosing
{
    public function __construct(
        private Rounds $rounds,
        private TicketTurnQueries $ticketTurnQueries,
        private TenantContext $tenantContext,
        private ReportPictures $reportPictures,
        private RoundChecklistCommands $checklistCommands,
    ) {
    }

    public function createReport(TicketId $ticketId, array $screenshots, UserId $reporterId, \DateTimeImmutable $reportedAt): string
    {
        $organizationId = $this->tenantContext->require();
        // Before any query, so a list sized from outside is never walked past the cap.
        if (\count($screenshots) > Round::MAX_SCREENSHOTS) {
            throw RoundRefused::invalidContent(\sprintf('A round holds at most %d screenshots.', Round::MAX_SCREENSHOTS));
        }
        $ticket = $this->ticketTurnQueries->factsOf($ticketId) ?? throw new TicketNotFound('That ticket does not exist.');
        if (!$ticket->reporterId->equals($reporterId)) {
            throw new RoundRefused('Only the person who reported the ticket can add its report.');
        }
        $this->requireOwnProcessedMedia($organizationId, $reporterId, $screenshots);

        try {
            $round = Round::report(
                $this->rounds->nextIdentity(),
                $organizationId,
                $ticketId,
                $reporterId,
                array_map(
                    fn (ScreenshotWithPins $screenshot, int $position): Screenshot => $this->screenshotOf($screenshot, $position, $reporterId),
                    $screenshots,
                    array_keys($screenshots),
                ),
                $reportedAt,
            );
        } catch (\InvalidArgumentException $invalidContent) {
            throw RoundRefused::invalidContent($invalidContent->getMessage(), $invalidContent);
        }

        try {
            $this->rounds->add($round);
        } catch (RoundSlotTaken $taken) {
            throw new RoundRefused('This ticket has a report already.', 0, $taken);
        }
        // CreateChecklistOnTicketEventsHandler saw no Round 0 when the ticket reached To do, and no event follows this one.
        if (TodoStep::CORE_CODE === $ticket->coreCode) {
            $this->checklistCommands->createFromReport($ticketId, $reportedAt);
        }

        return $round->id()->toString();
    }

    public function startDraft(TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): string
    {
        $organizationId = $this->tenantContext->require();
        if (null === $this->ticketTurnQueries->factsOf($ticketId)) {
            throw new TicketNotFound('That ticket does not exist.');
        }
        if (null !== $this->rounds->openUnsharedDraftOf($ticketId, $ownerId)) {
            throw new RoundRefused(RoundRefused::DRAFT_EXISTS);
        }

        $draft = Round::draft($this->rounds->nextIdentity(), $organizationId, $ticketId, $ownerId, $startedAt);
        try {
            $this->rounds->add($draft);
        } catch (RoundSlotTaken $taken) {
            // Another request started one between the check above and this add.
            throw new RoundRefused(RoundRefused::DRAFT_EXISTS, 0, $taken);
        }

        return $draft->id()->toString();
    }

    public function addDraftScreenshot(string $roundId, UserId $ownerId, string $mediaId, \DateTimeImmutable $addedAt): string
    {
        $organizationId = $this->tenantContext->require();
        $draft = $this->ownDraft($roundId, $ownerId);
        $refusal = $this->reportPictures->refusalOf($organizationId, $ownerId, [$mediaId]);
        if (null !== $refusal) {
            throw new RoundRefused($refusal);
        }
        $screenshotId = $this->rounds->nextScreenshotIdentity();

        $this->changeAndSave($draft, $addedAt, static fn (Round $round) => $round->addScreenshot($ownerId, $screenshotId, $mediaId));

        return $screenshotId->toString();
    }

    public function addDraftScreenshotOnce(string $roundId, UserId $ownerId, ScreenshotWithPins $screenshot, \DateTimeImmutable $addedAt): AddedDraftScreenshot
    {
        $organizationId = $this->tenantContext->require();
        self::refuseMorePinsThanAPictureHolds($screenshot->pins);
        $draft = $this->ownDraft($roundId, $ownerId);
        $heldBefore = $draft->screenshotOfMedia($screenshot->mediaId);
        if (null !== $heldBefore) {
            try {
                $draft->refuseUnlessEditableBy($ownerId);
            } catch (\DomainException $refused) {
                // ownDraft found the owner's undeleted draft, so only a shared or closed one is refused here.
                throw RoundRefused::readOnly($refused->getMessage(), $refused);
            }

            return new AddedDraftScreenshot($heldBefore->id()->toString(), true, \count($heldBefore->pins()));
        }
        $refusal = $this->reportPictures->refusalOf($organizationId, $ownerId, [$screenshot->mediaId]);
        if (null !== $refusal) {
            throw new RoundRefused($refusal);
        }
        $screenshotId = $this->rounds->nextScreenshotIdentity();

        $this->changeAndSave($draft, $addedAt, function (Round $round) use ($ownerId, $screenshotId, $screenshot): void {
            $round->addScreenshot($ownerId, $screenshotId, $screenshot->mediaId);
            $round->editScreenshotText($ownerId, $screenshotId, $screenshot->title, $screenshot->summary);
            $round->replacePins($ownerId, $screenshotId, $this->pinsOf($screenshot->pins, $ownerId));
        });

        return new AddedDraftScreenshot($screenshotId->toString(), false, \count($screenshot->pins));
    }

    public function removeDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, \DateTimeImmutable $at): void
    {
        $draft = $this->ownDraft($roundId, $ownerId);
        $id = self::screenshotIdOf($screenshotId);

        $this->changeAndSave($draft, $at, static fn (Round $round) => $round->removeScreenshot($ownerId, $id));
    }

    public function editDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, ?string $title, ?string $summary, \DateTimeImmutable $at): void
    {
        $draft = $this->ownDraft($roundId, $ownerId);
        $id = self::screenshotIdOf($screenshotId);

        $this->changeAndSave($draft, $at, static fn (Round $round) => $round->editScreenshotText($ownerId, $id, $title, $summary));
    }

    public function setDraftPins(string $roundId, UserId $ownerId, string $screenshotId, array $pins, \DateTimeImmutable $at): void
    {
        self::refuseMorePinsThanAPictureHolds($pins);
        $draft = $this->ownDraft($roundId, $ownerId);
        $id = self::screenshotIdOf($screenshotId);

        $this->changeAndSave($draft, $at, fn (Round $round) => $round->replacePins($ownerId, $id, $this->pinsOf($pins, $ownerId)));
    }

    public function setDraftVerdict(string $roundId, UserId $ownerId, string $screenshotId, ?string $verdict, \DateTimeImmutable $at): void
    {
        $draft = $this->ownDraft($roundId, $ownerId);
        $screenshotVerdict = self::screenshotVerdictOf($verdict);
        $id = self::screenshotIdOf($screenshotId);

        $this->changeAndSave($draft, $at, static fn (Round $round) => $round->setVerdict($ownerId, $id, $screenshotVerdict));
    }

    public function setDraftVerdictWithSummary(string $roundId, UserId $ownerId, string $screenshotId, string $verdict, ?string $summary, \DateTimeImmutable $at): void
    {
        $draft = $this->ownDraft($roundId, $ownerId);
        $screenshotVerdict = self::screenshotVerdictOf($verdict);
        $id = self::screenshotIdOf($screenshotId);

        $this->changeAndSave($draft, $at, static function (Round $round) use ($ownerId, $id, $screenshotVerdict, $summary): void {
            $round->setVerdict($ownerId, $id, $screenshotVerdict);
            if (null !== $summary) {
                $round->editScreenshotText($ownerId, $id, self::titleOf($round, $id), $summary);
            }
        });
    }

    public function shareDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        return $this->shareOwnDraft($roundId, $ownerId, $sharedAt, false);
    }

    public function shareDraftAtHandOff(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        return $this->shareOwnDraft($roundId, $ownerId, $sharedAt, true);
    }

    public function deleteDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $deletedAt): void
    {
        $draft = $this->ownDraft($roundId, $ownerId);

        $this->changeAndSave($draft, $deletedAt, static fn (Round $round) => $round->delete($ownerId, $deletedAt));
    }

    public function contentsOf(string $roundId, UserId $ownerId): DraftRoundAsRead
    {
        $draft = $this->ownDraft($roundId, $ownerId);
        $screenshots = array_map(
            static fn (Screenshot $screenshot): ScreenshotWithPins => new ScreenshotWithPins(
                $screenshot->mediaId(),
                $screenshot->title(),
                $screenshot->summary(),
                array_map(static fn (Pin $pin): PlacedPin => new PlacedPin($pin->xPercent(), $pin->yPercent(), $pin->note()), $screenshot->pins()),
            ),
            $draft->screenshots(),
        );

        return new DraftRoundAsRead($draft->id()->toString(), $draft->ticketId(), $draft->version(), $screenshots);
    }

    public function deleteAsRead(DraftRoundAsRead $read, UserId $ownerId, \DateTimeImmutable $deletedAt): void
    {
        $draft = $this->ownDraft($read->roundId, $ownerId);
        if ($draft->version() !== $read->version) {
            throw new RoundRefused(RoundRefused::DRAFT_CHANGED);
        }

        $this->changeAndSave($draft, $deletedAt, static fn (Round $round) => $round->delete($ownerId, $deletedAt));
    }

    /**
     * @throws RoundRefused    as shareDraft and shareDraftAtHandOff say; nothing is saved
     * @throws \LogicException when the findings' items cannot be written or a findings round cannot be closed; the
     *                         share is saved, so the caller's transaction must roll back
     */
    private function shareOwnDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt, bool $isHandOffChoice): int
    {
        $draft = $this->ownDraft($roundId, $ownerId);
        // A draft shared already is refused below as read-only, rather than as waiting for its own open round.
        $findingsRoundsToClose = $draft->isUnsharedDraft() ? $this->findingsRoundsClosedBySharing($draft->ticketId(), $ownerId) : [];
        // Takes the number lock openSharedRoundsOf took again; a transaction-scoped advisory lock is re-entrant.
        $number = $this->rounds->nextFreeNumberOf($draft->ticketId());

        try {
            $this->changeAndSave($draft, $sharedAt, static function (Round $round) use ($ownerId, $number, $sharedAt, $isHandOffChoice): void {
                $round->share($ownerId, $number, $sharedAt);
                if ($isHandOffChoice) {
                    $round->decide($ownerId, DraftDecision::Shared, $sharedAt);
                }
            });
        } catch (RoundSlotTaken $taken) {
            throw new RoundRefused(RoundRefused::NUMBER_TAKEN, 0, $taken);
        }
        // After the share's save, so a refused share closes nothing; both are in the caller's one transaction, so no
        // reader sees the new round beside an open findings round (ADR-0048: close first, then number).
        foreach ($findingsRoundsToClose as $findingsRound) {
            $this->closeLockedRound($findingsRound, $sharedAt);
        }
        $this->checklistCommands->createFromFindings($draft, $sharedAt);

        return $number;
    }

    /**
     * Reads the ticket's open shared rounds under Rounds::openSharedRoundsOf's locks, so a second share waits for this
     * one's transaction and then sees its round. A findings round for the assignee is closed by the assignee's next
     * share (ADR-0048); every other open shared round makes the share wait for its verdict.
     *
     * @return list<Round> the open findings rounds the share closes, locked until the transaction ends; none when no
     *                     shared round is open
     *
     * @throws RoundRefused   roundStillOpen with the lowest-numbered open round the share may not close; nothing is saved
     * @throws TicketNotFound when a shared round is open and the tenant has no ticket with that id
     */
    private function findingsRoundsClosedBySharing(TicketId $ticketId, UserId $sharerId): array
    {
        $openRounds = $this->rounds->openSharedRoundsOf($ticketId);
        if ([] === $openRounds) {
            return [];
        }
        $assigneeId = ($this->ticketTurnQueries->factsOf($ticketId) ?? throw new TicketNotFound('That ticket does not exist.'))->assigneeId;
        foreach ($openRounds as $openRound) {
            if (!$openRound->isClosedByShareOf($sharerId, $assigneeId)) {
                $number = $openRound->number() ?? throw new \LogicException('An open shared round has a number.');

                throw RoundRefused::roundStillOpen($number, $openRound->findingsReadyFor($assigneeId));
            }
        }

        return $openRounds;
    }

    public function closeLockedRound(Round $round, \DateTimeImmutable $closedAt): void
    {
        try {
            $round->close();
            $this->rounds->save($round, $closedAt);
        } catch (\DomainException|RoundChanged $refused) {
            throw new \LogicException(\sprintf('Round %s was read under a row lock, yet could not be closed.', $round->id()->toString()), 0, $refused);
        }
    }

    /**
     * @throws RoundRefused NOT_FOUND when the id is malformed or unknown, or the round is another organization's, not
     *                      a draft, another person's or deleted
     */
    private function ownDraft(string $roundId, UserId $ownerId): Round
    {
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            throw new RoundRefused(RoundRefused::NOT_FOUND);
        }
        $round = $this->rounds->get($id);
        if (null === $round || RoundKind::Draft !== $round->kind() || !$round->ownerId()->equals($ownerId) || null !== $round->deletedAt()) {
            throw new RoundRefused(RoundRefused::NOT_FOUND);
        }

        return $round;
    }

    /**
     * @throws RoundRefused SCREENSHOT_NOT_FOUND when the id is malformed
     */
    private static function screenshotIdOf(string $screenshotId): ScreenshotId
    {
        try {
            return new ScreenshotId($screenshotId);
        } catch (\InvalidArgumentException) {
            throw new RoundRefused(RoundRefused::SCREENSHOT_NOT_FOUND);
        }
    }

    /**
     * @throws RoundRefused UNKNOWN_VERDICT, as invalid content, for anything but the two verdicts and null
     */
    private static function screenshotVerdictOf(?string $verdict): ?ScreenshotVerdict
    {
        return match ($verdict) {
            null => null,
            self::VERDICT_LOOKS_RIGHT => ScreenshotVerdict::LooksRight,
            self::VERDICT_NOT_RIGHT_YET => ScreenshotVerdict::NotRight,
            default => throw RoundRefused::invalidContent(RoundRefused::UNKNOWN_VERDICT),
        };
    }

    /**
     * @throws \LogicException when the round has no such picture, which a change of it refused before
     */
    private static function titleOf(Round $round, ScreenshotId $screenshotId): ?string
    {
        foreach ($round->screenshots() as $screenshot) {
            if ($screenshot->id()->equals($screenshotId)) {
                return $screenshot->title();
            }
        }

        throw new \LogicException('The picture was changed a moment ago, so the round has it.');
    }

    /**
     * ownDraft read the round as the owner's undeleted draft, so a domain refusal of a round that was shared or closed
     * before the change is read-only; the change itself may share it, as shareDraftAtHandOff does.
     *
     * @param \Closure(Round): void $change
     *
     * @throws RoundRefused   with the domain's reason when it refuses the change (read-only for a shared or closed
     *                        draft, invalid content for content that breaks a rule), or DRAFT_CHANGED when another
     *                        request saved the round since it was read; nothing is saved
     * @throws RoundSlotTaken when another round has the number the change gave this one; nothing is saved
     */
    private function changeAndSave(Round $round, \DateTimeImmutable $at, \Closure $change): void
    {
        $wasChangeable = $round->isUnsharedDraft() && RoundState::Open === $round->state();
        try {
            $change($round);
        } catch (\InvalidArgumentException $invalidContent) {
            throw RoundRefused::invalidContent($invalidContent->getMessage(), $invalidContent);
        } catch (DraftHasNoScreenshots $empty) {
            throw RoundRefused::sharingBlocked($empty->getMessage(), $empty);
        } catch (\DomainException $refused) {
            throw $wasChangeable ? new RoundRefused($refused->getMessage(), 0, $refused) : RoundRefused::readOnly($refused->getMessage(), $refused);
        }
        try {
            $this->rounds->save($round, $at);
        } catch (RoundChanged $changed) {
            throw new RoundRefused(RoundRefused::DRAFT_CHANGED, 0, $changed);
        }
    }

    /**
     * Before any id is taken, so a refused picture builds nothing.
     *
     * @param list<ScreenshotWithPins> $screenshots at most Round::MAX_SCREENSHOTS, which UploadedMedia::MAX_MEDIA_IDS covers
     *
     * @throws RoundRefused when a picture is not one the reporter uploaded, or is still processing or rejected
     */
    private function requireOwnProcessedMedia(OrganizationId $organizationId, UserId $reporterId, array $screenshots): void
    {
        $refusal = $this->reportPictures->refusalOf(
            $organizationId,
            $reporterId,
            array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $screenshots),
        );
        if (null !== $refusal) {
            throw new RoundRefused($refusal);
        }
    }

    /**
     * @throws \InvalidArgumentException when the screenshot or one of its pins breaks a rule of the domain
     */
    private function screenshotOf(ScreenshotWithPins $screenshot, int $position, UserId $authorId): Screenshot
    {
        if (\count($screenshot->pins) > Screenshot::MAX_PINS) {
            throw new \InvalidArgumentException(\sprintf('A picture holds at most %d pins.', Screenshot::MAX_PINS));
        }

        return Screenshot::of(
            $this->rounds->nextScreenshotIdentity(),
            $screenshot->mediaId,
            $position,
            $screenshot->title,
            $screenshot->summary,
            $this->pinsOf($screenshot->pins, $authorId),
        );
    }

    /**
     * @param list<PlacedPin> $pins
     *
     * @return list<Pin> numbered 1, 2, … in this order, each with a new id
     *
     * @throws \InvalidArgumentException when a pin sits outside the picture or its note is over Pin::NOTE_MAX_LENGTH
     */
    private function pinsOf(array $pins, UserId $authorId): array
    {
        return array_map(
            fn (PlacedPin $pin, int $index): Pin => Pin::placedBy($this->rounds->nextPinIdentity(), $index + 1, $pin->xPercent, $pin->yPercent, $pin->note, $authorId),
            $pins,
            array_keys($pins),
        );
    }

    /**
     * Before any read, so a list sized from outside is never walked past the cap.
     *
     * @param list<PlacedPin> $pins
     *
     * @throws RoundRefused as invalid content when there are more than Screenshot::MAX_PINS
     */
    private static function refuseMorePinsThanAPictureHolds(array $pins): void
    {
        if (\count($pins) > Screenshot::MAX_PINS) {
            throw RoundRefused::invalidContent(\sprintf('A picture holds at most %d pins.', Screenshot::MAX_PINS));
        }
    }
}
