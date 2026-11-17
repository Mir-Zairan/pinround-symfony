<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use App\Identity\Api\CurrentTimeZone;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\Api\VerdictPermission;
use App\Review\UI\RoundPage\FocusPage;
use App\Review\UI\RoundPage\FocusPages;
use App\Review\UI\RoundPage\RoundPages;
use App\Review\UI\RoundsController;
use App\Review\UI\VisibleTicket;
use App\Review\UI\VisibleTicketLookup;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One shared round of a ticket as a page of its own (P05.T7, review-round.md): the header, a tab per screen area, the
 * earlier and this round's pictures with their pins, and the side panel, with the verdict forms VerdictController
 * answers for a person VerdictPermission SET grants. The viewer is only ever the logged-in person; "?area=N" picks the
 * tab by its position.
 *
 * Runs in the request transaction. An unknown or malformed key or number, another organization's ticket, a ticket the
 * viewer may not see and a round RoundPermission VIEW denies all answer the same 404, so no answer tells that a hidden
 * ticket or someone's private draft exists. The number only matches digits, so a malformed one never reaches here.
 */
final class RoundController extends AbstractController
{
    public const string ROUND_ROUTE = 'review_round';
    public const string FOCUS_ROUTE = 'review_round_focus';
    public const string AREA_PARAMETER = 'area';
    /** The full-screen review's picture: FocusPage::SIDE_BEFORE or SIDE_AFTER. */
    public const string SIDE_PARAMETER = 'side';
    /** The full-screen review's pin shown first, from 1. */
    public const string PIN_PARAMETER = 'pin';

    public const string NOT_FOUND = 'No round you may see has that number.';

    public function __construct(
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

        return $this->render('@Review/round.html.twig', [
            'page' => $this->roundPages->pageOf(
                $ticket,
                $round,
                self::numberParameterOf($request, self::AREA_PARAMETER) ?? 0,
                $this->isGranted(VerdictPermission::SET, $facts),
                $visible->actor->userId(),
                $this->clock->now(),
                $this->currentTimeZone->zone(),
            ),
            'ticketRoute' => RoundsController::TICKET_ROUTE,
            'ticketKey' => $ticket->key,
            'verdictCsrfId' => VerdictController::csrfIdOf($ticket->key),
            'roundNumber' => $round->number,
            'areaParameter' => self::AREA_PARAMETER,
            'focusRoute' => self::FOCUS_ROUTE,
        ]);
    }

    /**
     * The full-screen review of one area (P05.T10, full-screen-review.md), with the round page's access: "?area=N" picks
     * the area, "?side=before" the earlier picture and "?pin=N" the pin shown first, from 1. Previous, Next and the
     * After/Before switch are links, and Fixed, Still wrong and Undo plain forms that VerdictController answers with this
     * page again, so the walk works without JavaScript; pin-walker switches pins in place and adds the keys.
     */
    #[Route('/t/{key}/rounds/{number}/focus', name: self::FOCUS_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['GET'])]
    public function focus(string $key, int $number, Request $request): Response
    {
        [$visible, $round, $facts] = $this->visibleRoundOf($key, $number);
        $ticket = $visible->ticket;
        $side = $request->query->all()[self::SIDE_PARAMETER] ?? null;

        return $this->render('@Review/focus.html.twig', [
            'page' => $this->focusPages->pageOf(
                $ticket,
                $round,
                self::numberParameterOf($request, self::AREA_PARAMETER) ?? 0,
                \is_string($side) ? $side : FocusPage::SIDE_AFTER,
                self::numberParameterOf($request, self::PIN_PARAMETER) ?? 1,
                $this->isGranted(VerdictPermission::SET, $facts),
                $this->clock->now(),
            ),
            // The full-screen review always uses dark colours, whatever the person's theme (base.html.twig).
            'theme' => 'dark',
            'ticketKey' => $ticket->key,
            'roundNumber' => $round->number,
            'verdictCsrfId' => VerdictController::csrfIdOf($ticket->key),
            'areaParameter' => self::AREA_PARAMETER,
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
     * The query parameter as a whole number; null when it is missing or malformed, which each page reads as its first.
     */
    private static function numberParameterOf(Request $request, string $name): ?int
    {
        // all(), not get(): "?area[]=1" is a 400 from get().
        $value = $request->query->all()[$name] ?? null;

        return \is_string($value) && 1 === preg_match('/\A\d{1,9}\z/', $value) ? (int) $value : null;
    }
}
