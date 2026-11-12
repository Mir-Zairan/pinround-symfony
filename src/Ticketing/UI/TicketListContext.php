<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketVisibility;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tickets list's filters as its URL carries them (TicketListController's PROJECT_QUERY, PERSON_QUERY, DONE_QUERY,
 * SEARCH_QUERY and TEAM_QUERY), read and checked in this one place for the list and for the ticket page, which gets
 * them from a list row's link to walk that list with Previous and Next.
 */
final readonly class TicketListContext
{
    public const string NO_PERSON = 'No person has that id.';
    public const string NO_PROJECT = 'No project has that key.';

    /**
     * @param string|null $searchText   trimmed, one the list takes; null for no search
     * @param string|null $assigneeId   a canonical uuid; null for any assignee
     * @param TeamId|null $pickedTeamId one of the viewer's own teams, picked for the rail; it keeps no ticket out
     */
    public function __construct(
        public ?ProjectChoice $project = null,
        public bool $showsDone = false,
        public ?string $searchText = null,
        public ?string $assigneeId = null,
        public ?TeamId $pickedTeamId = null,
    ) {
    }

    /**
     * Checks in the list's order: the search, then the person, the team and the project, the only one that costs a
     * query (ProjectQueries::projectByKey).
     *
     * @throws BadRequestHttpException with TicketListController::searchRefusalOf's reason as its message, for a search
     *                                 the list refuses, before any query
     * @throws NotFoundHttpException   for a person that is not a canonical uuid, a team that is not one of the
     *                                 viewer's, or a project key the organization has no project with
     * @throws BadRequestException     for a filter sent as an array, which Symfony answers with a 400
     */
    public static function fromRequest(Request $request, ActorContext $viewer, ProjectQueries $projectQueries): self
    {
        $searchText = trim($request->query->getString(TicketListController::SEARCH_QUERY));
        $searchRefusal = TicketListController::searchRefusalOf($searchText);
        if (null !== $searchRefusal) {
            throw new BadRequestHttpException($searchRefusal);
        }
        $assigneeId = $request->query->getString(TicketListController::PERSON_QUERY);
        if ('' !== $assigneeId && !ListFilter::isCanonicalUuid($assigneeId)) {
            throw new NotFoundHttpException(self::NO_PERSON);
        }
        $pickedTeam = $request->query->getString(TicketListController::TEAM_QUERY);
        $pickedTeamId = '' !== $pickedTeam && ListFilter::isCanonicalUuid($pickedTeam) ? new TeamId($pickedTeam) : null;
        if ('' !== $pickedTeam && (null === $pickedTeamId || !$viewer->roles()->isInTeam($pickedTeamId))) {
            throw new NotFoundHttpException(TicketListController::NO_TEAM_OF_YOURS);
        }
        $projectKey = $request->query->getString(TicketListController::PROJECT_QUERY);

        return new self(
            '' === $projectKey ? null : ($projectQueries->projectByKey($projectKey) ?? throw new NotFoundHttpException(self::NO_PROJECT)),
            '1' === $request->query->getString(TicketListController::DONE_QUERY),
            '' === $searchText ? null : $searchText,
            '' === $assigneeId ? null : $assigneeId,
            $pickedTeamId,
        );
    }

    public function filterFor(TicketVisibility $visibility): ListFilter
    {
        return new ListFilter($visibility, $this->project?->id(), $this->showsDone, $this->searchText, $this->assigneeId);
    }

    /**
     * True when the list is the plain open-tickets one: no project, person, search or Done. A picked team alone keeps
     * it plain, as it only picks the rail.
     */
    public function isPlainList(): bool
    {
        return null === $this->project && !$this->showsDone && null === $this->searchText && null === $this->assigneeId;
    }

    /**
     * @return array<string, string> the URL query that gives this list again, only the filters that are set
     */
    public function query(): array
    {
        return array_filter([
            TicketListController::PROJECT_QUERY => $this->project?->key(),
            TicketListController::PERSON_QUERY => $this->assigneeId,
            TicketListController::DONE_QUERY => $this->showsDone ? '1' : null,
            TicketListController::SEARCH_QUERY => $this->searchText,
            TicketListController::TEAM_QUERY => $this->pickedTeamId?->toString(),
        ], static fn (?string $value): bool => null !== $value);
    }
}
