<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * The DBAL connection class of every connection (doctrine.yaml `wrapper_class`).
 *
 * DBAL counts a transaction before the driver's BEGIN runs and does not take the count back when BEGIN, or the
 * tenant setting TenantMiddleware runs right after it, fails. The next transaction on the same connection
 * would then send SAVEPOINT without a transaction, until the worker restarts. When the outermost begin fails,
 * this closes the connection, which resets the count and drops the driver connection (the server ends its
 * transaction), and rethrows the failure. The next statement opens a new connection.
 *
 * Only the outermost begin is covered. A nested begin (savepoint) that fails keeps the connection and its outer
 * transaction, but DBAL's nesting level stays one too high. That is DBAL's own behaviour and is not corrected here.
 */
final class CloseOnFailedBeginConnection extends Connection
{
    public function beginTransaction(): void
    {
        if ($this->isTransactionActive()) {
            parent::beginTransaction();

            return;
        }

        try {
            parent::beginTransaction();
        } catch (\Throwable $beginFailure) {
            $this->close();

            throw $beginFailure;
        }
    }
}
