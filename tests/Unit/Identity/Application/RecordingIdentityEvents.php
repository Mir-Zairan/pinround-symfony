<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\Event\UserRenamed;
use App\Identity\Application\IdentityEvents;

final class RecordingIdentityEvents implements IdentityEvents
{
    /** @var list<UserRenamed> */
    public array $publishedEvents = [];

    public function publish(UserRenamed $event): void
    {
        $this->publishedEvents[] = $event;
    }
}
