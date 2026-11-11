<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Application\RoleTicketVisibilities;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The tickets list at /tickets (FR-TKT-02, FR-TKT-18, P03.T3): the tickets the viewer may see, one section per step in group
 * order, of every project or of the one in PROJECT_QUERY (404 for a key the organization has no project with), Done
 * only with DONE_QUERY=1. Every person of the organization may open it (OrganizationPermission::VIEW); the rows are
 * the ticket visibility predicate's (ADR-0013). A "Show more" link opens the next page of one step through
 * TicketListCursorParameter; a malformed one is a 400.
 *
 * Five queries at most whatever the number of tickets: the triager, the project by key, then the list's rows, turn
 * users and names (TicketQueries::list).
 */
final class TicketListController extends AbstractController
{
    public const string ROUTE = 'ticketing_tickets';
    public const string PROJECT_QUERY = 'project';
    public const string DONE_QUERY = 'done';

    public const string ALL_TICKETS = 'All tickets';
    public const string NO_TICKETS = 'No tickets here yet.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly ProjectQueries $projectQueries,
        private readonly TicketQueries $ticketQueries,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/tickets', name: self::ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $cursor = $this->cursorOf($request);
        $showsDone = '1' === $request->query->getString(self::DONE_QUERY);

        $visibility = $this->ticketVisibilities->ofActor($actor);
        $projectKey = $request->query->getString(self::PROJECT_QUERY);
        $project = '' === $projectKey ? null : ($this->projectQueries->projectByKey($projectKey) ?? throw $this->createNotFoundException('No project has that key.'));
        $groups = $this->ticketQueries->list(new ListFilter($visibility, $project?->id(), $showsDone), $cursor);

        return $this->render('@Ticketing/list.html.twig', [
            'heading' => null === $project ? self::ALL_TICKETS : $project->key().' · '.$project->name(),
            'totalLine' => TicketListText::totalLineOf($groups),
            'groups' => $this->groupViewsOf($groups, $actor->userId(), $project, $showsDone),
            'noTickets' => self::NO_TICKETS,
        ]);
    }

    /**
     * @throws BadRequestHttpException when the step or the after value is sent but the pair is not one a "Show more"
     *                                 link carries
     */
    private function cursorOf(Request $request): ?Cursor
    {
        $stepId = $request->query->getString(TicketListCursorParameter::STEP_QUERY);
        $after = $request->query->getString(TicketListCursorParameter::AFTER_QUERY);
        if ('' === $stepId && '' === $after) {
            return null;
        }

        return TicketListCursorParameter::cursorOf($stepId, $after) ?? throw new BadRequestHttpException('That "Show more" link is not valid.');
    }

    /**
     * @param list<TicketListGroup> $groups
     *
     * @return list<array{code: string, label: string, hint: string|null, steps: list<array<string, mixed>>}>
     */
    private function groupViewsOf(array $groups, UserId $viewerId, ?ProjectChoice $project, bool $showsDone): array
    {
        $now = $this->clock->now();
        $listQuery = array_filter([self::PROJECT_QUERY => $project?->key(), self::DONE_QUERY => $showsDone ? '1' : null]);

        return array_map(fn (TicketListGroup $group): array => [
            'code' => $group->code,
            'label' => $group->label(),
            'hint' => $group->hint(),
            'steps' => array_map(fn (TicketListStep $step): array => [
                'id' => $step->stepId,
                'name' => $step->name,
                'isArchived' => $step->isArchived,
                'count' => TicketListText::stepCountOf($step),
                'rows' => array_map(static fn (TicketListRow $row): array => [
                    'key' => $row->key,
                    'title' => $row->title,
                    'meta' => TicketListText::metaOf($row, $viewerId, TicketListGroup::DONE === $group->code),
                    'priority' => TicketListText::priorityOf($row),
                    'isUrgent' => TicketListText::isUrgent($row),
                    'assigneeName' => $row->assigneeName,
                    'updated' => TicketListText::updatedOf($row->updatedAt, $now),
                    'updatedAt' => $row->updatedAt->format(\DateTimeInterface::ATOM),
                ], $step->rows),
                'moreUrl' => null === $step->nextCursor ? null : $this->generateUrl(self::ROUTE, [...$listQuery, ...TicketListCursorParameter::queryOf($step->nextCursor)]),
            ], $group->steps),
        ], $groups);
    }
}
