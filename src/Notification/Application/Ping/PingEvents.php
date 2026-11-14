<?php

declare(strict_types=1);

namespace App\Notification\Application\Ping;

use App\Notification\Api\Event\PingEvent;

interface PingEvents
{
    /**
     * Writes the event to the outbox in the caller's open transaction, so it is delivered only if the change it
     * describes commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function publish(PingEvent $event): void;
}
