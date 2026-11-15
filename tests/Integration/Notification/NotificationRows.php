<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Adds committed rows of ntf_notification or ntf_ping through the migrator, under the organization as tenant, as FORCE
 * ROW LEVEL SECURITY applies to the owner too. Their tickets must exist; TicketRows::removeAllOf removes these rows with
 * them. The caller makes sure no transaction is open.
 */
final readonly class NotificationRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    /**
     * @param string                     $table ntf_notification or ntf_ping
     * @param list<array<string, mixed>> $rows  by column
     */
    public function addOf(string $organizationId, string $table, array $rows): void
    {
        $this->asTenant($organizationId, static function (Connection $migrator) use ($table, $rows): void {
            foreach ($rows as $row) {
                $migrator->insert($table, $row);
            }
        });
    }

    /**
     * Runs one statement of the caller's, for rows made in bulk with generate_series.
     *
     * @param list<mixed> $parameters
     */
    public function executeOf(string $organizationId, string $sql, array $parameters): void
    {
        $this->asTenant($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement($sql, $parameters));
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asTenant(string $organizationId, \Closure $work): void
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator->transactional($work);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
