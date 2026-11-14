<?php

declare(strict_types=1);

namespace App\Notification\UI\Controller;

use App\Notification\Application\Realtime\RealtimeSubscriptions;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /realtime/token (realtime-and-notifications.md, Mercure topics): renews the subscriber cookie of a signed-in
 * member, for their own topic and each asked ticket that TicketPermission::VIEW still lets them see. The session and
 * the membership are checked again on every renewal, so after logout or removal the cookie runs out within
 * RealtimeSubscriptions::LIFETIME_SECONDS. A malformed, unknown, other organization's or hidden ticket id is left out
 * the same way, so the answer never tells that a hidden ticket exists.
 *
 * No form token: the answer is a cookie for the caller's own browser, which a cross-site post cannot read, and it
 * grants no more than a same-site renewal would.
 */
final class RealtimeTokenController extends AbstractController
{
    public const string ROUTE = 'notification_realtime_token';

    /** The form field with the ticket ids, as ticketIds[]. */
    public const string TICKET_IDS = 'ticketIds';

    /**
     * Each ticket topic adds about 100 bytes to the cookie's token; ten keep it well under the 4 KB browsers accept,
     * and one page follows one ticket.
     */
    public const int MAX_TICKETS = 10;

    public const string TOO_MANY_TICKETS = 'Ask for live updates of at most 10 tickets at a time.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly RealtimeSubscriptions $realtimeSubscriptions,
    ) {
    }

    #[Route('/realtime/token', name: self::ROUTE, methods: ['POST'])]
    public function renew(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $askedTicketIds = $request->request->all(self::TICKET_IDS);
        if (\count($askedTicketIds) > self::MAX_TICKETS) {
            return new Response(self::TOO_MANY_TICKETS, Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $viewableTicketIds = [];
        foreach ($askedTicketIds as $askedTicketId) {
            $ticketId = self::ticketIdOrNull($askedTicketId);
            if (null === $ticketId || isset($viewableTicketIds[$ticketId->toString()])) {
                continue;
            }
            $ticket = $this->ticketTurnQueries->factsOf($ticketId);
            if (null === $ticket) {
                continue;
            }
            $visibilityFacts = new TicketVisibilityFacts(
                $actor->organizationId(),
                $ticket->visibility,
                $ticket->teamId,
                $ticket->reporterId,
                $ticket->assigneeId,
                $ticket->pendingAssigneeId,
                $ticket->reviewerId,
                $ticket->testerId,
                $ticket->waitingOnUserId,
            );
            if ($this->isGranted(TicketPermission::VIEW, $visibilityFacts)) {
                $viewableTicketIds[$ticketId->toString()] = $ticketId;
            }
        }

        $this->realtimeSubscriptions->grant($actor->organizationId(), $actor->userId(), ...array_values($viewableTicketIds));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private static function ticketIdOrNull(mixed $askedTicketId): ?TicketId
    {
        if (!\is_string($askedTicketId)) {
            return null;
        }
        try {
            return new TicketId($askedTicketId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
