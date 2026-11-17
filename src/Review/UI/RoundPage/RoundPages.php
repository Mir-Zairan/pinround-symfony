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
use App\Review\UI\Voter\TicketTesterRule;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketPageView;

/**
 * Builds the review round page for one viewer: the header, a tab per screen area and, of the selected area only, both
 * pictures (signed in one call, whatever the number of areas) with their pins, and its side panel.
 */
final readonly class RoundPages
{
    /** The area verdicts in the order of their chips, by the value the form posts. */
    public const array AREA_VERDICT_LABELS = [
        VerdictCommands::AREA_PASS => 'Pass',
        VerdictCommands::AREA_NEEDS_FIX => 'Needs fix',
        VerdictCommands::AREA_QUESTION => 'Question',
    ];

    /** ReviewRoundView's state of a round that still takes verdicts. */
    private const string OPEN_ROUND = 'open';

    public function __construct(
        private SignedUrls $signedUrls,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @param int           $selectedPosition the area to show; the first one when the round has no area at that position
     * @param bool          $isVerdictGranted whether VerdictPermission SET is granted to the viewer on the round
     * @param \DateTimeZone $viewerTimeZone   the header's day and time are in it
     */
    public function pageOf(TicketPageView $ticket, ReviewRoundView $round, int $selectedPosition, bool $isVerdictGranted, UserId $viewerId, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): RoundPage
    {
        $title = \sprintf('Round %d', $round->number);
        $detail = self::detailOf($ticket, $round, $now, $viewerTimeZone);
        $selected = self::areaAt($round, $selectedPosition);
        if (null === $selected) {
            return new RoundPage($ticket->key, $ticket->title, $title, $detail, [], null, null);
        }

        return new RoundPage(
            $ticket->key,
            $ticket->title,
            $title,
            $detail,
            self::tabsOf($round, $selected),
            $this->selectedAreaOf($ticket, $round, $selected, $now),
            self::panelOf($ticket, $round, $selected, $isVerdictGranted, $viewerId),
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

    private function selectedAreaOf(TicketPageView $ticket, ReviewRoundView $round, AreaView $area, \DateTimeImmutable $now): SelectedArea
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

        return new SelectedArea(
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

        return new EarlierPinRow($pin->id, $pin->number, $pin->note, self::authorLineOf($pin->authorId, $ticket), $label, $class, null !== $pin->verdict);
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
        foreach ($snapshot->testerIds as $testerId) {
            if ($testerId->equals($userId)) {
                return true;
            }
        }

        return false;
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

    private static function detailOf(TicketPageView $ticket, ReviewRoundView $round, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $parts = ['shot '.self::whenOf($round->sharedAt, $now, $viewerTimeZone)];
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
