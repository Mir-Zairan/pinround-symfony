<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only message whose handler dispatches a RecordHandlerSighting without a TenantStamp, held back until it ends.
 */
final readonly class DispatchSightingAfterCurrentMessage
{
}
