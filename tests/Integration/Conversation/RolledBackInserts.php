<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Inserts rows as the app under one organization in a transaction that is always rolled back, for the table tests that
 * prove which rows a CHECK or unique index refuses. A row is a table name and its column values.
 */
final readonly class RolledBackInserts
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext, private string $organizationId)
    {
    }

    /**
     * @param non-empty-list<array{string, array<string, mixed>}> $rows
     *
     * @return list<int|string> the affected row count of each insert
     */
    public function insertAll(array $rows): array
    {
        return $this->rolledBack(fn (): array => array_map(fn (array $row): int|string => $this->connection->insert(...$row), $rows));
    }

    /**
     * Inserts every row but the last, then the last one, which the database must refuse.
     *
     * @param non-empty-list<array{string, array<string, mixed>}> $rows
     *
     * @return array{?string, bool, mixed} the refusal's SQLSTATE, whether its message names the constraint, and the
     *                                     rows the last row's table holds after the refusal
     */
    public function refusalOfLast(array $rows, string $constraint): array
    {
        $refusedRow = array_pop($rows);

        return $this->rolledBack(function () use ($rows, $refusedRow, $constraint): array {
            foreach ($rows as $row) {
                $this->connection->insert(...$row);
            }
            $this->connection->createSavepoint('refused_row');
            try {
                $this->connection->insert(...$refusedRow);
            } catch (DriverException $refusal) {
                $this->connection->rollbackSavepoint('refused_row');

                // PostgreSQL names the constraint only in the message; DBAL has no own exception for a check violation.
                return [$refusal->getSQLState(), str_contains($refusal->getMessage(), $constraint), $this->connection->fetchOne(\sprintf('SELECT count(*) FROM %s', $refusedRow[0]))];
            }

            throw new \LogicException(\sprintf('The database accepted the row that %s must refuse.', $constraint));
        });
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function rolledBack(\Closure $work): mixed
    {
        $this->tenantContext->set(new OrganizationId($this->organizationId));
        $this->connection->beginTransaction();
        try {
            return $work();
        } finally {
            $this->connection->rollBack();
            $this->tenantContext->clear();
        }
    }
}
