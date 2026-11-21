<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\MyReports;

use App\Insight\Api\MyReportsCounts;
use App\Insight\Api\MyReportsTotals;
use App\Insight\Api\TicketViewer;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

/**
 * MyReportsCounts; the contract is on {@see MyReportsCounts}. The badge reads only ins_ticket_turn, which holds a row
 * per open ticket with its reporter, through ins_ticket_turn_turn_user_id_base_score_idx; the totals read the same rows
 * as DbalMyReportsRows, without the page's keyset and limit.
 */
final readonly class DbalMyReportsCounts implements MyReportsCounts
{
    private const string TICKET_ALIAS = 'ticket';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function questionsWaitingOn(OrganizationId $organizationId, UserId $reporterId): int
    {
        $this->requireTenant($organizationId);

        $count = $this->connection->fetchOne(
            'SELECT CAST(count(*) AS integer) AS questions_waiting FROM ins_ticket_turn'
            .' WHERE organization_id = CAST(:organization AS uuid) AND turn_user_id = CAST(:reporter AS uuid)'
            .' AND kind = :answer_kind AND reporter_id = CAST(:reporter AS uuid)',
            ['organization' => $organizationId->toString(), 'reporter' => $reporterId->toString(), 'answer_kind' => DbalMyReportsRows::ANSWER_KIND],
        );

        return self::countOf($count);
    }

    public function totalsOf(TicketViewer $viewer): MyReportsTotals
    {
        $this->requireTenant($viewer->organizationId);

        $visibleTicket = TicketVisibilitySql::of(
            TicketVisibility::for($viewer->organizationId, $viewer->viewerId, $viewer->teamIds, $viewer->isAdmin, $viewer->isTriager ? $viewer->viewerId : null),
            self::TICKET_ALIAS,
        );
        $row = $this->connection->fetchAssociative(
            'SELECT CAST(count(*) AS integer) AS report_count,'
            .' CAST(count(*) FILTER (WHERE ticket.closed_at IS NULL AND turn.kind = :answer_kind AND turn.turn_user_id = CAST(:viewer AS uuid)) AS integer) AS needs_you_count'
            .' FROM tkt_ticket_list_v AS ticket'
            .' LEFT JOIN ins_ticket_turn AS turn ON turn.organization_id = CAST(:organization AS uuid) AND turn.ticket_id = ticket.id'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid) AND ticket.reporter_id = CAST(:viewer AS uuid)'
            .' AND '.$visibleTicket->sql(),
            [
                'organization' => $viewer->organizationId->toString(),
                'viewer' => $viewer->viewerId->toString(),
                'answer_kind' => DbalMyReportsRows::ANSWER_KIND,
                ...$visibleTicket->parameters(),
            ],
        );
        if (false === $row) {
            throw new \LogicException('A count of my reports returned no row.');
        }

        return new MyReportsTotals(self::countOf($row['report_count'] ?? null), self::countOf($row['needs_you_count'] ?? null));
    }

    /**
     * @throws \LogicException when no transaction is open, no tenant is set, or the organization is not the tenant
     */
    private function requireTenant(OrganizationId $organizationId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('My reports are counted in a transaction under the organization as tenant; none is open.');
        }
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('My reports are counted under the reporter\'s own organization as tenant.');
        }
    }

    private static function countOf(mixed $count): int
    {
        if (!\is_int($count)) {
            throw new \LogicException('A count of my reports from the database is not a whole number.');
        }

        return $count;
    }
}
