<?php

declare(strict_types=1);

namespace App\Notification\UI\Controller;

use App\Notification\Api\PingCommands;
use App\Notification\Api\PingMessageRefused;
use App\Notification\Api\PingPermission;
use App\Notification\Api\PingRecipientCannotView;
use App\Notification\Api\PingSlot;
use App\Notification\Api\PingsPaused;
use App\Notification\Api\PingTooSoon;
use App\Notification\Api\PingToYourself;
use App\Notification\UI\PingedState;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\MemberNameQueries;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * POST /t/{key}/ping/{userId}, the ticket page's ping sheet (FR-NTF, dialogs-and-popovers.md, Ping sheet). Without the
 * request transaction, as TicketMoveController: Transactions gives the ping a transaction of its own, so the toast says
 * "Pinged" only once the ping is committed, and a refusal leaves nothing; when that transaction fails after the ping
 * took its ping limit slot, the slot is given back before the failure goes on. In that order: a ticket the person may not see
 * is not found, as on its page, and so is one they may see but not ping from (a Viewer); a person who is not a member
 * of the organization is not found; then an expired form, a message that is no text, and the use case's refusals each
 * answer 422 with a toast saying why.
 *
 * A Turbo form gets a Turbo Stream with the toast, which also turns the person's Ping button into "Pinged · again in
 * N h" when the ping was sent or was too soon; any other form a redirect to the ticket page with the toast flashed.
 */
final class PingController extends AbstractController
{
    public const string ROUTE = 'notification_ping';

    public const string CSRF_FIELD = '_csrf_token';
    public const string MESSAGE_FIELD = 'message';

    public const string SHEET_ROUTE = 'notification_ping_sheet';

    /**
     * With the ticket's key and the person's id, the id of their Ping button, which a stream answer replaces; keyed by
     * ticket too, as My reports can hold sheets for one person on several cards.
     */
    public const string PING_TARGET_PREFIX = 'ping-';

    /**
     * With the ticket's key, the Turbo frame of a My reports card that the sheet route fills; Web's
     * my_reports/show.html.twig builds the same id, as Web may not use Notification's UI.
     */
    public const string SHEET_FRAME_PREFIX = 'ping-sheet-';

    /** The ticket page's People heading, where a request for the sheet outside its frame lands. */
    private const string PEOPLE_FRAGMENT = 'ticket-people-heading';
    private const string TURBO_FRAME_HEADER = 'Turbo-Frame';

    public const string RECIPIENT_NOT_FOUND = 'No person you may ping has that id.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';

    /** Domain\Ping::MAX_BODY_LENGTH, which Notification's UI may not use (deptrac.yaml). */
    public const int MESSAGE_MAX_LENGTH = 500;

    /**
     * Enough bytes for MESSAGE_MAX_LENGTH characters of four bytes each with as many spaces around them; anything
     * longer is refused before it is counted.
     */
    public const int MESSAGE_MAX_BYTES = 8 * self::MESSAGE_MAX_LENGTH;

    private const string TICKET_NOT_FOUND = 'No ticket you may see has that key.';
    /** The ticket page's route, named here as Notification's UI may not use Ticketing's (deptrac.yaml). */
    private const string TICKET_ROUTE = 'ticket_show';
    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'notification_ping:';
    private const string USER_ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly TicketQueries $ticketQueries,
        private readonly MemberNameQueries $memberNameQueries,
        private readonly PingCommands $pingCommands,
        private readonly PingedState $pingedState,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of every ping form of one ticket, for csrf_token() in the template.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    public static function targetIdOf(string $key, string $userId): string
    {
        return self::PING_TARGET_PREFIX.$key.'-'.$userId;
    }

    public static function sheetFrameIdOf(string $key): string
    {
        return self::SHEET_FRAME_PREFIX.$key;
    }

    /**
     * One person's ping sheet, opened as it loads, in the Turbo frame of a My reports card that offers "Ping <name>",
     * so the ping is sent from that page through ping() and its toast shows there. The same 404s as ping() for a ticket
     * the person may not see or ping from and for someone who is no member; the viewer's own id gets an empty frame, as
     * nobody pings themselves. A request that is not that frame's (no script, or another frame) goes to the ticket's
     * People, where the sheet is on the page.
     */
    #[Route('/t/{key}/ping/{userId}/sheet', name: self::SHEET_ROUTE, requirements: ['userId' => self::USER_ID_REQUIREMENT], methods: ['GET'])]
    public function sheet(string $key, string $userId, Request $request): Response
    {
        if (null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }
        try {
            [$actor, $ticket] = $this->pingableTicketOf($key);
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(self::TICKET_NOT_FOUND);
        }
        $toUserId = new UserId(strtolower($userId));
        $firstName = $this->recipientFirstNameOf($actor, $toUserId);
        $frameId = self::sheetFrameIdOf($ticket->key);
        if ($frameId !== $request->headers->get(self::TURBO_FRAME_HEADER)) {
            return $this->redirectToRoute(self::TICKET_ROUTE, ['key' => $ticket->key, '_fragment' => self::PEOPLE_FRAGMENT], Response::HTTP_SEE_OTHER);
        }

        return $this->render('@Notification/ping_sheet_frame.html.twig', [
            'frameId' => $frameId,
            'ticketId' => $ticket->id->toString(),
            'ticketKey' => $ticket->key,
            'ticketTitle' => $ticket->title,
            'toUserId' => $toUserId->toString(),
            'toFirstName' => $firstName,
            'isViewer' => $toUserId->equals($actor->userId()),
        ]);
    }

    #[Route('/t/{key}/ping/{userId}', name: self::ROUTE, requirements: ['userId' => self::USER_ID_REQUIREMENT], methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function ping(string $key, string $userId, Request $request): Response
    {
        if (null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }
        $toUserId = new UserId(strtolower($userId));
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $message = $request->request->getString(self::MESSAGE_FIELD);
        $now = $this->clock->now();
        $slot = null;

        try {
            [$toast, $againAt] = $this->transactions->run(function () use ($key, $toUserId, $csrfToken, $message, $now, &$slot): array {
                [$actor, $ticket] = $this->pingableTicketOf($key);
                $firstName = $this->recipientFirstNameOf($actor, $toUserId);
                if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
                    throw new PingRefusedWithToast(self::FORM_EXPIRED);
                }
                if (\strlen($message) > self::MESSAGE_MAX_BYTES || !mb_check_encoding($message, 'UTF-8')) {
                    throw new PingRefusedWithToast(new PingMessageRefused(self::MESSAGE_MAX_LENGTH)->getMessage());
                }
                try {
                    $slot = $this->pingCommands->ping($actor->organizationId(), $ticket->key, $actor->userId(), $toUserId, $message, $now);
                } catch (PingTooSoon $tooSoon) {
                    throw new PingRefusedWithToast(\sprintf('You pinged %s about this ticket recently. You can ping them %s.', $firstName, PingedState::againInOf($tooSoon->againAt, $now)), $tooSoon->againAt);
                } catch (PingToYourself|PingMessageRefused|PingRecipientCannotView|PingsPaused $refused) {
                    throw new PingRefusedWithToast($refused->getMessage());
                }

                return [\sprintf('Pinged %s. One notification sent.', $firstName), $slot->againAt];
            });
        } catch (\Throwable $failure) {
            if (null !== $slot) {
                $this->releaseUncommitted($slot);
            }
            if ($failure instanceof TicketNotFound) {
                throw $this->createNotFoundException(self::TICKET_NOT_FOUND);
            }
            if ($failure instanceof PingRefusedWithToast) {
                return $this->answerWithToast($request, $key, $toUserId, $failure->toast(), $failure->againAt(), $now, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $failure;
        }
        $this->pingedState->reset();

        return $this->answerWithToast($request, $key, $toUserId, $toast, $againAt, $now, Response::HTTP_OK);
    }

    /**
     * Inside the transaction run opens: outside one, CurrentActor finds no roles under row-level security. The same 404
     * as the ticket's page for a ticket the person may not see, and for one they see but may not ping from.
     *
     * @return array{ActorContext, TicketPageView}
     */
    private function pingableTicketOf(string $key): array
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $ticket = $this->ticketQueries->pageOf($key, $this->ticketVisibilities->of($actor->organizationId(), $actor->userId()));
        if (null === $ticket || !$this->isGranted(TicketPermission::VIEW, $ticket->visibilityFacts) || !$this->isGranted(PingPermission::SEND, $ticket->visibilityFacts)) {
            throw $this->createNotFoundException(self::TICKET_NOT_FOUND);
        }

        return [$actor, $ticket];
    }

    /**
     * The transaction rolled back, so the ping the slot was taken for is not stored; without this the sender could not
     * ping this person about this ticket again until the slot ends.
     */
    private function releaseUncommitted(PingSlot $slot): void
    {
        try {
            $this->pingCommands->releaseSlot($slot);
        } catch (PingsPaused) {
            // The slot then ends by itself at its againAt, and the failure of the transaction matters more than this.
        }
    }

    private function recipientFirstNameOf(ActorContext $actor, UserId $toUserId): string
    {
        $displayName = $this->memberNameQueries->displayNamesOf($actor->organizationId(), [$toUserId])[$toUserId->toString()] ?? null;
        if (null === $displayName) {
            throw $this->createNotFoundException(self::RECIPIENT_NOT_FOUND);
        }

        return explode(' ', trim($displayName), 2)[0];
    }

    /**
     * @param \DateTimeImmutable|null $againAt      when the sender may ping this person again; null leaves their Ping button
     * @param int                     $streamStatus 200 for a ping sent, 422 for one refused
     */
    private function answerWithToast(Request $request, string $key, UserId $toUserId, string $toast, ?\DateTimeImmutable $againAt, \DateTimeImmutable $now, int $streamStatus): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            $this->addFlash(self::TOAST, $toast);

            return $this->redirectToRoute(self::TICKET_ROUTE, ['key' => $key], Response::HTTP_SEE_OTHER);
        }

        return $this->render(
            '@Notification/ping.stream.html.twig',
            [
                'toast' => $toast,
                'pingTargetId' => self::targetIdOf($key, $toUserId->toString()),
                'againIn' => null === $againAt ? null : PingedState::againInOf($againAt, $now),
            ],
            new Response(null, $streamStatus, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
        );
    }
}
