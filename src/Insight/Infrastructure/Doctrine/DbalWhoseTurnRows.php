<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;
use App\Insight\Api\ViewersTurns;
use App\Insight\Api\WaitingTicket;
use App\Insight\Application\WhoseTurnRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

/**
 * One query: window functions count, date and number every visible turn row within its person or label, so the counts
 * and oldest waits cover every ticket the viewer may see. The page's groups are picked by keyset on (oldest wait,
 * label or not, user id or label in byte order), HomeQueriesService's order, and only their first rows are joined to
 * Ticketing's read-only view tkt_ticket_list_v for their key and title (the one cross-module join ADR-0015 allows).
 *
 * A search keeps only the turn rows of the tickets Ticketing's search_ticket_ids finds, in the same query, as the
 * tickets list's search does (data-model.md, "Ticket list with a search"): the function gets the project and the open
 * steps (Workflow's cached StepQueries::listedSteps), so a Done ticket never takes the place of a match, and only its
 * best TicketListStep::COUNT_CAP matches count.
 */
final readonly class DbalWhoseTurnRows implements WhoseTurnRows
{
    private const string TURN_ALIAS = 'turn';
    /** The order of a page's groups and of its keyset; COLLATE "C" compares labels byte by byte, as strcmp does. */
    private const string GROUP_ORDER = 'visible_turn.group_oldest, visible_turn.turn_user_id IS NULL,'
        .' COALESCE(CAST(visible_turn.turn_user_id AS text), visible_turn.turn_label) COLLATE "C"';
    /** The function returns one id past the cap only to say the cap was hit; that one never counts. */
    private const string MATCHED_CTE = 'matched AS MATERIALIZED (SELECT found.ticket_id, found.found_position'
        .' FROM search_ticket_ids(CAST(:organization AS uuid), CAST(:search_text AS text), CAST(:viewer AS uuid),'
        .' CAST(:search_team_ids AS uuid[]), CAST(:search_is_admin AS boolean), CAST(:search_is_triager AS boolean),'
        .' CAST(:search_project AS uuid), CAST(:search_step_ids AS uuid[]), CAST(NULL AS uuid))'
        .' WITH ORDINALITY AS found(ticket_id, found_position))';

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private StepQueries $stepQueries)
    {
    }

    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup, ?TurnGroupCursor $after, int $otherGroupLimit, ?string $searchText = null): array
    {
        $organizationId = $this->requireViewersTenant($viewer);
        $visibleTurn = $this->visibleTurnOf($viewer);
        // The ticket id breaks ties of waiting_since, so the shown rows of a group do not change between two reads.
        // The LEFT JOIN from the count keeps one row, with no ticket, when the page has no group.
        $rows = $this->connection->fetchAllAssociative(
            'WITH '.(null === $searchText ? '' : self::MATCHED_CTE.', ')
            .'visible_turn AS (SELECT turn.ticket_id, turn.turn_user_id, turn.turn_label, turn.kind, turn.waiting_since,'
            .' count(*) OVER turn_group AS group_tickets, min(turn.waiting_since) OVER turn_group AS group_oldest,'
            .' row_number() OVER (PARTITION BY turn.turn_user_id, turn.turn_label ORDER BY turn.waiting_since, turn.ticket_id) AS place'
            .' FROM ins_ticket_turn AS turn'
            .' WHERE turn.organization_id = CAST(:organization AS uuid)'
            .(null === $projectId ? '' : ' AND turn.project_id = CAST(:project AS uuid)')
            .(null === $searchText ? '' : ' AND turn.ticket_id IN (SELECT matched.ticket_id FROM matched WHERE matched.found_position <= '.TicketListStep::COUNT_CAP.')')
            .' AND '.$visibleTurn->sql()
            .' WINDOW turn_group AS (PARTITION BY turn.turn_user_id, turn.turn_label)),'
            .' page_group AS ('
            .(null === $after ? '(SELECT visible_turn.turn_user_id, visible_turn.turn_label FROM visible_turn'
                .' WHERE visible_turn.place = 1 AND visible_turn.turn_user_id = CAST(:viewer AS uuid)) UNION ALL ' : '')
            .'(SELECT visible_turn.turn_user_id, visible_turn.turn_label FROM visible_turn'
            .' WHERE visible_turn.place = 1 AND visible_turn.turn_user_id IS DISTINCT FROM CAST(:viewer AS uuid)'
            .(null === $after ? '' : ' AND ('.self::GROUP_ORDER.')'
                .' > (CAST(:after_oldest AS timestamptz), CAST(:after_is_label AS boolean), CAST(:after_key AS text) COLLATE "C")')
            .' ORDER BY '.self::GROUP_ORDER.' LIMIT CAST(:other_group_limit AS integer)))'
            .' SELECT totals.visible_tickets, totals.is_search_capped, shown.* FROM (SELECT count(*) AS visible_tickets,'
            .(null === $searchText ? ' false' : ' EXISTS (SELECT 1 FROM matched WHERE matched.found_position > '.TicketListStep::COUNT_CAP.')')
            .' AS is_search_capped FROM visible_turn) AS totals'
            .' LEFT JOIN (SELECT visible_turn.ticket_id, visible_turn.turn_user_id, visible_turn.turn_label, visible_turn.kind,'
            .' visible_turn.waiting_since, visible_turn.group_tickets, visible_turn.group_oldest, visible_turn.place, ticket.key, ticket.title'
            .' FROM visible_turn'
            .' JOIN page_group ON page_group.turn_user_id IS NOT DISTINCT FROM visible_turn.turn_user_id'
            .' AND page_group.turn_label IS NOT DISTINCT FROM visible_turn.turn_label'
            .' JOIN tkt_ticket_list_v AS ticket ON ticket.organization_id = CAST(:organization AS uuid) AND ticket.id = visible_turn.ticket_id'
            .' WHERE visible_turn.place <= CASE WHEN COALESCE(CAST(visible_turn.turn_user_id AS text), visible_turn.turn_label) = CAST(:opened AS text)'
            .' THEN CAST(:opened_shown AS integer) ELSE CAST(:shown AS integer) END) AS shown ON true'
            .' ORDER BY shown.turn_user_id, shown.turn_label, shown.place',
            [
                'organization' => $organizationId->toString(),
                ...(null === $projectId ? [] : ['project' => $projectId]),
                'viewer' => $viewer->viewerId->toString(),
                ...(null === $after ? [] : [
                    'after_oldest' => $after->oldestWaitingSince->format('Y-m-d H:i:sP'),
                    'after_is_label' => null === $after->turnUserId ? 'true' : 'false',
                    'after_key' => $after->turnUserId?->toString() ?? (string) $after->turnLabel,
                ]),
                'other_group_limit' => (string) $otherGroupLimit,
                'opened' => $openedGroup,
                'opened_shown' => (string) HomeQueries::SHOWN_TICKETS_OF_THE_OPENED_GROUP,
                'shown' => (string) HomeQueries::SHOWN_TICKETS,
                ...(null === $searchText ? [] : $this->searchParametersOf($viewer, $searchText, $projectId)),
                ...$visibleTurn->parameters(),
            ],
        );

        $ticketCount = 0;
        $isSearchCapped = false;
        /** @var array<string, array{?UserId, ?string, int, \DateTimeImmutable, list<WaitingTicket>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            if (!\is_int($row['visible_tickets'])) {
                throw new \LogicException('The whose-turn count from the database is not a number.');
            }
            if (!\is_bool($row['is_search_capped'])) {
                throw new \LogicException('The whose-turn search cap from the database is not a boolean.');
            }
            $ticketCount = $row['visible_tickets'];
            $isSearchCapped = $row['is_search_capped'];
            if (null === $row['ticket_id']) {
                continue;
            }
            if (!\is_string($row['ticket_id']) || !\is_string($row['kind']) || !\is_string($row['waiting_since'])
                || !\is_int($row['group_tickets']) || !\is_string($row['group_oldest'])
                || !\is_string($row['key']) || !\is_string($row['title'])
                || (null === $row['turn_user_id']) === (null === $row['turn_label'])
                || (null !== $row['turn_user_id'] && !\is_string($row['turn_user_id']))
                || (null !== $row['turn_label'] && !\is_string($row['turn_label']))) {
                throw new \LogicException('A whose-turn row from the database is not a waiting ticket of one person or label.');
            }
            $groupKey = $row['turn_user_id'] ?? 'label:'.$row['turn_label'];
            $groups[$groupKey] ??= [
                null === $row['turn_user_id'] ? null : new UserId($row['turn_user_id']),
                $row['turn_label'],
                $row['group_tickets'],
                new \DateTimeImmutable($row['group_oldest']),
                [],
            ];
            $groups[$groupKey][4][] = new WaitingTicket(
                new TicketId($row['ticket_id']),
                $row['key'],
                $row['title'],
                $row['kind'],
                new \DateTimeImmutable($row['waiting_since']),
            );
        }

        return [
            'groups' => array_values(array_map(
                static fn (array $group): TurnGroup => new TurnGroup(...$group),
                $groups,
            )),
            'ticketCount' => $ticketCount,
            'isSearchCapped' => $isSearchCapped,
        ];
    }

    /**
     * The window count runs before the inner LIMIT, so it counts every visible row of the viewer while only the shown
     * ones are joined to the view. No row means nothing waits on the viewer.
     */
    public function viewersTurnsOf(TicketViewer $viewer, int $limit): ViewersTurns
    {
        $organizationId = $this->requireViewersTenant($viewer);
        $visibleTurn = $this->visibleTurnOf($viewer);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT mine.ticket_id, mine.kind, mine.waiting_since, mine.ticket_count, ticket.key, ticket.title'
            .' FROM (SELECT turn.ticket_id, turn.kind, turn.waiting_since, count(*) OVER () AS ticket_count'
            .' FROM ins_ticket_turn AS turn'
            .' WHERE turn.organization_id = CAST(:organization AS uuid) AND turn.turn_user_id = CAST(:viewer AS uuid)'
            .' AND '.$visibleTurn->sql()
            .' ORDER BY turn.waiting_since, turn.ticket_id LIMIT CAST(:limit AS integer)) AS mine'
            .' JOIN tkt_ticket_list_v AS ticket ON ticket.organization_id = CAST(:organization AS uuid) AND ticket.id = mine.ticket_id'
            .' ORDER BY mine.waiting_since, mine.ticket_id',
            [
                'organization' => $organizationId->toString(),
                'viewer' => $viewer->viewerId->toString(),
                'limit' => (string) $limit,
                ...$visibleTurn->parameters(),
            ],
        );

        $ticketCount = 0;
        $tickets = [];
        foreach ($rows as $row) {
            if (!\is_string($row['ticket_id']) || !\is_string($row['kind']) || !\is_string($row['waiting_since'])
                || !\is_int($row['ticket_count']) || !\is_string($row['key']) || !\is_string($row['title'])) {
                throw new \LogicException('A row of the viewer\'s turns from the database is not a waiting ticket.');
            }
            $ticketCount = $row['ticket_count'];
            $tickets[] = new WaitingTicket(new TicketId($row['ticket_id']), $row['key'], $row['title'], $row['kind'], new \DateTimeImmutable($row['waiting_since']));
        }

        return new ViewersTurns($tickets, $ticketCount);
    }

    /**
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant
     */
    private function requireViewersTenant(TicketViewer $viewer): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Whose turn is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId)) {
            throw new \LogicException('Whose turn is read under the viewer\'s own organization as tenant.');
        }

        return $organizationId;
    }

    private function visibleTurnOf(TicketViewer $viewer): TicketVisibilitySql
    {
        return TicketVisibilitySql::of(
            TicketVisibility::for($viewer->organizationId, $viewer->viewerId, $viewer->teamIds, $viewer->isAdmin, $viewer->isTriager ? $viewer->viewerId : null),
            self::TURN_ALIAS,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function searchParametersOf(TicketViewer $viewer, string $searchText, ?string $projectId): array
    {
        $openStepIds = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            if (TicketListGroup::DONE !== $step->groupCode) {
                $openStepIds[] = $step->id;
            }
        }

        // As in TicketVisibilitySql: pdo_pgsql binds no arrays, and a uuid holds no comma or brace.
        return [
            'search_text' => $searchText,
            'search_team_ids' => '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $viewer->teamIds)).'}',
            'search_is_admin' => $viewer->isAdmin ? 'true' : 'false',
            'search_is_triager' => $viewer->isTriager ? 'true' : 'false',
            'search_project' => $projectId,
            'search_step_ids' => '{'.implode(',', $openStepIds).'}',
        ];
    }
}
