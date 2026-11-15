<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Media\Api\MediaImage;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\Organization\Api\MemberNameQueries;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Application\RoundsVisibleTo;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;

/**
 * Builds the cards of the ticket page's Rounds section for one viewer. Besides RoundQueries' own queries it reads the
 * owners' names in one batch and signs every picture in one call per SignedUrls::MAX_MEDIA_IDS pictures, whatever the
 * number of rounds. Only the report and drafts show their pictures; a checklist or review round is a row that opens its
 * own page.
 */
final readonly class RoundCards
{
    public const string UNKNOWN_OWNER = 'a former member';
    public const string VARIANT = 'view';

    private const string REPORT = 'report';
    private const string CHECKLIST = 'checklist';
    private const string DISMISSED = 'dismissed';
    private const string LOOKS_RIGHT = 'looks_right';
    private const string AI = 'ai';

    public function __construct(
        private RoundsVisibleTo $roundsVisibleTo,
        private TicketVisibilities $ticketVisibilities,
        private MemberNameQueries $memberNameQueries,
        private SignedUrls $signedUrls,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is $organizationId.
     *
     * @param TicketVisibility|null $visibility the viewer's, when the page has built it; null builds it
     */
    public function forTicket(OrganizationId $organizationId, TicketId $ticketId, UserId $viewer, \DateTimeImmutable $now, ?TicketVisibility $visibility = null): RoundsSectionView
    {
        $ticketRounds = $this->roundsVisibleTo->forVisibleTo($ticketId, $visibility ?? $this->ticketVisibilities->of($organizationId, $viewer));
        $rounds = $ticketRounds->rounds;
        if ([] === $rounds) {
            return new RoundsSectionView([], 0);
        }

        $ownerNames = $this->memberNameQueries->displayNamesOf($organizationId, self::ownersOf($rounds));
        $images = $this->imagesOf($organizationId, $rounds, $now);

        return new RoundsSectionView(
            array_map(fn (RoundView $round): RoundCard => $this->cardOf($round, $ownerNames[$round->ownerId->toString()] ?? self::UNKNOWN_OWNER, $images), $rounds),
            $ticketRounds->olderRoundsNotShown,
        );
    }

    /**
     * @param array<string, MediaImage> $images by media id
     */
    private function cardOf(RoundView $round, string $ownerName, array $images): RoundCard
    {
        $domId = 'round-'.$round->id;
        if (!$this->showsPictures($round)) {
            $title = self::CHECKLIST === $round->kind ? 'Checklist from the report' : 'Review';

            return new RoundCard($domId, \sprintf('Round %d · %s · by %s', $round->number ?? 0, $title, $ownerName), null, false, [], true);
        }

        $screenshots = [];
        $pinCount = 0;
        $looksRightCount = 0;
        foreach ($round->screenshots as $screenshot) {
            $view = $this->screenshotViewOf($round, $screenshot, $images[$screenshot->mediaId] ?? null);
            $screenshots[] = $view;
            $pinCount += \count($view->pins);
            if (self::LOOKS_RIGHT === $screenshot->verdict) {
                ++$looksRightCount;
            }
        }

        if (self::REPORT === $round->kind) {
            return new RoundCard($domId, \sprintf('Round %d · Report · by %s · %s', $round->number ?? 0, $ownerName, 1 === $pinCount ? '1 pin' : $pinCount.' pins'), null, false, $screenshots, false);
        }
        $status = \sprintf('%d of %d look right', $looksRightCount, \count($screenshots));
        if ($round->isUnsharedDraft()) {
            return new RoundCard($domId, 'Draft round · only you can see this', $status, true, $screenshots, false);
        }

        return new RoundCard($domId, \sprintf('Round %d · self-check by %s', $round->number ?? 0, $ownerName), $status, false, $screenshots, false);
    }

    private function screenshotViewOf(RoundView $round, RoundScreenshotView $screenshot, ?MediaImage $image): ScreenshotView
    {
        $pins = [];
        foreach ($screenshot->pins as $pin) {
            if (self::DISMISSED !== $pin->aiState) {
                $pins[] = self::pinViewOf($pin);
            }
        }
        $domId = 'screenshot-'.$screenshot->id;
        $altText = $screenshot->title ?? \sprintf('Screenshot %d of %s', $screenshot->position + 1, $round->isUnsharedDraft() ? 'your draft round' : 'round '.($round->number ?? 0));
        // A picture Media does not know is shown as refused rather than left out, so a round never loses a screenshot silently.
        $status = $image->status ?? MediaImageStatus::Rejected;
        if (MediaImageStatus::Ready === $status && null !== $image && null !== $image->url && null !== $image->width && null !== $image->height) {
            return new ScreenshotView($domId, $image->url, $image->width, $image->height, $altText, $screenshot->title, $screenshot->summary, $pins);
        }

        return new ScreenshotView($domId, null, null, null, $altText, $screenshot->title, $screenshot->summary, $pins, MediaImageStatus::Pending === $status ? MediaImageStatus::Pending : MediaImageStatus::Rejected);
    }

    private static function pinViewOf(RoundPinView $pin): PinView
    {
        return new PinView($pin->number, $pin->xPercent, $pin->yPercent, $pin->note, self::AI === $pin->source);
    }

    private function showsPictures(RoundView $round): bool
    {
        return self::REPORT === $round->kind || RoundView::DRAFT === $round->kind;
    }

    /**
     * @param list<RoundView> $rounds
     *
     * @return list<UserId> each owner once
     */
    private static function ownersOf(array $rounds): array
    {
        $owners = [];
        foreach ($rounds as $round) {
            $owners[$round->ownerId->toString()] = $round->ownerId;
        }

        return array_values($owners);
    }

    /**
     * @param list<RoundView> $rounds
     *
     * @return array<string, MediaImage> by media id
     */
    private function imagesOf(OrganizationId $organizationId, array $rounds, \DateTimeImmutable $now): array
    {
        $mediaIds = [];
        foreach ($rounds as $round) {
            if (!$this->showsPictures($round)) {
                continue;
            }
            foreach ($round->screenshots as $screenshot) {
                $mediaIds[$screenshot->mediaId] = true;
            }
        }
        $images = [];
        foreach (array_chunk(array_keys($mediaIds), SignedUrls::MAX_MEDIA_IDS) as $mediaIdsOfOneCall) {
            $images += $this->signedUrls->forVariants($organizationId, $mediaIdsOfOneCall, self::VARIANT, $now);
        }

        return $images;
    }
}
