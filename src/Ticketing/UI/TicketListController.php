<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\CurrentTimeZone;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\TeamPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
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
 * TicketListCursorParameter; a malformed one is a 400. SEARCH_QUERY, trimmed, keeps only the tickets that match it,
 * still grouped by step, each step in search order; a search longer than ListFilter::SEARCH_TEXT_MAX_LENGTH
 * characters, or not text, is a 400 before any query. PERSON_QUERY keeps only the tickets assigned to that person; an
 * id that is not a canonical uuid is a 404, like a project key of no project. The toolbar is TicketFilters, the
 * project rail ProjectRail, both for the team railTeamOf chooses, never one the viewer may not see; TEAM_QUERY picks
 * one of the viewer's own teams for "All projects", and any other id is a 404. TicketListContext reads and checks these
 * filters, for the ticket page too, and each row links to its ticket with them.
 *
 * Five queries at most for the list whatever the number of tickets, with a search or without: the triager, the
 * project by key, then the list's rows, turn users and names (TicketQueries::list); the whole page's toolbar adds two
 * at most (TicketFilters) and the rail three (performance-and-scale.md: eight for the page, a decision of 2026-11-12).
 * A request from the list's Turbo frames (TURBO_FRAME_HEADER) runs only the list's; one from the rail's frame
 * (RAIL_FRAME_ID), sent after each filter change, only the project and the rail's.
 */
final class TicketListController extends AbstractController
{
    public const string ROUTE = 'ticketing_tickets';
    // TicketListContext names the filters; ProjectRail still reads these.
    public const string PROJECT_QUERY = TicketListContext::PROJECT_QUERY;
    public const string DONE_QUERY = TicketListContext::DONE_QUERY;
    public const string SEARCH_QUERY = TicketListContext::SEARCH_QUERY;
    public const string TEAM_QUERY = TicketListContext::TEAM_QUERY;
    public const string TURBO_FRAME_HEADER = 'Turbo-Frame';
    public const string RAIL_FRAME_ID = 'project-rail-frame';

    public const string ALL_TICKETS = 'All tickets';
    public const string NO_TICKETS = 'No tickets here yet.';
    public const string NO_MATCHES = 'No tickets match. Clear the search or pick another person.';
    public const string SHOW_MORE_NOT_VALID = 'That "Show more" link is not valid.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly ProjectQueries $projectQueries,
        private readonly TicketQueries $ticketQueries,
        private readonly ClockInterface $clock,
        private readonly CurrentTimeZone $currentTimeZone,
        private readonly ProjectRail $projectRail,
    ) {
    }

    #[Route('/tickets', name: self::ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        try {
            $listContext = TicketListContext::fromRequest($request, $actor, $this->projectQueries);
        } catch (BadRequestHttpException $searchRefusal) {
            return new Response($searchRefusal->getMessage(), Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $project = $listContext->project;
        $showsDone = $listContext->showsDone;
        $searchText = $listContext->searchText;
        $assigneeId = $listContext->assigneeId;
        $pickedTeamId = $listContext->pickedTeamId;
        $cursor = $this->cursorOf($request);
        if (null !== $cursor && (null === $searchText) !== (null === $cursor->matchPosition)) {
            throw new BadRequestHttpException(self::SHOW_MORE_NOT_VALID);
        }
        $railTeamId = $this->railTeamOf($actor, $project, $pickedTeamId);
        $isProjectsTeamOnRail = null !== $railTeamId && null !== $project?->teamId() && $railTeamId->equals($project->teamId());
        $keptQuery = array_filter(
            [TicketListContext::PERSON_QUERY => $assigneeId, TicketListContext::DONE_QUERY => $showsDone ? '1' : null, TicketListContext::SEARCH_QUERY => $searchText],
            static fn (?string $value): bool => null !== $value,
        );
        // The rail's frame, reloaded after a filter change, gets the rail alone and runs none of the list's queries.
        if (self::RAIL_FRAME_ID === $request->headers->get(self::TURBO_FRAME_HEADER)) {
            $response = $this->render('@Ticketing/_project_rail.html.twig', [
                'rail' => $this->projectRail->viewOf($actor, $railTeamId, $project, $showsDone, $keptQuery),
            ]);
            $response->setVary(self::TURBO_FRAME_HEADER, false);

            return $response;
        }

        $visibility = $this->ticketVisibilities->ofActor($actor);
        $groups = $this->ticketQueries->list($listContext->filterFor($visibility), $cursor);
        $collapsedStepIds = CollapsedSteps::idsIn($request->getSession());

        // A frame of this page (the list after a filter change, or "Show more") replaces only its own frame, so it gets
        // the list frame alone: no layout, menu, toolbar or rail, and none of their queries.
        $isFrameRequest = $request->headers->has(self::TURBO_FRAME_HEADER);
        $response = $this->render($isFrameRequest ? '@Ticketing/_list_frame.html.twig' : '@Ticketing/list.html.twig', [
            'rail' => $isFrameRequest ? null : $this->projectRail->viewOf($actor, $railTeamId, $project, $showsDone, $keptQuery),
            'heading' => null === $project ? self::ALL_TICKETS : $project->key().' · '.$project->name(),
            'totalLine' => TicketListText::totalLineOf($groups),
            'groups' => $this->groupViewsOf($groups, $actor->userId(), $project, $showsDone, $searchText, $assigneeId, $cursor, $collapsedStepIds),
            // A row opens its ticket inside this list, so the page's Previous and Next walk it (ticket-page.md, Header).
            'listQuery' => $listContext->query(),
            'noTickets' => null === $searchText && null === $assigneeId ? self::NO_TICKETS : self::NO_MATCHES,
            'filters' => [
                'project' => $project?->key() ?? '',
                'projectId' => $project?->id() ?? '',
                'person' => $assigneeId ?? '',
                'showsDone' => $showsDone,
                'q' => $searchText ?? '',
                'team' => $railTeamId?->toString() ?? '',
                // When the project's own team is on the rail, the project decides it and the toolbar's links drop the
                // pick; for a project of no team, or of one the viewer may not see, the picked team still decides it.
                'pickedTeam' => $isProjectsTeamOnRail ? '' : ($pickedTeamId?->toString() ?? ''),
            ],
        ]);
        $response->setVary(self::TURBO_FRAME_HEADER, false);

        return $response;
    }

    /**
     * The one choice of team for both the rail and the toolbar's people chips: the picked project's team when the
     * viewer may see it (TeamPermission::VIEW); for "All projects", a project no team owns or one of a team the viewer
     * may not see, the team picked with TEAM_QUERY, else the viewer's first team by id.
     *
     * @return TeamId|null null for a viewer in no team whom the project's team, if any, is not shown to
     */
    private function railTeamOf(ActorContext $viewer, ?ProjectChoice $project, ?TeamId $pickedTeamId): ?TeamId
    {
        $projectsTeamId = $project?->teamId();
        if (null !== $projectsTeamId && $this->isGranted(TeamPermission::VIEW, $projectsTeamId)) {
            return $projectsTeamId;
        }

        return $pickedTeamId ?? $viewer->roles()->teamIds()[0] ?? null;
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

        return TicketListCursorParameter::cursorOf($stepId, $after) ?? throw new BadRequestHttpException(self::SHOW_MORE_NOT_VALID);
    }

    /**
     * The Turbo frame a "Show more" link sits in, named by the page it opens; the response to that link holds a frame of
     * the same id around the page's rows, so only those rows replace the link.
     */
    private static function frameIdOf(Cursor $cursor): string
    {
        return 'step-'.$cursor->stepId.'-after-'.substr(hash('sha256', TicketListCursorParameter::queryOf($cursor)[TicketListCursorParameter::AFTER_QUERY]), 0, 16);
    }

    /**
     * @param list<TicketListGroup> $groups
     * @param list<string>          $collapsedStepIds
     *
     * @return list<array{code: string, label: string, hint: string|null, steps: list<array<string, mixed>>}>
     */
    private function groupViewsOf(array $groups, UserId $viewerId, ?ProjectChoice $project, bool $showsDone, ?string $searchText, ?string $assigneeId, ?Cursor $cursor, array $collapsedStepIds): array
    {
        $now = $this->clock->now();
        $viewerTimeZone = $this->currentTimeZone->zone();
        $listQuery = array_filter(
            [TicketListContext::PROJECT_QUERY => $project?->key(), TicketListContext::PERSON_QUERY => $assigneeId, TicketListContext::DONE_QUERY => $showsDone ? '1' : null, TicketListContext::SEARCH_QUERY => $searchText],
            static fn (?string $value): bool => null !== $value,
        );

        return array_map(fn (TicketListGroup $group): array => [
            'code' => $group->code,
            'label' => $group->label,
            'hint' => $group->hint,
            'steps' => array_map(fn (TicketListStep $step): array => [
                'id' => $step->stepId,
                'name' => $step->name,
                'isArchived' => $step->isArchived,
                'count' => TicketListText::stepCountOf($step),
                'isCollapsed' => \in_array($step->stepId, $collapsedStepIds, true),
                'collapseUrl' => $this->generateUrl(StepCollapseController::ROUTE, ['stepId' => $step->stepId]),
                'csrfId' => StepCollapseController::CSRF_ID,
                'pageFrameId' => $cursor?->stepId === $step->stepId ? self::frameIdOf($cursor) : null,
                'moreFrameId' => null === $step->nextCursor ? null : self::frameIdOf($step->nextCursor),
                'rows' => array_map(static fn (TicketListRow $row): array => [
                    'key' => $row->key,
                    'title' => $row->title,
                    'meta' => TicketListText::metaOf($row, $viewerId, TicketListGroup::DONE === $group->code),
                    'priority' => TicketListText::priorityOf($row),
                    'isUrgent' => TicketListText::isUrgent($row),
                    'assigneeName' => $row->assigneeName,
                    'updated' => TicketListText::updatedOf($row->updatedAt, $now, $viewerTimeZone),
                    'updatedAt' => $row->updatedAt->format(\DateTimeInterface::ATOM),
                ], $step->rows),
                'moreUrl' => null === $step->nextCursor ? null : $this->generateUrl(self::ROUTE, [...$listQuery, ...TicketListCursorParameter::queryOf($step->nextCursor)]),
            ], $group->steps),
        ], $groups);
    }
}
