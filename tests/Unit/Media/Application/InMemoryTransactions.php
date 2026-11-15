<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;

/**
 * Transactions over the in-memory Media ports: like DoctrineTransactions it refuses without a tenant or inside another
 * transaction, and a write that throws leaves every enlisted port as it was before the run. A test sets
 * $nextCommitFailure to make the next commit fail as the database would, after the write ran.
 */
final class InMemoryTransactions implements Transactions
{
    public ?\Throwable $nextCommitFailure = null;

    private bool $isOpen = false;

    /** @var list<\Closure(): (\Closure(): void)> each takes a snapshot and returns what puts it back */
    private array $snapshotTakers = [];

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    /**
     * @param \Closure(): (\Closure(): void) $snapshotTaker
     */
    public function enlist(\Closure $snapshotTaker): void
    {
        $this->snapshotTakers[] = $snapshotTaker;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function run(\Closure $write): mixed
    {
        if (null === $this->tenantContext->get()) {
            throw new \LogicException('A transaction of its own needs the request\'s tenant.');
        }
        if ($this->isOpen) {
            throw new \LogicException('A transaction of its own must not run inside another transaction.');
        }

        $rollbacks = array_map(static fn (\Closure $takeSnapshot): \Closure => $takeSnapshot(), $this->snapshotTakers);
        $this->isOpen = true;
        try {
            $written = $write();
            if (null !== $this->nextCommitFailure) {
                $commitFailure = $this->nextCommitFailure;
                $this->nextCommitFailure = null;

                throw $commitFailure;
            }

            return $written;
        } catch (\Throwable $failure) {
            foreach ($rollbacks as $rollBack) {
                $rollBack();
            }

            throw $failure;
        } finally {
            $this->isOpen = false;
        }
    }
}
