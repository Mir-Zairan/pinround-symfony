<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\Event\ReviewEvent;

interface ReviewEvents
{
    /**
     * Writes the event to the outbox in the open transaction, so it is sent only when the change commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function publish(ReviewEvent $event): void;
}
