<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\Event\IdentityEvent;
use App\Identity\Application\IdentityEvents;

final class RecordingIdentityEvents implements IdentityEvents
{
    /** @var list<IdentityEvent> */
    public array $publishedEvents = [];

    public function publish(IdentityEvent $event): void
    {
        $this->publishedEvents[] = $event;
    }
}
