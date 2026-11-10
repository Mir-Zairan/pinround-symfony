<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * Writes of a request that runs without the request transaction, so the action knows its change is committed, or
 * rolled back as a whole when a command is refused, before it answers, such as CreateProject when another request
 * took the key first.
 */
interface Transactions
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
