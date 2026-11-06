<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Infrastructure\Messenger\MessageIdStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class MessageIdStampTest extends TestCase
{
    #[Test]
    public function aGeneratedIdIsACanonicalUuidV7OfTheCurrentMillisecondAndNewEachTime(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $first = MessageIdStamp::generate()->messageId();
        $second = MessageIdStamp::generate()->messageId();
        $after = (int) floor(microtime(true) * 1000);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $first);
        self::assertNotSame($first, $second);
        $unixTimeMs = (int) hexdec(str_replace('-', '', substr($first, 0, 13)));
        self::assertGreaterThanOrEqual($before, $unixTimeMs);
        self::assertLessThanOrEqual($after, $unixTimeMs);
    }

    #[Test]
    public function aStoredIdSurvivesASerializationRoundTrip(): void
    {
        $stamp = MessageIdStamp::generate();

        $restored = unserialize(serialize($stamp));

        self::assertInstanceOf(MessageIdStamp::class, $restored);
        self::assertSame($stamp->messageId(), $restored->messageId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsThatAreNoCanonicalUuidV7(): iterable
    {
        yield 'empty' => [''];
        yield 'version 4' => ['0197a3c0-0000-4000-8000-000000000301'];
        yield 'uppercase' => ['0197A3C0-0000-7000-8000-000000000301'];
        yield 'trailing newline' => ["0197a3c0-0000-7000-8000-000000000301\n"];
        yield 'wrong variant' => ['0197a3c0-0000-7000-c000-000000000301'];
        yield 'base58 of a valid UUIDv7' => [new UuidV7('0197a3c0-0000-7000-8000-000000000301')->toBase58()];
        yield 'base32 of a valid UUIDv7' => [new UuidV7('0197a3c0-0000-7000-8000-000000000301')->toBase32()];
    }

    #[Test]
    #[DataProvider('idsThatAreNoCanonicalUuidV7')]
    public function anIdThatIsNoCanonicalUuidV7IsRefused(string $messageId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MessageIdStamp($messageId);
    }
}
