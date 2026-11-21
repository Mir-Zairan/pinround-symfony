<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Api\MyReportsCursor;
use App\Insight\Api\TicketViewer;
use App\Insight\Application\MyReports\MyReportsRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

/**
 * One query: the reporter's tickets from Ticketing's view tkt_ticket_list_v, open and done, under the visibility
 * predicate on the view's own columns (a done ticket has no turn row to filter on), with their turn row joined by
 * primary key (the one cross-module join ADR-0015 allows). The keyset on (created_at, id) walks the index
 * tkt_ticket_reporter_id_created_at_id_idx in its order.
 */
final readonly class DbalMyReportsRows implements MyReportsRows
{
    private const string TICKET_ALIAS = 'ticket';
    /** The turn kind of an open question, which waits on the asked person (ADR-0015, turn rule 1). */
    public const string ANSWER_KIND = 'answer';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function reportsOf(TicketViewer $viewer, ?MyReportsCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('My reports are read at least one at a time.');
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('My reports are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId)) {
            throw new \LogicException('My reports are read under the viewer\'s own organization as tenant.');
        }

        $visibleTicket = TicketVisibilitySql::of(
            TicketVisibility::for($viewer->organizationId, $viewer->viewerId, $viewer->teamIds, $viewer->isAdmin, $viewer->isTriager ? $viewer->viewerId : null),
            self::TICKET_ALIAS,
        );
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ticket.id, ticket.key, ticket.title, ticket.step_id, ticket.created_at, ticket.updated_at, ticket.closed_at,'
            .' turn.grp, turn.turn_user_id, turn.turn_label, turn.kind'
            .' FROM tkt_ticket_list_v AS ticket'
            .' LEFT JOIN ins_ticket_turn AS turn ON turn.organization_id = CAST(:organization AS uuid) AND turn.ticket_id = ticket.id'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid) AND ticket.reporter_id = CAST(:viewer AS uuid)'
            .(null === $after ? '' : ' AND (ticket.created_at, ticket.id) < (CAST(:after_reported_at AS timestamptz), CAST(:after_ticket AS uuid))')
            .' AND '.$visibleTicket->sql()
            .' ORDER BY ticket.created_at DESC, ticket.id DESC'
            .' LIMIT CAST(:limit AS integer)',
            [
                'organization' => $organizationId->toString(),
                'viewer' => $viewer->viewerId->toString(),
                ...(null === $after ? [] : [
                    'after_reported_at' => $after->reportedAt->format('Y-m-d H:i:sP'),
                    'after_ticket' => $after->ticketId->toString(),
                ]),
                'limit' => (string) $limit,
                ...$visibleTicket->parameters(),
            ],
        );

        return array_map(static fn (array $row): MyReport => self::reportOf($row, $viewer->viewerId), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws \LogicException when the row is not a ticket with at most one turn person or label
     */
    private static function reportOf(array $row, UserId $reporterId): MyReport
    {
        if (!\is_string($row['id']) || !\is_string($row['key']) || !\is_string($row['title']) || !\is_string($row['step_id'])
            || !\is_string($row['created_at']) || !\is_string($row['updated_at'])
            || (null !== $row['closed_at'] && !\is_string($row['closed_at']))
            || (null !== $row['grp'] && !\is_string($row['grp']))
            || (null !== $row['kind'] && !\is_string($row['kind']))
            || (null !== $row['turn_user_id'] && !\is_string($row['turn_user_id']))
            || (null !== $row['turn_label'] && !\is_string($row['turn_label']))
            || (null !== $row['turn_user_id'] && null !== $row['turn_label'])) {
            throw new \LogicException('A row of my reports from the database is not a ticket with at most one turn person or label.');
        }
        $turnUserId = null === $row['turn_user_id'] ? null : new UserId($row['turn_user_id']);

        return new MyReport(
            new TicketId($row['id']),
            $row['key'],
            $row['title'],
            $row['step_id'],
            new \DateTimeImmutable($row['created_at']),
            new \DateTimeImmutable($row['updated_at']),
            null === $row['closed_at'] ? null : new \DateTimeImmutable($row['closed_at']),
            $row['grp'],
            $turnUserId,
            $row['turn_label'],
            $row['kind'],
            self::ANSWER_KIND === $row['kind'] && null !== $turnUserId && $turnUserId->equals($reporterId),
        );
    }
}
