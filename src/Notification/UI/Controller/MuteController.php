<?php

declare(strict_types=1);

namespace App\Notification\UI\Controller;

use App\Notification\Api\MutePermission;
use App\Notification\Application\Mute\MuteTicket;
use App\Notification\Application\Mute\UnmuteTicket;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /t/{key}/mute and /t/{key}/unmute: the signed-in person mutes one ticket for themselves or ends that mute
 * (FR-NTF, my-reports.md, "Mute updates"). No page shows a button for them before P07. Both run in the request
 * transaction. In that order: OrganizationVoter decides ORG_VIEW (ADR-0013); a ticket the person may not see is not
 * found, as on its page; an expired form answers 422; then the ticket page with the toast.
 */
final class MuteController extends AbstractController
{
    public const string MUTE_ROUTE = 'notification_mute';
    public const string UNMUTE_ROUTE = 'notification_unmute';

    public const string CSRF_FIELD = '_csrf_token';

    public const string MUTED = 'Muted. You still see it here.';
    public const string UNMUTED = 'You get updates again.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string TICKET_NOT_FOUND = 'No ticket you may see has that key.';

    /** The ticket page's route, named here as Notification's UI may not use Ticketing's (deptrac.yaml). */
    private const string TICKET_ROUTE = 'ticket_show';
    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'notification_mute:';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly TicketQueries $ticketQueries,
        private readonly MuteTicket $muteTicket,
        private readonly UnmuteTicket $unmuteTicket,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of the mute and unmute forms of one ticket, for csrf_token() in the template.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    #[Route('/t/{key}/mute', name: self::MUTE_ROUTE, methods: ['POST'])]
    public function mute(string $key, Request $request): Response
    {
        [$actor, $ticket] = $this->mutableTicketOf($key);
        if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $request->request->getString(self::CSRF_FIELD))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        $this->muteTicket->mute($actor->userId(), $ticket->id, $this->clock->now());

        return $this->ticketPageWithToast($ticket, self::MUTED);
    }

    #[Route('/t/{key}/unmute', name: self::UNMUTE_ROUTE, methods: ['POST'])]
    public function unmute(string $key, Request $request): Response
    {
        [$actor, $ticket] = $this->mutableTicketOf($key);
        if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $request->request->getString(self::CSRF_FIELD))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        $this->unmuteTicket->unmute($actor->userId(), $ticket->id);

        return $this->ticketPageWithToast($ticket, self::UNMUTED);
    }

    /**
     * The same 404 as the ticket's page for a ticket the person may not see.
     *
     * @return array{ActorContext, TicketPageView}
     */
    private function mutableTicketOf(string $key): array
    {
        // Without an organization the subject is null, which no voter decides, so the request is denied.
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $ticket = $this->ticketQueries->pageOf($key, $this->ticketVisibilities->of($actor->organizationId(), $actor->userId()));
        if (null === $ticket || !$this->isGranted(TicketPermission::VIEW, $ticket->visibilityFacts) || !$this->isGranted(MutePermission::MUTE, $ticket->visibilityFacts)) {
            throw $this->createNotFoundException(self::TICKET_NOT_FOUND);
        }

        return [$actor, $ticket];
    }

    private function ticketPageWithToast(TicketPageView $ticket, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);

        return $this->redirectToRoute(self::TICKET_ROUTE, ['key' => $ticket->key], Response::HTTP_SEE_OTHER);
    }

    private static function refusal(string $message): Response
    {
        return new Response($message, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
