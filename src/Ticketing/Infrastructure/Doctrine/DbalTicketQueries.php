<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

/**
 * TicketQueries as the query of data-model.md "Ticket list grouped by step": Workflow's StepQueries::listedSteps()
 * gives the steps in list order, and their ids, unnested WITH ORDINALITY, drive a LATERAL per step that takes
 * PAGE_SIZE + 1 rows, the extra one only saying there is more, through
 * tkt_ticket_step_id_priority_rank_updated_at_id_idx or, for one project,
 * tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx. With a search, the LATERALs read only the tickets
 * search_ticket_ids returns, in search order.
 */
final readonly class DbalTicketQueries implements TicketQueries
{
    private const string TICKET_ALIAS = 'ticket';
    private const string COUNTED_ALIAS = 'counted';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private StepQueries $stepQueries,
        private TurnUserQueries $turnUserQueries,
        private UserQueries $userQueries,
    ) {
    }

    public function list(ListFilter $filter, ?Cursor $cursor = null): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The tickets list is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($filter->visibility->organizationId())) {
            throw new \LogicException('The tickets list is read under the viewer\'s own organization as tenant.');
        }
        if (null !== $cursor && (null === $filter->searchText) !== (null === $cursor->matchPosition)) {
            throw new \InvalidArgumentException('A cursor has a match position exactly when the list has a search.');
        }

        $shownSteps = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            $isShown = ($filter->showsDone || TicketListGroup::DONE !== $step->groupCode)
                && (null === $cursor || $cursor->stepId === $step->id);
            if ($isShown) {
                $shownSteps[$step->id] = $step;
            }
        }
        if ([] === $shownSteps) {
            return [];
        }

        $ticketRows = $this->connection->fetchAllAssociative(...$this->listQuery($filter, $cursor, $organizationId->toString(), array_keys($shownSteps)));

        return $this->groupsOf($ticketRows, $shownSteps);
    }

    /**
     * The step's count is its own LATERAL, run once per step and the same with or without a cursor. Without a search it
     * counts at most COUNT_CAP + 1 visible tickets in the row fetch's order, so it reads the same index and stops there;
     * with one it is the step's share of the COUNT_CAP matches search_ticket_ids keeps (searchListQuery).
     *
     * @param non-empty-list<string> $stepIds in list order
     *
     * @return array{string, array<string, string|int|null>}
     */
    private function listQuery(ListFilter $filter, ?Cursor $cursor, string $organizationId, array $stepIds): array
    {
        $visibleTicket = TicketVisibilitySql::of($filter->visibility, self::TICKET_ALIAS);
        $parameters = [
            'organization' => $organizationId,
            // The ids come from the database as canonical uuids, so they need no quoting inside the array literal.
            'step_ids' => '{'.implode(',', $stepIds).'}',
            ...$visibleTicket->parameters(),
        ];
        if (null !== $filter->projectId) {
            $parameters['project'] = $filter->projectId;
        }
        if (null !== $filter->assigneeId) {
            $parameters['assignee'] = $filter->assigneeId;
        }

        if (null === $filter->searchText) {
            return $this->priorityListQuery($filter, $cursor, $parameters, $visibleTicket);
        }

        return $this->searchListQuery($filter, $filter->searchText, $cursor, $parameters, $visibleTicket);
    }

    /**
     * Each step in priority order, keyset on (priority rank, updated_at, id).
     *
     * @param array<string, string|int> $parameters
     *
     * @return array{string, array<string, string|int>}
     */
    private function priorityListQuery(ListFilter $filter, ?Cursor $cursor, array $parameters, TicketVisibilitySql $visibleTicket): array
    {
        $visibleCounted = TicketVisibilitySql::of($filter->visibility, self::COUNTED_ALIAS);
        $parameters = [...$parameters, ...$visibleCounted->parameters()];
        $ticketConditions = 'ticket.organization_id = CAST(:organization AS uuid) AND ticket.step_id = listed.step_id'
            .self::projectConditionOf($filter, self::TICKET_ALIAS).self::assigneeConditionOf($filter, self::TICKET_ALIAS);
        $countedConditions = 'counted.organization_id = CAST(:organization AS uuid) AND counted.step_id = listed.step_id'
            .self::projectConditionOf($filter, self::COUNTED_ALIAS).self::assigneeConditionOf($filter, self::COUNTED_ALIAS);
        if (null !== $cursor) {
            $ticketConditions .= ' AND (ticket.priority_rank > CAST(:after_rank AS smallint)'
                .' OR (ticket.priority_rank = CAST(:after_rank AS smallint)'
                .' AND (ticket.updated_at, ticket.id) < (CAST(:after_updated_at AS timestamptz), CAST(:after_id AS uuid))))';
            $parameters['after_rank'] = (string) $cursor->priorityRank;
            $parameters['after_updated_at'] = $cursor->updatedAt->format('Y-m-d H:i:sP');
            $parameters['after_id'] = $cursor->ticketId->toString();
        }

        $sql = 'SELECT listed.step_id, page.id, page.key, page.title, page.type, page.release, page.priority, page.priority_rank,'
            .' page.assignee_id, page.updated_at, step_count.ticket_count'
            .' FROM unnest(CAST(:step_ids AS uuid[])) WITH ORDINALITY AS listed(step_id, list_position)'
            .' CROSS JOIN LATERAL (SELECT count(*) AS ticket_count FROM ('
            .'SELECT 1 FROM tkt_ticket AS counted'
            .' WHERE '.$countedConditions.' AND '.$visibleCounted->sql()
            .' ORDER BY counted.priority_rank, counted.updated_at DESC, counted.id DESC'
            .' LIMIT '.(TicketListStep::COUNT_CAP + 1)
            .') AS capped) AS step_count'
            .' CROSS JOIN LATERAL ('
            .'SELECT ticket.id, ticket.key, ticket.title, ticket.type, ticket.release, ticket.priority, ticket.priority_rank,'
            .' ticket.assignee_id, ticket.updated_at'
            .' FROM tkt_ticket AS ticket'
            .' WHERE '.$ticketConditions.' AND '.$visibleTicket->sql()
            .' ORDER BY ticket.priority_rank, ticket.updated_at DESC, ticket.id DESC'
            .' LIMIT '.(TicketListStep::PAGE_SIZE + 1)
            .') AS page'
            .' ORDER BY listed.list_position, page.priority_rank, page.updated_at DESC, page.id DESC';

        return [$sql, $parameters];
    }

    /**
     * Only the matches of search_ticket_ids, which applies the list's project, steps and assignee before its cap, each
     * step in search order: the exact key first, then the match score, then id, all highest first, the keyset of "Show more".
     * The function returns ids only, so the score is worked out again here, as in its body, for its matches alone,
     * and kept as a numeric of 12 decimals, whose text reads back exactly in the cursor. The function runs once in
     * the same statement; its ids are joined back to tkt_ticket under row-level security and TicketVisibilitySql once,
     * in a CTE that both LATERALs read by step, so they never show a row the list would not.
     *
     * @param array<string, string|int|null> $parameters
     *
     * @return array{string, array<string, string|int|null>}
     */
    private function searchListQuery(ListFilter $filter, string $searchText, ?Cursor $cursor, array $parameters, TicketVisibilitySql $visibleTicket): array
    {
        $visibility = $filter->visibility;
        $parameters['search_text'] = $searchText;
        $parameters['search_viewer'] = $visibility->viewerId()->toString();
        // As in TicketVisibilitySql: pdo_pgsql binds no arrays, and a uuid holds no comma or brace.
        $parameters['search_team_ids'] = '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $visibility->teamIds())).'}';
        $parameters['search_is_admin'] = $visibility->isAdmin() ? 'true' : 'false';
        $parameters['search_is_triager'] = $visibility->isTriager() ? 'true' : 'false';
        $parameters['search_project'] = $filter->projectId;
        $parameters['search_assignee'] = $filter->assigneeId;
        $pageConditions = 'searched.step_id = listed.step_id';
        $searchOrderValues = $cursor?->searchOrderValues();
        if (null !== $cursor && null !== $searchOrderValues) {
            $pageConditions .= ' AND (searched.is_exact_key_match, searched.match_score, searched.id)'
                .' < (CAST(:after_exact_key_match AS boolean), CAST(:after_match_score AS numeric), CAST(:after_id AS uuid))';
            $parameters['after_exact_key_match'] = $searchOrderValues[0] ? 'true' : 'false';
            $parameters['after_match_score'] = $searchOrderValues[1];
            $parameters['after_id'] = $cursor->ticketId->toString();
        }

        // The score as search_ticket_ids orders by it; the function owns the formula, this only repeats it.
        $matchScore = "ts_rank(ticket.search, websearch_to_tsquery('simple', tkt_unaccent(CAST(:search_text AS text)))) * 0.6"
            .' + similarity(ticket.title, CAST(:search_text AS text)) * 0.4';
        // The function returns one id past the cap only to say the cap was hit; that one is never shown.
        $sql = 'WITH matched AS MATERIALIZED (SELECT found.ticket_id, found.found_position'
            .' FROM search_ticket_ids(CAST(:organization AS uuid), CAST(:search_text AS text), CAST(:search_viewer AS uuid),'
            .' CAST(:search_team_ids AS uuid[]), CAST(:search_is_admin AS boolean), CAST(:search_is_triager AS boolean),'
            .' CAST(:search_project AS uuid), CAST(:step_ids AS uuid[]), CAST(:search_assignee AS uuid)) WITH ORDINALITY AS found(ticket_id, found_position)),'
            .' searched AS MATERIALIZED (SELECT ticket.id, ticket.step_id, ticket.key, ticket.title, ticket.type, ticket.release,'
            .' ticket.priority, ticket.priority_rank, ticket.assignee_id, ticket.updated_at,'
            .' ticket.key = upper(btrim(CAST(:search_text AS text))) AS is_exact_key_match,'
            .' round(CAST('.$matchScore.' AS numeric), 12) AS match_score'
            .' FROM matched JOIN tkt_ticket AS ticket ON ticket.id = matched.ticket_id'
            .' WHERE matched.found_position <= '.TicketListStep::COUNT_CAP
            .' AND ticket.organization_id = CAST(:organization AS uuid) AND ticket.step_id = ANY(CAST(:step_ids AS uuid[]))'
            .self::projectConditionOf($filter, self::TICKET_ALIAS).self::assigneeConditionOf($filter, self::TICKET_ALIAS)
            .' AND '.$visibleTicket->sql().')'
            .' SELECT listed.step_id, page.id, page.key, page.title, page.type, page.release, page.priority, page.priority_rank,'
            .' page.assignee_id, page.updated_at, page.is_exact_key_match, page.match_score, step_count.ticket_count,'
            .' (SELECT count(*) FROM matched) > '.TicketListStep::COUNT_CAP.' AS is_search_capped'
            .' FROM unnest(CAST(:step_ids AS uuid[])) WITH ORDINALITY AS listed(step_id, list_position)'
            .' CROSS JOIN LATERAL (SELECT count(*) AS ticket_count FROM searched WHERE searched.step_id = listed.step_id) AS step_count'
            .' CROSS JOIN LATERAL (SELECT searched.* FROM searched WHERE '.$pageConditions
            .' ORDER BY searched.is_exact_key_match DESC, searched.match_score DESC, searched.id DESC'
            .' LIMIT '.(TicketListStep::PAGE_SIZE + 1).') AS page'
            .' ORDER BY listed.list_position, page.is_exact_key_match DESC, page.match_score DESC, page.id DESC';

        return [$sql, $parameters];
    }

    private static function projectConditionOf(ListFilter $filter, string $alias): string
    {
        return null === $filter->projectId ? '' : ' AND '.$alias.'.project_id = CAST(:project AS uuid)';
    }

    private static function assigneeConditionOf(ListFilter $filter, string $alias): string
    {
        return null === $filter->assigneeId ? '' : ' AND '.$alias.'.assignee_id = CAST(:assignee AS uuid)';
    }

    /**
     * @param list<array<string, mixed>>    $ticketRows PAGE_SIZE + 1 at most per step, in list order
     * @param array<string, ListedStepView> $shownSteps by id, in list order
     *
     * @return list<TicketListGroup>
     */
    private function groupsOf(array $ticketRows, array $shownSteps): array
    {
        /** @var array<string, array{ticketCount: int, isSearchCapped: bool, rows: list<array<string, mixed>>, nextCursor: ?Cursor}> $pages */
        $pages = [];
        $shownTicketIds = [];
        $userIds = [];
        foreach ($ticketRows as $row) {
            $stepId = self::text($row['step_id'], 'step id');
            $pages[$stepId] ??= [
                'ticketCount' => self::integer($row['ticket_count'], 'ticket count'),
                'isSearchCapped' => \array_key_exists('is_search_capped', $row) && self::boolean($row['is_search_capped'], 'search capped'),
                'rows' => [],
                'nextCursor' => null,
            ];
            if (TicketListStep::PAGE_SIZE === \count($pages[$stepId]['rows'])) {
                $lastRow = $pages[$stepId]['rows'][TicketListStep::PAGE_SIZE - 1];
                $pages[$stepId]['nextCursor'] = new Cursor(
                    $stepId,
                    self::integer($lastRow['priority_rank'], 'priority rank'),
                    new \DateTimeImmutable(self::text($lastRow['updated_at'], 'updated_at')),
                    new TicketId(self::text($lastRow['id'], 'ticket id')),
                    \array_key_exists('match_score', $lastRow)
                        ? Cursor::matchPositionOf(self::boolean($lastRow['is_exact_key_match'], 'exact key match'), self::text($lastRow['match_score'], 'match score'))
                        : null,
                );
                continue;
            }
            $pages[$stepId]['rows'][] = $row;
            $shownTicketIds[] = new TicketId(self::text($row['id'], 'ticket id'));
            if (null !== $row['assignee_id']) {
                $userIds[self::text($row['assignee_id'], 'assignee id')] = true;
            }
        }

        $turnUsers = $this->turnUserQueries->turnUsersOf($shownTicketIds);
        foreach ($turnUsers as $turnUserId) {
            $userIds[$turnUserId->toString()] = true;
        }
        $users = $this->userQueries->byIds(array_map(static fn (string $userId): UserId => new UserId($userId), array_keys($userIds)));

        /** @var array<string, array{step: ListedStepView, steps: list<TicketListStep>}> $groupsByCode */
        $groupsByCode = [];
        foreach ($shownSteps as $stepId => $step) {
            if (!isset($pages[$stepId])) {
                continue;
            }
            $page = $pages[$stepId];
            $groupsByCode[$step->groupCode] ??= ['step' => $step, 'steps' => []];
            $groupsByCode[$step->groupCode]['steps'][] = new TicketListStep(
                $stepId,
                $step->name,
                $step->isArchived,
                min($page['ticketCount'], TicketListStep::COUNT_CAP),
                // Past the search's cap a step may hold more matches than it counts, however few it shows.
                $page['ticketCount'] > TicketListStep::COUNT_CAP || $page['isSearchCapped'],
                array_map(static function (array $row) use ($stepId, $turnUsers, $users): TicketListRow {
                    $ticketId = self::text($row['id'], 'ticket id');
                    $assigneeId = null === $row['assignee_id'] ? null : new UserId(self::text($row['assignee_id'], 'assignee id'));
                    $turnUserId = $turnUsers[$ticketId] ?? null;

                    return new TicketListRow(
                        new TicketId($ticketId),
                        self::text($row['key'], 'key'),
                        self::text($row['title'], 'title'),
                        self::text($row['type'], 'type'),
                        null === $row['release'] ? null : self::text($row['release'], 'release'),
                        self::text($row['priority'], 'priority'),
                        $assigneeId,
                        null === $assigneeId ? null : ($users[$assigneeId->toString()] ?? null)?->displayName,
                        $turnUserId,
                        null === $turnUserId ? null : ($users[$turnUserId->toString()] ?? null)?->firstName(),
                        new \DateTimeImmutable(self::text($row['updated_at'], 'updated_at')),
                        $stepId,
                    );
                }, $page['rows']),
                $page['nextCursor'],
            );
        }

        return array_values(array_map(
            static fn (array $group): TicketListGroup => new TicketListGroup($group['step']->groupCode, $group['step']->groupLabel, $group['step']->groupHint, $group['steps']),
            $groupsByCode,
        ));
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('A ticket list %s from the database is not a string.', $column));
        }

        return $value;
    }

    private static function boolean(mixed $value, string $column): bool
    {
        if (!\is_bool($value)) {
            throw new \LogicException(\sprintf('A ticket list %s from the database is not a boolean.', $column));
        }

        return $value;
    }

    private static function integer(mixed $value, string $column): int
    {
        if (!\is_int($value)) {
            throw new \LogicException(\sprintf('A ticket list %s from the database is not an integer.', $column));
        }

        return $value;
    }
}
