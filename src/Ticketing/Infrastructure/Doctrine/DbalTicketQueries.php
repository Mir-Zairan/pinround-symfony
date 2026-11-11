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
use Doctrine\DBAL\Connection;

/**
 * TicketQueries as the query of data-model.md "Ticket list grouped by step": a LATERAL per step takes PAGE_SIZE + 1
 * rows, the extra one only saying there is more, through tkt_ticket_step_id_priority_rank_updated_at_id_idx or, for
 * one project, tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx.
 */
final readonly class DbalTicketQueries implements TicketQueries
{
    /**
     * Workflow's StepOrigin cases in order, which this module may not use; TicketListGroupTest keeps the copy equal.
     */
    public const array STEP_ORIGIN_ORDER = ['core', 'company', 'team'];

    private const string TICKET_ALIAS = 'ticket';
    private const string COUNTED_ALIAS = 'counted';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
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

        $stepRows = $this->connection->fetchAllAssociative(...$this->listQuery($filter, $cursor, $organizationId->toString()));

        return $this->groupsOf($stepRows);
    }

    /**
     * Without a cursor the LATERAL counts the step's visible tickets with count(*) OVER (), as the window runs before
     * its LIMIT; after a cursor the keyset condition narrows the LATERAL, so a subquery counts the whole step.
     *
     * @return array{string, array<string, string|int>}
     */
    private function listQuery(ListFilter $filter, ?Cursor $cursor, string $organizationId): array
    {
        $visibleTicket = TicketVisibilitySql::of($filter->visibility, self::TICKET_ALIAS);
        $parameters = ['organization' => $organizationId, ...$visibleTicket->parameters()];
        $ticketConditions = 'ticket.organization_id = CAST(:organization AS uuid) AND ticket.step_id = step.id';
        $countedConditions = 'counted.organization_id = CAST(:organization AS uuid) AND counted.step_id = step.id';
        if (null !== $filter->projectId) {
            $ticketConditions .= ' AND ticket.project_id = CAST(:project AS uuid)';
            $countedConditions .= ' AND counted.project_id = CAST(:project AS uuid)';
            $parameters['project'] = $filter->projectId;
        }

        $shownGroups = array_keys(TicketListGroup::IN_LIST_ORDER);
        if (!$filter->showsDone) {
            $shownGroups = array_values(array_diff($shownGroups, [TicketListGroup::DONE]));
        }
        // Group codes are this class's constants, never input, so they are written into the SQL.
        $stepConditions = 'step.organization_id = CAST(:organization AS uuid)'
            ." AND step.grp IN ('".implode("', '", $shownGroups)."')";

        if (null === $cursor) {
            $ticketCount = ', count(*) OVER () AS ticket_count';
            $outerCount = 'page.ticket_count';
        } else {
            $visibleCounted = TicketVisibilitySql::of($filter->visibility, self::COUNTED_ALIAS);
            $ticketCount = '';
            $outerCount = '(SELECT count(*) FROM tkt_ticket AS counted WHERE '.$countedConditions.' AND '.$visibleCounted->sql().')';
            $parameters = [...$parameters, ...$visibleCounted->parameters()];
            $ticketConditions .= ' AND (ticket.priority_rank > CAST(:after_rank AS smallint)'
                .' OR (ticket.priority_rank = CAST(:after_rank AS smallint)'
                .' AND (ticket.updated_at, ticket.id) < (CAST(:after_updated_at AS timestamptz), CAST(:after_id AS uuid))))';
            $stepConditions .= ' AND step.id = CAST(:step AS uuid)';
            $parameters['after_rank'] = (string) $cursor->priorityRank;
            $parameters['after_updated_at'] = $cursor->updatedAt->format('Y-m-d H:i:sP');
            $parameters['after_id'] = $cursor->ticketId->toString();
            $parameters['step'] = $cursor->stepId;
        }

        $sql = 'SELECT step.id AS step_id, step.name AS step_name, step.grp, step.archived_at IS NOT NULL AS is_archived,'
            .' page.id, page.key, page.title, page.type, page.release, page.priority, page.priority_rank, page.assignee_id,'
            .' page.updated_at, '.$outerCount.' AS ticket_count'
            .' FROM wf_step AS step'
            .' CROSS JOIN LATERAL ('
            .'SELECT ticket.id, ticket.key, ticket.title, ticket.type, ticket.release, ticket.priority, ticket.priority_rank,'
            .' ticket.assignee_id, ticket.updated_at'.$ticketCount
            .' FROM tkt_ticket AS ticket'
            .' WHERE '.$ticketConditions.' AND '.$visibleTicket->sql()
            .' ORDER BY ticket.priority_rank, ticket.updated_at DESC, ticket.id DESC'
            .' LIMIT '.(TicketListStep::PAGE_SIZE + 1)
            .') AS page'
            .' WHERE '.$stepConditions
            .' ORDER BY '.self::orderSql('step.grp', array_keys(TicketListGroup::IN_LIST_ORDER))
            .', '.self::orderSql('step.origin', self::STEP_ORIGIN_ORDER)
            .', step.position, step.id, page.priority_rank, page.updated_at DESC, page.id DESC';

        return [$sql, $parameters];
    }

    /**
     * @param list<array<string, mixed>> $stepRows one per ticket, PAGE_SIZE + 1 at most per step, in list order
     *
     * @return list<TicketListGroup>
     */
    private function groupsOf(array $stepRows): array
    {
        /** @var array<string, array{string, string, bool, int, list<array<string, mixed>>, ?Cursor}> $steps */
        $steps = [];
        $shownTicketIds = [];
        $userIds = [];
        foreach ($stepRows as $row) {
            $stepId = self::text($row['step_id'], 'step id');
            $steps[$stepId] ??= [self::text($row['grp'], 'group'), self::text($row['step_name'], 'step name'), true === $row['is_archived'], self::integer($row['ticket_count'], 'ticket count'), [], null];
            if (TicketListStep::PAGE_SIZE === \count($steps[$stepId][4])) {
                $lastRow = $steps[$stepId][4][TicketListStep::PAGE_SIZE - 1];
                $steps[$stepId][5] = new Cursor(
                    $stepId,
                    self::integer($lastRow['priority_rank'], 'priority rank'),
                    new \DateTimeImmutable(self::text($lastRow['updated_at'], 'updated_at')),
                    new TicketId(self::text($lastRow['id'], 'ticket id')),
                );
                continue;
            }
            $steps[$stepId][4][] = $row;
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

        $stepsByGroup = [];
        foreach ($steps as $stepId => [$groupCode, $name, $isArchived, $ticketCount, $rows, $nextCursor]) {
            $stepsByGroup[$groupCode][] = new TicketListStep(
                $stepId,
                $name,
                $isArchived,
                $ticketCount,
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
                        null === $turnUserId ? null : ($users[$turnUserId->toString()] ?? null)?->displayName,
                        new \DateTimeImmutable(self::text($row['updated_at'], 'updated_at')),
                        $stepId,
                    );
                }, $rows),
                $nextCursor,
            );
        }

        $groups = [];
        foreach (array_keys(TicketListGroup::IN_LIST_ORDER) as $groupCode) {
            if (isset($stepsByGroup[$groupCode])) {
                $groups[] = new TicketListGroup($groupCode, $stepsByGroup[$groupCode]);
            }
        }

        return $groups;
    }

    /**
     * A CASE that gives each code its index in $inOrder; the codes are constants of this module, never input.
     *
     * @param list<string> $inOrder
     */
    private static function orderSql(string $column, array $inOrder): string
    {
        $whens = array_map(
            static fn (string $code, int $index): string => \sprintf("WHEN '%s' THEN %d", $code, $index),
            $inOrder,
            array_keys($inOrder),
        );

        return \sprintf('CASE %s %s END', $column, implode(' ', $whens));
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
