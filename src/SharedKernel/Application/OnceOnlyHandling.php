<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * Lets an async handler run its effect at most once per message, however often the message is delivered (ADR-0009:
 * the outbox relay and retries deliver at least once).
 */
interface OnceOnlyHandling
{
    /**
     * Runs $effect unless $handlerName already ran it for the message being handled now. The record that it ran is
     * written in the same database transaction as the effect: if $effect throws, both roll back and the next delivery
     * runs it again. Only database writes in $effect are covered; a call to another system must be safe to repeat.
     *
     * @param string           $handlerName not empty, and kept when the handler class is renamed: a new name runs the
     *                                      effect again for every message delivered again
     * @param \Closure(): void $effect
     *
     * @return bool whether $effect ran
     *
     * @throws NoMessageBeingHandled when called outside the handling of a message on a bus
     */
    public function runOnce(string $handlerName, \Closure $effect): bool;
}
