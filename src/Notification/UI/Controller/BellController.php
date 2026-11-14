<?php

declare(strict_types=1);

namespace App\Notification\UI\Controller;

use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\BellInbox;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The bell's popover (dialogs-and-popovers.md, Pings popover): GET /bell is the popover's lazy frame, and Done and Open
 * act on one group of the signed-in person's open notifications, named by its sender and ticket. The person comes from
 * the request's actor, never from the URL; OrganizationVoter decides ORG_VIEW first (ADR-0013). Both run in the request
 * transaction. A malformed id, and a group with none open, which includes another person's group, are not found and
 * change nothing.
 */
final class BellController extends AbstractController
{
    public const string ROUTE = 'notification_bell';
    public const string DONE_ROUTE = 'notification_bell_done';
    public const string OPEN_ROUTE = 'notification_bell_open';

    /** The id of the frame the bell's popover loads, and the one GET /bell answers with. */
    public const string FRAME_ID = 'bell-frame';

    /** The CSRF token id of every Done and Open form of the bell. */
    public const string CSRF_ID = 'notification_bell';
    public const string CSRF_FIELD = '_csrf_token';

    public const string MARKED_DONE = 'Marked done.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string GROUP_NOT_FOUND = 'No open pings from that person on that ticket.';

    /** The ticket page's route, named here as Notification's UI may not use Ticketing's (deptrac.yaml). */
    private const string TICKET_ROUTE = 'ticket_show';
    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string HOME_ROUTE = 'web_home';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly NotificationQueries $notificationQueries,
        private readonly BellInbox $bellInbox,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/bell', name: self::ROUTE, methods: ['GET'])]
    public function show(): Response
    {
        $actor = $this->signedInActor();

        return $this->render('@Notification/bell_frame.html.twig', [
            'frameId' => self::FRAME_ID,
            'bell' => $this->notificationQueries->bell($actor->userId()),
        ]);
    }

    /**
     * A Turbo form gets a stream with the new badge, the new popover and the toast; any other form a redirect to Home
     * with the toast flashed.
     */
    #[Route('/bell/{senderId}/{ticketId}/done', name: self::DONE_ROUTE, methods: ['POST'])]
    public function done(Request $request, string $senderId, string $ticketId): Response
    {
        $actor = $this->signedInActor();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString(self::CSRF_FIELD))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        $group = $this->groupOf($actor, $senderId, $ticketId);
        if (0 === $this->bellInbox->markGroupDone($group, $this->clock->now())) {
            throw $this->createNotFoundException(self::GROUP_NOT_FOUND);
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            $this->addFlash(self::TOAST, self::MARKED_DONE);

            return $this->redirectToRoute(self::HOME_ROUTE, status: Response::HTTP_SEE_OTHER);
        }

        return $this->render(
            '@Notification/bell.stream.html.twig',
            ['bell' => $this->notificationQueries->bell($actor->userId()), 'toast' => self::MARKED_DONE],
            new Response(null, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
        );
    }

    /**
     * Marks the group read and goes to its ticket, whose page checks again that the person may see it.
     */
    #[Route('/bell/{senderId}/{ticketId}/open', name: self::OPEN_ROUTE, methods: ['POST'])]
    public function open(Request $request, string $senderId, string $ticketId): Response
    {
        $actor = $this->signedInActor();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString(self::CSRF_FIELD))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        $ticketKey = $this->bellInbox->markGroupRead($this->groupOf($actor, $senderId, $ticketId), $this->clock->now())
            ?? throw $this->createNotFoundException(self::GROUP_NOT_FOUND);

        return $this->redirectToRoute(self::TICKET_ROUTE, ['key' => $ticketKey], Response::HTTP_SEE_OTHER);
    }

    private function signedInActor(): ActorContext
    {
        // Without an organization the subject is null, which no voter decides, so the request is denied.
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());

        return $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
    }

    private function groupOf(ActorContext $actor, string $senderId, string $ticketId): BellGroupKey
    {
        try {
            return new BellGroupKey($actor->userId(), new UserId($senderId), new TicketId($ticketId));
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException(self::GROUP_NOT_FOUND);
        }
    }

    private static function refusal(string $message): Response
    {
        return new Response($message, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
