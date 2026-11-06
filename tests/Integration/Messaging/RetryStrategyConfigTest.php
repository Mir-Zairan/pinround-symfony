<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Yaml\Yaml;

/**
 * The retry waits of every environment but test, read from messenger.yaml: the test env drops the waits (and only
 * the waits), so the tests that run the retries cannot see them.
 */
final class RetryStrategyConfigTest extends TestCase
{
    private const array ASYNC_TRANSPORTS = ['async_media', 'async_ai', 'async_notify', 'async_integration'];

    #[Test]
    public function everyAsyncTransportRetriesThreeTimesAfterOneTenAndSixtySeconds(): void
    {
        foreach (self::ASYNC_TRANSPORTS as $transport) {
            $retryStrategy = self::strategyOf(self::configAt('framework', 'messenger', 'transports', $transport, 'retry_strategy'));

            self::assertSame([1000, 10000, 60000], self::waitsInMs($retryStrategy), $transport);
        }
    }

    #[Test]
    public function theOutboxRetriesTenTimesForAboutFiveMinutes(): void
    {
        $retryStrategy = self::strategyOf(self::configAt('framework', 'messenger', 'transports', 'outbox', 'retry_strategy'));

        self::assertSame([1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000, 60000, 60000], self::waitsInMs($retryStrategy));
    }

    #[Test]
    public function theTestEnvironmentChangesOnlyTheWaits(): void
    {
        foreach (['outbox', ...self::ASYNC_TRANSPORTS] as $transport) {
            self::assertSame(
                ['delay' => 0, 'multiplier' => 1],
                self::configAt('when@test', 'framework', 'messenger', 'transports', $transport, 'retry_strategy'),
                $transport,
            );
        }
    }

    /**
     * @param array<mixed> $retryStrategy
     */
    private static function strategyOf(array $retryStrategy): MultiplierRetryStrategy
    {
        self::assertSame(['max_retries', 'delay', 'multiplier', 'max_delay', 'jitter'], array_keys($retryStrategy));
        ['max_retries' => $maxRetries, 'delay' => $delayMs, 'multiplier' => $multiplier, 'max_delay' => $maxDelayMs, 'jitter' => $jitter] = $retryStrategy;
        self::assertIsInt($maxRetries);
        self::assertIsInt($delayMs);
        self::assertIsInt($multiplier);
        self::assertIsInt($maxDelayMs);
        self::assertIsInt($jitter);

        return new MultiplierRetryStrategy($maxRetries, $delayMs, $multiplier, $maxDelayMs, $jitter);
    }

    /**
     * @return list<int> the wait before each retry, until the strategy gives up
     */
    private static function waitsInMs(MultiplierRetryStrategy $retryStrategy): array
    {
        $waits = [];
        for ($retryCount = 0; $retryCount < 20; ++$retryCount) {
            $envelope = 0 === $retryCount ? new Envelope(new \stdClass()) : new Envelope(new \stdClass(), [new RedeliveryStamp($retryCount)]);
            if (!$retryStrategy->isRetryable($envelope)) {
                break;
            }
            $waits[] = $retryStrategy->getWaitingTime($envelope);
        }

        return $waits;
    }

    /**
     * @return array<mixed> the map at that path of messenger.yaml, aliases resolved
     */
    private static function configAt(string ...$path): array
    {
        $node = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/messenger.yaml');
        foreach ($path as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }
        self::assertIsArray($node);

        return $node;
    }
}
