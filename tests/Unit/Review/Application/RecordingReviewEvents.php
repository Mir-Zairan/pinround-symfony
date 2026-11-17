<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Api\Event\ReviewEvent;
use App\Review\Application\ReviewEvents;

final class RecordingReviewEvents implements ReviewEvents
{
    /** @var list<ReviewEvent> */
    private array $published = [];

    public function publish(ReviewEvent $event): void
    {
        $this->published[] = $event;
    }

    /**
     * @return list<ReviewEvent>
     */
    public function published(): array
    {
        return $this->published;
    }
}
