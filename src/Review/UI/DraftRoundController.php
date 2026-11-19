<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Organization\Api\ActorContext;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundRefused;
use App\Review\UI\Controller\RefusalAnswers;
use App\Review\UI\Controller\RefusedWithToast;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\TicketNotFound;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The buttons of a private draft round in the Rounds section (P05.T8, ADR-0017, ticket-page.md, Draft round): "Start a
 * draft round", "Share with the team now" and "Delete draft". A change made answers with a redirect to the ticket's
 * Rounds section and its toast flashed. A refusal (a start the person may not make, an expired form, or a RoundRefused
 * other than NOT_FOUND) changes nothing and is a 422 toast stream for a Turbo form, and a redirect with the toast
 * flashed for any other form, as the ticket's other actions answer.
 *
 * Without the request transaction, as TicketMoveController: each action runs in a transaction of its own, so a refused
 * command leaves nothing and the toast is set only once the change is committed. In that order: a ticket the person may
 * not see is the ticket page's 404; on share and delete, a round id RoundQueries::accessFactsOf does not know (a
 * malformed one too), a round of another ticket, and a round RoundPermission VIEW or EDIT denies are all one 404, so
 * another person's draft cannot be told from a missing one; starting a draft without RoundPermission DRAFT_CREATE on a
 * ticket the person may see is refused with its toast, as the frame may show the button after the ticket changed;
 * then a form token that is not valid changes nothing.
 */
final class DraftRoundController extends AbstractController
{
    public const string START_ROUTE = 'review_draft_start';
    public const string SHARE_ROUTE = 'review_draft_share';
    public const string DELETE_ROUTE = 'review_draft_delete';

    public const string CSRF_FIELD = '_csrf_token';

    public const string DRAFT_STARTED = 'Draft round started. Only you see it.';
    /** With the round's number. */
    public const string DRAFT_SHARED = 'Shared with the team as round %d.';
    public const string DRAFT_DELETED = 'Draft round deleted.';
    public const string ONLY_THE_ASSIGNEE_OR_TURN_STARTS = 'Only the assignee, or the person whose turn it is, can start a draft round.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'review_draft_round:';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly VisibleTicketLookup $visibleTicketLookup,
        private readonly RoundQueries $roundQueries,
        private readonly RoundCommands $roundCommands,
        private readonly RefusalAnswers $refusalAnswers,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of the draft actions on one ticket, for csrf_token() in the Rounds frame.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    #[Route('/t/{key}/rounds/draft', name: self::START_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function start(string $key, Request $request): Response
    {
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();

        return $this->respondToAction($request, $key, function () use ($key, $csrfToken, $now): string {
            $visible = $this->visibleTicketLookup->visibleTicketOf($key);
            if (!$this->isGranted(RoundPermission::DRAFT_CREATE, $visible->ticket->id)) {
                throw new RefusedWithToast(self::ONLY_THE_ASSIGNEE_OR_TURN_STARTS);
            }
            $this->refuseAnExpiredForm($key, $csrfToken);
            $this->roundCommands->startDraft($visible->ticket->id, $visible->actor->userId(), $now);

            return self::DRAFT_STARTED;
        });
    }

    #[Route('/t/{key}/rounds/{id}/share', name: self::SHARE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function share(string $key, string $id, Request $request): Response
    {
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();

        return $this->respondToAction($request, $key, function () use ($key, $id, $csrfToken, $now): string {
            $actor = $this->actorWhoMayEditRound($key, $id);
            $this->refuseAnExpiredForm($key, $csrfToken);

            return \sprintf(self::DRAFT_SHARED, $this->roundCommands->shareDraft($id, $actor->userId(), $now));
        });
    }

    #[Route('/t/{key}/rounds/{id}/delete', name: self::DELETE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function delete(string $key, string $id, Request $request): Response
    {
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();

        return $this->respondToAction($request, $key, function () use ($key, $id, $csrfToken, $now): string {
            $actor = $this->actorWhoMayEditRound($key, $id);
            $this->refuseAnExpiredForm($key, $csrfToken);
            $this->roundCommands->deleteDraft($id, $actor->userId(), $now);

            return self::DRAFT_DELETED;
        });
    }

    /**
     * @param callable(): string $action the change, returning its toast
     */
    private function respondToAction(Request $request, string $key, callable $action): Response
    {
        if (null === $this->tenantContext->get()) {
            // Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
            throw $this->createAccessDeniedException();
        }
        try {
            $toast = $this->transactions->run($action(...));
        } catch (TicketNotFound) {
            throw $this->createNotFoundException(RoundsController::NOT_FOUND);
        } catch (RefusedWithToast $refused) {
            return $this->respondToRefusal($request, $key, $refused);
        } catch (RoundRefused $refused) {
            if (RoundRefused::NOT_FOUND === $refused->getMessage()) {
                throw $this->createNotFoundException(RoundRefused::NOT_FOUND);
            }

            return $this->respondToRefusal($request, $key, new RefusedWithToast($refused->getMessage()));
        }

        return $this->redirectToTicket($key, $toast);
    }

    private function respondToRefusal(Request $request, string $key, RefusedWithToast $refused): Response
    {
        return $this->refusalAnswers->answer($request, $refused, fn (string $toast): Response => $this->redirectToTicket($key, $toast));
    }

    private function redirectToTicket(string $key, string $toast): Response
    {
        $this->addFlash(self::TOAST, $toast);

        return $this->redirectToRoute(RoundsController::TICKET_ROUTE, ['key' => $key, '_fragment' => Component\RoundsSection::FRAME_ID], Response::HTTP_SEE_OTHER);
    }

    /**
     * The round's own 404 for an unknown or malformed id, a round of another ticket, and one the person may not view or
     * change, before the form is read (ADR-0017).
     */
    private function actorWhoMayEditRound(string $key, string $roundId): ActorContext
    {
        $visible = $this->visibleTicketLookup->visibleTicketOf($key);
        $round = $this->roundQueries->accessFactsOf($roundId);
        if (null === $round
            || !$round->ticketId->equals($visible->ticket->id)
            || !$this->isGranted(RoundPermission::VIEW, $round)
            || !$this->isGranted(RoundPermission::EDIT, $round)) {
            throw $this->createNotFoundException(RoundRefused::NOT_FOUND);
        }

        return $visible->actor;
    }

    /**
     * @throws RefusedWithToast with RefusedWithToast::FORM_EXPIRED for a form token that is not valid
     */
    private function refuseAnExpiredForm(string $key, string $csrfToken): void
    {
        if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
            throw RefusedWithToast::formExpired();
        }
    }
}
