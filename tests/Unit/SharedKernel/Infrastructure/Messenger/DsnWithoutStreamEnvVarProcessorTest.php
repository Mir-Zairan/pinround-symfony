<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Infrastructure\Messenger\DsnWithoutStreamEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class DsnWithoutStreamEnvVarProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function dsnsWithoutAStream(): iterable
    {
        yield 'host and port' => ['redis://valkey-state:6379'];
        yield 'trailing slash' => ['redis://valkey-state:6379/'];
        yield 'password with a slash, TLS, db index in the query' => ['rediss://user:pass/word@valkey-state:6380?dbindex=2'];
        yield 'valkey scheme' => ['valkey://valkey-state:6379?lazy=1'];
        yield 'unix socket' => ['redis:///var/run/valkey/valkey.sock'];
        yield 'cluster hosts' => ['redis://valkey-a:6379,redis://valkey-b:6379'];
        yield 'a password with a comma, a slash and a question mark' => ['redis://user:se,c/r?et@valkey-state:6379'];
        yield 'not a Redis DSN' => ['in-memory://'];
        yield 'a Doctrine DSN with a path' => ['doctrine://default/messages'];
    }

    #[Test]
    #[DataProvider('dsnsWithoutAStream')]
    public function aDsnWithoutAStreamPassesUnchanged(string $dsn): void
    {
        self::assertSame($dsn, self::resolve($dsn));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dsnsWithAStream(): iterable
    {
        yield 'stream in the path' => ['redis://valkey-state:6379/messages'];
        yield 'stream, group and consumer in the path' => ['redis://valkey-state:6379/messages/symfony/consumer'];
        yield 'db index written as a path, as a cache DSN does' => ['redis://valkey-state:6379/1'];
        yield 'stream in the query' => ['valkey://valkey-state:6379?stream=messages'];
        yield 'stream on the second cluster host' => ['redis://valkey-a:6379,redis://valkey-b:6379/messages'];
        yield 'stream after a password' => ['rediss://user:secret-pass@valkey-state:6380/messages'];
        yield 'stream after a password with a comma and a question mark' => ['redis://user:secret,pa?ss@valkey-state:6379/messages'];
    }

    #[Test]
    #[DataProvider('dsnsWithAStream')]
    public function aDsnWithAStreamFailsWithoutShowingTheDsn(string $dsn): void
    {
        try {
            self::resolve($dsn);
            self::fail('The DSN with a stream was accepted.');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('"MESSENGER_TRANSPORT_DSN" environment variable names a Redis stream', $refusal->getMessage());
            self::assertStringNotContainsString('secret-pass', $refusal->getMessage());
            self::assertStringNotContainsString('valkey', $refusal->getMessage());
        }
    }

    #[Test]
    public function aValueThatIsNotAStringFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('must be a DSN string');

        new DsnWithoutStreamEnvVarProcessor()->getEnv(DsnWithoutStreamEnvVarProcessor::PREFIX, 'MESSENGER_TRANSPORT_DSN', static fn (): null => null);
    }

    #[Test]
    public function itProvidesItsPrefixAsAString(): void
    {
        self::assertSame(['dsn_without_stream' => 'string'], DsnWithoutStreamEnvVarProcessor::getProvidedTypes());
    }

    private static function resolve(string $dsn): string
    {
        return new DsnWithoutStreamEnvVarProcessor()->getEnv(
            DsnWithoutStreamEnvVarProcessor::PREFIX,
            'MESSENGER_TRANSPORT_DSN',
            static fn (string $name): string => 'MESSENGER_TRANSPORT_DSN' === $name ? $dsn : throw new \LogicException($name),
        );
    }
}
