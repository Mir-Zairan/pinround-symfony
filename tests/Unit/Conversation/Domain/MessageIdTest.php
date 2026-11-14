<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\ReplyId;
use App\Notification\Domain\PingId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MessageId, ReplyId and PingId share one rule: a canonical UUIDv7, kept in lowercase.
 */
final class MessageIdTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(string): (MessageId|ReplyId|PingId), string}>
     */
    public static function idTypes(): iterable
    {
        yield 'message' => [static fn (string $value): MessageId => new MessageId($value), 'A message id must be a canonical UUIDv7.'];
        yield 'reply' => [static fn (string $value): ReplyId => new ReplyId($value), 'A reply id must be a canonical UUIDv7.'];
        yield 'ping' => [static fn (string $value): PingId => new PingId($value), 'A ping id must be a canonical UUIDv7.'];
    }

    /**
     * @param \Closure(string): (MessageId|ReplyId|PingId) $idOf
     */
    #[Test]
    #[DataProvider('idTypes')]
    public function anIdIsKeptInLowercaseAndEqualsItsOtherCase(\Closure $idOf, string $refusal): void
    {
        $id = $idOf('0197A3C0-1234-7ABC-BDEF-0123456789AB');

        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', $id->toString());
        self::assertTrue($id->equals($idOf('0197a3c0-1234-7abc-bdef-0123456789ab')));
        self::assertFalse($id->equals($idOf('0197a3c0-1234-7abc-bdef-0123456789ac')));
    }

    /**
     * @param \Closure(string): (MessageId|ReplyId|PingId) $idOf
     */
    #[Test]
    #[DataProvider('idTypes')]
    public function anIdThatIsNotACanonicalUuidV7IsRefused(\Closure $idOf, string $refusal): void
    {
        foreach (['', '0197a3c0-1234-4abc-8def-0123456789ab', '0197a3c0-1234-7abc-cdef-0123456789ab', "0197a3c0-1234-7abc-8def-0123456789ab\n"] as $value) {
            try {
                $idOf($value);
                self::fail(\sprintf('Not refused: %s', json_encode($value)));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($refusal, $exception->getMessage());
            }
        }
    }
}
