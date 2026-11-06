<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\SharedKernel\Infrastructure\Messenger\DsnWithoutStreamEnvVarProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Yaml\Yaml;

/**
 * The four async_* transports share one MESSENGER_TRANSPORT_DSN and must still write to four streams (ADR-0033). Each
 * transport's options from messenger.yaml go through the Redis transport's own DSN parser, with a stub Valkey client
 * that records the stream of each write.
 */
final class AsyncTransportStreamsTest extends TestCase
{
    private const array ASYNC_TRANSPORTS = ['async_media', 'async_ai', 'async_notify', 'async_integration'];

    #[Test]
    public function exactlyFourAsyncTransportsReadTheSharedDsnThroughTheStreamGuard(): void
    {
        $transports = self::transports();
        $asyncTransports = array_values(array_filter(array_keys($transports), static fn (string|int $name): bool => str_starts_with((string) $name, 'async_')));

        self::assertEqualsCanonicalizing(self::ASYNC_TRANSPORTS, $asyncTransports);
        foreach (self::ASYNC_TRANSPORTS as $transport) {
            self::assertSame('%env(dsn_without_stream:MESSENGER_TRANSPORT_DSN)%', $transports[$transport]['dsn'] ?? null, $transport);
        }
    }

    #[Test]
    public function theDevDsnWritesEachAsyncTransportToItsOwnStream(): void
    {
        $dsn = self::throughTheGuard(self::devTransportDsn());

        $streams = array_map(static fn (string $transport): string => self::streamWrittenBy($transport, $dsn), self::ASYNC_TRANSPORTS);

        self::assertSame(self::ASYNC_TRANSPORTS, $streams);
    }

    /** Why the guard exists: the Redis transport lets a stream in the DSN path win over each transport's own. */
    #[Test]
    public function aDsnWithAStreamWouldMergeTheFourQueuesAndTheGuardRefusesIt(): void
    {
        $dsnWithStream = 'redis://valkey-state:6379/messages';

        $streams = array_map(static fn (string $transport): string => self::streamWrittenBy($transport, $dsnWithStream), self::ASYNC_TRANSPORTS);
        self::assertSame(['messages'], array_values(array_unique($streams)));

        $this->expectException(RuntimeException::class);
        self::throughTheGuard($dsnWithStream);
    }

    private static function streamWrittenBy(string $transport, string $dsn): string
    {
        $options = self::transports()[$transport]['options'] ?? null;
        self::assertIsArray($options, $transport);

        $writtenStreams = [];
        $valkey = self::createStub(\Redis::class);
        $valkey->method('isConnected')->willReturn(true);
        $valkey->method('xadd')->willReturnCallback(static function (string $stream) use (&$writtenStreams): string {
            $writtenStreams[] = $stream;

            return '1-0';
        });

        // auto_setup off: setup would create the consumer group first, which the stub cannot answer.
        Connection::fromDsn($dsn, ['auto_setup' => false] + $options, $valkey)->add('{}', []);
        self::assertCount(1, $writtenStreams, $transport);

        return $writtenStreams[0];
    }

    private static function throughTheGuard(string $dsn): string
    {
        return new DsnWithoutStreamEnvVarProcessor()->getEnv(DsnWithoutStreamEnvVarProcessor::PREFIX, 'MESSENGER_TRANSPORT_DSN', static fn (): string => $dsn);
    }

    private static function devTransportDsn(): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/.env.dev');
        self::assertIsString($contents);
        $dsn = new Dotenv()->parse($contents)['MESSENGER_TRANSPORT_DSN'] ?? null;
        self::assertIsString($dsn);
        self::assertStringStartsWith('redis://', $dsn);

        return $dsn;
    }

    /**
     * @return array<array-key, array<mixed>> framework.messenger.transports of every environment but test
     */
    private static function transports(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/messenger.yaml');
        $transports = $config;
        foreach (['framework', 'messenger', 'transports'] as $key) {
            self::assertIsArray($transports);
            $transports = $transports[$key] ?? null;
        }
        self::assertIsArray($transports);

        return array_filter($transports, \is_array(...));
    }
}
