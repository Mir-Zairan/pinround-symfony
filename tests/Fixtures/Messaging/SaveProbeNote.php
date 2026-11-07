<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only message whose $note stands for ticket text: it must never reach a log line. Its handler always fails.
 */
final readonly class SaveProbeNote
{
    public function __construct(public string $note)
    {
    }
}
