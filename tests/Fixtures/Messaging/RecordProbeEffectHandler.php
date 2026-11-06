<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\SharedKernel\Application\OnceOnlyHandling;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes its effect, a row in the test-only table messaging_probe_effect, through OnceOnlyHandling, and counts how
 * often it was called, so a test tells a skipped effect from a handler that never ran. Registered on both buses.
 */
#[AsMessageHandler]
final class RecordProbeEffectHandler
{
    public const string HANDLER_NAME = 'tests.record_probe_effect';

    private int $callCount = 0;
    private bool $isNextEffectFailing = false;
    private bool $isNextEffectFailureCaught = false;

    public function __construct(
        private readonly OnceOnlyHandling $onceOnlyHandling,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @throws \RuntimeException after writing the effect, when failNextEffectAfterWriting() was called
     */
    public function __invoke(RecordProbeEffect $message): void
    {
        ++$this->callCount;

        try {
            $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($message): void {
                $this->connection->executeStatement('INSERT INTO messaging_probe_effect (probe_id) VALUES (?)', [$message->probeId]);

                if ($this->isNextEffectFailing) {
                    $this->isNextEffectFailing = false;

                    throw new \RuntimeException('The probe effect failed after it was written.');
                }
            });
        } catch (\RuntimeException $failure) {
            if (!$this->isNextEffectFailureCaught) {
                throw $failure;
            }

            // The handler goes on, so the bus's transaction commits around the rolled-back effect.
            $this->isNextEffectFailureCaught = false;
        }
    }

    public function failNextEffectAfterWriting(): void
    {
        $this->isNextEffectFailing = true;
    }

    public function failNextEffectAfterWritingAndCatchTheFailure(): void
    {
        $this->isNextEffectFailing = true;
        $this->isNextEffectFailureCaught = true;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
