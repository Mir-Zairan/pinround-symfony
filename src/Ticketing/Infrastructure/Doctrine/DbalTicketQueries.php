<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Application\TenantContext;
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
 * tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx.
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
     * The step's count is its own LATERAL, run once per step and the same with or without a cursor: it counts at most
     * COUNT_CAP + 1 visible tickets in the row fetch's order, so it reads the same index and stops there.
     *
     * @param non-empty-list<string> $stepIds in list order
     *
     * @return array{string, array<string, string|int>}
     */
    private function listQuery(ListFilter $filter, ?Cursor $cursor, string $organizationId, array $stepIds): array
    {
        $visibleTicket = TicketVisibilitySql::of($filter->visibility, self::TICKET_ALIAS);
        $visibleCounted = TicketVisibilitySql::of($filter->visibility, self::COUNTED_ALIAS);
        $parameters = [
            'organization' => $organizationId,
            // The ids come from the database as canonical uuids, so they need no quoting inside the array literal.
            'step_ids' => '{'.implode(',', $stepIds).'}',
            ...$visibleTicket->parameters(),
            ...$visibleCounted->parameters(),
        ];
        $ticketConditions = 'ticket.organization_id = CAST(:organization AS uuid) AND ticket.step_id = listed.step_id';
        $countedConditions = 'counted.organization_id = CAST(:organization AS uuid) AND counted.step_id = listed.step_id';
        if (null !== $filter->projectId) {
            $ticketConditions .= ' AND ticket.project_id = CAST(:project AS uuid)';
            $countedConditions .= ' AND counted.project_id = CAST(:project AS uuid)';
            $parameters['project'] = $filter->projectId;
        }

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
     * @param list<array<string, mixed>>    $ticketRows PAGE_SIZE + 1 at most per step, in list order
     * @param array<string, ListedStepView> $shownSteps by id, in list order
     *
     * @return list<TicketListGroup>
     */
    private function groupsOf(array $ticketRows, array $shownSteps): array
    {
        /** @var array<string, array{ticketCount: int, rows: list<array<string, mixed>>, nextCursor: ?Cursor}> $pages */
        $pages = [];
        $shownTicketIds = [];
        $userIds = [];
        foreach ($ticketRows as $row) {
            $stepId = self::text($row['step_id'], 'step id');
            $pages[$stepId] ??= ['ticketCount' => self::integer($row['ticket_count'], 'ticket count'), 'rows' => [], 'nextCursor' => null];
            if (TicketListStep::PAGE_SIZE === \count($pages[$stepId]['rows'])) {
                $lastRow = $pages[$stepId]['rows'][TicketListStep::PAGE_SIZE - 1];
                $pages[$stepId]['nextCursor'] = new Cursor(
                    $stepId,
                    self::integer($lastRow['priority_rank'], 'priority rank'),
                    new \DateTimeImmutable(self::text($lastRow['updated_at'], 'updated_at')),
                    new TicketId(self::text($lastRow['id'], 'ticket id')),
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
                $page['ticketCount'] > TicketListStep::COUNT_CAP,
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

    private static function integer(mixed $value, string $column): int
    {
        if (!\is_int($value)) {
            throw new \LogicException(\sprintf('A ticket list %s from the database is not an integer.', $column));
        }

        return $value;
    }
}
