<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\MessageKind;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-000000004101';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004102';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004103';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004104';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004105';

    #[Test]
    public function aCommentAsksNobodyAndIsOpenToNothing(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:00:00+00:00');

        $comment = Message::comment(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), 'The button moved.', $createdAt);

        self::assertSame(self::ID, $comment->id()->toString());
        self::assertSame(self::ACME_FOODS, $comment->organizationId()->toString());
        self::assertSame(self::TICKET, $comment->ticketId()->toString());
        self::assertSame(self::LENA, $comment->authorId()->toString());
        self::assertSame(MessageKind::Comment, $comment->kind());
        self::assertNull($comment->toUserId());
        self::assertSame('The button moved.', $comment->body());
        self::assertSame($createdAt, $comment->createdAt());
        self::assertNull($comment->resolvedAt());
        self::assertNull($comment->resolvedBy());
        self::assertNull($comment->editedAt());
        self::assertNull($comment->removedAt());
    }

    #[Test]
    public function aQuestionNamesThePersonAsked(): void
    {
        $question = Message::question(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which build?', new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));

        self::assertSame(MessageKind::Question, $question->kind());
        self::assertSame(self::OMAR, $question->toUserId()?->toString());
        self::assertNull($question->resolvedAt());
    }

    #[Test]
    public function aBlankBodyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A message needs a body.');

        Message::comment(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), " \n\t", new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }
}
