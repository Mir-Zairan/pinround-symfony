<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Ping;

use App\Notification\Api\Event\PingEvent;
use App\Notification\Application\Ping\PingEvents;

final class RecordingPingEvents implements PingEvents
{
    /** @var list<PingEvent> */
    private array $published = [];

    private ?\Throwable $nextPublishFailure = null;

    public function publish(PingEvent $event): void
    {
        if (null !== $this->nextPublishFailure) {
            $failure = $this->nextPublishFailure;
            $this->nextPublishFailure = null;

            throw $failure;
        }

        $this->published[] = $event;
    }

    public function failNextPublish(\Throwable $failure): void
    {
        $this->nextPublishFailure = $failure;
    }

    /**
     * @return list<PingEvent>
     */
    public function published(): array
    {
        return $this->published;
    }
}
