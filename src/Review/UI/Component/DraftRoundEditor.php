<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundRefused;
use App\Review\UI\DraftRoundController;
use App\Review\UI\Media\RoundMediaTarget;
use App\Review\UI\PinView;
use App\Review\UI\RoundCard;
use App\Review\UI\RoundCards;
use App\Review\UI\RoundCardScreenshot;
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
 * The owner's unshared draft round on the ticket page (P05.T8 step 2, ADR-0017, ticket-page.md "Draft round"): its
 * card with the screenshots, each with its "Looks right" / "Not right yet" toggle, title, note (the summary), pins and
 * "Remove screenshot", "k of n look right", and "Add screenshot". Each change is saved at once through RoundCommands'
 * draft commands, in the request transaction: a RoundRefused other than NOT_FOUND is shown in the card and saves
 * nothing; NOT_FOUND is the round's 404.
 *
 * Every live request, each action included, first finds the ticket for the logged-in person (VisibleTicketLookup's
 * 404 when they may not see it) and asks RoundPermission VIEW and EDIT on the round: an unknown or malformed id, a
 * round of another ticket and a denied round are one 404, never 403, so another person's draft cannot be told from a
 * missing one. The first render, from the Rounds frame, takes the card the frame read and reads nothing itself.
 */
#[AsLiveComponent(name: 'DraftRoundEditor', template: '@Review/components/DraftRoundEditor.html.twig', method: 'post')]
final class DraftRoundEditor
{
    use DefaultActionTrait;

    public const string NOTE_PLACEHOLDER = 'What is right or wrong here?';
    public const string EMPTY_NOTE = 'No screenshots yet. Add one here.';

    /** The ticket's key, as the page's URL carries it. */
    #[LiveProp]
    public string $ticketKey = '';

    #[LiveProp]
    public string $roundId = '';

    /** The draft's card as the Rounds frame read it; null on a live request, which reads it again after its change. */
    public ?RoundCard $card = null;

    /** Why the last change was refused; nothing of it was saved. */
    public ?string $refusal = null;

    private ?VisibleTicket $visibleTicket = null;
    private bool $isCardRead = false;

    public function __construct(
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly RoundQueries $roundQueries,
        private readonly RoundCommands $roundCommands,
        private readonly RoundCards $roundCards,
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
    }

    #[LiveAction]
    public function removeScreenshot(#[LiveArg] string $screenshotId): void
    {
        $this->change(fn (UserId $owner, \DateTimeImmutable $now) => $this->roundCommands->removeDraftScreenshot($this->roundId, $owner, $screenshotId, $now));
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
     * The card read once per render: the one the frame gave, or the draft as stored now. Null when the round is no
     * longer among the person's rounds of the ticket.
     */
    public function draftCard(): ?RoundCard
    {
        if (!$this->isCardRead && null === $this->card) {
            $visibleTicket = $this->visibleTicket ??= $this->visibleTicketLookup->visibleTicketOf($this->ticketKey);
            $section = $this->roundCards->forTicket($visibleTicket->ticket->id, $visibleTicket->visibility, $this->clock->now());
            foreach ($section->cards as $card) {
                if (strtolower($this->roundId) === $card->roundId) {
                    $this->card = $card;
                }
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
