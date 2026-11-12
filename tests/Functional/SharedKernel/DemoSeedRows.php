<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Ticketing\ProjectRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Tests\Integration\Workflow\WorkflowRows;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Removes what app:demo:seed and its steps committed, wherever an earlier run left it. The caller empties the entity
 * manager first, as the tenant may only change once it holds nothing.
 */
final readonly class DemoSeedRows
{
    public function __construct(private Connection $migrator, private Connection $connection, private TenantContext $tenantContext)
    {
    }

    /**
     * Removes the organizations of the people with these emails with their tickets, projects and workflow, every
     * outbox message, and the people with their credentials.
     *
     * @param list<string> $emails
     */
    public function removePeopleAndTheirOrganizations(array $emails): void
    {
        $userIds = $this->migrator->fetchFirstColumn('SELECT id FROM idn_user WHERE email IN (?)', [$emails], [ArrayParameterType::STRING]);
        $organizationIds = [];
        foreach ($userIds as $userId) {
            foreach ($this->connection->fetchFirstColumn('SELECT organization_id FROM resolve_user_orgs(?)', [$userId]) as $organizationId) {
                \assert(\is_string($organizationId));
                $organizationIds[$organizationId] = $organizationId;
            }
        }
        $this->removeOrganizations(...array_values($organizationIds));
        $this->migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        if ([] !== $userIds) {
            $this->migrator->executeStatement('DELETE FROM idn_credential WHERE user_id IN (?)', [$userIds], [ArrayParameterType::STRING]);
            $this->migrator->executeStatement('DELETE FROM idn_user WHERE id IN (?)', [$userIds], [ArrayParameterType::STRING]);
        }
    }

    public function removeOrganizations(string ...$organizationIds): void
    {
        new TicketRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        new ProjectRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        new WorkflowRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        new OrganizationRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
    }
}
