<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * A step of a command inside the caller's open transaction that undoes its own writes when it is refused, so the
 * caller may catch the refusal and still commit. For a step that writes before it can know it will be refused.
 */
interface Savepoints
{
    /**
     * Runs $write in a savepoint of the caller's open transaction and releases it. When $write throws, its writes
     * are rolled back to the savepoint, the caller's transaction stays usable and the exception is rethrown.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @param-immediately-invoked-callable $write
     *
     * @return T
     *
     * @throws \LogicException when no transaction is open, as nothing would then undo $write; $write does not run
     */
    public function run(\Closure $write): mixed;
}
