<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

/**
 * Writes of a request that runs without the request transaction, for a command that must be rolled back as a whole
 * when it is refused, such as CreateProject when another request took the key first.
 */
interface TicketingTransactions
{
    /**
     * Runs $write in a transaction of its own under the request's tenant, so row-level security applies, and commits
     * it. When $write throws, the transaction is rolled back, the entity manager is left closed and the exception is
     * rethrown.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @param-immediately-invoked-callable $write
     *
     * @return T
     *
     * @throws \LogicException when the request has no tenant, or a transaction is open, as $write would then run in it;
     *                         $write does not run
     */
    public function run(\Closure $write): mixed;
}
