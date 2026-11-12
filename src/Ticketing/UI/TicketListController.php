<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\CurrentTimeZone;
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
 * TicketListCursorParameter; a malformed one is a 400. SEARCH_QUERY, trimmed, keeps only the tickets that match it,
 * still grouped by step, each step in search order; a search longer than ListFilter::SEARCH_TEXT_MAX_LENGTH
 * characters, or not text, is a 400 before any query.
 *
 * Five queries at most whatever the number of tickets, with a search or without: the triager, the project by key,
 * then the list's rows, turn users and names (TicketQueries::list).
 */
final class TicketListController extends AbstractController
{
    public const string ROUTE = 'ticketing_tickets';
    public const string PROJECT_QUERY = 'project';
    public const string DONE_QUERY = 'done';
    public const string SEARCH_QUERY = 'q';

    public const string ALL_TICKETS = 'All tickets';
    public const string NO_TICKETS = 'No tickets here yet.';
    public const string NO_MATCHES = 'No tickets match. Clear the search or pick another person.';
    public const string SEARCH_TOO_LONG = 'The search is too long. Use '.ListFilter::SEARCH_TEXT_MAX_LENGTH.' characters or fewer.';
    public const string SEARCH_NOT_TEXT = 'The search is not valid text.';
    public const string SHOW_MORE_NOT_VALID = 'That "Show more" link is not valid.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly RoleTicketVisibilities $ticketVisibilities,
        private readonly ProjectQueries $projectQueries,
        private readonly TicketQueries $ticketQueries,
        private readonly ClockInterface $clock,
        private readonly CurrentTimeZone $currentTimeZone,
    ) {
    }

    #[Route('/tickets', name: self::ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $searchText = trim($request->query->getString(self::SEARCH_QUERY));
        $searchRefusal = self::searchRefusalOf($searchText);
        if (null !== $searchRefusal) {
            return new Response($searchRefusal, Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $searchText = '' === $searchText ? null : $searchText;
        $cursor = $this->cursorOf($request);
        if (null !== $cursor && (null === $searchText) !== (null === $cursor->matchPosition)) {
            throw new BadRequestHttpException(self::SHOW_MORE_NOT_VALID);
        }
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $showsDone = '1' === $request->query->getString(self::DONE_QUERY);

        $visibility = $this->ticketVisibilities->ofActor($actor);
        $projectKey = $request->query->getString(self::PROJECT_QUERY);
        $project = '' === $projectKey ? null : ($this->projectQueries->projectByKey($projectKey) ?? throw $this->createNotFoundException('No project has that key.'));
        $groups = $this->ticketQueries->list(new ListFilter($visibility, $project?->id(), $showsDone, $searchText), $cursor);
        $collapsedStepIds = CollapsedSteps::idsIn($request->getSession());

        return $this->render('@Ticketing/list.html.twig', [
            'heading' => null === $project ? self::ALL_TICKETS : $project->key().' · '.$project->name(),
            'totalLine' => TicketListText::totalLineOf($groups),
            'groups' => $this->groupViewsOf($groups, $actor->userId(), $project, $showsDone, $searchText, $cursor, $collapsedStepIds),
            'noTickets' => null === $searchText ? self::NO_TICKETS : self::NO_MATCHES,
        ]);
    }

    /**
     * @param string $searchText already trimmed; empty for no search
     *
     * @return string|null why the list refuses the search, or null when it takes it
     */
    private static function searchRefusalOf(string $searchText): ?string
    {
        if (!mb_check_encoding($searchText, 'UTF-8') || str_contains($searchText, "\0")) {
            return self::SEARCH_NOT_TEXT;
        }
        if (mb_strlen($searchText, 'UTF-8') > ListFilter::SEARCH_TEXT_MAX_LENGTH) {
            return self::SEARCH_TOO_LONG;
        }

        return null;
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
    private function groupViewsOf(array $groups, UserId $viewerId, ?ProjectChoice $project, bool $showsDone, ?string $searchText, ?Cursor $cursor, array $collapsedStepIds): array
    {
        $now = $this->clock->now();
        $viewerTimeZone = $this->currentTimeZone->zone();
        $listQuery = array_filter(
            [self::PROJECT_QUERY => $project?->key(), self::DONE_QUERY => $showsDone ? '1' : null, self::SEARCH_QUERY => $searchText],
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
