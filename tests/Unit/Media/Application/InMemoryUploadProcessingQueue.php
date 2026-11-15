<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Application\ProcessUpload;
use App\Media\Application\UploadProcessingQueue;

/**
 * UploadProcessingQueue as MessengerUploadProcessingQueue behaves on the outbox: it refuses outside a transaction, and
 * a message queued in a transaction that rolls back is gone with it.
 */
final class InMemoryUploadProcessingQueue implements UploadProcessingQueue
{
    /** @var list<ProcessUpload> */
    private array $queued = [];

    public function __construct(private readonly InMemoryTransactions $transactions)
    {
        $transactions->enlist(function (): \Closure {
            $snapshot = $this->queued;

            return function () use ($snapshot): void {
                $this->queued = $snapshot;
            };
        });
    }

    public function queue(ProcessUpload $processUpload): void
    {
        if (!$this->transactions->isOpen()) {
            throw new \LogicException('An upload is queued for processing in the transaction of its media object; none is open.');
        }

        $this->queued[] = $processUpload;
    }

    /**
     * What the outbox holds, oldest first; for assertions only.
     *
     * @return list<ProcessUpload>
     */
    public function queued(): array
    {
        return $this->queued;
    }
}
