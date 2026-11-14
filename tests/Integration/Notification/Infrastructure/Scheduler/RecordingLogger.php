<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Scheduler;

use Psr\Log\AbstractLogger;

/** Keeps each log call's level, message and context, in call order. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
