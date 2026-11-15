<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Processing;

use App\Media\Api\Event\MediaEvent;
use App\Media\Application\Processing\MediaEvents;
use App\Tests\Unit\Media\Application\InMemoryTransactions;

/**
 * MediaEvents as MessengerMediaEvents behaves on the outbox: it refuses outside a transaction, and an event published
 * in a transaction that rolls back is gone with it.
 */
final class RecordingMediaEvents implements MediaEvents
{
    /** @var list<MediaEvent> */
    private array $published = [];

    public function __construct(private readonly InMemoryTransactions $transactions)
    {
        $transactions->enlist(function (): \Closure {
            $snapshot = $this->published;

            return function () use ($snapshot): void {
                $this->published = $snapshot;
            };
        });
    }

    public function publish(MediaEvent $event): void
    {
        if (!$this->transactions->isOpen()) {
            throw new \LogicException('A media event is published in the transaction of its change; none is open.');
        }

        $this->published[] = $event;
    }

    /**
     * @return list<MediaEvent>
     */
    public function published(): array
    {
        return $this->published;
    }
}
