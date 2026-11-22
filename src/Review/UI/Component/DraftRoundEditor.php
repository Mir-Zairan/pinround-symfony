<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Media\Api\SignedUrls;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundRefused;
use App\Review\Api\RoundView;
use App\Review\Application\RoundsVisibleTo;
use App\Review\UI\Controller\RoundController;
use App\Review\UI\DraftRoundController;
use App\Review\UI\Media\RoundMediaTarget;
use App\Review\UI\PinView;
use App\Review\UI\RoundCard;
use App\Review\UI\RoundCards;
use App\Review\UI\RoundCardScreenshot;
use App\Review\UI\RoundPage\RoundPages;
use App\Review\UI\ScreenshotView;
use App\Review\UI\VisibleTicket;
use App\Review\UI\VisibleTicketLookup;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The owner's unshared draft round on its own page (P05.T8 step 2, ADR-0017, ticket-page.md "Draft round"), laid out as
 * a round page: the header with "k of n look right", the share, new-ticket and delete buttons and a tab per screenshot,
 * then one screenshot at a time, beside the picture of the same number in the hand-off round before it when there is
 * one (Before, After or both), with its "Looks right" / "Not right yet" toggle, pins, title, summary and "Remove
 * screenshot", and "Add screenshot". A retaken screenshot shows its latest try with "Try 2 of 2" and a switch to its
 * earlier tries (ADR-0048); an earlier try is read-only but for marking its pins "Fixed on try N" or "Still wrong" and
 * "Remove this try". Each change is saved at once through RoundCommands' draft commands, in the request transaction: a
 * RoundRefused other than NOT_FOUND is shown on the page and saves nothing; NOT_FOUND is the round's 404.
 *
 * Every live request, each action included, first finds the ticket for the logged-in person (VisibleTicketLookup's
 * 404 when they may not see it) and asks RoundPermission VIEW and EDIT on the round: an unknown or malformed id, a
 * round of another ticket and a denied round are one 404, never 403, so another person's draft cannot be told from a
 * missing one. The first render comes from DraftRoundController's page, which made the same checks.
 */
#[AsLiveComponent(name: 'DraftRoundEditor', template: '@Review/components/DraftRoundEditor.html.twig', method: 'post')]
final class DraftRoundEditor
{
    use DefaultActionTrait;

    public const string NOTE_PLACEHOLDER = 'What is right or wrong here?';
    public const string EMPTY_NOTE = 'No screenshots yet. Add one here.';
    public const string PAGE_TITLE = 'Draft round';

    /** The pictures shown: the Before and the After side by side, or one of them. */
    public const string COMPARE_BOTH = 'both';
    public const string COMPARE_BEFORE = 'before';
    public const string COMPARE_AFTER = 'after';

    /**
     * Past the highest number a round can get (rvw_round.number is an INT), so the hand-off round before it is the
     * latest one shared: the round the draft would follow if it were shared now.
     */
    private const int NUMBER_AFTER_EVERY_ROUND = 2_147_483_647;

    /** The ticket's key, as the page's URL carries it. */
    #[LiveProp]
    public string $ticketKey = '';

    #[LiveProp]
    public string $roundId = '';

    /**
     * The earlier tries the owner switched to, at most one per chain; a chain with none here shows its latest try.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $shownTryIds = [];

    /** From 1, the screenshot whose tab is chosen; one past the last shows the last. */
    #[LiveProp]
    public int $shownNumber = 1;

    /** One of the COMPARE_ constants; a screenshot with no Before shows its picture alone whatever this is. */
    #[LiveProp]
    public string $compareMode = self::COMPARE_BOTH;

    /** The draft's card, read once per render. */
    private ?RoundCard $card = null;

    /** Why the last change was refused; nothing of it was saved. */
    public ?string $refusal = null;

    private ?VisibleTicket $visibleTicket = null;
    private bool $isCardRead = false;
    private ?RoundView $previousHandOffRound = null;
    private bool $isPreviousHandOffRoundRead = false;

    public function __construct(
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly RoundQueries $roundQueries,
        private readonly RoundCommands $roundCommands,
        private readonly RoundCards $roundCards,
        private readonly RoundsVisibleTo $roundsVisibleTo,
        private readonly SignedUrls $signedUrls,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundHttpException with RoundRefused::NOT_FOUND unless the logged-in person may view and change the
     *                               round, which must be of the ticket; with the ticket page's text for a ticket they
     *                               may not see
     */
    #[PostHydrate]
    public function denyUnlessThePersonMayEditTheRound(): void
    {
        $visibleTicket = $this->visibleTicketLookup->visibleTicketOf($this->ticketKey);
        $round = $this->roundQueries->accessFactsOf($this->roundId);
        if (null === $round
            || !$round->ticketId->equals($visibleTicket->ticket->id)
            || !$this->security->isGranted(RoundPermission::VIEW, $round)
            || !$this->security->isGranted(RoundPermission::EDIT, $round)) {
            throw new NotFoundHttpException(RoundRefused::NOT_FOUND);
        }
        $this->visibleTicket = $visibleTicket;
    }

    /**
     * @param string $mediaId the id presigned-upload:ready gave for a picture the person uploaded to this round
     */
    #[LiveAction]
    public function addScreenshot(#[LiveArg] string $mediaId): void
    {
        $this->change(fn (UserId $owner, \DateTimeImmutable $now): string => $this->roundCommands->addDraftScreenshot($this->roundId, $owner, $mediaId, $now));
        if (null === $this->refusal) {
            $this->shownNumber = \count($this->draftCard()->screenshots ?? []);
        }
    }

    /**
     * Shows the screenshot with that number, from 1; a number past the last shows the last. Changes nothing stored.
     *
     * @throws BadRequestHttpException when the number is not a whole number from 1 of at most nine digits
     */
    #[LiveAction]
    public function showScreenshot(#[LiveArg] string $number): void
    {
        $position = RoundController::positionOf($number);
        if (null === $position || 0 === $position) {
            throw new BadRequestHttpException('A screenshot number is not valid.');
        }
        $this->shownNumber = $position;
    }

    /**
     * @param string $mode one of the COMPARE_ constants; any other shows both
     */
    #[LiveAction]
    public function compare(#[LiveArg] string $mode): void
    {
        $this->compareMode = \in_array($mode, [self::COMPARE_BEFORE, self::COMPARE_AFTER], true) ? $mode : self::COMPARE_BOTH;
    }

    /**
     * Removes a screenshot, or one try of it: "Remove this try" on an earlier try sends that try's id.
     */
    #[LiveAction]
    public function removeScreenshot(#[LiveArg] string $screenshotId): void
    {
        $this->change(fn (UserId $owner, \DateTimeImmutable $now) => $this->roundCommands->removeDraftScreenshot($this->roundId, $owner, $screenshotId, $now));
    }

    /**
     * Shows one try of a screenshot in place of the one shown; the latest try is what a chain shows by default. Reads
     * the draft and changes nothing stored.
     */
    #[LiveAction]
    public function showTry(#[LiveArg] string $screenshotId): void
    {
        foreach ($this->draftCard()->screenshots ?? [] as $item) {
            $tryIds = array_map(static fn (RoundCardScreenshot $try): string => $try->screenshotId, $item->tries());
            if (!\in_array($screenshotId, $tryIds, true)) {
                continue;
            }
            $this->shownTryIds = array_values(array_diff($this->shownTryIds, $tryIds));
            if ($item->screenshotId !== $screenshotId) {
                $this->shownTryIds[] = $screenshotId;
            }

            return;
        }
        $this->refusal = RoundRefused::SCREENSHOT_NOT_FOUND;
    }

    /**
     * Marks a pin of an earlier try (ADR-0048).
     *
     * @param string $verdict VerdictCommands::PIN_FIXED, PIN_STILL_WRONG, or '' to clear it: a pressed chip sends '', so
     *                        pressing it again takes the mark back
     */
    #[LiveAction]
    public function setPinVerdict(#[LiveArg] string $pinId, #[LiveArg] string $verdict): void
    {
        $this->change(fn (UserId $owner, \DateTimeImmutable $now) => $this->roundCommands->setDraftPinVerdict($this->roundId, $owner, $pinId, '' === $verdict ? null : $verdict, $now));
    }

    /**
     * Saves both texts at once; a blank one is none.
     */
    #[LiveAction]
    public function editScreenshotText(#[LiveArg] string $screenshotId, #[LiveArg] string $title, #[LiveArg] string $summary): void
    {
        $this->change(fn (UserId $owner, \DateTimeImmutable $now) => $this->roundCommands->editDraftScreenshot($this->roundId, $owner, $screenshotId, $title, $summary, $now));
    }

    /**
     * @param string $verdict RoundCommands::VERDICT_LOOKS_RIGHT, VERDICT_NOT_RIGHT_YET, or '' to clear it: a pressed
     *                        chip sends '', so pressing it again takes the verdict back
     */
    #[LiveAction]
    public function setVerdict(#[LiveArg] string $screenshotId, #[LiveArg] string $verdict): void
    {
        $this->change(fn (UserId $owner, \DateTimeImmutable $now) => $this->roundCommands->setDraftVerdict($this->roundId, $owner, $screenshotId, '' === $verdict ? null : $verdict, $now));
    }

    /**
     * Puts the pins in place of the screenshot's, in this order. Only one pin past RoundCommands'
     * MAX_PINS_PER_SCREENSHOT is read, so a longer list is refused by the command before anything else is done with it.
     *
     * @param array<mixed> $pins each {x, y, note}: x and y in percent of the picture as numbers, note a string
     *
     * @throws BadRequestHttpException when a pin is not of that shape; nothing is saved
     */
    #[LiveAction]
    public function setPins(#[LiveArg] string $screenshotId, #[LiveArg] array $pins): void
    {
        $placedPins = array_map(self::placedPinOf(...), array_values(\array_slice($pins, 0, RoundCommands::MAX_PINS_PER_SCREENSHOT + 1)));
        $this->change(fn (UserId $owner, \DateTimeImmutable $now) => $this->roundCommands->setDraftPins($this->roundId, $owner, $screenshotId, $placedPins, $now));
    }

    /**
     * The draft as stored now, read once per render; a change reads it after it is saved. Null when the round is no
     * longer among the person's rounds of the ticket.
     */
    public function draftCard(): ?RoundCard
    {
        if (!$this->isCardRead && null === $this->card) {
            $visibleTicket = $this->visibleTicket();
            $draft = $this->roundQueries->draftOf($this->roundId, $visibleTicket->actor->userId());
            if (null !== $draft) {
                $this->card = $this->roundCards->forDraft($visibleTicket->visibility->organizationId(), $draft, $visibleTicket->ticket->turnSnapshot->assigneeId, $this->clock->now());
            }
        }
        $this->isCardRead = true;

        return $this->card;
    }

    /**
     * The picture with its pins, without the title and summary, which the card shows as fields.
     */
    public function pictureOf(RoundCardScreenshot $screenshot): ScreenshotView
    {
        $view = $screenshot->view;

        return new ScreenshotView($view->domId, $view->imageUrl, $view->width, $view->height, $view->altText, null, null, $view->pins, $view->status);
    }

    /**
     * The try of the screenshot the owner switched to, or its latest.
     */
    public function shownTryOf(RoundCardScreenshot $screenshot): RoundCardScreenshot
    {
        foreach ($screenshot->earlierTries as $earlierTry) {
            if (\in_array($earlierTry->screenshotId, $this->shownTryIds, true)) {
                return $earlierTry;
            }
        }

        return $screenshot;
    }

    /**
     * From 1, the screenshot shown: the chosen one, or the last when the chosen one was removed. 0 for a draft with none.
     */
    public function shownPosition(): int
    {
        return min($this->shownNumber, \count($this->draftCard()->screenshots ?? []));
    }

    /** The chain whose tab is chosen, as its latest try; null for a draft with no screenshot. */
    public function shownScreenshot(): ?RoundCardScreenshot
    {
        return ($this->draftCard()->screenshots ?? [])[$this->shownPosition() - 1] ?? null;
    }

    /**
     * The shown screenshot's Before: the latest try of the screenshot with its number in the hand-off round shared last,
     * or the report when none was (RoundPages::beforeScreenshotOf), signed now; null when that round has no screenshot
     * with its number or the ticket has neither.
     */
    public function before(): ?ScreenshotView
    {
        $number = $this->shownPosition();
        $previousRound = $this->previousHandOffRound();
        $beforeScreenshot = RoundPages::beforeScreenshotOf($previousRound, $number);
        if (null === $previousRound || null === $beforeScreenshot) {
            return null;
        }
        $visibleTicket = $this->visibleTicket();
        $images = $this->signedUrls->forVariants($visibleTicket->visibility->organizationId(), [$beforeScreenshot->mediaId], RoundCards::VARIANT, $this->clock->now());

        return RoundCards::screenshotViewOf($previousRound, $beforeScreenshot, $number, $images);
    }

    /** "Before · round 2"; null when there is no round before the draft. */
    public function beforeCaption(): ?string
    {
        $previousRound = $this->previousHandOffRound();

        return null === $previousRound ? null : \sprintf('Before · round %d', $previousRound->number ?? 0);
    }

    public function ticketTitle(): string
    {
        return $this->visibleTicket()->ticket->title;
    }

    public function draftCsrfId(): string
    {
        return DraftRoundController::csrfIdOf($this->ticketKey);
    }

    public function uploadTargetKind(): string
    {
        return RoundMediaTarget::KIND;
    }

    public function titleMaxLength(): int
    {
        return ScreenshotView::TITLE_MAX_LENGTH;
    }

    public function summaryMaxLength(): int
    {
        return ScreenshotView::SUMMARY_MAX_LENGTH;
    }

    public function noteMaxLength(): int
    {
        return PinView::NOTE_MAX_LENGTH;
    }

    /**
     * @param \Closure(UserId, \DateTimeImmutable): mixed $command
     *
     * @throws NotFoundHttpException for RoundRefused::NOT_FOUND
     */
    private function change(\Closure $command): void
    {
        $owner = ($this->visibleTicket ?? throw new \LogicException('A change runs only after the round was checked.'))->actor->userId();
        try {
            $command($owner, $this->clock->now());
        } catch (RoundRefused $refused) {
            if (RoundRefused::NOT_FOUND === $refused->getMessage()) {
                throw new NotFoundHttpException(RoundRefused::NOT_FOUND, $refused);
            }
            $this->refusal = $refused->getMessage();
        }
        $this->forgetShownTriesThatAreNoLongerEarlier();
    }

    /**
     * A removed try, or one that became the latest when the try after it was removed, leaves the switch; otherwise a
     * later retake would open on it. Reads the draft as stored now, which the render then reuses.
     */
    private function forgetShownTriesThatAreNoLongerEarlier(): void
    {
        $earlierTryIds = [];
        foreach ($this->draftCard()->screenshots ?? [] as $item) {
            foreach ($item->earlierTries as $earlierTry) {
                $earlierTryIds[] = $earlierTry->screenshotId;
            }
        }
        $this->shownTryIds = array_values(array_intersect($this->shownTryIds, $earlierTryIds));
    }

    private function visibleTicket(): VisibleTicket
    {
        return $this->visibleTicket ??= $this->visibleTicketLookup->visibleTicketOf($this->ticketKey);
    }

    /** Read once per render, and only for a draft with a screenshot to pair. */
    private function previousHandOffRound(): ?RoundView
    {
        if (!$this->isPreviousHandOffRoundRead && 0 !== $this->shownPosition()) {
            $visibleTicket = $this->visibleTicket();
            $this->previousHandOffRound = $this->roundsVisibleTo->previousHandOffRoundOf($visibleTicket->ticket->id, $visibleTicket->visibility, self::NUMBER_AFTER_EVERY_ROUND);
            $this->isPreviousHandOffRoundRead = true;
        }

        return $this->previousHandOffRound;
    }

    /**
     * @throws BadRequestHttpException when the pin is not {x: number, y: number, note: string}
     */
    private static function placedPinOf(mixed $pin): PlacedPin
    {
        $xPercent = \is_array($pin) ? ($pin['x'] ?? null) : null;
        $yPercent = \is_array($pin) ? ($pin['y'] ?? null) : null;
        $note = \is_array($pin) ? ($pin['note'] ?? null) : null;
        if (!(\is_int($xPercent) || \is_float($xPercent)) || !(\is_int($yPercent) || \is_float($yPercent)) || !\is_string($note)) {
            throw new BadRequestHttpException('A pin is not valid.');
        }

        return new PlacedPin((float) $xPercent, (float) $yPercent, $note);
    }
}
