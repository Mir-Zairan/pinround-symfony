<?php

declare(strict_types=1);

namespace App\Identity\Api\Event;

/**
 * A person saved a new display name. Users are global, so it names no organization. It has no route to the outbox: it
 * is handled at once, inside the transaction of the change, by whoever keeps the name (the team people cache).
 */
final readonly class UserRenamed
{
    /**
     * @param string $userId a canonical UUID
     */
    public function __construct(public string $userId)
    {
    }
}
