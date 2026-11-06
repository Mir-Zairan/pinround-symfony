<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Yaml\Yaml;

/**
 * The retry waits of every environment but test, read from messenger.yaml: the test env drops the waits (and only
 * the waits), so the tests that run the retries cannot see them. The numbers are ADR-0009's and ADR-0033's.
 */
final class RetryStrategyConfigTest extends TestCase
{
    private const array SHORT_JOB_TRANSPORTS = ['async_media', 'async_notify'];
    private const array RATE_LIMITED_TRANSPORTS = ['async_ai', 'async_integration'];

    #[Test]
    public function exactlyTheOutboxAndTheFourAsyncTransportsRetry(): void
    {
        $retryingTransports = array_keys(array_filter(
            self::configAt('framework', 'messenger', 'transports'),
            static fn (mixed $transport): bool => \is_array($transport) && \array_key_exists('retry_strategy', $transport),
        ));

        self::assertEqualsCanonicalizing(['outbox', ...self::SHORT_JOB_TRANSPORTS, ...self::RATE_LIMITED_TRANSPORTS], $retryingTransports);
    }

    #[Test]
    public function theMediaAndNotifyTransportsRetryThreeTimesAfterOneTenAndSixtySeconds(): void
    {
        foreach (self::SHORT_JOB_TRANSPORTS as $transport) {
            [$retryStrategy, $jitter] = self::strategyOf($transport);

            self::assertSame([1000, 10000, 60000], self::waitsInMs($retryStrategy), $transport);
            self::assertSame(0, $jitter, $transport);
        }
    }

    #[Test]
    public function theAiAndIntegrationTransportsRetryFiveTimesFromThirtySecondsUpToFifteenMinutesWithJitter(): void
    {
        foreach (self::RATE_LIMITED_TRANSPORTS as $transport) {
            [$retryStrategy, $jitter] = self::strategyOf($transport);

            self::assertSame([30000, 90000, 270000, 810000, 900000], self::waitsInMs($retryStrategy), $transport);
            self::assertSame(0.1, $jitter, $transport);
        }
    }

    #[Test]
    public function theOutboxRetriesTenTimesForAboutFiveMinutes(): void
    {
        [$retryStrategy, $jitter] = self::strategyOf('outbox');

        self::assertSame([1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000, 60000, 60000], self::waitsInMs($retryStrategy));
        self::assertSame(0, $jitter);
    }

    #[Test]
    public function theTestEnvironmentChangesOnlyTheWaits(): void
    {
        foreach (['outbox', ...self::SHORT_JOB_TRANSPORTS, ...self::RATE_LIMITED_TRANSPORTS] as $transport) {
            self::assertSame(
                ['delay' => 0, 'multiplier' => 1],
                self::configAt('when@test', 'framework', 'messenger', 'transports', $transport, 'retry_strategy'),
                $transport,
            );
        }
    }

    /**
     * @return array{MultiplierRetryStrategy, int|float} the transport's strategy with its jitter left out, and the jitter
     */
    private static function strategyOf(string $transport): array
    {
        $retryStrategy = self::configAt('framework', 'messenger', 'transports', $transport, 'retry_strategy');
        self::assertSame(['max_retries', 'delay', 'multiplier', 'max_delay', 'jitter'], array_keys($retryStrategy), $transport);
        ['max_retries' => $maxRetries, 'delay' => $delayMs, 'multiplier' => $multiplier, 'max_delay' => $maxDelayMs, 'jitter' => $jitter] = $retryStrategy;
        self::assertIsInt($maxRetries);
        self::assertIsInt($delayMs);
        self::assertIsInt($multiplier);
        self::assertIsInt($maxDelayMs);
        self::assertTrue(\is_int($jitter) || \is_float($jitter), $transport);

        return [new MultiplierRetryStrategy($maxRetries, $delayMs, $multiplier, $maxDelayMs, 0.0), $jitter];
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
