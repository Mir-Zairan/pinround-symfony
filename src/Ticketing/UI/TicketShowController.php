<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\CurrentTimeZone;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketPosition;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Application\RoleTicketVisibilities;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One ticket's page at /t/{key} (FR-TKT-08, P03.T6, ticket-page.md): the header, The ask, the Rounds section's lazy frame,
 * the "Your next step" box of a ticket a team owns (its verbs and move list only for someone TicketPermission::MOVE lets
 * move it) and Details. Every person of the organization may ask for it (OrganizationPermission::VIEW); TicketQueries::pageOf reads
 * only a ticket the viewer may see, and TicketPermission VIEW on its visibility facts is the second guard. An unknown
 * or malformed key, another organization's ticket (row-level security hides it) and one the viewer may not see all
 * answer the same 404, so the answer never tells that a hidden ticket exists.
 *
 * The list a row's link came from travels in the list's own query (TicketListContext): "N of M" with Previous and Next
 * walk that list (TicketQueries::positionOf). With no list, a filter the list would refuse, or a ticket that is not in
 * it, they walk the plain open-tickets list; a Done ticket in neither has no position line. A ticket in the viewer's
 * queue (TicketQueue, FR-TKT-15) shows the QueueBar instead, which walks the queue, and costs no position query.
 *
 * Six queries beyond the request's own when the steps, rules and team's people are cached, seven for a lead whose move
 * list offers Needs input (performance-and-scale.md: ten at most for the page): the settings (step policy and
 * triager, one query), the ticket with its project and parent, the names of everyone the page shows in one batch, the
 * saved turn with the waiting turns of {@see \App\Ticketing\Application\SavedTicketPageTurns} in one, the latest moves of What happened in one
 * ({@see TicketPageParts}), and the position in one; the lead's Needs input dialog adds its membership read, and a miss
 * of the team's people adds two. A list's project adds its lookup; a list the ticket is not in adds the plain list's
 * position. The thread and the Rounds section load in lazy frames of their own requests (ThreadController,
 * RoundsController), so the page reads none of them.
 */
final class TicketShowController extends AbstractController
{
    public const string ROUTE = 'ticket_show';
    public const string NOT_FOUND = 'No ticket you may see has that key.';

    public const string NOBODY = 'Nobody';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly ListTurnTickets $listTurnTickets,
        private readonly TicketQueries $ticketQueries,
        private readonly ClockInterface $clock,
        private readonly CurrentTimeZone $currentTimeZone,
        private readonly ProjectQueries $projectQueries,
        private readonly TicketPageParts $ticketPageParts,
    ) {
    }

    #[Route('/t/{key}', name: self::ROUTE, methods: ['GET'])]
    public function show(string $key, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $visibility = $this->ticketVisibilities->ofActor($actor);
        $ticket = $this->ticketQueries->pageOf($key, $visibility);
        if (null === $ticket || !$this->isGranted(TicketPermission::VIEW, $ticket->visibilityFacts)) {
            throw $this->createNotFoundException(self::NOT_FOUND);
        }
        $listContext = TicketListContext::fromRequestOrPlain($request, $actor, $this->projectQueries);
        $queue = TicketQueue::of($request->getSession());
        $queueBar = null === $queue ? null : $this->queueBarOf($queue, $ticket->key, $listContext);
        $position = null;
        $positionContext = $listContext;
        if (null === $queueBar) {
            $position = $this->ticketQueries->positionOf($ticket->key, $listContext->filterFor($visibility, $this->listTurnTickets->of($listContext, $actor->userId())));
            if (null === $position && !$listContext->isPlainList()) {
                $positionContext = new TicketListContext();
                $position = $this->ticketQueries->positionOf($ticket->key, $positionContext->filterFor($visibility));
            }
        }

        $now = $this->clock->now();
        $viewerTimeZone = $this->currentTimeZone->zone();

        return $this->render('@Ticketing/show.html.twig', [
            ...$this->ticketPageParts->of($ticket, $actor),
            'backQuery' => $listContext->query(),
            'queueBar' => $queueBar,
            'position' => null === $position ? null : [
                'line' => self::positionLineOf($position),
                'previousUrl' => null === $position->previousKey ? null : $this->generateUrl(self::ROUTE, ['key' => $position->previousKey, ...$positionContext->query()]),
                'nextUrl' => null === $position->nextKey ? null : $this->generateUrl(self::ROUTE, ['key' => $position->nextKey, ...$positionContext->query()]),
            ],
            'type' => ucfirst($ticket->type),
            'priority' => ucfirst($ticket->priority),
            'created' => TicketListText::dateOf($ticket->createdAt, $now, $viewerTimeZone),
            'assignee' => null === $ticket->visibilityFacts->assigneeId ? self::NOBODY : ($ticket->assigneeName ?? ucfirst(PersonNames::UNKNOWN_PERSON)),
            'components' => implode(', ', $ticket->components),
        ]);
    }

    /**
     * "3 of 61", with the list's capped counts: "1,000+" for a total past a step's cap, "1,003+" for a place past one.
     */
    public static function positionLineOf(TicketPosition $position): string
    {
        return TicketListText::countOf($position->index, $position->isIndexCapped).' of '.TicketListText::countOf($position->total, $position->isTotalCapped);
    }

    /**
     * The QueueBar's props for a ticket in the viewer's queue; Previous and Next keep the list query so Stop and Back
     * return to the list the queue started from.
     *
     * Null when the queue does not hold the ticket.
     *
     * @return array{label: string, place: int, count: int, previousUrl: string|null, nextUrl: string|null, finishUrl: string, stopUrl: string, csrfId: string}|null
     */
    private function queueBarOf(TicketQueue $queue, string $ticketKey, TicketListContext $listContext): ?array
    {
        $place = $queue->placeOf($ticketKey);
        if (null === $place) {
            return null;
        }
        $previousKey = $queue->previousKeyOf($ticketKey);
        $nextKey = $queue->nextKeyOf($ticketKey);

        return [
            'label' => $queue->label,
            'place' => $place,
            'count' => $queue->count(),
            'previousUrl' => null === $previousKey ? null : $this->generateUrl(self::ROUTE, ['key' => $previousKey, ...$listContext->query()]),
            'nextUrl' => null === $nextKey ? null : $this->generateUrl(self::ROUTE, ['key' => $nextKey, ...$listContext->query()]),
            'finishUrl' => $this->generateUrl(QueueController::FINISH_ROUTE),
            'stopUrl' => $this->generateUrl(QueueController::STOP_ROUTE, ['key' => $ticketKey, ...$listContext->query()]),
            'csrfId' => QueueController::CSRF_ID,
        ];
    }
}
