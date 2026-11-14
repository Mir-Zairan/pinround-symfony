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

    /**
     * Counted in characters as cnv_message_body_check's char_length counts them, not in bytes: each é is two bytes.
     */
    #[Test]
    public function aBodyOfTenThousandCharactersIsKept(): void
    {
        $body = str_repeat('é', 10000);

        $question = Message::question(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), $body, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));

        self::assertSame($body, $question->body());
    }

    #[Test]
    public function aCommentOfOneCharacterMoreIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A message can have at most 10000 characters.');

        Message::comment(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), str_repeat('é', 10001), new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    #[Test]
    public function aQuestionOfOneCharacterMoreIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A message can have at most 10000 characters.');

        Message::question(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), str_repeat('a', 10001), new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    #[Test]
    public function theAuthorEditsACommentWithinFifteenMinutes(): void
    {
        $comment = self::commentOfLena();
        $editedAt = new \DateTimeImmutable('2027-03-15 10:14:59+00:00');

        $comment->edit(new UserId(self::LENA), 'The button moved left.', $editedAt);

        self::assertSame('The button moved left.', $comment->body());
        self::assertSame($editedAt, $comment->editedAt());
    }

    #[Test]
    public function theAuthorEditsACommentAtExactlyFifteenMinutes(): void
    {
        $comment = self::commentOfLena();

        $comment->edit(new UserId(self::LENA), 'The button moved left.', new \DateTimeImmutable('2027-03-15 10:15:00+00:00'));

        self::assertSame('The button moved left.', $comment->body());
    }

    #[Test]
    public function anEditAfterFifteenMinutesIsRefusedAndTheBodyStays(): void
    {
        $comment = self::commentOfLena();

        try {
            $comment->edit(new UserId(self::LENA), 'The button moved left.', new \DateTimeImmutable('2027-03-15 10:15:01+00:00'));
            self::fail('An edit after 15 minutes was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame('You can edit a message for 15 minutes after you post it.', $refusal->getMessage());
        }

        self::assertSame('The button moved.', $comment->body());
        self::assertNull($comment->editedAt());
    }

    #[Test]
    public function anEditBySomeoneElseIsRefusedAndTheBodyStays(): void
    {
        $comment = self::commentOfLena();

        try {
            $comment->edit(new UserId(self::OMAR), 'Not my words.', new \DateTimeImmutable('2027-03-15 10:01:00+00:00'));
            self::fail('An edit by someone else was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame('Only the person who wrote a message can edit it.', $refusal->getMessage());
        }

        self::assertSame('The button moved.', $comment->body());
        self::assertNull($comment->editedAt());
    }

    #[Test]
    public function anEditOfARemovedCommentIsRefused(): void
    {
        $comment = self::commentOfLena();
        $comment->remove(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:01:00+00:00'));

        try {
            $comment->edit(new UserId(self::LENA), 'Back again.', new \DateTimeImmutable('2027-03-15 10:02:00+00:00'));
            self::fail('An edit of a removed comment was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame('This message was removed, so it cannot be edited.', $refusal->getMessage());
        }

        self::assertSame('The button moved.', $comment->body());
        self::assertNull($comment->editedAt());
    }

    #[Test]
    public function anEditToABlankBodyIsRefusedAndTheBodyStays(): void
    {
        $comment = self::commentOfLena();

        try {
            $comment->edit(new UserId(self::LENA), " \r\n", new \DateTimeImmutable('2027-03-15 10:01:00+00:00'));
            self::fail('A blank edit was not refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A message needs a body.', $refusal->getMessage());
        }

        self::assertSame('The button moved.', $comment->body());
        self::assertNull($comment->editedAt());
    }

    #[Test]
    public function anEditOfOneCharacterTooManyIsRefusedAndTheBodyStays(): void
    {
        $comment = self::commentOfLena();

        try {
            $comment->edit(new UserId(self::LENA), str_repeat('é', 10001), new \DateTimeImmutable('2027-03-15 10:01:00+00:00'));
            self::fail('An edit of 10,001 characters was not refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A message can have at most 10000 characters.', $refusal->getMessage());
        }

        self::assertSame('The button moved.', $comment->body());
    }

    #[Test]
    public function theAuthorRemovesACommentLongAfterPostingIt(): void
    {
        $comment = self::commentOfLena();
        $removedAt = new \DateTimeImmutable('2027-04-20 08:00:00+00:00');

        $comment->remove(new UserId(self::LENA), $removedAt);

        self::assertSame($removedAt, $comment->removedAt());
    }

    #[Test]
    public function aRemovalBySomeoneElseIsRefusedAndTheCommentStays(): void
    {
        $comment = self::commentOfLena();

        try {
            $comment->remove(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:01:00+00:00'));
            self::fail('A removal by someone else was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame('Only the person who wrote a message can remove it.', $refusal->getMessage());
        }

        self::assertNull($comment->removedAt());
    }

    #[Test]
    public function aSecondRemovalIsRefusedAndKeepsTheFirstTime(): void
    {
        $comment = self::commentOfLena();
        $firstRemovedAt = new \DateTimeImmutable('2027-03-15 10:01:00+00:00');
        $comment->remove(new UserId(self::LENA), $firstRemovedAt);

        try {
            $comment->remove(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:02:00+00:00'));
            self::fail('A second removal was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame('This message is already removed.', $refusal->getMessage());
        }

        self::assertSame($firstRemovedAt, $comment->removedAt());
    }

    private static function commentOfLena(): Message
    {
        return Message::comment(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), 'The button moved.', new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }
}
