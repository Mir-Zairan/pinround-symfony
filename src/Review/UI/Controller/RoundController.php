<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use App\Identity\Api\CurrentTimeZone;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\Api\VerdictPermission;
use App\Review\Application\OpenSharedRound;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\RoundPage\FocusPage;
use App\Review\UI\RoundPage\FocusPages;
use App\Review\UI\RoundPage\RoundPages;
use App\Review\UI\RoundsController;
use App\Review\UI\VisibleTicket;
use App\Review\UI\VisibleTicketLookup;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\MoveOnVerbQueries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One shared round of a ticket, of any kind, as a page of its own (P05.T7, P14.T8, review-round.md): the header, a strip
 * of its screenshots, one shown with its pins and, for a review round's screen area, the earlier round's picture and the
 * side panel with the verdict forms VerdictController answers for a person VerdictPermission SET grants. The viewer is
 * only ever the logged-in person; "?area=N" picks a screen area by its position, and "?screenshot=N", from 1, the
 * screenshot of a round without areas. On an open hand-off round the person whose turn it is also gets the ticket's
 * move-on verbs (MoveOnVerbQueries), which post to the ticket page's move and so close the round (ADR-0048), unless
 * their move would leave it open (OpenSharedRound::isClosedByMoveOf), as the owner's of a round shared with "Share now".
 *
 * Runs in the request transaction. An unknown or malformed key or number, another organization's ticket, a ticket the
 * viewer may not see and a round RoundPermission VIEW denies all answer the same 404, so no answer tells that a hidden
 * ticket or someone's private draft exists. The number only matches digits, so a malformed one never reaches here. A
 * "?screenshot=N" that is malformed or names no screenshot of the round is a 404 too, while an unknown "?area=N" shows
 * the first area.
 */
final class RoundController extends AbstractController
{
    public const string ROUND_ROUTE = 'review_round';
    public const string FOCUS_ROUTE = 'review_round_focus';
    public const string AREA_PARAMETER = 'area';
    /** From 1, the screenshot a round without screen areas shows. */
    public const string SCREENSHOT_PARAMETER = 'screenshot';
    /** The full-screen review's picture: FocusPage::SIDE_BEFORE or SIDE_AFTER. */
    public const string SIDE_PARAMETER = 'side';
    /** The full-screen review's pin shown first, from 1. */
    public const string PIN_PARAMETER = 'pin';
    /** With RETURN_TO_TICKET: the full-screen review was opened from the ticket page, which its exit goes back to. */
    public const string RETURN_PARAMETER = 'from';
    public const string RETURN_TO_TICKET = 'ticket';
    /** The element id of the round page's Full screen link, which the full-screen review's way back names. */
    public const string FULL_SCREEN_CONTROL_ID = 'round-full-screen';

    public const string NOT_FOUND = 'No round you may see has that number.';
    public const string SCREENSHOT_NOT_FOUND = 'This round has no screenshot with that number.';

    public function __construct(
        private readonly MoveOnVerbQueries $moveOnVerbQueries,
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly ReviewRoundQueries $reviewRoundQueries,
        private readonly RoundPages $roundPages,
        private readonly FocusPages $focusPages,
        private readonly ClockInterface $clock,
        private readonly CurrentTimeZone $currentTimeZone,
    ) {
    }

    #[Route('/t/{key}/rounds/{number}', name: self::ROUND_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['GET'])]
    public function round(string $key, int $number, Request $request): Response
    {
        [$visible, $round, $facts] = $this->visibleRoundOf($key, $number);
        $ticket = $visible->ticket;
        $page = $this->roundPages->pageOf(
            $visible,
            $round,
            self::numberParameterOf($request, self::AREA_PARAMETER),
            self::screenshotNumberOf($request),
            $this->isGranted(VerdictPermission::SET, $facts),
            $this->clock->now(),
            $this->currentTimeZone->zone(),
        ) ?? throw new NotFoundHttpException(self::SCREENSHOT_NOT_FOUND);

        return $this->render('@Review/round.html.twig', [
            'page' => $page,
            'ticketRoute' => RoundsController::TICKET_ROUTE,
            'roundRoute' => self::ROUND_ROUTE,
            'ticketKey' => $ticket->key,
            'verdictCsrfId' => VerdictController::csrfIdOf($ticket->key),
            'roundNumber' => $round->number,
            'focusRoute' => self::FOCUS_ROUTE,
            'fullScreenControlId' => self::FULL_SCREEN_CONTROL_ID,
            'moveOnForm' => self::isClosedByMoveOnOf($visible->actor->userId(), $round, $ticket->turnSnapshot->assigneeId) ? $this->moveOnVerbQueries->moveOnFormOf($ticket) : null,
        ]);
    }

    /**
     * Whether a move-on verb of $viewerId would close the round (ADR-0048), were it their turn: MoveOnVerbQueries
     * gives verbs only to the person whose turn it is. False for Round 0, the checklist round and a closed round, and
     * for the owner of a round shared with "Share now", whose own move leaves it open. Private: the container makes
     * a public controller method's class-typed parameters action arguments, and UserId is no service.
     *
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody has it
     */
    private static function isClosedByMoveOnOf(UserId $viewerId, ReviewRoundView $round, ?UserId $assigneeId): bool
    {
        return true === OpenSharedRound::ofReviewRound($round)?->isClosedByMoveOf($viewerId, true, $assigneeId);
    }

    /**
     * The full-screen review of one round of any kind (P05.T10, P14.T8, full-screen-review.md), with the round page's
     * access: "?area=N" picks a screen area, "?screenshot=N" the screenshot of a round without areas, as on the round
     * page, "?side=before" an area's earlier picture and "?pin=N" the pin shown first, from 1. Previous, Next, the
     * screenshots' Back and Next and the After/Before switch are links, and Fixed, Still wrong and Undo plain forms that
     * VerdictController answers with this page again, so the walk works without JavaScript; pin-walker switches pins in
     * place and adds the keys.
     *
     * Exit full screen, and Escape, go back to the round page on the same picture, or, with "?from=ticket", to the ticket
     * page's Rounds, naming in the address's fragment where the Full screen link that opened it is, so focus returns to
     * it (fragment-focus).
     */
    #[Route('/t/{key}/rounds/{number}/focus', name: self::FOCUS_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['GET'])]
    public function focus(string $key, int $number, Request $request): Response
    {
        [$visible, $round, $facts] = $this->visibleRoundOf($key, $number);
        $ticket = $visible->ticket;
        $side = $request->query->all()[self::SIDE_PARAMETER] ?? null;
        $page = $this->focusPages->pageOf(
            $visible,
            $round,
            self::numberParameterOf($request, self::AREA_PARAMETER) ?? 0,
            self::screenshotNumberOf($request),
            \is_string($side) ? $side : FocusPage::SIDE_AFTER,
            self::numberParameterOf($request, self::PIN_PARAMETER) ?? 1,
            $this->isGranted(VerdictPermission::SET, $facts),
            $this->clock->now(),
        ) ?? throw new NotFoundHttpException(self::SCREENSHOT_NOT_FOUND);
        $isFromTicket = self::RETURN_TO_TICKET === ($request->query->all()[self::RETURN_PARAMETER] ?? null);

        return $this->render('@Review/focus.html.twig', [
            'page' => $page,
            // The full-screen review always uses dark colours, whatever the person's theme (base.html.twig).
            'theme' => 'dark',
            'ticketKey' => $ticket->key,
            'roundNumber' => $round->number,
            'verdictCsrfId' => VerdictController::csrfIdOf($ticket->key),
            'exitHref' => $isFromTicket
                // The Rounds frame, not the link inside it: the frame loads only once it is scrolled into view.
                ? $this->generateUrl(RoundsController::TICKET_ROUTE, ['key' => $ticket->key, '_fragment' => RoundsSection::FRAME_ID])
                : $this->generateUrl(self::ROUND_ROUTE, ['key' => $ticket->key, 'number' => $round->number, '_fragment' => self::FULL_SCREEN_CONTROL_ID] + $page->shownParameters),
            'returnParameters' => $isFromTicket ? [self::RETURN_PARAMETER => self::RETURN_TO_TICKET] : [],
            'sideParameter' => self::SIDE_PARAMETER,
            'pinParameter' => self::PIN_PARAMETER,
        ]);
    }

    /**
     * Runs in the request transaction.
     *
     * @return array{VisibleTicket, ReviewRoundView, RoundAccessFacts}
     *
     * @throws NotFoundHttpException for a ticket the viewer may not see, a number it has no round of, and a round
     *                               RoundPermission VIEW denies
     */
    private function visibleRoundOf(string $key, int $number): array
    {
        $visible = $this->visibleTicketLookup->visibleTicketOf($key);
        $round = $this->reviewRoundQueries->roundOf($visible->ticket->id, $number, $visible->actor->userId()) ?? throw new NotFoundHttpException(self::NOT_FOUND);

        $facts = new RoundAccessFacts($visible->actor->organizationId(), $round->id, $visible->ticket->id, $round->ownerId, false, false);
        if (!$this->isGranted(RoundPermission::VIEW, $facts)) {
            throw new NotFoundHttpException(self::NOT_FOUND);
        }

        return [$visible, $round, $facts];
    }

    /**
     * A query or form value as a whole number of at most nine digits; null when it is missing or anything else, which
     * each page reads as its first area or pin.
     */
    public static function positionOf(mixed $value): ?int
    {
        return \is_string($value) && 1 === preg_match('/\A\d{1,9}\z/', $value) ? (int) $value : null;
    }

    /**
     * "?screenshot=N" from 1, or null when it is missing.
     *
     * @throws NotFoundHttpException when it is there but not a whole number of at most nine digits
     */
    private static function screenshotNumberOf(Request $request): ?int
    {
        $value = $request->query->all()[self::SCREENSHOT_PARAMETER] ?? null;
        $number = self::positionOf($value);
        if (null !== $value && null === $number) {
            throw new NotFoundHttpException(self::SCREENSHOT_NOT_FOUND);
        }

        return $number;
    }

    private static function numberParameterOf(Request $request, string $name): ?int
    {
        // all(), not get(): "?area[]=1" is a 400 from get().
        return self::positionOf($request->query->all()[$name] ?? null);
    }
}
