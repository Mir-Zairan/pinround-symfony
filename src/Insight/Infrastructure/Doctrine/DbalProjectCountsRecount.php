<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\ProjectCountsRecount;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * ProjectCountsRecount on ins_project_counts; the contract is on {@see ProjectCountsRecount}. Version20261112140100
 * runs a copy of these statements for every organization.
 */
final readonly class DbalProjectCountsRecount implements ProjectCountsRecount
{
    /**
     * The organization's lock on its counts: the recount takes it exclusive, ProjectCountsProjector shared. A recount
     * then waits for a transaction that already counted a new ticket to commit, so it sees that ticket, and a new
     * ticket waits for a running recount, so it is added to the recounted rows rather than lost under them.
     */
    public const string LOCK_KEY = "hashtextextended('ins_project_counts ' || ?, 0)";

    private const string DELETE = 'DELETE FROM ins_project_counts WHERE organization_id = ?';
    private const string INSERT = 'INSERT INTO ins_project_counts (organization_id, project_id, visibility, open_count, done_count)'
        ." SELECT ticket.organization_id, ticket.project_id, ticket.visibility, count(*) FILTER (WHERE step.grp <> 'done'),"
        ." count(*) FILTER (WHERE step.grp = 'done')"
        .' FROM tkt_ticket AS ticket'
        .' JOIN wf_step AS step ON step.organization_id = ticket.organization_id AND step.id = ticket.step_id'
        .' WHERE ticket.organization_id = ?'
        .' GROUP BY ticket.organization_id, ticket.project_id, ticket.visibility';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function recount(OrganizationId $organizationId): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every ticket.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The project counts are recounted in a transaction under the organization as tenant; none is open.');
        }
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('The project counts are recounted under their own organization as tenant.');
        }

        $organization = $organizationId->toString();
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock('.self::LOCK_KEY.')', [$organization]);
        $this->connection->executeStatement(self::DELETE, [$organization]);
        $this->connection->executeStatement(self::INSERT, [$organization]);
    }
}
