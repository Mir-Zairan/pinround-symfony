<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\WaitingTicket;
use App\Insight\Application\WhoseTurnRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

/**
 * One query: window functions count, date and number every visible turn row within its person or label, so the counts
 * and oldest waits cover every ticket the viewer may see while only the first rows of each group are joined to
 * Ticketing's read-only view tkt_ticket_list_v for their key and title (the one cross-module join ADR-0015 allows).
 */
final readonly class DbalWhoseTurnRows implements WhoseTurnRows
{
    private const string TURN_ALIAS = 'turn';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Whose turn is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId)) {
            throw new \LogicException('Whose turn is read under the viewer\'s own organization as tenant.');
        }

        $visibleTurn = TicketVisibilitySql::of(
            TicketVisibility::for($viewer->organizationId, $viewer->viewerId, $viewer->teamIds, $viewer->isAdmin, $viewer->isTriager ? $viewer->viewerId : null),
            self::TURN_ALIAS,
        );
        // The ticket id breaks ties of waiting_since, so the shown rows of a group do not change between two reads.
        $rows = $this->connection->fetchAllAssociative(
            'WITH visible_turn AS (SELECT turn.ticket_id, turn.turn_user_id, turn.turn_label, turn.kind, turn.waiting_since,'
            .' count(*) OVER turn_group AS group_tickets, min(turn.waiting_since) OVER turn_group AS group_oldest,'
            .' row_number() OVER (PARTITION BY turn.turn_user_id, turn.turn_label ORDER BY turn.waiting_since, turn.ticket_id) AS place'
            .' FROM ins_ticket_turn AS turn'
            .' WHERE turn.organization_id = CAST(:organization AS uuid)'
            .(null === $projectId ? '' : ' AND turn.project_id = CAST(:project AS uuid)')
            .' AND '.$visibleTurn->sql()
            .' WINDOW turn_group AS (PARTITION BY turn.turn_user_id, turn.turn_label))'
            .' SELECT visible_turn.ticket_id, visible_turn.turn_user_id, visible_turn.turn_label, visible_turn.kind,'
            .' visible_turn.waiting_since, visible_turn.group_tickets, visible_turn.group_oldest, ticket.key, ticket.title'
            .' FROM visible_turn'
            .' JOIN tkt_ticket_list_v AS ticket ON ticket.organization_id = CAST(:organization AS uuid) AND ticket.id = visible_turn.ticket_id'
            .' WHERE visible_turn.place <= CASE WHEN COALESCE(CAST(visible_turn.turn_user_id AS text), visible_turn.turn_label) = CAST(:opened AS text)'
            .' THEN CAST(:opened_shown AS integer) ELSE CAST(:shown AS integer) END'
            .' ORDER BY visible_turn.turn_user_id, visible_turn.turn_label, visible_turn.place',
            [
                'organization' => $organizationId->toString(),
                ...(null === $projectId ? [] : ['project' => $projectId]),
                'opened' => $openedGroup,
                'opened_shown' => (string) HomeQueries::SHOWN_TICKETS_OF_THE_OPENED_GROUP,
                'shown' => (string) HomeQueries::SHOWN_TICKETS,
                ...$visibleTurn->parameters(),
            ],
        );

        /** @var array<string, array{?UserId, ?string, int, \DateTimeImmutable, list<WaitingTicket>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
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

        return array_values(array_map(
            static fn (array $group): TurnGroup => new TurnGroup(...$group),
            $groups,
        ));
    }
}
