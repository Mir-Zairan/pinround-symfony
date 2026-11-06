<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only message whose handler writes one row to messaging_probe_effect through OnceOnlyHandling.
 */
final readonly class RecordProbeEffect
{
    public function __construct(public string $probeId)
    {
    }
}
