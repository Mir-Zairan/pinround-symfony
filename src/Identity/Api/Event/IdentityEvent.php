<?php

declare(strict_types=1);

namespace App\Identity\Api\Event;

/**
 * An event of the Identity module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under the organization the change was made in, or under none. Users are global, so
 * UserRenamed names no organization; a token event names the token's, its tenant when it was published. Ids are
 * canonical UUID strings, so a consumer needs no Identity class to read one.
 */
interface IdentityEvent
{
    public string $userId { get; }
}
