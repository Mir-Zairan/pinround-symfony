<?php

declare(strict_types=1);

namespace App\Assist\UI\Controller;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalPackageId;
use App\Assist\Api\MainSummaryRefused;
use App\Assist\Api\Summaries;
use App\Assist\Api\SummaryRequestRefused;
use App\Assist\Api\SummaryRequestView;
use App\Assist\UI\Component\TheAsk;
use App\Assist\UI\SummarySourceNote;
use App\Identity\Api\CurrentTimeZone;
use App\Identity\Api\UserQueries;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketSummaryQueries;
use App\Ticketing\Api\TicketSummaryView;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * "The ask"'s summary frame (P08.T4, ticket-page.md): GET /t/{key}/summary renders TheAsk's frame with the latest
 * request of the ticket (Summaries::latestRequestOfVisibleTicket, which trusts this controller's voter check, so a
 * poll checks access once), which the summary-poll controller fetches while a request runs; GET /t/{key}/summary/earlier lists the earlier summaries in their lazy frame; POST /t/{key}/summary queues a
 * summary ("Summarize with AI") and answers the frame in its running state.
 *
 * Each route reads the ticket with TicketQueries::pageOf and decides TicketPermission::VIEW on it, so an unknown or
 * malformed key, another organization's ticket and one the viewer may not see all answer the same 404 as the ticket's
 * page. The POST then checks its form token (422), and a refusal of Summaries::request answers with its line: 429 for
 * the AI call limit, 409 with the running frame while a summary is already on its way, 403 for the policy's refusals
 * and, failing closed, any code it does not name. A request that is not Turbo's frame request is sent to the ticket's
 * page with 303, a POST's refusal flashed as a toast.
 *
 * POST /t/{key}/summary/laptop/{packageId} is "Use as main summary" on the ticket's laptop summary proposal
 * (LocalAi::makeMainSummary). An unknown key, a ticket the person may not see and a person who is neither the lead of
 * the ticket's team nor its reporter, or a reporter who is a Viewer of that team (TicketPermission::CHOOSE_MAIN_SUMMARY), answer the ticket's 404; then an expired
 * form 422; then a malformed package id and any result that is not the ticket's proposal 404, and a text Ticketing
 * refuses as a summary 422 with its sentence, nothing changed. A made main summary answers 303 to the ticket with the
 * toast MADE_MAIN_SUMMARY. A refusal answers a Turbo form with a toast stream, any other form with 303 and the toast
 * flashed. The AI policy does not decide it, as no AI runs (ADR-0020).
 */
final class SummaryController extends AbstractController
{
    public const string FRAME_ROUTE = 'assist_summary_frame';
    public const string EARLIER_ROUTE = 'assist_summary_earlier';
    public const string REQUEST_ROUTE = 'assist_summary_request';
    public const string MAIN_SUMMARY_ROUTE = 'assist_summary_main';

    public const string CSRF_ID = 'assist_summary';
    public const string MAIN_SUMMARY_CSRF_ID = 'assist_main_summary';
    public const string CSRF_FIELD = '_csrf_token';

    public const string FORM_EXPIRED = 'The page was open too long. Reload it and try again.';
    public const string MADE_MAIN_SUMMARY = 'Now the main summary. The old one is kept in history.';

    /** TicketShowController's route and not-found text, which Assist's UI may not import (deptrac). */
    public const string TICKET_ROUTE = 'ticket_show';
    public const string NOT_FOUND = 'No ticket you may see has that key.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    /** The header Turbo sends when it loads a frame, with the frame's id. */
    private const string TURBO_FRAME_HEADER = 'Turbo-Frame';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly TicketQueries $ticketQueries,
        private readonly TicketSummaryQueries $ticketSummaryQueries,
        private readonly Summaries $summaries,
        private readonly UserQueries $userQueries,
        private readonly ClockInterface $clock,
        private readonly CurrentTimeZone $currentTimeZone,
        private readonly Transactions $transactions,
        private readonly LocalAi $localAi,
    ) {
    }

    #[Route('/t/{key}/summary', name: self::FRAME_ROUTE, methods: ['GET'])]
    public function frame(string $key, Request $request): Response
    {
        [$actor, , $ticket] = $this->visibleTicketOf($key);
        if (!self::isFrameRequest($request)) {
            return $this->redirectToTicket($key);
        }

        return $this->renderFrame($ticket, $this->summaries->latestRequestOfVisibleTicket($ticket->id, $actor->organizationId()));
    }

    /**
     * One query for the summaries and one for the names of their authors and of who made them the main summary.
     */
    #[Route('/t/{key}/summary/earlier', name: self::EARLIER_ROUTE, methods: ['GET'])]
    public function earlier(string $key, Request $request): Response
    {
        [, $visibility, $ticket] = $this->visibleTicketOf($key);
        if (!self::isFrameRequest($request)) {
            return $this->redirectToTicket($key);
        }
        $history = $this->ticketSummaryQueries->historyOf($ticket->id, $visibility) ?? throw $this->createNotFoundException(self::NOT_FOUND);
        $authorIds = [];
        foreach ($history->earlier as $summary) {
            foreach ([$summary->authorId, $summary->chosenById] as $personId) {
                if (null !== $personId) {
                    $authorIds[$personId->toString()] = $personId;
                }
            }
        }
        $authors = $this->userQueries->byIds(array_values($authorIds));
        $nameOf = static fn (?UserId $personId): ?string => null === $personId ? null : ($authors[$personId->toString()] ?? null)?->displayName;
        $now = $this->clock->now();
        $viewerTimeZone = $this->currentTimeZone->zone();

        $response = $this->render('@Assist/summary_earlier.html.twig', [
            'earlierSummaries' => array_map(static fn (TicketSummaryView $summary): array => [
                'note' => SummarySourceNote::of($summary, $nameOf($summary->authorId), $now, $viewerTimeZone, $nameOf($summary->chosenById)),
                'text' => $summary->text,
            ], $history->earlier),
            'unlistedCount' => $history->earlierCount - \count($history->earlier),
        ]);
        $response->setVary(self::TURBO_FRAME_HEADER);

        return $response;
    }

    #[Route('/t/{key}/summary', name: self::REQUEST_ROUTE, methods: ['POST'])]
    public function requestSummary(string $key, Request $request): Response
    {
        [$actor, , $ticket] = $this->visibleTicketOf($key);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString(self::CSRF_FIELD))) {
            return $this->respondToRefusal($request, $ticket, Response::HTTP_UNPROCESSABLE_ENTITY, notice: self::FORM_EXPIRED);
        }

        try {
            $this->summaries->request($ticket->id, $actor->userId(), $actor->organizationId());
        } catch (SummaryRequestRefused $refused) {
            return match ($refused->refusalCode) {
                SummaryRequestRefused::TOO_MANY_AI_REQUESTS => $this->respondToRefusal($request, $ticket, Response::HTTP_TOO_MANY_REQUESTS, notice: $refused->getMessage()),
                SummaryRequestRefused::ALREADY_QUEUED => $this->respondToRefusal(
                    $request,
                    $ticket,
                    Response::HTTP_CONFLICT,
                    summaryRequest: $this->summaries->latestRequestOfVisibleTicket($ticket->id, $actor->organizationId()) ?? SummaryRequestView::queued($actor->userId(), $this->clock->now()),
                    toast: $refused->getMessage(),
                ),
                default => $this->respondToRefusal($request, $ticket, Response::HTTP_FORBIDDEN, refusal: $refused->getMessage()),
            };
        }

        if (!self::isFrameRequest($request)) {
            return $this->redirectToTicket($key);
        }

        return $this->renderFrame($ticket, SummaryRequestView::queued($actor->userId(), $this->clock->now()));
    }

    /**
     * Runs without the request transaction, so the result is made the main summary, or nothing changed, before the
     * answer: a refusal leaves Transactions::run rolled back as a whole.
     */
    #[Route('/t/{key}/summary/laptop/{packageId}', name: self::MAIN_SUMMARY_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function chooseMainSummary(string $key, string $packageId, Request $request): Response
    {
        if (null === $this->tenantContext->get()) {
            // A visitor has no tenant, and the firewall sends the AccessDeniedException to the login page.
            throw $this->createAccessDeniedException();
        }
        $csrfToken = $request->request->getString(self::CSRF_FIELD);

        try {
            $isTokenValid = $this->transactions->run(function () use ($key, $packageId, $csrfToken): bool {
                [$actor, , $ticket] = $this->visibleTicketOf($key);
                if (!$this->isGranted(TicketPermission::CHOOSE_MAIN_SUMMARY, $ticket->visibilityFacts)) {
                    throw $this->createNotFoundException(self::NOT_FOUND);
                }
                if (!$this->isCsrfTokenValid(self::MAIN_SUMMARY_CSRF_ID, $csrfToken)) {
                    return false;
                }
                try {
                    $localPackageId = new LocalPackageId($packageId);
                } catch (\InvalidArgumentException) {
                    throw MainSummaryRefused::notAProposal();
                }
                $this->localAi->makeMainSummary($localPackageId, $ticket->id, $ticket->currentSummary?->createdAt, $actor->userId(), $actor->organizationId());

                return true;
            });
        } catch (MainSummaryRefused $refused) {
            if (MainSummaryRefused::NOT_A_PROPOSAL === $refused->refusalCode) {
                throw $this->createNotFoundException($refused->getMessage());
            }

            return $this->answerWithToast($request, $key, $refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$isTokenValid) {
            return $this->answerWithToast($request, $key, self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash(self::TOAST, self::MADE_MAIN_SUMMARY);

        return $this->redirectToTicket($key);
    }

    /**
     * A Turbo form gets a Turbo Stream that shows the toast with $status; any other form the ticket's page with the
     * toast flashed.
     */
    private function answerWithToast(Request $request, string $key, string $toast, int $status): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            $this->addFlash(self::TOAST, $toast);

            return $this->redirectToTicket($key);
        }

        return $this->render('@Web/_toast_stream.html.twig', ['message' => $toast], new Response(null, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]));
    }

    /**
     * The person must be logged in to the tenant; the firewall sends a visitor's AccessDeniedException to the login
     * page. Runs in the request transaction, or chooseMainSummary's own, as pageOf and the visibility read under
     * row-level security.
     *
     * @return array{ActorContext, TicketVisibility, TicketPageView}
     */
    private function visibleTicketOf(string $key): array
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $visibility = $this->ticketVisibilities->of($actor->organizationId(), $actor->userId());
        $ticket = $this->ticketQueries->pageOf($key, $visibility);
        if (null === $ticket || !$this->isGranted(TicketPermission::VIEW, $ticket->visibilityFacts)) {
            throw $this->createNotFoundException(self::NOT_FOUND);
        }

        return [$actor, $visibility, $ticket];
    }

    /**
     * @param string|null $notice  a line shown with the button kept
     * @param string|null $refusal a line shown with the button hidden
     * @param string|null $toast   the line flashed when the request is not Turbo's; $notice or $refusal when null
     */
    private function respondToRefusal(
        Request $request,
        TicketPageView $ticket,
        int $status,
        ?string $notice = null,
        ?string $refusal = null,
        ?SummaryRequestView $summaryRequest = null,
        ?string $toast = null,
    ): Response {
        if (!self::isFrameRequest($request)) {
            $this->addFlash(self::TOAST, $toast ?? $notice ?? $refusal ?? '');

            return $this->redirectToTicket($ticket->key);
        }

        return $this->renderFrame($ticket, $summaryRequest, $status, $notice, $refusal);
    }

    private function renderFrame(TicketPageView $ticket, ?SummaryRequestView $summaryRequest, int $status = Response::HTTP_OK, ?string $notice = null, ?string $refusal = null): Response
    {
        $response = $this->render(
            '@Assist/summary_frame.html.twig',
            ['ticket' => $ticket, 'summaryRequest' => $summaryRequest, 'notice' => $notice, 'refusal' => $refusal],
            new Response(null, $status),
        );
        $response->setVary(self::TURBO_FRAME_HEADER);

        return $response;
    }

    private function redirectToTicket(string $key): Response
    {
        return $this->redirectToRoute(self::TICKET_ROUTE, ['key' => $key], Response::HTTP_SEE_OTHER);
    }

    private static function isFrameRequest(Request $request): bool
    {
        return $request->headers->has(self::TURBO_FRAME_HEADER);
    }
}
