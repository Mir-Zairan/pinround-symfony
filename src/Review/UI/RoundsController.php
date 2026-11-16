<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Review\Api\RoundPermission;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A ticket's rounds (P05.T4, ticket-page.md, Rounds): Round 0 with its screenshots and pins, the newest numbered rounds,
 * shared drafts and the viewer's own draft. A Turbo Frame request, from RoundsSection's lazy frame, gets the bare frame;
 * any other request the rounds as a page of their own. The viewer is only ever the logged-in person.
 *
 * Runs in the request transaction, as the visibility predicate and the rounds are read under row-level security. An
 * unknown or malformed key, another organization's ticket and a ticket the viewer may not see (TicketPermission VIEW
 * on its visibility facts, after TicketQueries::pageOf reads only what the viewer may see) all answer the same 404 as
 * the ticket's page, so no answer tells that a hidden ticket exists. "Start a draft round" shows only when
 * RoundPermission DRAFT_CREATE grants it and the viewer has no unshared draft shown; DraftRoundController takes its
 * post and the own draft's Share and Delete.
 */
final class RoundsController extends AbstractController
{
    public const string ROUNDS_ROUTE = 'review_rounds';

    /** TicketShowController's route and not-found text, which Review's UI may not import (deptrac); RoundsFrameTest keeps them the same. */
    public const string TICKET_ROUTE = 'ticket_show';
    public const string NOT_FOUND = 'No ticket you may see has that key.';

    /** The header Turbo sends when it loads a frame, with the frame's id. */
    private const string TURBO_FRAME_HEADER = 'Turbo-Frame';

    public function __construct(
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly RoundCards $roundCards,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/t/{key}/rounds', name: self::ROUNDS_ROUTE, methods: ['GET'])]
    public function rounds(string $key, Request $request): Response
    {
        $visible = $this->visibleTicketLookup->visibleTicketOf($key);
        $ticket = $visible->ticket;

        // The voter runs whether or not the viewer has a draft, so the frame reads the same queries either way.
        $mayStartDraft = $this->isGranted(RoundPermission::DRAFT_CREATE, $ticket->id);
        $section = $this->roundCards->forTicket($ticket->id, $visible->visibility, $this->clock->now());

        $response = $this->render(
            $request->headers->has(self::TURBO_FRAME_HEADER) ? '@Review/rounds_frame.html.twig' : '@Review/rounds.html.twig',
            [
                'ticketKey' => $ticket->key,
                'section' => $section,
                'canStartDraft' => $mayStartDraft && !$section->hasPrivateDraft(),
                'draftCsrfId' => DraftRoundController::csrfIdOf($ticket->key),
            ],
        );
        $response->setVary(self::TURBO_FRAME_HEADER);

        return $response;
    }
}
