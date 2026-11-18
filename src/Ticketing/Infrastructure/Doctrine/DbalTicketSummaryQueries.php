<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketSummaryHistory;
use App\Ticketing\Api\TicketSummaryQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

final readonly class DbalTicketSummaryQueries implements TicketSummaryQueries
{
    private const string TICKET_ALIAS = 't';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function historyOf(TicketId $ticketId, TicketVisibility $visibility): ?TicketSummaryHistory
    {
        $organizationId = $this->tenantContext->require();
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Ticket summaries are read in a transaction under the organization as tenant; none is open.');
        }

        // A visible ticket with no summary is one row of NULLs, so no row at all means hidden or unknown. The window
        // count runs before the LIMIT, so it counts every summary of the ticket.
        $visible = TicketVisibilitySql::of($visibility, self::TICKET_ALIAS);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.text, s.source, s.author_id, s.model, s.prompt_version, s.is_current, s.created_at,'
            .' COUNT(s.id) OVER () AS summary_count'
            .' FROM tkt_ticket t LEFT JOIN tkt_summary s ON s.organization_id = t.organization_id AND s.ticket_id = t.id'
            .' WHERE t.organization_id = CAST(:organization AS uuid) AND t.id = CAST(:ticket AS uuid) AND '.$visible->sql()
            .' ORDER BY s.is_current DESC NULLS LAST, s.created_at DESC, s.id DESC'
            .' LIMIT '.(self::EARLIER_LIMIT + 1),
            [
                ...$visible->parameters(),
                'organization' => $organizationId->toString(),
                'ticket' => $ticketId->toString(),
            ],
        );
        if ([] === $rows) {
            return null;
        }

        $current = null;
        $earlier = [];
        foreach ($rows as $row) {
            if (null === $row['id']) {
                continue;
            }
            $view = TicketSummaryRow::viewOf($row);
            if (true === $row['is_current']) {
                $current = $view;
            } else {
                $earlier[] = $view;
            }
        }
        $summaryCount = $rows[0]['summary_count'];
        if (!\is_int($summaryCount)) {
            throw new \LogicException('The summary count of a ticket is not an integer.');
        }

        return new TicketSummaryHistory(
            $current,
            \array_slice($earlier, 0, self::EARLIER_LIMIT),
            $summaryCount - (null === $current ? 0 : 1),
        );
    }
}
