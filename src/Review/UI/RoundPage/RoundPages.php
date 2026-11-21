<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Media\Api\MediaImage;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\Review\Api\AreaState;
use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\VerdictCommands;
use App\Review\Application\RoundsVisibleTo;
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
 * Builds the round page of any round kind for one viewer (FR-RND-05): the header, a strip of every screenshot as a
 * small picture, and one screenshot shown large with its pins. A review round's screenshots are its screen areas, each
 * with the earlier round's picture of the place and a side panel; the report's and a shared draft's are the latest try
 * of each chain, numbered as RoundCards numbers them; a checklist round shows the report's, whose pins it checks.
 *
 * Its reads do not grow with the screenshots or pins: two signing calls (the shown pictures, then every small picture,
 * one more per SignedUrls::MAX_MEDIA_IDS of them), for a round without areas other than a review round, RoundsVisibleTo's
 * read of the one round whose screenshots it shows, and, on an open hand-off round, RoundReadyFor's reads of who it is
 * ready for.
 */
final readonly class RoundPages
{
    /** The area verdicts in the order of their chips, by the value the form posts. */
    public const array AREA_VERDICT_LABELS = [
        VerdictCommands::AREA_PASS => 'Pass',
        VerdictCommands::AREA_NEEDS_FIX => 'Needs fix',
        VerdictCommands::AREA_QUESTION => 'Question',
    ];

    /** With the area's position: the id of its thumb's state tag, which a verdict's stream replaces. */
    public const string AREA_STATE_ID_PREFIX = 'screenshot-thumb-state-';

    public const string AREAS_STRIP_LABEL = 'Screen areas';
    public const string SCREENSHOTS_STRIP_LABEL = 'Screenshots';
    public const string NO_AREAS = 'This round has no screen areas.';
    public const string NO_SCREENSHOTS = 'This round has no screenshots.';

    /** ReviewRoundView's state of a round that still takes verdicts. */
    private const string OPEN_ROUND = 'open';

    /** ReviewRoundView's and RoundView's kinds. */
    private const string REPORT = 'report';
    private const string CHECKLIST = 'checklist';
    private const string REVIEW = 'review';

    /** The report is always Round 0. */
    private const int REPORT_NUMBER = 0;

    public function __construct(
        private SignedUrls $signedUrls,
        private RoundsVisibleTo $roundsVisibleTo,
        private RoundReadyFor $roundReadyFor,
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
        $detail = $this->detailOf($visible, $round, $now, $viewerTimeZone);
        $selected = self::areaAt($round, $areaPosition ?? 0);
        if (null !== $selected) {
            $thumbs = $this->areaThumbsOf($ticket, $round, $selected, $now);

            return new RoundPage(
                $ticket->key,
                $ticket->title,
                $title,
                $detail,
                self::AREAS_STRIP_LABEL,
                $thumbs,
                ScreenshotPager::over($thumbs),
                $this->selectedAreaOf($ticket, $round, $selected, $now),
                self::panelOf($ticket, $round, $selected, $isVerdictGranted, $visible->actor->userId()),
                self::NO_AREAS,
            );
        }
        if (self::REVIEW === $round->kind) {
            // ReviewRoundQueries reads a review round's pictures through its areas only.
            return new RoundPage($ticket->key, $ticket->title, $title, $detail, self::AREAS_STRIP_LABEL, [], null, null, null, self::NO_AREAS);
        }

        [$picturesRound, $screenshots] = $this->screenshotsOf($visible, $round) ?? [null, []];
        $shownIndex = null === $screenshotNumber ? 0 : $screenshotNumber - 1;
        if (null !== $screenshotNumber && !isset($screenshots[$shownIndex])) {
            return null;
        }
        if (null === $picturesRound || [] === $screenshots) {
            return new RoundPage($ticket->key, $ticket->title, $title, $detail, self::SCREENSHOTS_STRIP_LABEL, [], null, null, null, self::NO_SCREENSHOTS);
        }
        $thumbs = $this->screenshotThumbsOf($ticket, $screenshots, $shownIndex, $now);

        return new RoundPage(
            $ticket->key,
            $ticket->title,
            $title,
            $detail,
            self::SCREENSHOTS_STRIP_LABEL,
            $thumbs,
            ScreenshotPager::over($thumbs),
            $this->shownScreenshotOf($ticket, $picturesRound, $screenshots[$shownIndex], $shownIndex + 1, $now),
            null,
            self::NO_SCREENSHOTS,
        );
    }

    /**
     * The screenshots a round without areas shows, other than a review round's: the latest try of each chain, in the
     * order the page numbers them from 1, and the round they belong to. One read of that round alone, through
     * RoundsVisibleTo. Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @return array{RoundView, list<RoundScreenshotView>}|null null when there is no round to show the pictures of: a
     *                                                          checklist round of a ticket without a report
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
     * The round whose screenshots the page shows: the report (Round 0) for a checklist round, the round itself
     * otherwise, read alone, so a shared draft older than the rounds the ticket page lists is found too. Null when the
     * ticket has no report.
     */
    private function picturesRoundOf(VisibleTicket $visible, ReviewRoundView $round): ?RoundView
    {
        if (self::CHECKLIST !== $round->kind) {
            return $this->roundsVisibleTo->sharedRoundOf($visible->ticket->id, $visible->visibility, $round->number);
        }
        $report = $this->roundsVisibleTo->sharedRoundOf($visible->ticket->id, $visible->visibility, self::REPORT_NUMBER);

        return self::REPORT === $report?->kind ? $report : null;
    }

    /**
     * @param AreaView $selected one of the round's areas
     *
     * @return non-empty-list<ScreenshotThumb> by position, each with its area's state as tabsOf gives it
     */
    private function areaThumbsOf(TicketPageView $ticket, ReviewRoundView $round, AreaView $selected, \DateTimeImmutable $now): array
    {
        $images = $this->thumbImagesOf($ticket, array_map(static fn (AreaView $area): string => $area->after->mediaId, $round->areas), $now);
        $thumbs = [];
        foreach (self::tabsOf($round, $selected) as $index => $tab) {
            $thumbs[] = new ScreenshotThumb(
                $index + 1,
                $tab->name,
                [RoundController::AREA_PARAMETER => $tab->position],
                self::thumbUrlOf($images[$round->areas[$index]->after->mediaId] ?? null),
                $tab->stateLabel,
                $tab->stateClass,
                self::AREA_STATE_ID_PREFIX.$tab->position,
                $tab->isSelected,
            );
        }
        if ([] === $thumbs) {
            throw new \LogicException('A round with a selected area has areas.');
        }

        return $thumbs;
    }

    /**
     * @param non-empty-list<RoundScreenshotView> $screenshots the latest try of each chain, in order
     *
     * @return non-empty-list<ScreenshotThumb>
     */
    private function screenshotThumbsOf(TicketPageView $ticket, array $screenshots, int $shownIndex, \DateTimeImmutable $now): array
    {
        $images = $this->thumbImagesOf($ticket, array_map(static fn (RoundScreenshotView $screenshot): string => $screenshot->mediaId, $screenshots), $now);
        $thumbs = [];
        foreach ($screenshots as $index => $screenshot) {
            $pinCount = \count(RoundCards::shownPinsOf($screenshot));
            $thumbs[] = new ScreenshotThumb(
                $index + 1,
                \sprintf('Screenshot %d', $index + 1),
                [RoundController::SCREENSHOT_PARAMETER => $index + 1],
                self::thumbUrlOf($images[$screenshot->mediaId] ?? null),
                1 === $pinCount ? '1 pin' : $pinCount.' pins',
                0 === $pinCount ? 'screenshot-thumb__pins--none' : 'screenshot-thumb__pins',
                null,
                $index === $shownIndex,
            );
        }

        return $thumbs;
    }

    /**
     * @param int $number from 1, its place in the strip
     */
    private function shownScreenshotOf(TicketPageView $ticket, RoundView $picturesRound, RoundScreenshotView $screenshot, int $number, \DateTimeImmutable $now): ShownScreenshot
    {
        $images = $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, [$screenshot->mediaId], RoundCards::VARIANT, $now);

        return new ShownScreenshot(
            \sprintf('Screenshot %d', $number),
            null,
            null,
            RoundCards::screenshotViewOf($picturesRound, $screenshot, $number, $images),
            null,
        );
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, MediaImage> by media id
     */
    private function thumbImagesOf(TicketPageView $ticket, array $mediaIds, \DateTimeImmutable $now): array
    {
        $images = [];
        foreach (array_chunk(array_values(array_unique($mediaIds)), SignedUrls::MAX_MEDIA_IDS) as $mediaIdsOfOneCall) {
            $images += $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, $mediaIdsOfOneCall, RoundCards::THUMB_VARIANT, $now);
        }

        return $images;
    }

    private static function thumbUrlOf(?MediaImage $image): ?string
    {
        return null !== $image && MediaImageStatus::Ready === $image->status ? $image->url : null;
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
            null === $area->beforeRoundNumber ? null : \sprintf('Pins from round %d', $area->beforeRoundNumber),
            $earlierPins,
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
     * round, the report or a checklist round.
     */
    private function detailOf(VisibleTicket $visible, ReviewRoundView $round, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $parts = ['shot '.self::whenOf($round->sharedAt, $now, $viewerTimeZone)];
        $readyForLine = RoundController::isOpenHandOffRound($round) ? $this->roundReadyFor->readyForLineOf($visible, $round->kind, $round->ownerId) : null;
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
