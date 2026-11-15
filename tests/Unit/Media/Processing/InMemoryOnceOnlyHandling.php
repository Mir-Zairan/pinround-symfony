<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Processing;

use App\SharedKernel\Application\NoMessageBeingHandled;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\Tests\Unit\Media\Application\InMemoryTransactions;

/**
 * OnceOnlyHandling as the ledger behaves: the effect runs in a transaction, and the record that it ran is kept only if
 * the effect did not throw. A test starts a message with deliver(); delivering the same id again is a redelivery.
 */
final class InMemoryOnceOnlyHandling implements OnceOnlyHandling
{
    private ?string $messageId = null;

    /** @var array<string, true> */
    private array $ran = [];

    public function __construct(private readonly InMemoryTransactions $transactions)
    {
    }

    public function deliver(string $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function runOnce(string $handlerName, \Closure $effect): bool
    {
        if ('' === $handlerName) {
            throw new \InvalidArgumentException('A handler name is not empty.');
        }
        if (null === $this->messageId) {
            throw new NoMessageBeingHandled();
        }
        $key = $handlerName.' '.$this->messageId;
        if (isset($this->ran[$key])) {
            return false;
        }

        $this->transactions->run(static function () use ($effect): void {
            $effect();
        });
        $this->ran[$key] = true;

        return true;
    }
}
