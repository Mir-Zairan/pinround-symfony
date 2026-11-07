<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Health;

use App\Media\Infrastructure\Health\ObjectStorageIsReachable;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObjectStorageIsReachableTest extends TestCase
{
    /** @var resource|null */
    private $listeningSocket;

    protected function tearDown(): void
    {
        if (\is_resource($this->listeningSocket)) {
            fclose($this->listeningSocket);
        }
    }

    #[Test]
    public function itPassesWhenTheEndpointAcceptsAConnection(): void
    {
        $check = new ObjectStorageIsReachable('http://'.$this->listen());

        $check->verify();

        self::assertSame('object_storage', $check->name());
    }

    #[Test]
    public function itThrowsWhenNothingListens(): void
    {
        $address = $this->listen();
        fclose($this->listeningSocket ?? throw new \LogicException('Not listening.'));

        $this->expectException(ReadinessCheckFailed::class);

        new ObjectStorageIsReachable('http://'.$address)->verify();
    }

    #[Test]
    public function itGivesUpOnAHostThatNeverAnswersWithinTheTimeout(): void
    {
        // TEST-NET-1 (RFC 5737): routed nowhere, so a connect waits for its timeout.
        $startedAt = microtime(true);

        try {
            new ObjectStorageIsReachable('http://192.0.2.1:9000')->verify();
            self::fail('A host that never answers passed.');
        } catch (ReadinessCheckFailed) {
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        if ($elapsedSeconds < 0.5) {
            self::markTestSkipped('This network refused 192.0.2.1 at once, so the connect timeout was not reached.');
        }
        self::assertLessThan(3.0, $elapsedSeconds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpointsWithNothingToReach(): iterable
    {
        yield 'empty, as in .env' => [''];
        yield 'no host' => ['http://'];
        yield 'no scheme and no port' => ['minio'];
        yield 'a scheme with no default port' => ['ftp://minio'];
        yield 'not a URL' => ['http://:80'];
    }

    #[Test]
    #[DataProvider('endpointsWithNothingToReach')]
    public function anEndpointWithNothingToReachFails(string $endpoint): void
    {
        $this->expectException(ReadinessCheckFailed::class);

        new ObjectStorageIsReachable($endpoint)->verify();
    }

    /**
     * @return string the host:port it listens on
     */
    private function listen(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $this->listeningSocket = $socket;

        return (string) stream_socket_get_name($socket, false);
    }
}
