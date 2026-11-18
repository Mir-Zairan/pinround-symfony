<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\MyTicket;
use App\Insight\Api\MyTicketQueries;
use App\Insight\Api\MyTickets;
use App\Insight\Api\TicketViewer;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

/**
 * One query: the viewer's turn rows and the rows that name them as assignee, under the visibility predicate on the
 * row's copied columns, joined by primary key to Ticketing's view tkt_ticket_list_v for the key, title, step, asker
 * and last change (the one cross-module join ADR-0015 allows). One row past the limit tells whether more exist.
 * assignee_id has no index on ins_ticket_turn, so the assignee half reads the organization's open turn rows; they are
 * one per open ticket, and the MCP tool limits its calls per token.
 */
final readonly class DbalMyTicketQueries implements MyTicketQueries
{
    private const string TURN_ALIAS = 'turn';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function myTicketsOf(TicketViewer $viewer, int $limit): MyTickets
    {
        if ($limit < 1 || $limit > self::MAX_TICKETS) {
            throw new \InvalidArgumentException(\sprintf('My tickets are read 1 to %d at a time.', self::MAX_TICKETS));
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('My tickets are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId)) {
            throw new \LogicException('My tickets are read under the viewer\'s own organization as tenant.');
        }

        $visibleTurn = TicketVisibilitySql::of(
            TicketVisibility::for($viewer->organizationId, $viewer->viewerId, $viewer->teamIds, $viewer->isAdmin, $viewer->isTriager ? $viewer->viewerId : null),
            self::TURN_ALIAS,
        );
        $rows = $this->connection->fetchAllAssociative(
            'SELECT turn.ticket_id, turn.team_id, turn.turn_user_id, turn.turn_label, turn.kind, turn.assignee_id,'
            .' ticket.key, ticket.title, ticket.step_id, ticket.asker_id, ticket.updated_at'
            .' FROM ins_ticket_turn AS turn'
            .' JOIN tkt_ticket_list_v AS ticket ON ticket.organization_id = CAST(:organization AS uuid) AND ticket.id = turn.ticket_id'
            .' WHERE turn.organization_id = CAST(:organization AS uuid)'
            .' AND (turn.turn_user_id = CAST(:viewer AS uuid) OR turn.assignee_id = CAST(:viewer AS uuid))'
            .' AND '.$visibleTurn->sql()
            .' ORDER BY ticket.updated_at DESC, turn.ticket_id DESC'
            .' LIMIT CAST(:limit AS integer)',
            [
                'organization' => $organizationId->toString(),
                'viewer' => $viewer->viewerId->toString(),
                'limit' => (string) ($limit + 1),
                ...$visibleTurn->parameters(),
            ],
        );

        $tickets = array_map(self::ticketOf(...), \array_slice($rows, 0, $limit));

        return new MyTickets($tickets, \count($rows) > $limit);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws \LogicException when the row is not an open ticket of one person or label
     */
    private static function ticketOf(array $row): MyTicket
    {
        if (!\is_string($row['ticket_id']) || !\is_string($row['kind']) || !\is_string($row['key'])
            || !\is_string($row['title']) || !\is_string($row['step_id']) || !\is_string($row['updated_at'])
            || (null === $row['turn_user_id']) === (null === $row['turn_label'])
            || (null !== $row['turn_label'] && !\is_string($row['turn_label']))) {
            throw new \LogicException('A row of my tickets from the database is not an open ticket of one person or label.');
        }

        return new MyTicket(
            new TicketId($row['ticket_id']),
            $row['key'],
            $row['title'],
            $row['step_id'],
            null === $row['team_id'] ? null : new TeamId(self::text($row['team_id'])),
            self::userIdOrNull($row['turn_user_id']),
            $row['turn_label'],
            $row['kind'],
            self::userIdOrNull($row['assignee_id']),
            self::userIdOrNull($row['asker_id']),
            new \DateTimeImmutable($row['updated_at']),
        );
    }

    private static function userIdOrNull(mixed $value): ?UserId
    {
        return null === $value ? null : new UserId(self::text($value));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('An id of my tickets from the database is not text.');
    }
}
