<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Messenger;

use App\Insight\Infrastructure\Doctrine\DbalProjectCountsRecount;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Ticketing\Api\Event\TicketCreated;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts a new ticket into ins_project_counts, by its project, visibility and step group, in the transaction that
 * creates it: messenger.yaml routes TicketCreated to the sync transport same_transaction as well as to the outbox, and
 * this handler takes it only from there, so the relayed copy in worker-notify does not count it twice. A failure here
 * fails the creation.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'same_transaction')]
final readonly class ProjectCountsProjector
{
    private const string UPSERT = 'INSERT INTO ins_project_counts AS stored (organization_id, project_id, visibility, open_count, done_count)'
        ." SELECT ticket.organization_id, ticket.project_id, ticket.visibility, CASE WHEN step.grp = 'done' THEN 0 ELSE 1 END,"
        ." CASE WHEN step.grp = 'done' THEN 1 ELSE 0 END"
        .' FROM tkt_ticket AS ticket'
        .' JOIN wf_step AS step ON step.organization_id = ticket.organization_id AND step.id = ticket.step_id'
        .' WHERE ticket.organization_id = ? AND ticket.id = ?'
        .' ON CONFLICT (organization_id, project_id, visibility) DO UPDATE'
        .' SET open_count = stored.open_count + EXCLUDED.open_count, done_count = stored.done_count + EXCLUDED.done_count';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    /**
     * @throws \LogicException when no transaction is open or the event is of another organization than the tenant
     */
    public function __invoke(TicketCreated $event): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A new ticket is counted in the transaction that creates it; none is open.');
        }
        if (!$this->tenantContext->require()->equals(new OrganizationId($event->organizationId))) {
            throw new \LogicException('A new ticket is counted under its own organization as tenant.');
        }

        $this->connection->executeQuery('SELECT pg_advisory_xact_lock_shared('.DbalProjectCountsRecount::LOCK_KEY.')', [$event->organizationId]);
        $this->connection->executeStatement(self::UPSERT, [$event->organizationId, $event->ticketId]);
    }
}
