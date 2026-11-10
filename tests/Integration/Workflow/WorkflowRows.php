<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Removes the committed steps and rule sets of the given test organizations through the migrator, which owns
 * wf_step and wf_rule_set and so may delete from them (the app may not), under each organization as tenant, as FORCE
 * ROW LEVEL SECURITY applies to the owner too. The caller makes sure no transaction is open and the entity manager is
 * empty.
 */
final readonly class WorkflowRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    public function removeAllOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                    $migrator->executeStatement('DELETE FROM wf_rule_set WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext->clear();
            }
        }
    }
}
