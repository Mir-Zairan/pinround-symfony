<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\UI\RoundPage\RoundPages;
use App\Review\UI\RoundsController;
use App\Review\UI\VisibleTicketLookup;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One shared round of a ticket as a page of its own (P05.T7, review-round.md), read-only for now: the header, a tab per
 * screen area, the earlier and this round's pictures with their pins, and the earlier pins with their verdicts. The
 * viewer is only ever the logged-in person; "?area=N" picks the tab by its position.
 *
 * Runs in the request transaction. An unknown or malformed key or number, another organization's ticket, a ticket the
 * viewer may not see and a round RoundPermission VIEW denies all answer the same 404, so no answer tells that a hidden
 * ticket or someone's private draft exists. The number only matches digits, so a malformed one never reaches here.
 */
final class RoundController extends AbstractController
{
    public const string ROUND_ROUTE = 'review_round';
    public const string AREA_PARAMETER = 'area';

    private const string NOT_FOUND = 'No round you may see has that number.';

    public function __construct(
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly ReviewRoundQueries $reviewRoundQueries,
        private readonly RoundPages $roundPages,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/t/{key}/rounds/{number}', name: self::ROUND_ROUTE, requirements: ['number' => '\d{1,9}'], methods: ['GET'])]
    public function round(string $key, int $number, Request $request): Response
    {
        $visible = $this->visibleTicketLookup->visibleTicketOf($key);
        $ticket = $visible->ticket;
        $round = $this->reviewRoundQueries->roundOf($ticket->id, $number, $visible->actor->userId()) ?? throw new NotFoundHttpException(self::NOT_FOUND);

        $facts = new RoundAccessFacts($visible->actor->organizationId(), $round->id, $ticket->id, $round->ownerId, false, false);
        if (!$this->isGranted(RoundPermission::VIEW, $facts)) {
            throw new NotFoundHttpException(self::NOT_FOUND);
        }

        // all(), not get(): "?area[]=1" is a 400 from get(), and any malformed area is just the first tab.
        $area = $request->query->all()[self::AREA_PARAMETER] ?? null;
        $selectedPosition = \is_string($area) && 1 === preg_match('/\A\d{1,9}\z/', $area) ? (int) $area : 0;

        return $this->render('@Review/round.html.twig', [
            'page' => $this->roundPages->pageOf($ticket, $round, $selectedPosition, $this->clock->now()),
            'ticketRoute' => RoundsController::TICKET_ROUTE,
            'roundNumber' => $round->number,
            'areaParameter' => self::AREA_PARAMETER,
        ]);
    }
}
