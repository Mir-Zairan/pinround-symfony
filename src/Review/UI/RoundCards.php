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
use App\Review\Api\ScreenshotChains;
use App\Review\Api\VerdictCommands;
use App\Review\Application\RoundsVisibleTo;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Checklist\ChecklistCards;
use App\Review\UI\RoundPage\EarlierPinRow;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;

/**
 * Builds the cards of the Rounds section for one viewer. Besides RoundQueries' own queries it reads the owners'
 * names in one batch and signs the pictures shown in one query per variant and SignedUrls::MAX_MEDIA_IDS pictures,
 * whatever the number of rounds. On the ticket only the report and the viewer's own unshared draft are cards with
 * pictures, the report's at most MAX_PICTURES_PER_CARD and as thumbs. The checklist round is a card of its rows. Every
 * review round and shared draft is a one-line row that opens its own page, loads and signs no picture and says the
 * screenshot count the rounds query counted; a findings round keeps its check items under its row (ChecklistCards,
 * read only when the ticket has a checklist round or a shared draft).
 * The unshared draft's card shows every picture (the domain caps them), so its editor can reach each one, as one
 * screenshot per chain of tries with its earlier tries (ADR-0048). forDraft builds one draft's card alone, shared or
 * not, with its pictures.
 */
final readonly class RoundCards
{
    public const string UNKNOWN_OWNER = 'a former member';
    public const string VARIANT = 'view';
    /** The report's pictures on the ticket and a round page's strip: at most 480 px wide, signed with their size. */
    public const string THUMB_VARIANT = 'thumb';

    /**
     * The most CSS pixels high a report picture is drawn on the ticket, its width scaled with it, so a ticket with five
     * rounds fits under 1,400 px at 1440 × 900 (P14.T7). A 480 px thumb of a common screen shape (16:10, 300 high) is
     * drawn 320 × 200.
     */
    public const int THUMBNAIL_MAX_HEIGHT = 200;

    /**
     * The "For Claude Code" box of the viewer's own unshared draft: the line to give Claude Code, with the ticket's key,
     * and the tools it uses (AgentApi's MCP server at /mcp); the API tokens line below the note says how to connect.
     */
    public const string CLAUDE_CODE_PROMPT = 'Read my pinround notes for %s and fix what is marked “Not right yet”.';
    public const string CLAUDE_CODE_TOOLS_NOTE = 'Claude Code can read these notes and verdicts and add screenshots. Tools: get_round_notes, add_screenshot, set_verdict.';

    /** Asked by the browser before the owner's draft is deleted; the delete form carries it as data-turbo-confirm. */
    public const string DELETE_DRAFT_CONFIRM = 'Delete this draft round? Its screenshots, pins and notes go too.';

    /** The pictures one card shows; a round may hold 50 screenshots, and the card says how many more it left out. */
    public const int MAX_PICTURES_PER_CARD = 10;

    private const string REPORT = 'report';
    private const string CHECKLIST = 'checklist';
    private const string REVIEW = 'review';
    private const string DISMISSED = 'dismissed';
    private const string LOOKS_RIGHT = 'looks_right';
    private const string AI = 'ai';
    private const string NOT_RIGHT = 'not_right';

    private const string PRIVATE_DRAFT_DESCRIPTION = 'Check your own work before you pass it on. Screenshots, pins, notes and a verdict per screenshot. Nobody else sees this until you share it.';
    private const string EMPTY_DRAFT_NOTE = 'No screenshots yet.';

    /** A shared draft of the ticket's assignee, with its number and owner's name. */
    private const string SELF_CHECK_HEADLINE = 'Round %d · self-check by %s';
    private const string SELF_CHECK_DESCRIPTION = 'What %s checked before passing the ticket on.';
    /** A shared draft of anyone else (ADR-0044): a tester's or reviewer's new findings, each pin a check item. */
    private const string FINDINGS_HEADLINE = 'Round %d · findings by %s';
    private const string FINDINGS_DESCRIPTION = 'What %s found while checking the ticket.';

    /** The rows of later rounds: number, owner's name, open or closed, and the screenshot count. */
    private const string REVIEW_ROW = 'Round %d · review by %s · %s · %s';
    private const string SELF_CHECK_ROW = 'Round %d · self-check by %s · %s · %s';
    private const string FINDINGS_ROW = 'Round %d · findings by %s · %s · %s';

    public function __construct(
        private RoundsVisibleTo $roundsVisibleTo,
        private MemberNameQueries $memberNameQueries,
        private SignedUrls $signedUrls,
        private ChecklistCards $checklistCards,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization.
     *
     * @param UserId|null $assigneeId the ticket's assignee now; a shared draft of anyone else is findings (ADR-0044)
     */
    public function forTicket(TicketId $ticketId, TicketVisibility $viewer, ?UserId $assigneeId, \DateTimeImmutable $now): RoundsSectionView
    {
        $organizationId = $viewer->organizationId();
        $ticketRounds = $this->roundsVisibleTo->forVisibleTo($ticketId, $viewer);
        $rounds = $ticketRounds->rounds;
        if ([] === $rounds) {
            return new RoundsSectionView([], 0);
        }

        $ownerNames = $this->memberNameQueries->displayNamesOf($organizationId, self::ownersOf($rounds));
        $imagesByVariant = $this->imagesOf($organizationId, array_values(array_filter($rounds, static fn (RoundView $round): bool => !self::isRow($round))), $now);
        $checklists = $this->checklistCards->forRounds($organizationId, $ticketId, self::idsOfRoundsWithItems($rounds));

        return new RoundsSectionView(
            array_map(function (RoundView $round) use ($ownerNames, $assigneeId, $imagesByVariant, $checklists): RoundCard {
                $ownerName = $ownerNames[$round->ownerId->toString()] ?? self::UNKNOWN_OWNER;

                return self::isRow($round)
                    ? self::rowOf($round, $ownerName, $assigneeId, $checklists[$round->id] ?? null)
                    : $this->cardOf($round, $ownerName, $assigneeId, $imagesByVariant[self::variantOf($round)] ?? [], $checklists[$round->id] ?? null);
            }, $rounds),
            $ticketRounds->olderRoundsNotShown,
        );
    }

    /**
     * The card of one draft round of its owner, reading and signing nothing of any other round: every picture of an
     * unshared draft, and the owner's name in one query only once the draft was shared (its headline names them). Runs
     * inside the request transaction like forTicket.
     *
     * @param UserId|null $assigneeId as forTicket takes it
     */
    public function forDraft(OrganizationId $organizationId, RoundView $draft, ?UserId $assigneeId, \DateTimeImmutable $now): RoundCard
    {
        if (RoundView::DRAFT !== $draft->kind) {
            throw new \InvalidArgumentException('Only a draft round has a card of its own.');
        }
        $ownerName = $draft->isUnsharedDraft()
            ? self::UNKNOWN_OWNER
            : ($this->memberNameQueries->displayNamesOf($organizationId, [$draft->ownerId])[$draft->ownerId->toString()] ?? self::UNKNOWN_OWNER);

        return $this->cardOf($draft, $ownerName, $assigneeId, $this->imagesOf($organizationId, [$draft], $now)[self::variantOf($draft)] ?? []);
    }

    /**
     * @param array<string, MediaImage> $images    by media id
     * @param ChecklistCard|null        $checklist the round's own check items: the checklist round's, or a findings round's
     */
    private function cardOf(RoundView $round, string $ownerName, ?UserId $assigneeId, array $images, ?ChecklistCard $checklist = null): RoundCard
    {
        $domId = 'round-'.$round->id;
        if (self::CHECKLIST === $round->kind) {
            // Checklist::fromReport makes none without pins, DbalChecklists stores the round with its items in one
            // savepoint, and no command removes a report's pins: a round without items is broken data, not an empty card.
            if (null === $checklist) {
                throw new \LogicException(\sprintf('Checklist round %s has no items.', $round->id));
            }

            return new RoundCard($round->id, $domId, $checklist->headline, $checklist->status, false, [], false, number: $round->number, checklist: $checklist);
        }
        if (!$this->showsPictures($round)) {
            throw new \LogicException(\sprintf('A %s round is a row, not a card.', $round->kind));
        }

        $pictureLimit = self::pictureLimitOf($round);
        $chains = self::shownChainsOf($round);
        $screenshots = [];
        $pinCount = 0;
        $looksRightCount = 0;
        foreach ($chains as $chainIndex => $tries) {
            $latestTry = $tries[\count($tries) - 1];
            $pinCount += \count(self::shownPinsOf($latestTry));
            if (self::LOOKS_RIGHT === $latestTry->verdict) {
                ++$looksRightCount;
            }
            if (null === $pictureLimit || \count($screenshots) < $pictureLimit) {
                // Numbered by chain in the order of their first tries, before and after share alike, as ChecklistCard
                // numbers the pins of a findings round (DbalChecklistQueries).
                $screenshots[] = $this->cardScreenshotOf($round, $tries, $chainIndex + 1, $images);
            }
        }
        $notShown = \count($chains) - \count($screenshots);
        $moreNote = 0 === $notShown ? null : (1 === $notShown ? '1 more screenshot is not shown.' : \sprintf('%d more screenshots are not shown.', $notShown));

        if (self::REPORT === $round->kind) {
            return new RoundCard(
                $round->id,
                $domId,
                \sprintf('Round %d · Report · by %s · %s', $round->number ?? 0, $ownerName, 1 === $pinCount ? '1 pin' : $pinCount.' pins'),
                null,
                false,
                $screenshots,
                false,
                $moreNote,
                number: $round->number,
                hasFullScreen: [] !== $chains,
            );
        }
        $status = [] === $chains ? null : \sprintf('%d of %d look right', $looksRightCount, \count($chains));
        $emptyNote = [] === $round->screenshots ? self::EMPTY_DRAFT_NOTE : null;
        if ($round->isUnsharedDraft()) {
            return new RoundCard($round->id, $domId, 'Draft round · only you can see this', $status, true, $screenshots, false, $moreNote, self::PRIVATE_DRAFT_DESCRIPTION, $emptyNote);
        }

        // FindingsChecklist's rule, read against today's assignee: a later reassignment changes the label, not the items,
        // so the items made on share show under either label.
        $isSelfCheck = null !== $assigneeId && $round->ownerId->equals($assigneeId);

        return new RoundCard(
            $round->id,
            $domId,
            \sprintf($isSelfCheck ? self::SELF_CHECK_HEADLINE : self::FINDINGS_HEADLINE, $round->number ?? 0, $ownerName),
            $status,
            false,
            $screenshots,
            false,
            $moreNote,
            \sprintf($isSelfCheck ? self::SELF_CHECK_DESCRIPTION : self::FINDINGS_DESCRIPTION, $ownerName),
            $emptyNote,
            findingsChecklist: $checklist,
        );
    }

    /**
     * A review round or a shared draft on the ticket: one line and "Open round", no picture. Neither is loaded with its
     * screenshots (RoundsVisibleTo), so the line says the count the rounds query made: the latest try of each.
     *
     * @param ChecklistCard|null $findingsChecklist a findings round's check items, the one place to mark them fixed
     *
     * @throws \LogicException when the round was read without its count, as only a read of one round is
     */
    private static function rowOf(RoundView $round, string $ownerName, ?UserId $assigneeId, ?ChecklistCard $findingsChecklist): RoundCard
    {
        $number = $round->number ?? 0;
        $screenshotCount = $round->screenshotCount ?? throw new \LogicException(\sprintf('Round %s was read without its screenshot count, which its row shows.', $round->id));
        if (RoundView::DRAFT !== $round->kind) {
            $headline = \sprintf(self::REVIEW_ROW, $number, $ownerName, $round->state, self::screenshotCountOf($screenshotCount));
        } else {
            $isSelfCheck = null !== $assigneeId && $round->ownerId->equals($assigneeId);
            $headline = \sprintf($isSelfCheck ? self::SELF_CHECK_ROW : self::FINDINGS_ROW, $number, $ownerName, $round->state, self::screenshotCountOf($screenshotCount));
        }

        return new RoundCard($round->id, 'round-'.$round->id, $headline, null, false, [], true, number: $round->number, findingsChecklist: $findingsChecklist, isRow: true);
    }

    private static function screenshotCountOf(int $count): string
    {
        return match ($count) {
            0 => 'no screenshots',
            1 => '1 screenshot',
            default => $count.' screenshots',
        };
    }

    /**
     * Every round after the report and the checklist that the team sees: a review round or a shared draft.
     */
    private static function isRow(RoundView $round): bool
    {
        return self::REVIEW === $round->kind || (RoundView::DRAFT === $round->kind && !$round->isUnsharedDraft());
    }

    /**
     * @return list<PinView> the pins of a screenshot but the AI's that were dismissed
     */
    public static function shownPinsOf(RoundScreenshotView $screenshot): array
    {
        $pins = [];
        foreach ($screenshot->pins as $pin) {
            if (self::isShownPin($pin)) {
                $pins[] = self::pinViewOf($pin);
            }
        }

        return $pins;
    }

    /** Every pin but an AI suggestion that was dismissed. */
    public static function isShownPin(RoundPinView $pin): bool
    {
        return self::DISMISSED !== $pin->aiState;
    }

    /**
     * The latest try of a chain; in a draft with its earlier tries, each with its pins as rows to mark.
     *
     * @param non-empty-list<RoundScreenshotView> $tries  by position, the latest last
     * @param int                                 $number the "Screenshot n" of the chain, from 1
     * @param array<string, MediaImage>           $images by media id
     */
    private function cardScreenshotOf(RoundView $round, array $tries, int $number, array $images): RoundCardScreenshot
    {
        $tryCount = \count($tries);
        $latestTry = $tries[$tryCount - 1];
        if (RoundView::DRAFT !== $round->kind) {
            return new RoundCardScreenshot($latestTry->id, self::thumbnailOf(self::screenshotViewOf($round, $latestTry, $number, $images)));
        }
        $earlierTries = [];
        foreach (\array_slice($tries, 0, -1) as $index => $earlierTry) {
            $earlierTries[] = $this->draftScreenshotOf($round, $earlierTry, $number, $images, $index + 1, $tryCount, [], self::earlierPinRowsOf($earlierTry));
        }

        return $this->draftScreenshotOf($round, $latestTry, $number, $images, $tryCount, $tryCount, $earlierTries, []);
    }

    /**
     * @param array<string, MediaImage> $images       by media id
     * @param list<RoundCardScreenshot> $earlierTries
     * @param list<EarlierPinRow>       $pinRows
     */
    private function draftScreenshotOf(RoundView $round, RoundScreenshotView $screenshot, int $number, array $images, int $tryNumber, int $tryCount, array $earlierTries, array $pinRows): RoundCardScreenshot
    {
        [$verdictLabel, $verdictClass] = match ($screenshot->verdict) {
            self::LOOKS_RIGHT => [RoundCardScreenshot::LOOKS_RIGHT, 'verdict-tag--looks-right'],
            self::NOT_RIGHT => [RoundCardScreenshot::NOT_RIGHT, 'verdict-tag--not-right'],
            default => [RoundCardScreenshot::NO_VERDICT, 'verdict-tag--none'],
        };

        return new RoundCardScreenshot(
            $screenshot->id,
            self::screenshotViewOf($round, $screenshot, $number, $images),
            \sprintf('Screenshot %d', $number),
            $verdictLabel,
            $verdictClass,
            $tryNumber,
            $tryCount,
            $earlierTries,
            $pinRows,
        );
    }

    /**
     * @return list<EarlierPinRow> the pins of an earlier try but the AI's that were dismissed, by number; a fixed one
     *                             names the try it was fixed on when it was marked, not the latest try now
     */
    private static function earlierPinRowsOf(RoundScreenshotView $earlierTry): array
    {
        $rows = [];
        foreach ($earlierTry->pins as $pin) {
            if (!self::isShownPin($pin)) {
                continue;
            }
            [$verdictLabel, $verdictClass, $isChecked] = match ($pin->verdict) {
                VerdictCommands::PIN_FIXED => [
                    null === $pin->fixedOnTry ? RoundCardScreenshot::PIN_FIXED : \sprintf(RoundCardScreenshot::PIN_FIXED_ON_TRY, $pin->fixedOnTry),
                    'verdict-tag--looks-right',
                    true,
                ],
                VerdictCommands::PIN_STILL_WRONG => [RoundCardScreenshot::PIN_STILL_WRONG, 'verdict-tag--not-right', true],
                default => [RoundCardScreenshot::PIN_NOT_CHECKED, 'verdict-tag--none', false],
            };
            $rows[] = new EarlierPinRow($pin->id, $pin->number, $pin->note, null, $verdictLabel, $verdictClass, $isChecked, VerdictCommands::PIN_FIXED === $pin->verdict);
        }

        return $rows;
    }

    /**
     * @param int                       $number the "Screenshot n" of its chain, from 1
     * @param array<string, MediaImage> $images by media id
     */
    public static function screenshotViewOf(RoundView $round, RoundScreenshotView $screenshot, int $number, array $images): ScreenshotView
    {
        $altText = $screenshot->title ?? \sprintf('Screenshot %d of %s', $number, $round->isUnsharedDraft() ? 'your draft round' : 'round '.($round->number ?? 0));

        return self::viewOfPicture('screenshot-'.$screenshot->id, $altText, $screenshot->title, $screenshot->summary, $images[$screenshot->mediaId] ?? null, self::shownPinsOf($screenshot));
    }

    /**
     * The owner's unshared draft shows each chain with every try (ADR-0048). Any other round shows what the team sees,
     * the latest try of each chain, each as a chain of its own: RoundQueries lists only those for a shared round on the
     * ticket, but every try for its owner's draftOf. The round page numbers its screenshots from here, so its "2 of 3"
     * is the card's "Screenshot 2".
     *
     * @return list<non-empty-list<RoundScreenshotView>> by their first try's position, as RoundView lists them; each
     *                                                   chain's tries by position, the latest last
     */
    public static function shownChainsOf(RoundView $round): array
    {
        $chains = ScreenshotChains::of($round->screenshots);
        if ($round->isUnsharedDraft()) {
            return $chains;
        }

        return array_map(static fn (array $tries): array => [$tries[\count($tries) - 1]], $chains);
    }

    /**
     * The picture of one screenshot as the components draw it. A picture Media does not know is shown as refused rather
     * than left out, so a round never loses a screenshot silently.
     *
     * @param list<PinView> $pins
     */
    public static function viewOfPicture(string $domId, string $altText, ?string $title, ?string $summary, ?MediaImage $image, array $pins): ScreenshotView
    {
        $status = $image->status ?? MediaImageStatus::Rejected;
        if (MediaImageStatus::Ready === $status && null !== $image && null !== $image->url && null !== $image->width && null !== $image->height) {
            return new ScreenshotView($domId, $image->url, $image->width, $image->height, $altText, $title, $summary, $pins);
        }

        return new ScreenshotView($domId, null, null, null, $altText, $title, $summary, $pins, MediaImageStatus::Pending === $status ? MediaImageStatus::Pending : MediaImageStatus::Rejected);
    }

    /**
     * The report's picture drawn no higher than THUMBNAIL_MAX_HEIGHT, its width scaled with it so the pins, placed in
     * percent of the picture, stay on their spots; review.css narrows it to the column, never wider.
     */
    private static function thumbnailOf(ScreenshotView $view): ScreenshotView
    {
        if (null === $view->width || null === $view->height || $view->height <= self::THUMBNAIL_MAX_HEIGHT) {
            return $view;
        }
        $drawnWidth = max(1, (int) round($view->width * self::THUMBNAIL_MAX_HEIGHT / $view->height));

        return new ScreenshotView($view->domId, $view->imageUrl, $drawnWidth, self::THUMBNAIL_MAX_HEIGHT, $view->altText, $view->title, $view->summary, $view->pins, $view->status);
    }

    /**
     * The report's pictures are signed as thumbs, each round's own pictures as views: their pins are placed and checked
     * on the full picture.
     */
    private static function variantOf(RoundView $round): string
    {
        return self::REPORT === $round->kind ? self::THUMB_VARIANT : self::VARIANT;
    }

    public static function pinViewOf(RoundPinView $pin): PinView
    {
        return new PinView($pin->number, $pin->xPercent, $pin->yPercent, $pin->note, self::AI === $pin->source);
    }

    /**
     * @return int|null null for the viewer's own unshared draft, whose editor must reach every picture it holds
     */
    private static function pictureLimitOf(RoundView $round): ?int
    {
        return $round->isUnsharedDraft() ? null : self::MAX_PICTURES_PER_CARD;
    }

    /**
     * @param list<RoundView> $rounds
     *
     * @return list<string> the checklist round's id and the shared drafts', whose pins may be check items (ADR-0044)
     */
    private static function idsOfRoundsWithItems(array $rounds): array
    {
        $roundIds = [];
        foreach ($rounds as $round) {
            if (self::CHECKLIST === $round->kind || (RoundView::DRAFT === $round->kind && !$round->isUnsharedDraft())) {
                $roundIds[] = $round->id;
            }
        }

        return $roundIds;
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
     * @return array<string, array<string, MediaImage>> by variant (variantOf), then by media id; one query per variant
     *                                                  and SignedUrls::MAX_MEDIA_IDS pictures
     */
    private function imagesOf(OrganizationId $organizationId, array $rounds, \DateTimeImmutable $now): array
    {
        $mediaIdsByVariant = [];
        foreach ($rounds as $round) {
            if (!$this->showsPictures($round)) {
                continue;
            }
            $pictureLimit = self::pictureLimitOf($round);
            $chains = self::shownChainsOf($round);
            foreach (null === $pictureLimit ? $chains : \array_slice($chains, 0, $pictureLimit) as $tries) {
                foreach ($tries as $screenshot) {
                    $mediaIdsByVariant[self::variantOf($round)][$screenshot->mediaId] = true;
                }
            }
        }
        $imagesByVariant = [];
        foreach ($mediaIdsByVariant as $variant => $mediaIds) {
            $imagesByVariant[$variant] = [];
            foreach (array_chunk(array_keys($mediaIds), SignedUrls::MAX_MEDIA_IDS) as $mediaIdsOfOneCall) {
                $imagesByVariant[$variant] += $this->signedUrls->forVariants($organizationId, $mediaIdsOfOneCall, $variant, $now);
            }
        }

        return $imagesByVariant;
    }
}
