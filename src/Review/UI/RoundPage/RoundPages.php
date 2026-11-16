<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
use App\Review\Api\AreaState;
use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\VerdictCommands;
use App\Review\UI\PinView;
use App\Review\UI\RoundCards;
use App\Review\UI\ScreenshotView;
use App\Ticketing\Api\TicketPageView;

/**
 * Builds the review round page for one viewer: the header, a tab per screen area and, of the selected area only, both
 * pictures (signed in one call, whatever the number of areas) with their pins, and the earlier pins to check.
 */
final readonly class RoundPages
{
    private const string FIXED = 'fixed';
    private const string STILL_WRONG = 'still_wrong';

    private const array AREA_VERDICT_LABELS = [
        VerdictCommands::AREA_PASS => 'Pass',
        VerdictCommands::AREA_NEEDS_FIX => 'Needs fix',
        VerdictCommands::AREA_QUESTION => 'Question',
    ];

    public function __construct(
        private SignedUrls $signedUrls,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @param int $selectedPosition the area to show; the first one when the round has no area at that position
     */
    public function pageOf(TicketPageView $ticket, ReviewRoundView $round, int $selectedPosition, \DateTimeImmutable $now): RoundPage
    {
        $header = self::headerOf($ticket, $round, $now);
        if ([] === $round->areas) {
            return new RoundPage($ticket->key, $ticket->title, $header, [], null);
        }
        $selected = $round->areas[0];
        foreach ($round->areas as $area) {
            if ($area->position === $selectedPosition) {
                $selected = $area;
            }
        }
        $tabs = array_map(
            static function (AreaView $area) use ($selected): AreaTab {
                $chip = self::chipOf($area->state(), $area->pinCount());

                return new AreaTab($area->position, $area->name, $chip['stateLabel'], $chip['stateClass'], $area === $selected);
            },
            $round->areas,
        );

        return new RoundPage($ticket->key, $ticket->title, $header, $tabs, $this->selectedAreaOf($ticket, $round, $selected, $now));
    }

    private function selectedAreaOf(TicketPageView $ticket, ReviewRoundView $round, AreaView $area, \DateTimeImmutable $now): SelectedArea
    {
        $mediaIds = [$area->after->mediaId];
        if (null !== $area->before) {
            $mediaIds[] = $area->before->mediaId;
        }
        $images = $this->signedUrls->forVariants($ticket->visibilityFacts->organizationId, $mediaIds, RoundCards::VARIANT, $now);

        $before = null;
        $beforeCaption = null;
        $earlierPins = [];
        if (null !== $area->before) {
            $before = self::viewOf($area, $area->before, 'before', \sprintf('Before, round %d: %s', $area->beforeRoundNumber, $area->name), $images);
            $beforeCaption = \sprintf('Before · round %d', $area->beforeRoundNumber);
            $earlierPins = array_map(self::earlierPinRowOf(...), $area->before->pins);
        }

        return new SelectedArea(
            $area->name,
            $before,
            $beforeCaption,
            self::viewOf($area, $area->after, 'after', \sprintf('After, round %d: %s', $round->number, $area->name), $images),
            \sprintf('After · round %d', $round->number),
            null === $area->beforeRoundNumber ? null : \sprintf('Pins from round %d', $area->beforeRoundNumber),
            $earlierPins,
            null === $area->verdict ? null : (self::AREA_VERDICT_LABELS[$area->verdict] ?? null),
            $area->note,
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

    private static function earlierPinRowOf(RoundPinView $pin): EarlierPinRow
    {
        [$label, $class] = match ($pin->verdict) {
            self::FIXED => ['Fixed', 'verdict-tag--looks-right'],
            self::STILL_WRONG => ['Still wrong', 'verdict-tag--not-right'],
            default => ['Not checked yet', 'verdict-tag--none'],
        };

        return new EarlierPinRow($pin->number, $pin->note, $label, $class);
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

    private static function headerOf(TicketPageView $ticket, ReviewRoundView $round, \DateTimeImmutable $now): string
    {
        $parts = [\sprintf('Round %d', $round->number), 'shot '.self::whenOf($round->sharedAt, $now)];
        $readyFor = self::readyForOf($ticket);
        if (null !== $readyFor) {
            $parts[] = 'Ready for '.$readyFor;
        }

        return implode(' · ', $parts);
    }

    /**
     * The ticket's tester, else the first tester of its team, by name; null when the ticket has none or Identity does
     * not know the name.
     */
    private static function readyForOf(TicketPageView $ticket): ?string
    {
        $snapshot = $ticket->turnSnapshot;
        $testerId = $snapshot->testerId ?? ($snapshot->testerIds[0] ?? null);

        return null === $testerId ? null : ($ticket->displayNames[$testerId->toString()] ?? null);
    }

    /** "today, 09:12", "yesterday, 09:12" or "5 Nov, 09:12", in UTC. */
    private static function whenOf(\DateTimeImmutable $sharedAt, \DateTimeImmutable $now): string
    {
        $utc = new \DateTimeZone('UTC');
        $shared = $sharedAt->setTimezone($utc);
        $today = $now->setTimezone($utc)->format('Y-m-d');
        $time = $shared->format('H:i');
        $day = $shared->format('Y-m-d');
        if ($day === $today) {
            return 'today, '.$time;
        }
        if ($day === $now->setTimezone($utc)->modify('-1 day')->format('Y-m-d')) {
            return 'yesterday, '.$time;
        }

        return $shared->format('j M').', '.$time;
    }
}
