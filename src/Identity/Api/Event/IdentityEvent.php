<?php

declare(strict_types=1);

namespace App\Identity\Api\Event;

/**
 * An event of the Identity module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml). Users are global, so it names no organization and is handled under none, or under the organization
 * the change was made in. Ids are canonical UUID strings, so a consumer needs no Identity class to read one.
 */
interface IdentityEvent
{
    public string $userId { get; }
}
