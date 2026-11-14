<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\SharedKernel\Application\NoMessageBeingHandled;
use App\SharedKernel\Application\OnceOnlyHandling;

/**
 * Like ProcessedMessageLedger: runs an effect once per message id and handler name, refuses an empty name, and keeps
 * no record of an effect that threw, so the next delivery runs it again.
 */
final class OnceOnlyHandlingDouble implements OnceOnlyHandling
{
    public ?string $messageId = null;
    /** @var list<string> */
    public array $handlerNames = [];
    /** @var array<string, true> */
    private array $processed = [];

    public function runOnce(string $handlerName, \Closure $effect): bool
    {
        if ('' === $handlerName) {
            throw new \InvalidArgumentException('A handler name must not be empty.');
        }
        $this->handlerNames[] = $handlerName;
        $messageId = $this->messageId ?? throw new NoMessageBeingHandled();
        $key = $messageId.'|'.$handlerName;
        if (isset($this->processed[$key])) {
            return false;
        }
        $effect();
        $this->processed[$key] = true;

        return true;
    }
}
