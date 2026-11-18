<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\Event\IdentityEvent;

/**
 * Tells other modules what changed about a person or their tokens: the event goes to the outbox in the transaction of
 * the change.
 */
interface IdentityEvents
{
    /**
     * @throws \LogicException when no transaction is open
     */
    public function publish(IdentityEvent $event): void;
}
