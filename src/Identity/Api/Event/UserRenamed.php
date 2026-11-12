<?php

declare(strict_types=1);

namespace App\Identity\Api\Event;

/**
 * A person saved a new display name. Whoever keeps the name (Organization's team people cache) learns of it once the
 * relay has moved it from the outbox and worker-notify handles it, so the old name can show until then.
 */
final readonly class UserRenamed implements IdentityEvent
{
    /**
     * @param string $userId a canonical UUID
     */
    public function __construct(public string $userId)
    {
    }
}
