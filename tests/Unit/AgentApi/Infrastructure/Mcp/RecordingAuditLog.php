<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditEntryForAnotherTenantRefused;
use App\Audit\Api\AuditLog;
use App\Audit\Application\AuditDataAllowList;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * An AuditLog that keeps its entries in memory. Like DbalAuditLog it refuses data Audit's allow-list does not list and
 * an entry of another organization than the tenant's, and it can fail as a write in a broken transaction does. Given a connection, it also refuses an entry
 * outside a transaction and notes each entry in a table of that connection, so a rollback takes the entry back as it
 * would take back the aud_event row.
 */
final class RecordingAuditLog implements AuditLog
{
    private const string TABLE = 'recorded_audit_entry';

    /** @var list<AuditEntry> every entry ever recorded, also those a rollback took back */
    private array $recorded = [];

    /** @var list<AuditEntry> the entries no rollback took back, in the order they were recorded */
    public array $entries {
        get {
            if (null === $this->connection) {
                return $this->recorded;
            }

            return array_map(
                fn (mixed $position): AuditEntry => $this->recorded[\is_int($position) ? $position : throw new \LogicException('A position is no integer.')],
                $this->connection->fetchFirstColumn('SELECT position FROM '.self::TABLE.' ORDER BY position'),
            );
        }
    }

    /**
     * @param ?Connection $connection one with no transaction open yet, as the table is made in it
     */
    public function __construct(private readonly OrganizationId $tenant, private readonly ?\Throwable $failure = null, private readonly ?Connection $connection = null)
    {
        $this->connection?->executeStatement('CREATE TABLE '.self::TABLE.' (position INTEGER NOT NULL)');
    }

    public function record(AuditEntry $entry): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        AuditDataAllowList::refuseUnlessAllowed($entry->action, $entry->data);
        if (!$entry->organizationId->equals($this->tenant)) {
            throw new AuditEntryForAnotherTenantRefused();
        }
        if (null !== $this->connection && !$this->connection->isTransactionActive()) {
            throw new \LogicException('An audit entry is recorded in the transaction of the change it describes; none is open.');
        }
        $this->recorded[] = $entry;
        $this->connection?->insert(self::TABLE, ['position' => \count($this->recorded) - 1]);
    }
}
