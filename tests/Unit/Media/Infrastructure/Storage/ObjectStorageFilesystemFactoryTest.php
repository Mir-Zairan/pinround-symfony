<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Infrastructure\Storage\ObjectStorageFilesystemFactory;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sends a command through the factory's client to a stand-in for the storage that fails every request as a dropped
 * connection would, and records what each attempt was sent with.
 */
final class ObjectStorageFilesystemFactoryTest extends TestCase
{
    /** @var list<mixed> the '@http' request options of every attempt, in order */
    private array $attempts = [];

    #[Test]
    public function everyRequestCarriesAConnectTimeoutAndARequestTimeout(): void
    {
        $this->headAnObjectOnAStorageThatDropsEveryConnection();

        self::assertNotEmpty($this->attempts);
        foreach ($this->attempts as $requestOptions) {
            self::assertIsArray($requestOptions);
            self::assertSame(1, $requestOptions['connect_timeout'] ?? null);
            self::assertSame(30, $requestOptions['timeout'] ?? null);
        }
    }

    #[Test]
    public function aDroppedConnectionIsTriedOnceMoreThenGivenUp(): void
    {
        $refusal = $this->headAnObjectOnAStorageThatDropsEveryConnection();

        self::assertTrue($refusal->isConnectionError());
        self::assertCount(2, $this->attempts);
    }

    private function headAnObjectOnAStorageThatDropsEveryConnection(): S3Exception
    {
        $client = new ObjectStorageFilesystemFactory(
            new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'us-east-1', 'acme-key', 'acme-secret'),
        )->createClient();
        $dropTheConnection = function (CommandInterface $command): S3Exception {
            $this->attempts[] = $command['@http'];

            return new S3Exception('Connection dropped.', $command, ['connection_error' => true]);
        };
        // More than the client may use, so the SDK's default of three attempts would show up in the count.
        $client->getHandlerList()->setHandler(new MockHandler(array_fill(0, 3, $dropTheConnection)));

        try {
            $client->headObject(['Bucket' => 'pinround', 'Key' => 'uploads/any']);
        } catch (S3Exception $refusal) {
            return $refusal;
        }

        self::fail('A storage that drops every connection answered.');
    }
}
