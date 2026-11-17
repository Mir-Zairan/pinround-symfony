<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Media\Api\SignedUrls;
use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\UI\PinView;
use App\Review\UI\RoundCards;
use App\Ticketing\Api\TicketPageView;

/**
 * Builds the full-screen review of one screen area for one viewer: signs the one picture shown and walks the pins of
 * the earlier round, the same pins the round page's side panel lists, in the same order.
 */
final readonly class FocusPages
{
    public function __construct(
        private SignedUrls $signedUrls,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @param int    $selectedPosition the area to show; the first one when the round has no area at that position
     * @param string $side             FocusPage::SIDE_BEFORE shows the earlier picture when the area has one; anything
     *                                 else the round's own
     * @param int    $pinPosition      from 1, the stop to show first; the first one when the walk has none there
     * @param bool   $isVerdictGranted whether VerdictPermission SET is granted to the viewer on the round
     */
    public function pageOf(TicketPageView $ticket, ReviewRoundView $round, int $selectedPosition, string $side, int $pinPosition, bool $isVerdictGranted, \DateTimeImmutable $now): FocusPage
    {
        $roundLine = \sprintf('%s · Round %d', $ticket->key, $round->number);
        $area = RoundPages::areaAt($round, $selectedPosition);
        if (null === $area) {
            return new FocusPage($ticket->key, $roundLine, null, FocusPage::SIDE_AFTER, false, null, [], null, false);
        }

        $shown = FocusPage::SIDE_BEFORE === $side ? ($area->before ?? $area->after) : $area->after;
        $isBefore = $shown !== $area->after;
        $pins = $area->before->pins ?? [];
        $stops = self::stopsOf($ticket, $pins);
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
        );
    }

    /**
     * @param list<RoundPinView> $pins
     *
     * @return list<FocusPinStop>
     */
    private static function stopsOf(TicketPageView $ticket, array $pins): array
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
