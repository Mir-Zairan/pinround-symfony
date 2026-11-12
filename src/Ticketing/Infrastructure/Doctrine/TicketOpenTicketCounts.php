<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;
use Doctrine\DBAL\Connection;

/**
 * Both counts use tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx (organization_id, project_id, ...); the
 * viewer's count filters the matched rows with the visibility predicate.
 */
final readonly class TicketOpenTicketCounts implements OpenTicketCounts
{
    private const string TICKET_ALIAS = 'ticket';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function openTicketsIn(ProjectId ...$projectIds): array
    {
        return $this->openTicketsMatching(null, $projectIds);
    }

    public function openTicketsVisibleTo(TicketVisibility $viewer, ProjectId ...$projectIds): array
    {
        return $this->openTicketsMatching(TicketVisibilitySql::of($viewer, self::TICKET_ALIAS), $projectIds);
    }

    /**
     * @param array<ProjectId> $projectIds
     *
     * @return array<string, int>
     */
    private function openTicketsMatching(?TicketVisibilitySql $visibleToViewer, array $projectIds): array
    {
        $counts = [];
        foreach ($projectIds as $projectId) {
            $counts[$projectId->toString()] = 0;
        }
        if ([] === $counts) {
            return [];
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Open tickets are counted in a transaction under the organization as tenant; none is open.');
        }

        // One array literal keeps the SQL text the same for any number of ids; a ProjectId is a uuid, so needs no quoting.
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT ticket.project_id, count(*) FROM tkt_ticket AS ticket'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid) AND ticket.project_id = ANY(CAST(:projects AS uuid[])) AND ticket.closed_at IS NULL'
            .(null === $visibleToViewer ? '' : ' AND '.$visibleToViewer->sql())
            .' GROUP BY ticket.project_id',
            [
                'organization' => $this->tenantContext->require()->toString(),
                'projects' => '{'.implode(',', array_keys($counts)).'}',
                ...($visibleToViewer?->parameters() ?? []),
            ],
        );
        foreach ($rows as $projectId => $openTickets) {
            if (!\is_int($openTickets) || !\array_key_exists($projectId, $counts)) {
                throw new \LogicException('A ticket count from the database is not an integer of a given project.');
            }
            $counts[$projectId] = $openTickets;
        }

        return $counts;
    }
}
