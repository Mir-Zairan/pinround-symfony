<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketMatch;
use App\Ticketing\Api\TicketMatches;
use App\Ticketing\Api\TicketSearchQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

/**
 * TicketSearchQueries on search_ticket_ids, the SECURITY DEFINER function the tickets list's search runs, so it uses
 * the GIN indexes of tkt_ticket. The function gets the open steps and the project, so a Done ticket or one of another
 * project never takes the place of a match shown here; its ids are numbered WITH ORDINALITY in its order and joined
 * back to tkt_ticket under row-level security and TicketVisibilitySql, one past the limit to tell there are more.
 */
final readonly class DbalTicketSearchQueries implements TicketSearchQueries
{
    private const string TICKET_ALIAS = 'ticket';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private StepQueries $stepQueries,
        private TurnUserQueries $turnUserQueries,
        private UserQueries $userQueries,
    ) {
    }

    public function openMatchesOf(TicketVisibility $viewer, string $searchText, ?string $projectId, int $limit): TicketMatches
    {
        if (!ListFilter::isSearchText($searchText)) {
            throw new \InvalidArgumentException(\sprintf('A search text must be trimmed, valid UTF-8 without NUL, and 1 to %d characters.', ListFilter::SEARCH_TEXT_MAX_LENGTH));
        }
        if (null !== $projectId && !ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('A search\'s project id must be a canonical lowercase uuid.');
        }
        if ($limit < 1 || $limit > self::MAX_MATCHES) {
            throw new \InvalidArgumentException(\sprintf('A search returns 1 to %d matches.', self::MAX_MATCHES));
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Tickets are searched in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId())) {
            throw new \LogicException('Tickets are searched under the viewer\'s own organization as tenant.');
        }

        $openSteps = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            if (TicketListGroup::DONE !== $step->groupCode) {
                $openSteps[$step->id] = $step;
            }
        }
        if ([] === $openSteps) {
            return new TicketMatches([], false);
        }

        $rows = $this->connection->fetchAllAssociative(...self::matchesQuery($viewer, $organizationId->toString(), $searchText, $projectId, array_keys($openSteps), $limit));
        $hasMore = \count($rows) > $limit;

        return new TicketMatches($this->matchesOf(\array_slice($rows, 0, $limit), $openSteps), $hasMore);
    }

    /**
     * @param non-empty-list<string> $openStepIds
     *
     * @return array{string, array<string, string|null>}
     */
    private static function matchesQuery(TicketVisibility $viewer, string $organizationId, string $searchText, ?string $projectId, array $openStepIds, int $limit): array
    {
        $visibleTicket = TicketVisibilitySql::of($viewer, self::TICKET_ALIAS);
        $sql = 'SELECT ticket.id, ticket.key, ticket.title, ticket.step_id, ticket.assignee_id'
            .' FROM search_ticket_ids(CAST(:organization AS uuid), CAST(:search_text AS text), CAST(:search_viewer AS uuid),'
            .' CAST(:search_team_ids AS uuid[]), CAST(:search_is_admin AS boolean), CAST(:search_is_triager AS boolean),'
            .' CAST(:project AS uuid), CAST(:step_ids AS uuid[]), CAST(NULL AS uuid)) WITH ORDINALITY AS found(ticket_id, found_position)'
            .' JOIN tkt_ticket AS ticket ON ticket.organization_id = CAST(:organization AS uuid) AND ticket.id = found.ticket_id'
            .' WHERE ticket.step_id = ANY(CAST(:step_ids AS uuid[]))'
            .(null === $projectId ? '' : ' AND ticket.project_id = CAST(:project AS uuid)')
            .' AND '.$visibleTicket->sql()
            .' ORDER BY found.found_position'
            .' LIMIT '.($limit + 1);

        return [$sql, [
            'organization' => $organizationId,
            'search_text' => $searchText,
            'search_viewer' => $viewer->viewerId()->toString(),
            // As in TicketVisibilitySql: pdo_pgsql binds no arrays, and a uuid holds no comma or brace.
            'search_team_ids' => '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $viewer->teamIds())).'}',
            'search_is_admin' => $viewer->isAdmin() ? 'true' : 'false',
            'search_is_triager' => $viewer->isTriager() ? 'true' : 'false',
            'project' => $projectId,
            'step_ids' => '{'.implode(',', $openStepIds).'}',
            ...$visibleTicket->parameters(),
        ]];
    }

    /**
     * @param list<array<string, mixed>>    $rows      in search order
     * @param array<string, ListedStepView> $openSteps by id
     *
     * @return list<TicketMatch>
     */
    private function matchesOf(array $rows, array $openSteps): array
    {
        if ([] === $rows) {
            return [];
        }
        $turnUsers = $this->turnUserQueries->turnUsersOf(array_map(static fn (array $row): TicketId => new TicketId(self::text($row['id'], 'id')), $rows));
        $uniqueTurnUsers = [];
        foreach ($turnUsers as $turnUserId) {
            $uniqueTurnUsers[$turnUserId->toString()] = $turnUserId;
        }
        $people = $this->userQueries->byIds(array_values($uniqueTurnUsers));

        return array_map(static function (array $row) use ($openSteps, $turnUsers, $people): TicketMatch {
            $ticketId = self::text($row['id'], 'id');
            $step = $openSteps[self::text($row['step_id'], 'step id')] ?? throw new \LogicException('A ticket the search found is in a step its organization does not have.');
            $turnUserId = $turnUsers[$ticketId] ?? null;

            return new TicketMatch(
                new TicketId($ticketId),
                self::text($row['key'], 'key'),
                self::text($row['title'], 'title'),
                $step->name,
                null === $row['assignee_id'] ? null : new UserId(self::text($row['assignee_id'], 'assignee id')),
                $turnUserId,
                null === $turnUserId ? null : ($people[$turnUserId->toString()] ?? null)?->displayName,
            );
        }, $rows);
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('A searched ticket\'s %s from the database is not a string.', $column));
        }

        return $value;
    }
}
