<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

use App\Media\Api\Event\MediaEvent;

interface MediaEvents
{
    /**
     * Writes the event to the outbox in the caller's open transaction, so it is delivered only if the change it
     * describes commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function publish(MediaEvent $event): void;
}
