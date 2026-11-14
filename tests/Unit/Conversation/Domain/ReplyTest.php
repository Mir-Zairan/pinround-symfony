<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\Reply;
use App\Conversation\Domain\ReplyId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplyTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-000000004111';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004112';
    private const string MESSAGE = '0197a3c0-0000-7000-8000-000000004113';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004114';

    #[Test]
    public function aReplyKeepsItsMessageAuthorAndWhetherItAnswers(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:05:00+00:00');

        $reply = Reply::post(new ReplyId(self::ID), new OrganizationId(self::ACME_FOODS), new MessageId(self::MESSAGE), new UserId(self::OMAR), 'Build 42.', true, $createdAt);

        self::assertSame(self::ID, $reply->id()->toString());
        self::assertSame(self::ACME_FOODS, $reply->organizationId()->toString());
        self::assertSame(self::MESSAGE, $reply->messageId()->toString());
        self::assertSame(self::OMAR, $reply->authorId()->toString());
        self::assertSame('Build 42.', $reply->body());
        self::assertTrue($reply->isAnswer());
        self::assertSame($createdAt, $reply->createdAt());
        self::assertNull($reply->editedAt());
        self::assertNull($reply->removedAt());
    }

    #[Test]
    public function aBlankBodyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A reply needs a body.');

        Reply::post(new ReplyId(self::ID), new OrganizationId(self::ACME_FOODS), new MessageId(self::MESSAGE), new UserId(self::OMAR), '   ', false, new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
    }

    /**
     * Counted in characters as cnv_reply_body_check's char_length counts them, not in bytes: each é is two bytes.
     */
    #[Test]
    public function aBodyOfTenThousandCharactersIsKept(): void
    {
        $body = str_repeat('é', 10000);

        $reply = Reply::post(new ReplyId(self::ID), new OrganizationId(self::ACME_FOODS), new MessageId(self::MESSAGE), new UserId(self::OMAR), $body, false, new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));

        self::assertSame($body, $reply->body());
    }

    #[Test]
    public function aBodyOfOneCharacterMoreIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A reply can have at most 10000 characters.');

        Reply::post(new ReplyId(self::ID), new OrganizationId(self::ACME_FOODS), new MessageId(self::MESSAGE), new UserId(self::OMAR), str_repeat('é', 10001), true, new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
    }
}
