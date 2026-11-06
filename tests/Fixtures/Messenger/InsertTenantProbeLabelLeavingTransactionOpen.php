<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

/**
 * Test-only message: its handler begins a transaction of its own, writes a row and never ends it.
 */
final readonly class InsertTenantProbeLabelLeavingTransactionOpen
{
    public function __construct(public string $organizationId, public string $label)
    {
    }
}
