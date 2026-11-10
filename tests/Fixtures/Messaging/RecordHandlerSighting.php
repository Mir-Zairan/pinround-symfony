<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only message whose handler records what it sees of the organization, the tenant probe rows, the message id,
 * the entity manager and the workflow cache, and then loads the probes as entities, so they would be there for a next message.
 */
final readonly class RecordHandlerSighting
{
    public function __construct(public bool $isFailingAfterLoading = false)
    {
    }
}
