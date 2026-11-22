<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
use App\Review\Api\AreaState;
use App\Review\Api\AreaView;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\VerdictCommands;
use App\Review\Application\OpenSharedRound;
use App\Review\Application\RoundsVisibleTo;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Checklist\ChecklistCards;
use App\Review\UI\Checklist\ChecklistRow;
use App\Review\UI\Controller\RoundController;
use App\Review\UI\PinView;
use App\Review\UI\RoundCards;
use App\Review\UI\RoundReadyFor;
use App\Review\UI\ScreenshotView;
use App\Review\UI\VisibleTicket;
use App\Review\UI\Voter\TicketTesterRule;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketPageView;

/**
 * Builds the round page of any round kind for one viewer (FR-RND-05, the prototype's Review board): the header, a tab
 * per screenshot, and one screenshot shown large with its pins and a side panel. A review round's screenshots are its
 * screen areas, each with the earlier round's picture of the place; the report's and a shared draft's are the latest
 * try of each chain, numbered as RoundCards numbers them. The checklist from the report has no number and so no page
 * (ADR-0048, note of 2026-11-22). The panel lists the pins of both pictures; a pin that is a check item (the report's,
 * through the checklist, or a findings round's) carries its state and the checklist buttons the viewer may use.
 *
 * Its reads do not grow with the screenshots or pins: one signing call for the shown pictures, for a round without areas
 * other than a review round, RoundsVisibleTo's read of the one round whose screenshots it shows, for a shared draft its
 * read of the hand-off round before it or, with none, of the report, whose pictures are the Befores (and, for a copied
 * picture that round does not hold, pairedBeforeOf's reads of its source), checkRowsOf's reads of the check items, and, on an open hand-off round, RoundReadyFor's reads of who it is ready for.
 */
final readonly class RoundPages
{
    /** The area verdicts in the order of their chips, by the value the form posts. */
    public const array AREA_VERDICT_LABELS = [
        VerdictCommands::AREA_PASS => 'Pass',
        VerdictCommands::AREA_NEEDS_FIX => 'Needs fix',
        VerdictCommands::AREA_QUESTION => 'Question',
    ];

    /** With the area's position: the id of its tab's state tag, which a verdict's stream replaces. */
    public const string AREA_STATE_ID_PREFIX = 'screenshot-tab-state-';

    public const string AREAS_TABS_LABEL = 'Screen areas';
    public const string SCREENSHOTS_TABS_LABEL = 'Screenshots';
    /** With a round number: the heading of a picture's pins in the side panel. */
    public const string PINS_FROM_ROUND = 'Pins from round %d';
    public const string NO_AREAS = 'This round has no screen areas.';
    public const string NO_SCREENSHOTS = 'This round has no screenshots.';

    /** ReviewRoundView's state of a round that still takes verdicts. */
    private const string OPEN_ROUND = 'open';

    /** ReviewRoundView's and RoundView's kinds. */
    private const string REPORT = 'report';
    private const string REVIEW = 'review';

    public function __construct(
        private SignedUrls $signedUrls,
        private RoundsVisibleTo $roundsVisibleTo,
        private RoundReadyFor $roundReadyFor,
        private ChecklistQueries $checklistQueries,
        private ChecklistCards $checklistCards,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @param int|null      $areaPosition     a round with areas shows the area at that position, the first one when it
     *                                        has none there or this is null
     * @param int|null      $screenshotNumber from 1, the screenshot a round without areas shows; the first one when this
     *                                        is null. A round with areas and a review round do not read it
     * @param bool          $isVerdictGranted whether VerdictPermission SET is granted to the viewer on the round
     * @param \DateTimeZone $viewerTimeZone   the header's day and time are in it
     *
     * @return RoundPage|null null when $screenshotNumber names no screenshot of a round without areas, which the
     *                        caller answers with 404
     */
    public function pageOf(VisibleTicket $visible, ReviewRoundView $round, ?int $areaPosition, ?int $screenshotNumber, bool $isVerdictGranted, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): ?RoundPage
    {
        $ticket = $visible->ticket;
        $title = \sprintf('Round %d', $round->number);
        $openRound = OpenSharedRound::ofReviewRound($round);
        $readyForId = null === $openRound ? null : $this->roundReadyFor->userIdOf($ticket, $openRound);
        $detail = $this->detailOf($visible, $round, $readyForId, $now, $viewerTimeZone);
        $verdictGiverName = $this->verdictGiverNameOf($visible, $readyForId);
        $selected = self::areaAt($round, $areaPosition ?? 0);
        if (null !== $selected) {
            $tabs = self::areaTabsOf($round, $selected);

            return new RoundPage(
                $ticket->key,
                $ticket->title,
                $title,
                $detail,
                self::AREAS_TABS_LABEL,
                $tabs,
                ScreenshotPager::over($tabs),
                $this->selectedAreaOf($ticket, $round, $selected, $now),
                self::panelOf($ticket, $round, $selected, $isVerdictGranted, $visible->actor->userId()),
                null,
                $verdictGiverName,
                self::NO_AREAS,
            );
        }
        if (self::REVIEW === $round->kind) {
            // ReviewRoundQueries reads a review round's pictures through its areas only.
            return new RoundPage($ticket->key, $ticket->title, $title, $detail, self::AREAS_TABS_LABEL, [], null, null, null, null, $verdictGiverName, self::NO_AREAS);
        }

        [$picturesRound, $screenshots] = $this->screenshotsOf($visible, $round) ?? [null, []];
        $shownIndex = null === $screenshotNumber ? 0 : $screenshotNumber - 1;
        if (null !== $screenshotNumber && !isset($screenshots[$shownIndex])) {
            return null;
        }
        if (null === $picturesRound || [] === $screenshots) {
            return new RoundPage($ticket->key, $ticket->title, $title, $detail, self::SCREENSHOTS_TABS_LABEL, [], null, null, null, null, $verdictGiverName, self::NO_SCREENSHOTS);
        }
        $tabs = self::screenshotTabsOf($screenshots, $shownIndex);
        $number = $shownIndex + 1;
        $screenshot = $screenshots[$shownIndex];
        [$beforeRound, $beforeScreenshot, $beforeNumber] = $this->pairedBeforeOf($visible, $picturesRound, $number, $this->previousHandOffRoundOf($visible, $round), $round->number) ?? [null, null, 0];
        $checkRows = $this->checkRowsOf($visible, $round, $beforeRound);

        return new RoundPage(
            $ticket->key,
            $ticket->title,
            $title,
            $detail,
            self::SCREENSHOTS_TABS_LABEL,
            $tabs,
            ScreenshotPager::over($tabs),
            $this->shownScreenshotOf($ticket, $picturesRound, $screenshot, $number, $beforeRound, $beforeScreenshot, $beforeNumber, $now),
            null,
            new ScreenshotPanel(
                null === $beforeRound ? null : \sprintf(self::PINS_FROM_ROUND, $beforeRound->number ?? 0),
                null === $beforeScreenshot ? [] : self::picturePinRowsOf($ticket, $beforeScreenshot, $beforeNumber - 1, $checkRows['before']),
                \sprintf(self::PINS_FROM_ROUND, $picturesRound->number ?? 0),
                self::picturePinRowsOf($ticket, $screenshot, $shownIndex, $checkRows['after']),
            ),
            $verdictGiverName,
            self::NO_SCREENSHOTS,
        );
    }

    /**
     * The check items on the pins of the pictures a round without areas shows, the After's and the Before's apart: the
     * checklist's on the report's pictures, a findings round's on its own; a self-check round's pins are none.
     * Reads ChecklistQueries once when the report's pictures are shown, to find the checklist round, and ChecklistCards'
     * queries when either side may have items. Runs inside the request transaction whose tenant is the viewer's
     * organization.
     *
     * @param RoundView|null $previousRound the round of the shown screenshot's Before, as pairedBeforeOf gives it
     *
     * @return array{after: array<string, ChecklistRow>, before: array<string, ChecklistRow>} each by checkKeyOf
     */
    public function checkRowsOf(VisibleTicket $visible, ReviewRoundView $round, ?RoundView $previousRound): array
    {
        $ticketId = $visible->ticket->id;
        $checklistRoundId = null;
        if (\in_array(self::REPORT, [$round->kind, $previousRound?->kind], true)) {
            $checklistRoundId = $this->checklistQueries->forTicket($ticketId)?->roundId;
        }
        $afterRoundId = match ($round->kind) {
            self::REPORT => $checklistRoundId,
            RoundView::DRAFT => $round->id,
            default => null,
        };
        $beforeRoundId = match ($previousRound?->kind) {
            self::REPORT => $checklistRoundId,
            RoundView::DRAFT => $previousRound->id,
            default => null,
        };
        $roundIds = array_values(array_unique(array_map(strtolower(...), array_filter([$afterRoundId, $beforeRoundId], static fn (?string $id): bool => null !== $id))));
        $cards = [] === $roundIds ? [] : $this->checklistCards->forRounds($visible->actor->organizationId(), $ticketId, $roundIds);

        return [
            'after' => self::checkRowsByPinOf($cards[strtolower($afterRoundId ?? '')] ?? null),
            'before' => self::checkRowsByPinOf($cards[strtolower($beforeRoundId ?? '')] ?? null),
        ];
    }

    /**
     * The key checkRowsOf gives a pin's check row: the pin's chain of tries and its number, as pin numbers start at 1 on
     * each picture.
     *
     * @param int $chainIndex the place of the picture's chain among its round's chains, from 0, as
     *                        RoundCards::shownChainsOf orders them; not the picture's stored position, which a retake
     *                        moves past every first try
     */
    public static function checkKeyOf(int $chainIndex, int $pinNumber): string
    {
        return $chainIndex.':'.$pinNumber;
    }

    /**
     * @return array<string, ChecklistRow> by checkKeyOf
     */
    private static function checkRowsByPinOf(?ChecklistCard $card): array
    {
        $rows = [];
        foreach ($card->rows ?? [] as $row) {
            $rows[self::checkKeyOf($row->picturePosition, $row->pinNumber)] = $row;
        }

        return $rows;
    }

    /**
     * The picture's pins as the side panel lists them, every pin but a dismissed AI suggestion, each with its check row.
     *
     * @param int                         $chainIndex as checkKeyOf takes it
     * @param array<string, ChecklistRow> $checkRows  by checkKeyOf
     *
     * @return list<PicturePinRow> by number
     */
    private static function picturePinRowsOf(TicketPageView $ticket, RoundScreenshotView $picture, int $chainIndex, array $checkRows): array
    {
        $rows = [];
        foreach ($picture->pins as $pin) {
            if (RoundCards::isShownPin($pin)) {
                $rows[] = new PicturePinRow($pin->number, $pin->note, self::authorLineOf($pin->authorId, $ticket), $checkRows[self::checkKeyOf($chainIndex, $pin->number)] ?? null);
            }
        }

        return $rows;
    }

    /**
     * Whoever else the open round is ready for, by first name, who gives its verdict; the round page names them where
     * the viewer would get the move-on buttons. Reads as RoundReadyFor::firstNameOf.
     *
     * @param UserId|null $readyForId as RoundReadyFor::userIdOf gives it
     */
    private function verdictGiverNameOf(VisibleTicket $visible, ?UserId $readyForId): ?string
    {
        if (null === $readyForId || $readyForId->equals($visible->actor->userId())) {
            return null;
        }
        $firstName = $this->roundReadyFor->firstNameOf($readyForId, $visible->ticket);

        return null === $firstName || '' === trim($firstName) ? null : $firstName;
    }

    /**
     * The screenshots a round without areas shows, other than a review round's: the latest try of each chain, in the
     * order the page numbers them from 1, and the round they belong to. One read of that round alone, through
     * RoundsVisibleTo. Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @return array{RoundView, list<RoundScreenshotView>}|null null when the round cannot be read again: it was
     *                                                          deleted meanwhile
     */
    public function screenshotsOf(VisibleTicket $visible, ReviewRoundView $round): ?array
    {
        $picturesRound = $this->picturesRoundOf($visible, $round);
        if (null === $picturesRound) {
            return null;
        }

        return [
            $picturesRound,
            array_map(static fn (array $tries): RoundScreenshotView => $tries[\count($tries) - 1], RoundCards::shownChainsOf($picturesRound)),
        ];
    }

    /**
     * The hand-off round shared just before a shared draft, or the report when there is none, whose screenshots are the
     * Befores of the draft's; one read through RoundsVisibleTo. Null for any other kind of round, which has no Before
     * by screenshot.
     * Runs inside the request transaction whose tenant is the viewer's organization.
     */
    public function previousHandOffRoundOf(VisibleTicket $visible, ReviewRoundView $round): ?RoundView
    {
        if (RoundView::DRAFT !== $round->kind) {
            return null;
        }

        return $this->roundsVisibleTo->previousHandOffRoundOf($visible->ticket->id, $visible->visibility, $round->number);
    }

    /**
     * The Before of a draft's "Screenshot N", its round and its number from 1 there. A copy made by "Pin this in my
     * draft" keeps its source's picture, so its Before is the source: found in the previous hand-off round, else in the
     * round copySourceOf names, and none when no shared round below holds it any more. Any other chain's Before is the
     * screenshot with its number in the previous hand-off round (beforeScreenshotOf). Reads copySourceOf, and then
     * sharedRoundOf for a copy, only for a chain the previous hand-off round does not hold. Runs inside the request
     * transaction whose tenant is the viewer's organization.
     *
     * @param RoundView      $draft         a shared or unshared draft, with its tries
     * @param int            $number        from 1, the chain's place in the strip
     * @param RoundView|null $previousRound as previousHandOffRoundOf gives it; null means no Before at all
     * @param int            $belowNumber   the draft's number, or RoundsVisibleTo::UNSHARED_DRAFT_NUMBER for an
     *                                      unshared draft, as copySourceOf takes it
     *
     * @return array{RoundView, RoundScreenshotView, int}|null
     */
    public function pairedBeforeOf(VisibleTicket $visible, RoundView $draft, int $number, ?RoundView $previousRound, int $belowNumber): ?array
    {
        $firstTry = RoundCards::shownChainsOf($draft)[$number - 1][0] ?? null;
        if (null === $previousRound || null === $firstTry) {
            return null;
        }
        $heldByPreviousRound = self::screenshotWithMediaOf($previousRound, $firstTry->mediaId);
        if (null !== $heldByPreviousRound) {
            return [$previousRound, ...$heldByPreviousRound];
        }
        $copySource = $this->roundsVisibleTo->copySourceOf($visible->ticket->id, $visible->visibility, $draft->id, $firstTry->mediaId, $belowNumber);
        if (null === $copySource) {
            $beforeScreenshot = self::beforeScreenshotOf($previousRound, $number);

            return null === $beforeScreenshot ? null : [$previousRound, $beforeScreenshot, $number];
        }
        $sourceRound = null === $copySource->roundNumber ? null : $this->roundsVisibleTo->sharedRoundOf($visible->ticket->id, $visible->visibility, $copySource->roundNumber);
        $heldBySourceRound = null === $sourceRound ? null : self::screenshotWithMediaOf($sourceRound, $copySource->mediaId);

        return null === $sourceRound || null === $heldBySourceRound ? null : [$sourceRound, ...$heldBySourceRound];
    }

    /**
     * No data ties a chain to one of an earlier round (a retake stays in its own draft, ADR-0048), so the Before of
     * "Screenshot N" is the latest try of "Screenshot N" of the previous hand-off round, the way the review seed pairs
     * areas by position; a chain numbered past that round's last one is new and has none.
     *
     * @param RoundView|null $previousRound as previousHandOffRoundOf gives it
     * @param int            $number        from 1, the screenshot's place in the strip
     */
    public static function beforeScreenshotOf(?RoundView $previousRound, int $number): ?RoundScreenshotView
    {
        if (null === $previousRound) {
            return null;
        }
        $tries = RoundCards::shownChainsOf($previousRound)[$number - 1] ?? [];

        return [] === $tries ? null : $tries[\count($tries) - 1];
    }

    /**
     * The latest try that shows that picture and its number from 1, as the round page numbers it; a copy made by "Pin
     * this in my draft" reuses its source's picture, so this finds the source in the round it came from.
     *
     * @return array{RoundScreenshotView, int}|null null when no latest try of the round shows it
     */
    public static function screenshotWithMediaOf(RoundView $round, string $mediaId): ?array
    {
        foreach (RoundCards::shownChainsOf($round) as $index => $tries) {
            $latestTry = $tries[\count($tries) - 1];
            if ($latestTry->mediaId === $mediaId) {
                return [$latestTry, $index + 1];
            }
        }

        return null;
    }

    /**
     * The round with its screenshots, read alone, so a shared draft older than the rounds the ticket page lists is found
     * too.
     */
    private function picturesRoundOf(VisibleTicket $visible, ReviewRoundView $round): ?RoundView
    {
        return $this->roundsVisibleTo->sharedRoundOf($visible->ticket->id, $visible->visibility, $round->number);
    }

    /**
     * @param AreaView $selected one of the round's areas
     *
     * @return non-empty-list<ScreenshotTab> by position, each with its area's state as tabsOf gives it
     */
    private static function areaTabsOf(ReviewRoundView $round, AreaView $selected): array
    {
        $tabs = [];
        foreach (self::tabsOf($round, $selected) as $index => $tab) {
            $tabs[] = new ScreenshotTab(
                $index + 1,
                $tab->name,
                [RoundController::AREA_PARAMETER => $tab->position],
                $tab->stateLabel,
                $tab->stateClass,
                self::AREA_STATE_ID_PREFIX.$tab->position,
                $tab->isSelected,
            );
        }
        if ([] === $tabs) {
            throw new \LogicException('A round with a selected area has areas.');
        }

        return $tabs;
    }

    /**
     * @param non-empty-list<RoundScreenshotView> $screenshots the latest try of each chain, in order
     *
     * @return non-empty-list<ScreenshotTab>
     */
    private static function screenshotTabsOf(array $screenshots, int $shownIndex): array
    {
        $tabs = [];
        foreach ($screenshots as $index => $screenshot) {
            $pinCount = \count(RoundCards::shownPinsOf($screenshot));
            $tabs[] = new ScreenshotTab(
                $index + 1,
                $screenshot->title ?? \sprintf('Screenshot %d', $index + 1),
                [RoundController::SCREENSHOT_PARAMETER => $index + 1],
                1 === $pinCount ? '1 pin' : $pinCount.' pins',
                0 === $pinCount ? 'screenshot-tab__pins--none' : 'screenshot-tab__pins',
                null,
                $index === $shownIndex,
            );
        }

        return $tabs;
    }

    /**
     * The screenshot with its Before, as pairedBeforeOf pairs them; one without a Before stands alone.
     *
     * @param int                      $number           from 1, its place in the strip
     * @param RoundView|null           $beforeRound      the round of the Before, with its latest tries
     * @param RoundScreenshotView|null $beforeScreenshot the Before; null when the screenshot has none
     * @param int                      $beforeNumber     from 1, the Before's place in its own round
     */
    private function shownScreenshotOf(TicketPageView $ticket, RoundView $picturesRound, RoundScreenshotView $screenshot, int $number, ?RoundView $beforeRound, ?RoundScreenshotView $beforeScreenshot, int $beforeNumber, \DateTimeImmutable $now): ShownScreenshot
    {
        $mediaIds = null === $beforeScreenshot ? [$screenshot->mediaId] : [$screenshot->mediaId, $beforeScreenshot->mediaId];
        $images = $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, array_values(array_unique($mediaIds)), RoundCards::VARIANT, $now);
        $after = RoundCards::screenshotViewOf($picturesRound, $screenshot, $number, $images);
        $afterSource = new ScreenshotSource($picturesRound->id, $screenshot->id, $picturesRound->ownerId);
        if (null === $beforeRound || null === $beforeScreenshot) {
            return new ShownScreenshot(\sprintf('Screenshot %d', $number), null, null, $after, null, $afterSource);
        }

        return new ShownScreenshot(
            \sprintf('Screenshot %d', $number),
            RoundCards::screenshotViewOf($beforeRound, $beforeScreenshot, $beforeNumber, $images),
            \sprintf('Before · round %d', $beforeRound->number ?? 0),
            $after,
            \sprintf('After · round %d', $picturesRound->number ?? 0),
            $afterSource,
        );
    }

    /**
     * The area at that position, the first one when the round has none there, and null for a round with no areas.
     */
    public static function areaAt(ReviewRoundView $round, int $position): ?AreaView
    {
        foreach ($round->areas as $area) {
            if ($area->position === $position) {
                return $area;
            }
        }

        return $round->areas[0] ?? null;
    }

    /**
     * Whether the verdict forms show: VerdictPermission SET is granted on an open round of a ticket with a team lead,
     * who sees the verdicts next.
     */
    public static function mayGiveVerdictsOn(TicketPageView $ticket, ReviewRoundView $round, bool $isVerdictGranted): bool
    {
        return $isVerdictGranted && self::OPEN_ROUND === $round->state && null !== $ticket->turnSnapshot->leadDisplayName;
    }

    /**
     * The tag of a pin's verdict: "Fixed", "Still wrong" or "Not checked yet", and its modifier class.
     *
     * @return array{string, string}
     */
    private static function pinVerdictTagOf(?string $verdict): array
    {
        return match ($verdict) {
            VerdictCommands::PIN_FIXED => ['Fixed', 'verdict-tag--looks-right'],
            VerdictCommands::PIN_STILL_WRONG => ['Still wrong', 'verdict-tag--not-right'],
            default => ['Not checked yet', 'verdict-tag--none'],
        };
    }

    /**
     * @param AreaView $selected one of the round's areas
     *
     * @return list<AreaTab>
     */
    public static function tabsOf(ReviewRoundView $round, AreaView $selected): array
    {
        return array_map(
            static function (AreaView $area) use ($selected): AreaTab {
                $chip = self::chipOf($area->state(), $area->pinCount());

                return new AreaTab($area->position, $area->name, $chip['stateLabel'], $chip['stateClass'], $area->id === $selected->id);
            },
            $round->areas,
        );
    }

    /**
     * Reads nothing: the names come with the ticket.
     *
     * @param AreaView $area             one of the round's areas
     * @param bool     $isVerdictGranted whether VerdictPermission SET is granted to the viewer on the round
     */
    public static function panelOf(TicketPageView $ticket, ReviewRoundView $round, AreaView $area, bool $isVerdictGranted, UserId $viewerId): RoundPanel
    {
        $snapshot = $ticket->turnSnapshot;
        $leadName = $snapshot->leadDisplayName;
        $mayGiveVerdicts = self::mayGiveVerdictsOn($ticket, $round, $isVerdictGranted);
        // The verbs are the tester's to make; the lead would be offered a question to themself.
        $isOfferedTheNextVerb = $mayGiveVerdicts && TicketTesterRule::isTesterOf($snapshot, $viewerId);
        $earlierPins = null === $area->before ? [] : array_map(
            static fn (RoundPinView $pin): EarlierPinRow => self::earlierPinRowOf($pin, $ticket),
            $area->before->pins,
        );

        return new RoundPanel(
            $area->id,
            $area->position,
            $area->name,
            null === $area->beforeRoundNumber ? null : \sprintf(self::PINS_FROM_ROUND, $area->beforeRoundNumber),
            $earlierPins,
            \sprintf(self::PINS_FROM_ROUND, $round->number),
            self::picturePinRowsOf($ticket, $area->after, $area->position, []),
            $area->verdict,
            null === $area->verdict ? null : (self::AREA_VERDICT_LABELS[$area->verdict] ?? null),
            $area->note,
            null === $area->decidedBy ? null : ($ticket->displayNames[$area->decidedBy->toString()] ?? null),
            $mayGiveVerdicts,
            $leadName,
            $isOfferedTheNextVerb ? self::nextVerbOf($ticket, $round) : null,
        );
    }

    /**
     * The verb that matches the round's verdicts once every area has one (phase 5 design notes): a question to the
     * lead first, as its answer may change the others; else Needs changes for any Needs fix; else Pass it.
     */
    private static function nextVerbOf(TicketPageView $ticket, ReviewRoundView $round): ?NextVerbOffer
    {
        $verdicts = array_map(static fn (AreaView $area): ?string => $area->verdict, $round->areas);
        if (\in_array(null, $verdicts, true)) {
            return null;
        }
        if (\in_array(VerdictCommands::AREA_QUESTION, $verdicts, true)) {
            $snapshot = $ticket->turnSnapshot;

            return new NextVerbOffer(
                \sprintf('An area has a question for %s.', $snapshot->leadDisplayName),
                'Ask '.$snapshot->leadDisplayName,
                $snapshot->leadId?->toString(),
            );
        }
        if (\in_array(VerdictCommands::AREA_NEEDS_FIX, $verdicts, true)) {
            return new NextVerbOffer('At least one area needs a fix.', 'Needs changes on '.$ticket->key, null);
        }

        return new NextVerbOffer('Every area passed.', 'Pass it on '.$ticket->key, null);
    }

    private function selectedAreaOf(TicketPageView $ticket, ReviewRoundView $round, AreaView $area, \DateTimeImmutable $now): ShownScreenshot
    {
        $mediaIds = [$area->after->mediaId];
        if (null !== $area->before) {
            $mediaIds[] = $area->before->mediaId;
        }
        $images = $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, $mediaIds, RoundCards::VARIANT, $now);

        $before = null;
        $beforeCaption = null;
        if (null !== $area->before) {
            $before = self::viewOf($area, $area->before, 'before', \sprintf('Before, round %d: %s', $area->beforeRoundNumber, $area->name), $images);
            $beforeCaption = \sprintf('Before · round %d', $area->beforeRoundNumber);
        }

        return new ShownScreenshot(
            $area->name,
            $before,
            $beforeCaption,
            self::viewOf($area, $area->after, 'after', \sprintf('After, round %d: %s', $round->number, $area->name), $images),
            \sprintf('After · round %d', $round->number),
            new ScreenshotSource($round->id, $area->after->id, $round->ownerId),
        );
    }

    /**
     * @param array<string, MediaImage> $images by media id
     */
    private static function viewOf(AreaView $area, RoundScreenshotView $screenshot, string $side, string $altText, array $images): ScreenshotView
    {
        $pins = array_map(
            static fn (RoundPinView $pin): PinView => new PinView($pin->number, $pin->xPercent, $pin->yPercent, $pin->note, false),
            $screenshot->pins,
        );

        return RoundCards::viewOfPicture('area-'.$area->id.'-'.$side, $altText, $screenshot->title, $screenshot->summary, $images[$screenshot->mediaId] ?? null, $pins);
    }

    private static function earlierPinRowOf(RoundPinView $pin, TicketPageView $ticket): EarlierPinRow
    {
        [$label, $class] = self::pinVerdictTagOf($pin->verdict);

        return new EarlierPinRow($pin->id, $pin->number, $pin->note, self::authorLineOf($pin->authorId, $ticket), $label, $class, null !== $pin->verdict, VerdictCommands::PIN_FIXED === $pin->verdict);
    }

    /**
     * "Mia · tester", "Lena · team lead", or the name alone for anyone else on the ticket; null when Identity does not
     * know the name.
     */
    private static function authorLineOf(UserId $authorId, TicketPageView $ticket): ?string
    {
        $name = $ticket->displayNames[$authorId->toString()] ?? null;
        if (null === $name) {
            return null;
        }
        $snapshot = $ticket->turnSnapshot;
        if (self::isTesterOf($snapshot, $authorId)) {
            return $name.' · tester';
        }
        if (null !== $snapshot->leadId && $snapshot->leadId->equals($authorId)) {
            return $name.' · team lead';
        }

        return $name;
    }

    private static function isTesterOf(TicketTurnSnapshot $snapshot, UserId $userId): bool
    {
        if (null !== $snapshot->testerId && $snapshot->testerId->equals($userId)) {
            return true;
        }

        return array_any($snapshot->testerIds, static fn ($testerId) => $testerId->equals($userId));
    }

    /**
     * @return array{stateLabel: string, stateClass: string}
     */
    private static function chipOf(AreaState $state, int $pinCount): array
    {
        return match ($state) {
            AreaState::Passed => ['stateLabel' => 'Passed', 'stateClass' => 'area-chip--passed'],
            AreaState::Pins => ['stateLabel' => 1 === $pinCount ? '1 pin' : $pinCount.' pins', 'stateClass' => 'area-chip--pins'],
            AreaState::NeedsFix => ['stateLabel' => 'Needs fix', 'stateClass' => 'area-chip--needs-fix'],
            AreaState::Question => ['stateLabel' => 'Question', 'stateClass' => 'area-chip--question'],
            AreaState::NotReviewed => ['stateLabel' => 'Not reviewed', 'stateClass' => 'area-chip--none'],
        };
    }

    /**
     * "shot <when>", then, on an open hand-off round, the round bar's "Ready for" line, which names nobody on a closed
     * round or the report.
     *
     * @param UserId|null $readyForId as RoundReadyFor::userIdOf gives it on an open hand-off round; null on any other
     */
    private function detailOf(VisibleTicket $visible, ReviewRoundView $round, ?UserId $readyForId, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $parts = ['shot '.self::whenOf($round->sharedAt, $now, $viewerTimeZone)];
        $readyForLine = $this->roundReadyFor->readyForLineOfUser($visible, $readyForId);
        if (null !== $readyForLine) {
            $parts[] = $readyForLine;
        }

        return implode(' · ', $parts);
    }

    /** "today, 09:12", "yesterday, 09:12", "5 Nov, 09:12" or, in another year, "5 Nov 2025, 09:12", in the viewer's zone. */
    private static function whenOf(\DateTimeImmutable $sharedAt, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $shared = $sharedAt->setTimezone($viewerTimeZone);
        $nowInZone = $now->setTimezone($viewerTimeZone);
        $time = $shared->format('H:i');
        $day = $shared->format('Y-m-d');
        if ($day === $nowInZone->format('Y-m-d')) {
            return 'today, '.$time;
        }
        if ($day === $nowInZone->modify('-1 day')->format('Y-m-d')) {
            return 'yesterday, '.$time;
        }

        return RelativeTime::dateOf($sharedAt, $now, $viewerTimeZone).', '.$time;
    }
}
