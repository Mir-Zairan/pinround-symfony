<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Application\ProcessUpload;
use App\Media\Application\UploadProcessingQueue;
use Doctrine\DBAL\Connection;

/**
 * Queues on the queue it wraps, counts the media objects and outbox rows the open transaction then sees, and fails
 * that transaction.
 */
final class FailingUploadProcessingQueue implements UploadProcessingQueue
{
    /** @var list<int> empty until queue() ran */
    public array $mediaObjectsAndOutboxRowsBeforeTheFailure = [];

    public function __construct(private readonly UploadProcessingQueue $queue, private readonly Connection $connection)
    {
    }

    public function queue(ProcessUpload $processUpload): void
    {
        $this->queue->queue($processUpload);
        $this->mediaObjectsAndOutboxRowsBeforeTheFailure = [
            $this->countOf('SELECT count(*) FROM med_object'),
            $this->countOf("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'"),
        ];

        throw new \RuntimeException('The test fails the transaction after queueing.');
    }

    private function countOf(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        if (!\is_int($count)) {
            throw new \UnexpectedValueException('A count query gave no integer.');
        }

        return $count;
    }
}
