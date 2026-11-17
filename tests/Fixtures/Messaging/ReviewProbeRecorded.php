<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\Review\Api\Event\ReviewEvent;

/**
 * Test-only Review event of a kind no consumer knows, as each new one Review adds is at first: routed and relayed as
 * every ReviewEvent is, to prove a consumer of some Review events lets it pass.
 */
final readonly class ReviewProbeRecorded implements ReviewEvent
{
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
