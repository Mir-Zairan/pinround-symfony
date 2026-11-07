<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Infrastructure\Messenger\RedisDsnHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Exception\InvalidArgumentException as MessengerInvalidArgumentException;

final class RedisDsnHostsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function dsnsAndTheirHosts(): iterable
    {
        yield 'one host' => ['redis://valkey-state:6379', ['redis://valkey-state:6379']];
        yield 'several hosts, mixed schemes' => [
            'redis://a:6379,valkey://b:6379,rediss://c:6380',
            ['redis://a:6379', 'valkey://b:6379', 'rediss://c:6380'],
        ];
        yield 'a password with an encoded comma and a raw question mark' => [
            'redis://user:se%2Cc?ret@a:6379,redis://user:se%2Cc?ret@b:6379?dbindex=1',
            ['redis://user:se%2Cc?ret@a:6379', 'redis://user:se%2Cc?ret@b:6379?dbindex=1'],
        ];
    }

    /**
     * @param list<string> $expectedHosts
     */
    #[Test]
    #[DataProvider('dsnsAndTheirHosts')]
    public function itSplitsWhereAnotherHostBegins(string $dsn, array $expectedHosts): void
    {
        self::assertSame($expectedHosts, RedisDsnHosts::of($dsn));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dsnsMessengerRefuses(): iterable
    {
        yield 'a raw comma in the password' => ['redis://user:secret,pass@valkey-state:6379', 'Write a comma in a password as %2C'];
        yield 'a raw comma in a query value' => ['redis://valkey-state:6379?ssl[ciphers]=a,b', 'Write a comma in a password as %2C'];
        yield 'a trailing comma' => ['redis://valkey-state:6379,', 'Write a comma in a password as %2C'];
        yield 'a host without a scheme' => ['redis://a:6379,b:6379', 'Write a comma in a password as %2C'];
        yield 'not a Redis or Valkey DSN' => ['in-memory://', 'not a Redis or Valkey DSN'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dsnsOnly(): iterable
    {
        foreach (self::dsnsMessengerRefuses() as $case => [$dsn]) {
            yield $case => [$dsn];
        }
    }

    #[Test]
    #[DataProvider('dsnsMessengerRefuses')]
    public function aDsnMessengerRefusesIsRefusedWithoutShowingIt(string $dsn, string $expectedMessage): void
    {
        try {
            RedisDsnHosts::of($dsn);
            self::fail('The DSN was accepted.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString($expectedMessage, $refusal->getMessage());
            self::assertStringNotContainsString('valkey-state', $refusal->getMessage());
        }
    }

    /**
     * Pins the reason for the rule: Messenger's Redis transport refuses these DSNs while parsing, before it connects.
     */
    #[Test]
    #[DataProvider('dsnsOnly')]
    public function messengersRedisTransportRefusesTheSameDsns(string $dsn): void
    {
        $this->expectException(MessengerInvalidArgumentException::class);

        Connection::fromDsn($dsn);
    }
}
