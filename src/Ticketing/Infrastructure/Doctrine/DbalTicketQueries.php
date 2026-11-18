<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPosition;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Ticketing\Domain\TicketKey;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

/**
 * TicketQueries as the query of data-model.md "Ticket list grouped by step": Workflow's StepQueries::listedSteps()
 * gives the steps in list order, and their ids, unnested WITH ORDINALITY, drive a LATERAL per step that takes
 * PAGE_SIZE + 1 rows, the extra one only saying there is more, through
 * tkt_ticket_step_id_priority_rank_updated_at_id_idx or, for one project,
 * tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx. With a search, the LATERALs read only the tickets
 * search_ticket_ids returns, in search order. A ticket's page is read by (organization_id, key), the unique index
 * tkt_ticket_organization_id_key_key; its place in a list (positionOf) by that index too, then by keyset over the
 * list's indexes, the same capped step counts and, under a search, the same matches.
 */
final readonly class DbalTicketQueries implements TicketQueries
{
    private const string TICKET_ALIAS = 'ticket';
    private const string COUNTED_ALIAS = 'counted';
    private const string PARENT_ALIAS = 'parent';
    private const string AHEAD_ALIAS = 'ahead';
    private const string NEIGHBOUR_ALIAS = 'neighbour';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private StepQueries $stepQueries,
        private TurnUserQueries $turnUserQueries,
        private UserQueries $userQueries,
        private TicketTeamLookup $teamLookup,
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

        $shownSteps = $this->shownStepsOf($filter);
        if (null !== $cursor) {
            $shownSteps = array_filter($shownSteps, static fn (ListedStepView $step): bool => $cursor->stepId === $step->id);
        }
        if ([] === $shownSteps) {
            return [];
        }

        $ticketRows = $this->connection->fetchAllAssociative(...$this->listQuery($filter, $cursor, $organizationId->toString(), array_keys($shownSteps)));

        return $this->groupsOf($ticketRows, $shownSteps);
    }

    public function positionOf(string $key, ListFilter $filter): ?TicketPosition
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s place in a list is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($filter->visibility->organizationId())) {
            throw new \LogicException('A ticket\'s place in a list is read under the viewer\'s own organization as tenant.');
        }
        if (!TicketKey::canBe($key)) {
            return null;
        }
        $shownSteps = $this->shownStepsOf($filter);
        if ([] === $shownSteps) {
            return null;
        }

        $visibleTicket = TicketVisibilitySql::of($filter->visibility, self::TICKET_ALIAS);
        $parameters = [...self::listParametersOf($filter, $organizationId->toString(), array_keys($shownSteps), $visibleTicket), 'key' => $key];
        $row = $this->connection->fetchAssociative(...(null === $filter->searchText
            ? $this->priorityPositionQuery($filter, $parameters, $visibleTicket)
            : $this->searchPositionQuery($filter, $filter->searchText, $parameters, $visibleTicket)));
        if (false === $row) {
            return null;
        }

        return new TicketPosition(
            self::integer($row['list_index'], 'list index'),
            self::integer($row['total_count'], 'total count'),
            self::textOrNull($row['previous_key'], 'previous key'),
            self::textOrNull($row['next_key'], 'next key'),
            self::boolean($row['is_total_capped'], 'total capped'),
            self::boolean($row['is_index_capped'], 'index capped'),
        );
    }

    /**
     * The steps the filter's list shows, by id in list order: every listed step, Done only when the filter shows it.
     *
     * @return array<string, ListedStepView>
     */
    private function shownStepsOf(ListFilter $filter): array
    {
        $shownSteps = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            if ($filter->showsDone || TicketListGroup::DONE !== $step->groupCode) {
                $shownSteps[$step->id] = $step;
            }
        }

        return $shownSteps;
    }

    public function pageOf(string $key, TicketVisibility $viewer): ?TicketPageView
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket page is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId())) {
            throw new \LogicException('A ticket page is read under the viewer\'s own organization as tenant.');
        }
        if (!TicketKey::canBe($key)) {
            return null;
        }

        $visibleTicket = TicketVisibilitySql::of($viewer, self::TICKET_ALIAS);
        $visibleParent = TicketVisibilitySql::of($viewer, self::PARENT_ALIAS);
        $row = $this->connection->fetchAssociative(
            'SELECT ticket.id, ticket.key, ticket.title, ticket.description, ticket.type, ticket.created_at,'
            .' to_json(ticket.components) AS components, '.TurnSnapshotRow::COLUMNS.','
            .' ticket.version, project.name AS project_name, parent.key AS parent_key,'
            .' summary.id AS summary_id, summary.text AS summary_text, summary.source AS summary_source,'
            .' summary.author_id AS summary_author_id, summary.model AS summary_model,'
            .' summary.prompt_version AS summary_prompt_version, summary.created_at AS summary_created_at,'
            .' earlier.summary_count AS earlier_summary_count'
            .' FROM tkt_ticket AS ticket'
            .' JOIN tkt_project AS project ON project.organization_id = ticket.organization_id AND project.id = ticket.project_id'
            .' LEFT JOIN tkt_ticket AS parent ON parent.organization_id = ticket.organization_id AND parent.id = ticket.parent_id'
            .' AND '.$visibleParent->sql()
            // tkt_summary_ticket_id_current_key keeps at most one current summary per ticket, so the join adds no row.
            .' LEFT JOIN tkt_summary AS summary ON summary.organization_id = ticket.organization_id AND summary.ticket_id = ticket.id'
            .' AND summary.is_current'
            // Lateral rather than a scalar subquery in the column list: the query-budget tests label a statement by its first FROM,
            // which must stay tkt_ticket. count(*) always gives one row, so the cross join keeps the ticket's.
            .' CROSS JOIN LATERAL (SELECT count(*) AS summary_count FROM tkt_summary AS replaced WHERE replaced.organization_id = ticket.organization_id'
            .' AND replaced.ticket_id = ticket.id AND NOT replaced.is_current) AS earlier'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid) AND ticket.key = :key AND '.$visibleTicket->sql(),
            ['organization' => $organizationId->toString(), 'key' => $key, ...$visibleTicket->parameters(), ...$visibleParent->parameters()],
        );
        if (false === $row) {
            return null;
        }

        return $this->pageViewOf($row, $organizationId);
    }

    public function visibleTicketIdsOf(TicketVisibility $viewer, array $ticketIds): array
    {
        if (\count($ticketIds) > self::MAX_CHECKED_TICKET_IDS) {
            throw new \InvalidArgumentException(\sprintf('At most %d tickets are checked for visibility at once.', self::MAX_CHECKED_TICKET_IDS));
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Visible tickets are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId())) {
            throw new \LogicException('Visible tickets are read under the viewer\'s own organization as tenant.');
        }
        $uniqueTicketIds = [];
        foreach ($ticketIds as $ticketId) {
            $uniqueTicketIds[$ticketId->toString()] ??= $ticketId;
        }
        if ([] === $uniqueTicketIds) {
            return [];
        }

        $visibleTicket = TicketVisibilitySql::of($viewer, self::TICKET_ALIAS);
        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a TicketId holds no comma or brace.
        $visibleIds = $this->connection->fetchFirstColumn(
            'SELECT ticket.id FROM tkt_ticket AS ticket'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid) AND ticket.id = ANY(CAST(:ids AS uuid[]))'
            .' AND '.$visibleTicket->sql(),
            [
                'organization' => $organizationId->toString(),
                'ids' => '{'.implode(',', array_keys($uniqueTicketIds)).'}',
                ...$visibleTicket->parameters(),
            ],
        );
        $isVisible = array_fill_keys(array_map(static fn (mixed $id): string => self::text($id, 'id'), $visibleIds), true);

        return array_values(array_filter($uniqueTicketIds, static fn (TicketId $ticketId): bool => isset($isVisible[$ticketId->toString()])));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pageViewOf(array $row, OrganizationId $organizationId): TicketPageView
    {
        $stepId = self::text($row['step_id'], 'step id');
        $step = array_find($this->stepQueries->listedSteps(), static fn (ListedStepView $listed): bool => $listed->id === $stepId)
            ?? throw new \LogicException('A ticket is in a step its organization does not have.');
        $reporterId = new UserId(self::text($row['reporter_id'], 'reporter id'));
        $assigneeId = self::userIdOrNull($row['assignee_id'], 'assignee id');
        $teamId = TurnSnapshotRow::teamIdOf($row);
        $coreCode = $this->teamLookup->stepOf($stepId, $teamId)?->coreCode();
        [$leadId, $leadDisplayName, $developerIds, $testerIds] = null === $teamId ? [null, null, [], []] : $this->teamLookup->peopleOf($teamId);
        $currentSummary = null === $row['summary_id'] ? null : TicketSummaryRow::viewOf($row, 'summary_');
        $users = $this->userQueries->byIds(self::distinctUserIds([
            $reporterId, $assigneeId, self::userIdOrNull($row['pending_assignee_id'], 'pending assignee id'), self::userIdOrNull($row['reviewer_id'], 'reviewer id'),
            self::userIdOrNull($row['tester_id'], 'tester id'), self::userIdOrNull($row['waiting_on_user_id'], 'waiting-on user id'),
            $leadId, ...$developerIds, ...$testerIds, $currentSummary?->authorId,
        ]));
        $displayNames = array_map(static fn (UserView $user): string => $user->displayName, $users);
        $decodedComponents = json_decode(self::text($row['components'], 'components'), true, 2, \JSON_THROW_ON_ERROR);
        if (!\is_array($decodedComponents) || !array_is_list($decodedComponents)) {
            throw new \LogicException('A ticket\'s components from the database are not a list.');
        }
        $components = array_map(static fn (mixed $component): string => self::text($component, 'component'), $decodedComponents);

        return new TicketPageView(
            new TicketId(self::text($row['id'], 'ticket id')),
            self::text($row['key'], 'key'),
            self::text($row['title'], 'title'),
            self::text($row['description'], 'description'),
            self::text($row['type'], 'type'),
            self::text($row['priority'], 'priority'),
            self::textOrNull($row['release'], 'release'),
            self::textOrNull($row['parent_key'], 'parent key'),
            $components,
            new \DateTimeImmutable(self::text($row['created_at'], 'created_at')),
            self::text($row['project_name'], 'project name'),
            $step->name,
            $step->groupCode,
            $step->groupLabel,
            ($users[$reporterId->toString()] ?? null)?->displayName,
            null === $assigneeId ? null : ($users[$assigneeId->toString()] ?? null)?->displayName,
            new TicketVisibilityFacts(
                $organizationId,
                self::text($row['visibility'], 'visibility'),
                null === $row['team_id'] ? null : new TeamId(self::text($row['team_id'], 'team id')),
                $reporterId,
                $assigneeId,
                self::userIdOrNull($row['pending_assignee_id'], 'pending assignee id'),
                self::userIdOrNull($row['reviewer_id'], 'reviewer id'),
                self::userIdOrNull($row['tester_id'], 'tester id'),
                self::userIdOrNull($row['waiting_on_user_id'], 'waiting-on user id'),
            ),
            TurnSnapshotRow::snapshotOf(new TicketId(self::text($row['id'], 'ticket id')), $row, $step->groupCode, $coreCode, $leadId, $leadDisplayName, $developerIds, $testerIds),
            $displayNames,
            self::integer($row['version'], 'version'),
            $currentSummary,
            self::integer($row['earlier_summary_count'], 'earlier summary count'),
        );
    }

    /**
     * @param list<UserId|null> $userIds
     *
     * @return list<UserId> each person once, in the order first met
     */
    private static function distinctUserIds(array $userIds): array
    {
        $distinct = [];
        foreach ($userIds as $userId) {
            if (null !== $userId) {
                $distinct[$userId->toString()] = $userId;
            }
        }

        return array_values($distinct);
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
        $parameters = self::listParametersOf($filter, $organizationId, $stepIds, $visibleTicket);

        if (null === $filter->searchText) {
            return $this->priorityListQuery($filter, $cursor, $parameters, $visibleTicket);
        }

        return $this->searchListQuery($filter, $filter->searchText, $cursor, $parameters, $visibleTicket);
    }

    /**
     * @param non-empty-list<string> $stepIds in list order
     *
     * @return array<string, string>
     */
    private static function listParametersOf(ListFilter $filter, string $organizationId, array $stepIds, TicketVisibilitySql $visibleTicket): array
    {
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

        return $parameters;
    }

    /**
     * The visible tickets of the filter's project and assignee in one step, as a WHERE condition on the alias.
     *
     * @param string $stepId the SQL of the step's id, a column of the outer query
     */
    private static function stepTicketsConditionOf(ListFilter $filter, string $alias, TicketVisibilitySql $visible, string $stepId): string
    {
        return $alias.'.organization_id = CAST(:organization AS uuid) AND '.$alias.'.step_id = '.$stepId
            .self::projectConditionOf($filter, $alias).self::assigneeConditionOf($filter, $alias).' AND '.$visible->sql();
    }

    /**
     * A LATERAL's body that counts at most COUNT_CAP + 1 of a step's tickets in the row fetch's order, so it reads the
     * list's index and stops there; one more than the cap says the step holds more than it counts.
     */
    private static function cappedStepCountOf(ListFilter $filter, TicketVisibilitySql $visibleCounted, string $stepId): string
    {
        return 'SELECT count(*) AS ticket_count FROM ('
            .'SELECT 1 FROM tkt_ticket AS counted'
            .' WHERE '.self::stepTicketsConditionOf($filter, self::COUNTED_ALIAS, $visibleCounted, $stepId)
            .' ORDER BY counted.priority_rank, counted.updated_at DESC, counted.id DESC'
            .' LIMIT '.(TicketListStep::COUNT_CAP + 1)
            .') AS capped';
    }

    /**
     * The ticket's place without a search, never sorting a whole step: the target by (organization_id, key); "N" as
     * the capped counts of the earlier steps plus the tickets ahead of it in its own step, read in two index ranges
     * (a higher priority; the same one, updated later or with a higher id), each stopping past the cap; "M" as the
     * capped counts of every shown step. Each neighbour is the nearest row by keyset in its own step, first at the
     * same priority, then at the next one, else the edge row of the nearest earlier or later step that holds one.
     *
     * @param array<string, string> $parameters
     *
     * @return array{string, array<string, string>}
     */
    private function priorityPositionQuery(ListFilter $filter, array $parameters, TicketVisibilitySql $visibleTicket): array
    {
        $visibleCounted = TicketVisibilitySql::of($filter->visibility, self::COUNTED_ALIAS);
        $visibleAhead = TicketVisibilitySql::of($filter->visibility, self::AHEAD_ALIAS);
        $visibleNeighbour = TicketVisibilitySql::of($filter->visibility, self::NEIGHBOUR_ALIAS);
        $parameters = [...$parameters, ...$visibleCounted->parameters(), ...$visibleAhead->parameters(), ...$visibleNeighbour->parameters()];
        $cap = TicketListStep::COUNT_CAP;
        $aheadInStep = self::stepTicketsConditionOf($filter, self::AHEAD_ALIAS, $visibleAhead, 'target.step_id');
        $neighbourInStep = self::stepTicketsConditionOf($filter, self::NEIGHBOUR_ALIAS, $visibleNeighbour, 'target.step_id');
        $neighbourInListedStep = self::stepTicketsConditionOf($filter, self::NEIGHBOUR_ALIAS, $visibleNeighbour, 'other_step.step_id');
        $listOrder = ' ORDER BY neighbour.priority_rank, neighbour.updated_at DESC, neighbour.id DESC LIMIT 1';
        $reversedListOrder = ' ORDER BY neighbour.priority_rank DESC, neighbour.updated_at, neighbour.id LIMIT 1';

        $sql = 'WITH listed AS MATERIALIZED (SELECT step_id, list_position'
            .' FROM unnest(CAST(:step_ids AS uuid[])) WITH ORDINALITY AS listed(step_id, list_position)),'
            .' target AS MATERIALIZED (SELECT ticket.id, ticket.step_id, ticket.priority_rank, ticket.updated_at, listed.list_position'
            .' FROM tkt_ticket AS ticket JOIN listed ON listed.step_id = ticket.step_id'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid) AND ticket.key = :key'
            .self::projectConditionOf($filter, self::TICKET_ALIAS).self::assigneeConditionOf($filter, self::TICKET_ALIAS)
            .' AND '.$visibleTicket->sql().')'
            // Past the cap in its own step the place is a lower bound that never passes the step's capped count.
            .' SELECT CAST(totals.earlier_count + least(ahead.ticket_count + 1, '.$cap.') AS integer) AS list_index,'
            .' CAST(totals.total_count AS integer) AS total_count, totals.is_total_capped,'
            .' totals.is_earlier_capped OR ahead.ticket_count >= '.$cap.' AS is_index_capped,'
            .' COALESCE('
            .'(SELECT neighbour.key FROM tkt_ticket AS neighbour WHERE '.$neighbourInStep
            .' AND neighbour.priority_rank = target.priority_rank AND (neighbour.updated_at, neighbour.id) > (target.updated_at, target.id)'
            .' ORDER BY neighbour.updated_at, neighbour.id LIMIT 1),'
            .' (SELECT neighbour.key FROM tkt_ticket AS neighbour WHERE '.$neighbourInStep
            .' AND neighbour.priority_rank < target.priority_rank'.$reversedListOrder.'),'
            .' (SELECT edge.key FROM listed AS other_step CROSS JOIN LATERAL ('
            .'SELECT neighbour.key FROM tkt_ticket AS neighbour WHERE '.$neighbourInListedStep.$reversedListOrder.') AS edge'
            .' WHERE other_step.list_position < target.list_position ORDER BY other_step.list_position DESC LIMIT 1)'
            .') AS previous_key,'
            .' COALESCE('
            .'(SELECT neighbour.key FROM tkt_ticket AS neighbour WHERE '.$neighbourInStep
            .' AND neighbour.priority_rank = target.priority_rank AND (neighbour.updated_at, neighbour.id) < (target.updated_at, target.id)'
            .' ORDER BY neighbour.updated_at DESC, neighbour.id DESC LIMIT 1),'
            .' (SELECT neighbour.key FROM tkt_ticket AS neighbour WHERE '.$neighbourInStep
            .' AND neighbour.priority_rank > target.priority_rank'.$listOrder.'),'
            .' (SELECT edge.key FROM listed AS other_step CROSS JOIN LATERAL ('
            .'SELECT neighbour.key FROM tkt_ticket AS neighbour WHERE '.$neighbourInListedStep.$listOrder.') AS edge'
            .' WHERE other_step.list_position > target.list_position ORDER BY other_step.list_position LIMIT 1)'
            .') AS next_key'
            .' FROM target'
            .' CROSS JOIN LATERAL (SELECT'
            .' COALESCE(sum(least(step_count.ticket_count, '.$cap.')) FILTER (WHERE listed.list_position < target.list_position), 0) AS earlier_count,'
            .' COALESCE(bool_or(step_count.ticket_count > '.$cap.') FILTER (WHERE listed.list_position < target.list_position), false) AS is_earlier_capped,'
            .' sum(least(step_count.ticket_count, '.$cap.')) AS total_count,'
            .' bool_or(step_count.ticket_count > '.$cap.') AS is_total_capped'
            .' FROM listed CROSS JOIN LATERAL ('.self::cappedStepCountOf($filter, $visibleCounted, 'listed.step_id').') AS step_count) AS totals'
            .' CROSS JOIN LATERAL (SELECT count(*) AS ticket_count FROM ('
            .'(SELECT 1 FROM tkt_ticket AS ahead WHERE '.$aheadInStep.' AND ahead.priority_rank < target.priority_rank'
            .' ORDER BY ahead.priority_rank, ahead.updated_at DESC, ahead.id DESC LIMIT '.($cap + 1).')'
            .' UNION ALL (SELECT 1 FROM tkt_ticket AS ahead WHERE '.$aheadInStep
            .' AND ahead.priority_rank = target.priority_rank AND (ahead.updated_at, ahead.id) > (target.updated_at, target.id)'
            .' ORDER BY ahead.updated_at DESC, ahead.id DESC LIMIT '.($cap + 1).')'
            .') AS ahead_rows) AS ahead';

        return [$sql, $parameters];
    }

    /**
     * The ticket's place among the matches list() reads under a search, at most COUNT_CAP of them, ranked in its order:
     * the steps' list order, then the exact key, the match score and the id, all highest first.
     *
     * @param array<string, string> $parameters
     *
     * @return array{string, array<string, string|int|null>}
     */
    private function searchPositionQuery(ListFilter $filter, string $searchText, array $parameters, TicketVisibilitySql $visibleTicket): array
    {
        [$searchedCtes, $parameters] = self::searchedCtesOf($filter, $searchText, $parameters, $visibleTicket);
        $sql = $searchedCtes.','
            .' ordered AS (SELECT searched.key, row_number() OVER list_order AS list_index,'
            .' lag(searched.key) OVER list_order AS previous_key, lead(searched.key) OVER list_order AS next_key,'
            .' count(*) OVER () AS total_count'
            .' FROM searched JOIN unnest(CAST(:step_ids AS uuid[])) WITH ORDINALITY AS listed(step_id, list_position) ON listed.step_id = searched.step_id'
            .' WINDOW list_order AS (ORDER BY listed.list_position, searched.is_exact_key_match DESC, searched.match_score DESC, searched.id DESC))'
            .' SELECT CAST(ordered.list_index AS integer) AS list_index, CAST(ordered.total_count AS integer) AS total_count,'
            .' ordered.previous_key, ordered.next_key,'
            .' (SELECT count(*) FROM matched) > '.TicketListStep::COUNT_CAP.' AS is_total_capped, false AS is_index_capped'
            .' FROM ordered WHERE ordered.key = :key';

        return [$sql, $parameters];
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
        $ticketConditions = self::stepTicketsConditionOf($filter, self::TICKET_ALIAS, $visibleTicket, 'listed.step_id');
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
            .' CROSS JOIN LATERAL ('.self::cappedStepCountOf($filter, $visibleCounted, 'listed.step_id').') AS step_count'
            .' CROSS JOIN LATERAL ('
            .'SELECT ticket.id, ticket.key, ticket.title, ticket.type, ticket.release, ticket.priority, ticket.priority_rank,'
            .' ticket.assignee_id, ticket.updated_at'
            .' FROM tkt_ticket AS ticket'
            .' WHERE '.$ticketConditions
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
        [$searchedCtes, $parameters] = self::searchedCtesOf($filter, $searchText, $parameters, $visibleTicket);
        $pageConditions = 'searched.step_id = listed.step_id';
        $searchOrderValues = $cursor?->searchOrderValues();
        if (null !== $cursor && null !== $searchOrderValues) {
            $pageConditions .= ' AND (searched.is_exact_key_match, searched.match_score, searched.id)'
                .' < (CAST(:after_exact_key_match AS boolean), CAST(:after_match_score AS numeric), CAST(:after_id AS uuid))';
            $parameters['after_exact_key_match'] = $searchOrderValues[0] ? 'true' : 'false';
            $parameters['after_match_score'] = $searchOrderValues[1];
            $parameters['after_id'] = $cursor->ticketId->toString();
        }

        $sql = $searchedCtes
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

    /**
     * The WITH clause both search reads share: "matched", the ids search_ticket_ids returns, which applies the list's
     * project, steps and assignee before its cap, and "searched", the first COUNT_CAP of them joined back to tkt_ticket
     * under the filter's project, assignee and visibility, each with its exact-key flag and match score.
     *
     * @param array<string, string|int|null> $parameters
     *
     * @return array{string, array<string, string|int|null>}
     */
    private static function searchedCtesOf(ListFilter $filter, string $searchText, array $parameters, TicketVisibilitySql $visibleTicket): array
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
            .' AND '.$visibleTicket->sql().')';

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

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function userIdOrNull(mixed $value, string $column): ?UserId
    {
        return null === $value ? null : new UserId(self::text($value, $column));
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
