<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Tracing;

/**
 * Test-only message of the Messenger span tests: $note stands for message content, which must never reach a span.
 * Its handler runs one query; with $isFailing it then throws.
 */
final readonly class TracedProbeMessage
{
    public function __construct(public string $note, public bool $isFailing = false)
    {
    }
}
