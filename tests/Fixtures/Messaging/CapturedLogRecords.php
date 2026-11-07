<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Test-only: keeps every record any channel logs, before a handler buffers or drops it, so a test can read what a
 * log would hold. Not resettable on purpose: the worker's service reset must not empty it mid-test.
 */
#[AsMonologProcessor]
final class CapturedLogRecords implements ProcessorInterface
{
    /** @var list<LogRecord> */
    private array $records = [];

    public function __invoke(LogRecord $record): LogRecord
    {
        $this->records[] = $record;

        return $record;
    }

    /**
     * @return list<LogRecord>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
