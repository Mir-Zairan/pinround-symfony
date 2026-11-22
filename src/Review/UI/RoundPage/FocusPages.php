<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Media\Api\SignedUrls;
use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\UI\Checklist\ChecklistRow;
use App\Review\UI\Controller\RoundController;
use App\Review\UI\PinView;
use App\Review\UI\RoundCards;
use App\Review\UI\VisibleTicket;
use App\Ticketing\Api\TicketPageView;

/**
 * Builds the full-screen review of one round for one viewer: signs the one picture shown and walks its pins. On a screen
 * area those are the earlier round's pins, the same the round page's side panel lists, in the same order; on a
 * screenshot of any other round, the pins that the round page draws on the picture shown, numbered as the round page
 * numbers its screenshots (RoundPages::screenshotsOf). A shared draft's screenshot has the same Before as on the round
 * page (RoundPages::pairedBeforeOf): a copy's source, else the same-numbered screenshot of the hand-off round before
 * it, or of the report when there is none.
 *
 * Its reads do not grow with the screenshots or pins: one signing call, and, for a round without areas other than a
 * review round, RoundPages' read of the one round whose screenshots it shows, for a shared draft its read of the
 * hand-off round before it or the report (and of a copy's source), and RoundPages::checkRowsOf's reads of the check items, whose buttons the
 * walked pins that are check items show.
 */
final readonly class FocusPages
{
    private const string REVIEW = 'review';

    public function __construct(
        private SignedUrls $signedUrls,
        private RoundPages $roundPages,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @param int      $selectedPosition the area to show; the first one when the round has no area at that position
     * @param int|null $screenshotNumber from 1, the screenshot a round without areas shows; the first one when this is
     *                                   null. A round with areas and a review round do not read it
     * @param string   $side             FocusPage::SIDE_BEFORE shows the earlier picture when the area or the shared
     *                                   draft's screenshot has one; anything else the round's own
     * @param int      $pinPosition      from 1, the stop to show first; the first one when the walk has none there
     * @param bool     $isVerdictGranted whether VerdictPermission SET is granted to the viewer on the round
     *
     * @return FocusPage|null null when $screenshotNumber names no screenshot of a round without areas, which the caller
     *                        answers with 404, as the round page does
     */
    public function pageOf(VisibleTicket $visible, ReviewRoundView $round, int $selectedPosition, ?int $screenshotNumber, string $side, int $pinPosition, bool $isVerdictGranted, \DateTimeImmutable $now): ?FocusPage
    {
        $ticket = $visible->ticket;
        $roundLine = \sprintf('%s · Round %d', $ticket->key, $round->number);
        $area = RoundPages::areaAt($round, $selectedPosition);
        if (null !== $area) {
            return $this->areaPageOf($ticket, $round, $area, $roundLine, $side, $pinPosition, $isVerdictGranted, $now);
        }
        if (self::REVIEW === $round->kind) {
            // ReviewRoundQueries reads a review round's pictures through its areas only.
            return new FocusPage($ticket->key, $roundLine, null, FocusPage::SIDE_AFTER, false, null, [], null, false, [], null, RoundPages::NO_AREAS, null);
        }

        [$picturesRound, $screenshots] = $this->roundPages->screenshotsOf($visible, $round) ?? [null, []];
        $shownIndex = null === $screenshotNumber ? 0 : $screenshotNumber - 1;
        if (null !== $screenshotNumber && !isset($screenshots[$shownIndex])) {
            return null;
        }
        $screenshot = $screenshots[$shownIndex] ?? null;
        if (null === $picturesRound || null === $screenshot) {
            return new FocusPage($ticket->key, $roundLine, null, FocusPage::SIDE_AFTER, false, null, [], null, false, [], null, RoundPages::NO_SCREENSHOTS, null);
        }

        $number = $shownIndex + 1;
        $count = \count($screenshots);
        [$beforeRound, $before, $beforeNumber] = $this->roundPages->pairedBeforeOf($visible, $picturesRound, $number, $this->roundPages->previousHandOffRoundOf($visible, $round), $round->number) ?? [null, null, 0];
        $isBefore = null !== $before && FocusPage::SIDE_BEFORE === $side;
        $shown = $isBefore ? $before : $screenshot;
        // Each picture walks its own pins, as each side of the round page's Before / After draws its own.
        $pins = array_values(array_filter($shown->pins, RoundCards::isShownPin(...)));
        $checkRows = $this->roundPages->checkRowsOf($visible, $round, $beforeRound)[$isBefore ? 'before' : 'after'];
        // Each side keys its check items on its own chain: a copied screenshot's Before has its number in its source round.
        $stops = self::stopsOf($ticket, $pins, $isBefore ? $beforeNumber - 1 : $shownIndex, $checkRows);
        $image = $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, [$shown->mediaId], RoundCards::VARIANT, $now)[$shown->mediaId] ?? null;
        $picture = RoundCards::viewOfPicture(
            'focus-picture',
            match (true) {
                null === $before => self::altTextOf($round, $screenshot, $number),
                $isBefore => \sprintf('Before, round %d: %s', $beforeRound->number ?? 0, $before->title ?? \sprintf('Screenshot %d', $beforeNumber)),
                default => \sprintf('After, round %d: %s', $round->number, $screenshot->title ?? \sprintf('Screenshot %d', $number)),
            },
            null,
            null,
            $image,
            array_map(RoundCards::pinViewOf(...), $pins),
        );

        return new FocusPage(
            $ticket->key,
            null === $screenshot->title
                ? \sprintf('%s · Screenshot %d of %d', $roundLine, $number, $count)
                : \sprintf('%s · %s · screenshot %d of %d', $roundLine, $screenshot->title, $number, $count),
            null,
            $isBefore ? FocusPage::SIDE_BEFORE : FocusPage::SIDE_AFTER,
            null !== $before,
            $picture,
            $stops,
            $stops[$pinPosition - 1] ?? $stops[0] ?? null,
            false,
            [RoundController::SCREENSHOT_PARAMETER => $number],
            ScreenshotPager::overScreenshots($number, $count),
            RoundPages::NO_SCREENSHOTS,
            $isBefore ? null : new ScreenshotSource($picturesRound->id, $screenshot->id, $picturesRound->ownerId),
        );
    }

    private function areaPageOf(TicketPageView $ticket, ReviewRoundView $round, AreaView $area, string $roundLine, string $side, int $pinPosition, bool $isVerdictGranted, \DateTimeImmutable $now): FocusPage
    {
        $shown = FocusPage::SIDE_BEFORE === $side ? ($area->before ?? $area->after) : $area->after;
        $isBefore = $shown !== $area->after;
        $pins = $area->before->pins ?? [];
        // An earlier round's pins take pin verdicts here, not checklist changes.
        $stops = self::stopsOf($ticket, $pins, 0, []);
        $image = $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, [$shown->mediaId], RoundCards::VARIANT, $now)[$shown->mediaId] ?? null;
        $picture = RoundCards::viewOfPicture(
            'focus-picture',
            $isBefore ? \sprintf('Before, round %d: %s', $area->beforeRoundNumber, $area->name) : \sprintf('After, round %d: %s', $round->number, $area->name),
            // No title or summary above the picture, as in full-screen-review.md: the screen is the picture's and the bar's.
            null,
            null,
            $image,
            // The walked pins, on either picture: the round's own pins would repeat their numbers.
            array_map(static fn (RoundPinView $pin): PinView => new PinView($pin->number, $pin->xPercent, $pin->yPercent, $pin->note), $pins),
        );

        return new FocusPage(
            $ticket->key,
            \sprintf('%s · %s · section %d of %d', $roundLine, $area->name, self::indexOf($round, $area) + 1, \count($round->areas)),
            $area->position,
            $isBefore ? FocusPage::SIDE_BEFORE : FocusPage::SIDE_AFTER,
            null !== $area->before,
            $picture,
            $stops,
            $stops[$pinPosition - 1] ?? $stops[0] ?? null,
            RoundPages::mayGiveVerdictsOn($ticket, $round, $isVerdictGranted),
            [RoundController::AREA_PARAMETER => $area->position],
            null,
            RoundPages::NO_AREAS,
            $isBefore ? null : new ScreenshotSource($round->id, $area->after->id, $round->ownerId),
        );
    }

    /**
     * The screenshot's title, or "Screenshot 2 of round 1", as the round page's picture is named.
     *
     * @param int $number from 1, its place among the round's screenshots
     */
    private static function altTextOf(ReviewRoundView $round, RoundScreenshotView $screenshot, int $number): string
    {
        return $screenshot->title ?? \sprintf('Screenshot %d of round %d', $number, $round->number);
    }

    /**
     * @param list<RoundPinView>          $pins       on one picture
     * @param int                         $chainIndex that picture's chain, as RoundPages::checkKeyOf takes it
     * @param array<string, ChecklistRow> $checkRows  by RoundPages::checkKeyOf
     *
     * @return list<FocusPinStop>
     */
    private static function stopsOf(TicketPageView $ticket, array $pins, int $chainIndex, array $checkRows): array
    {
        $count = \count($pins);
        $stops = [];
        foreach ($pins as $index => $pin) {
            $position = $index + 1;
            $name = $ticket->displayNames[$pin->authorId->toString()] ?? null;
            $label = \sprintf('Pin %d of %d', $position, $count).(null === $name ? '' : ' · '.$name);
            $stops[] = new FocusPinStop(
                $pin->id,
                $position,
                $pin->number,
                $pin->note,
                $label,
                null !== $pin->verdict,
                FocusPage::previousPositionBefore($position, $count),
                FocusPage::nextPositionAfter($position, $count),
                $checkRows[RoundPages::checkKeyOf($chainIndex, $pin->number)] ?? null,
            );
        }

        return $stops;
    }

    private static function indexOf(ReviewRoundView $round, AreaView $area): int
    {
        $index = array_search($area, $round->areas, true);

        return false === $index ? 0 : $index;
    }
}
