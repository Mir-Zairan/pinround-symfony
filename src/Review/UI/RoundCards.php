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
use App\Ticketing\Api\TicketVisibility;

/**
 * Builds the cards of the Rounds section for one viewer. Besides RoundQueries' own queries it reads the owners'
 * names in one batch and signs the pictures shown in one query per SignedUrls::MAX_MEDIA_IDS pictures, whatever the
 * number of rounds. Only the report and drafts show their pictures, at most MAX_PICTURES_PER_CARD each; a checklist or
 * review round is a row that opens its own page.
 */
final readonly class RoundCards
{
    public const string UNKNOWN_OWNER = 'a former member';
    public const string VARIANT = 'view';

    /**
     * The "For Claude Code" box of the viewer's own unshared draft: the line to give Claude Code, with the ticket's key,
     * and the tools it will use (served in P09).
     */
    public const string CLAUDE_CODE_PROMPT = 'Read my pinround notes for %s and fix what is marked “Not right yet”.';
    public const string CLAUDE_CODE_TOOLS_NOTE = 'Planned: pinround’s tool connection (built with mcp/sdk) lets Claude Code read these notes and verdicts, and add screenshots. Tools: get_round_notes, add_screenshot, set_verdict. Not built yet.';

    /** Asked by the browser before the owner's draft is deleted; the delete form carries it as data-turbo-confirm. */
    public const string DELETE_DRAFT_CONFIRM = 'Delete this draft round? Its screenshots, pins and notes go too.';

    /** The pictures one card shows; a round may hold 50 screenshots, and the card says how many more it left out. */
    public const int MAX_PICTURES_PER_CARD = 10;

    private const string REPORT = 'report';
    private const string CHECKLIST = 'checklist';
    private const string DISMISSED = 'dismissed';
    private const string LOOKS_RIGHT = 'looks_right';
    private const string AI = 'ai';
    private const string NOT_RIGHT = 'not_right';

    private const string PRIVATE_DRAFT_DESCRIPTION = 'Check your own work before you pass it on. Screenshots, pins, notes and a verdict per screenshot. Nobody else sees this until you share it.';
    private const string EMPTY_DRAFT_NOTE = 'No screenshots yet.';

    public function __construct(
        private RoundsVisibleTo $roundsVisibleTo,
        private MemberNameQueries $memberNameQueries,
        private SignedUrls $signedUrls,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     */
    public function forTicket(TicketId $ticketId, TicketVisibility $viewer, \DateTimeImmutable $now): RoundsSectionView
    {
        $organizationId = $viewer->organizationId();
        $ticketRounds = $this->roundsVisibleTo->forVisibleTo($ticketId, $viewer);
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

            return new RoundCard($round->id, $domId, \sprintf('Round %d · %s · by %s', $round->number ?? 0, $title, $ownerName), null, false, [], true);
        }

        $screenshots = [];
        $pinCount = 0;
        $looksRightCount = 0;
        foreach ($round->screenshots as $screenshot) {
            $shownPins = self::shownPinsOf($screenshot);
            $pinCount += \count($shownPins);
            if (self::LOOKS_RIGHT === $screenshot->verdict) {
                ++$looksRightCount;
            }
            if (\count($screenshots) < self::MAX_PICTURES_PER_CARD) {
                $screenshots[] = $this->cardScreenshotOf($round, $screenshot, $images[$screenshot->mediaId] ?? null, $shownPins);
            }
        }
        $notShown = \count($round->screenshots) - \count($screenshots);
        $moreNote = 0 === $notShown ? null : (1 === $notShown ? '1 more screenshot is not shown.' : \sprintf('%d more screenshots are not shown.', $notShown));

        if (self::REPORT === $round->kind) {
            return new RoundCard($round->id, $domId, \sprintf('Round %d · Report · by %s · %s', $round->number ?? 0, $ownerName, 1 === $pinCount ? '1 pin' : $pinCount.' pins'), null, false, $screenshots, false, $moreNote);
        }
        $status = [] === $round->screenshots ? null : \sprintf('%d of %d look right', $looksRightCount, \count($round->screenshots));
        $emptyNote = [] === $round->screenshots ? self::EMPTY_DRAFT_NOTE : null;
        if ($round->isUnsharedDraft()) {
            return new RoundCard($round->id, $domId, 'Draft round · only you can see this', $status, true, $screenshots, false, $moreNote, self::PRIVATE_DRAFT_DESCRIPTION, $emptyNote);
        }

        return new RoundCard(
            $round->id,
            $domId,
            \sprintf('Round %d · self-check by %s', $round->number ?? 0, $ownerName),
            $status,
            false,
            $screenshots,
            false,
            $moreNote,
            \sprintf('What %s checked before passing the ticket on.', $ownerName),
            $emptyNote,
        );
    }

    /**
     * @return list<PinView> the pins of a screenshot but the AI's that were dismissed
     */
    private static function shownPinsOf(RoundScreenshotView $screenshot): array
    {
        $pins = [];
        foreach ($screenshot->pins as $pin) {
            if (self::DISMISSED !== $pin->aiState) {
                $pins[] = self::pinViewOf($pin);
            }
        }

        return $pins;
    }

    /**
     * @param list<PinView> $pins
     */
    private function cardScreenshotOf(RoundView $round, RoundScreenshotView $screenshot, ?MediaImage $image, array $pins): RoundCardScreenshot
    {
        $view = $this->screenshotViewOf($round, $screenshot, $image, $pins);
        if (RoundView::DRAFT !== $round->kind) {
            return new RoundCardScreenshot($screenshot->id, $view);
        }
        [$verdictLabel, $verdictClass] = match ($screenshot->verdict) {
            self::LOOKS_RIGHT => [RoundCardScreenshot::LOOKS_RIGHT, 'verdict-tag--looks-right'],
            self::NOT_RIGHT => [RoundCardScreenshot::NOT_RIGHT, 'verdict-tag--not-right'],
            default => [RoundCardScreenshot::NO_VERDICT, 'verdict-tag--none'],
        };

        return new RoundCardScreenshot($screenshot->id, $view, \sprintf('Screenshot %d', $screenshot->position + 1), $verdictLabel, $verdictClass);
    }

    /**
     * @param list<PinView> $pins
     */
    private function screenshotViewOf(RoundView $round, RoundScreenshotView $screenshot, ?MediaImage $image, array $pins): ScreenshotView
    {
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
            foreach (\array_slice($round->screenshots, 0, self::MAX_PICTURES_PER_CARD) as $screenshot) {
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
