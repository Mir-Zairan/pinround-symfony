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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tickets list's filters as its URL carries them, read and checked in this one place for the list, its toolbar
 * and the ticket page, which gets them from a list row's link to walk that list with Previous and Next.
 */
final readonly class TicketListContext
{
    public const string PROJECT_QUERY = 'project';
    public const string DONE_QUERY = 'done';
    public const string SEARCH_QUERY = 'q';
    public const string PERSON_QUERY = 'person';
    public const string TEAM_QUERY = 'team';

    public const string NO_PERSON = 'No person has that id.';
    public const string NO_PROJECT = 'No project has that key.';
    public const string NO_TEAM_OF_YOURS = 'You are in no team with that id.';
    public const string SEARCH_TOO_LONG = 'The search is too long. Use '.ListFilter::SEARCH_TEXT_MAX_LENGTH.' characters or fewer.';
    public const string SEARCH_NOT_TEXT = 'The search is not valid text.';

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
     * @throws BadRequestHttpException with searchRefusalOf's reason as its message, for a search
     *                                 the list refuses, before any query
     * @throws NotFoundHttpException   for a person that is not a canonical uuid, a team that is not one of the
     *                                 viewer's, or a project key the organization has no project with
     * @throws BadRequestException     for a filter sent as an array, which Symfony answers with a 400
     */
    public static function fromRequest(Request $request, ActorContext $viewer, ProjectQueries $projectQueries): self
    {
        $searchText = trim($request->query->getString(self::SEARCH_QUERY));
        $searchRefusal = self::searchRefusalOf($searchText);
        if (null !== $searchRefusal) {
            throw new BadRequestHttpException($searchRefusal);
        }
        $assigneeId = $request->query->getString(self::PERSON_QUERY);
        if ('' !== $assigneeId && !ListFilter::isCanonicalUuid($assigneeId)) {
            throw new NotFoundHttpException(self::NO_PERSON);
        }
        $pickedTeam = $request->query->getString(self::TEAM_QUERY);
        $pickedTeamId = '' !== $pickedTeam && ListFilter::isCanonicalUuid($pickedTeam) ? new TeamId($pickedTeam) : null;
        if ('' !== $pickedTeam && (null === $pickedTeamId || !$viewer->roles()->isInTeam($pickedTeamId))) {
            throw new NotFoundHttpException(self::NO_TEAM_OF_YOURS);
        }
        $projectKey = $request->query->getString(self::PROJECT_QUERY);

        return new self(
            '' === $projectKey ? null : ($projectQueries->projectByKey($projectKey) ?? throw new NotFoundHttpException(self::NO_PROJECT)),
            '1' === $request->query->getString(self::DONE_QUERY),
            '' === $searchText ? null : $searchText,
            '' === $assigneeId ? null : $assigneeId,
            $pickedTeamId,
        );
    }

    /**
     * The list a page was opened from, for pages that still work without it (the ticket page, the queue's Stop): a
     * filter the list would refuse only drops the context, as fromRequest's refusals would.
     */
    public static function fromRequestOrPlain(Request $request, ActorContext $viewer, ProjectQueries $projectQueries): self
    {
        try {
            return self::fromRequest($request, $viewer, $projectQueries);
        } catch (HttpExceptionInterface|BadRequestException) {
            return new self();
        }
    }

    /**
     * @param string $searchText already trimmed; empty for no search
     *
     * @return string|null why the list refuses the search, or null when it takes it
     */
    public static function searchRefusalOf(string $searchText): ?string
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
     * @param list<string> $turnTicketIds for "Me", the tickets waiting on the viewer's turn (ListTurnTickets::of)
     */
    public function filterFor(TicketVisibility $visibility, array $turnTicketIds = []): ListFilter
    {
        return new ListFilter($visibility, $this->project?->id(), $this->showsDone, $this->searchText, $this->assigneeId, null === $this->assigneeId ? [] : $turnTicketIds);
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
            self::PROJECT_QUERY => $this->project?->key(),
            self::PERSON_QUERY => $this->assigneeId,
            self::DONE_QUERY => $this->showsDone ? '1' : null,
            self::SEARCH_QUERY => $this->searchText,
            self::TEAM_QUERY => $this->pickedTeamId?->toString(),
        ], static fn (?string $value): bool => null !== $value);
    }
}
