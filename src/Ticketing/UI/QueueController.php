<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Application\RoleTicketVisibilities;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Starts, finishes and stops a person's walk through tickets one at a time (FR-TKT-15, P03.T10); the ticket page shows
 * the QueueBar while its ticket is in the queue. Anyone who may open the tickets list (OrganizationPermission::VIEW,
 * ADR-0013) may keep a queue, in their own session only. A queue is made of the keys the list shows the viewer, so it
 * holds only tickets they may see, and each ticket page reads its ticket again under their visibility.
 */
final class QueueController extends AbstractController
{
    public const string START_ROUTE = 'ticketing_queue_start';
    public const string FINISH_ROUTE = 'ticketing_queue_finish';
    public const string STOP_ROUTE = 'ticketing_queue_stop';
    public const string CSRF_ID = 'ticketing_queue';

    /** The only start for now: one step of the tickets list. Home's groups start through the same route in P07. */
    public const string LIST_STEP_SOURCE = 'list-step';
    public const string STEP_FIELD = 'step';
    /** Stop sent by the ticket page's Back button: the queue ends and the list opens. */
    public const string BACK_FIELD = 'back';

    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    public const string NO_STEP = 'No step you may see has that id.';
    public const string ALL_DONE = 'All done in %s.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly ListTurnTickets $listTurnTickets,
        private readonly ProjectQueries $projectQueries,
        private readonly TicketQueries $ticketQueries,
    ) {
    }

    /**
     * Queues the first TicketQueue::MAX_KEYS tickets of one step of the list the query string names, in list order,
     * labelled with the step's name, and opens the first. A step id that is not a canonical uuid, or of no step with a
     * ticket the viewer may see in that list, is a 404.
     */
    #[Route('/tickets/queue/start/{source}', name: self::START_ROUTE, requirements: ['source' => self::LIST_STEP_SOURCE], methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        $stepId = $request->request->getString(self::STEP_FIELD);
        if (!ListFilter::isCanonicalUuid($stepId)) {
            throw $this->createNotFoundException(self::NO_STEP);
        }
        $listContext = TicketListContext::fromRequest($request, $actor, $this->projectQueries);
        $filter = $listContext->filterFor($this->ticketVisibilities->ofActor($actor), $this->listTurnTickets->of($listContext, $actor->userId()));

        $step = self::stepIn($this->ticketQueries->list($filter), $stepId) ?? throw $this->createNotFoundException(self::NO_STEP);
        $label = $step->name;
        $keys = self::keysOf($step);
        while (\count($keys) < TicketQueue::MAX_KEYS && null !== $step?->nextCursor) {
            $step = self::stepIn($this->ticketQueries->list($filter, $step->nextCursor), $stepId);
            $keys = [...$keys, ...(null === $step ? [] : self::keysOf($step))];
        }
        // A ticket updated between two pages can show on both; the queue holds each key once.
        $keys = \array_slice(array_values(array_unique($keys)), 0, TicketQueue::MAX_KEYS);
        new TicketQueue($label, $keys)->storeIn($request->getSession());

        return $this->redirectToRoute(TicketShowController::ROUTE, ['key' => $keys[0], ...$listContext->query()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Ends the queue and goes Home with "All done in {label}."; with no queue left (ended in another tab) Home opens
     * with no toast.
     */
    #[Route('/tickets/queue/finish', name: self::FINISH_ROUTE, methods: ['POST'])]
    public function finish(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        $session = $request->getSession();
        $queue = TicketQueue::of($session);
        TicketQueue::endIn($session);
        if (null !== $queue) {
            $this->addFlash('toast', \sprintf(self::ALL_DONE, $queue->label));
        }

        return $this->redirectToRoute('web_home', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Ends the queue and opens the ticket again, or, with BACK_FIELD=1, the list the query string names. The ticket
     * page decides whether the viewer may see the ticket.
     */
    #[Route('/t/{key}/queue/stop', name: self::STOP_ROUTE, methods: ['POST'])]
    public function stop(Request $request, string $key): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_csrf_token'))) {
            return self::refusal(self::FORM_EXPIRED);
        }
        TicketQueue::endIn($request->getSession());
        $listQuery = TicketListContext::fromRequestOrPlain($request, $actor, $this->projectQueries)->query();
        if ('1' === $request->request->getString(self::BACK_FIELD)) {
            return $this->redirectToRoute(TicketListController::ROUTE, $listQuery, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute(TicketShowController::ROUTE, ['key' => $key, ...$listQuery], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param list<TicketListGroup> $groups
     */
    private static function stepIn(array $groups, string $stepId): ?TicketListStep
    {
        foreach ($groups as $group) {
            foreach ($group->steps as $step) {
                if ($step->stepId === $stepId) {
                    return $step;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function keysOf(TicketListStep $step): array
    {
        return array_map(static fn (TicketListRow $row): string => $row->key, $step->rows);
    }

    private static function refusal(string $message): Response
    {
        return new Response($message, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
