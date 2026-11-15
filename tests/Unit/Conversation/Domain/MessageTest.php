<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\MessageKind;
use App\Conversation\Domain\ReplyId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-000000004101';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004102';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004103';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004104';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004105';
    private const string REPLY = '0197a3c0-0000-7000-8000-000000004106';

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

    #[Test]
    public function theAskedPersonAnswersAnOpenQuestionAndResolvesIt(): void
    {
        $question = self::questionOfLenaToOmar();
        $answeredAt = new \DateTimeImmutable('2027-03-15 11:00:00+00:00');

        $question->answer(new UserId(self::OMAR), $answeredAt);

        self::assertSame($answeredAt, $question->resolvedAt());
        self::assertSame(self::OMAR, $question->resolvedBy()?->toString());
        self::assertNull($question->removedAt());
    }

    /**
     * @return iterable<string, array{\Closure(): Message, string, string}>
     */
    public static function refusedAnswers(): iterable
    {
        yield 'a comment' => [self::commentOfLena(...), self::OMAR, 'Only a question can be answered.'];
        yield 'a withdrawn question' => [static function (): Message {
            $question = self::questionOfLenaToOmar();
            $question->withdraw(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $question;
        }, self::OMAR, 'This question was withdrawn.'];
        yield 'an answered question' => [static function (): Message {
            $question = self::questionOfLenaToOmar();
            $question->answer(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $question;
        }, self::OMAR, 'This question is already answered.'];
        yield 'someone not asked, the asker included' => [self::questionOfLenaToOmar(...), self::LENA, 'Only the person asked can answer this question.'];
    }

    /**
     * @param \Closure(): Message $messageOf
     */
    #[Test]
    #[DataProvider('refusedAnswers')]
    public function aRefusedAnswerSaysWhyAndLeavesTheMessageAsItWas(\Closure $messageOf, string $answererId, string $reason): void
    {
        $message = $messageOf();
        $before = [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()];

        try {
            $message->answer(new UserId($answererId), new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
            self::fail('The answer was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }

        self::assertEquals($before, [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()]);
    }

    #[Test]
    public function theAskerWithdrawsTheirOpenQuestionAndItIsRemoved(): void
    {
        $question = self::questionOfLenaToOmar();
        $withdrawnAt = new \DateTimeImmutable('2027-03-15 11:00:00+00:00');

        $question->withdraw(new UserId(self::LENA), $withdrawnAt);

        self::assertSame($withdrawnAt, $question->removedAt());
        self::assertNull($question->resolvedAt());
    }

    /**
     * @return iterable<string, array{\Closure(): Message, string, string}>
     */
    public static function refusedWithdrawals(): iterable
    {
        yield 'a comment' => [self::commentOfLena(...), self::LENA, 'Only a question can be withdrawn.'];
        yield 'a withdrawn question' => [static function (): Message {
            $question = self::questionOfLenaToOmar();
            $question->withdraw(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $question;
        }, self::LENA, 'This question is already withdrawn.'];
        yield 'an answered question' => [static function (): Message {
            $question = self::questionOfLenaToOmar();
            $question->answer(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $question;
        }, self::LENA, 'An answered question cannot be withdrawn.'];
        yield 'the asked person' => [self::questionOfLenaToOmar(...), self::OMAR, 'Only the person who asked a question can withdraw it.'];
    }

    /**
     * @param \Closure(): Message $messageOf
     */
    #[Test]
    #[DataProvider('refusedWithdrawals')]
    public function aRefusedWithdrawalSaysWhyAndLeavesTheMessageAsItWas(\Closure $messageOf, string $withdrawerId, string $reason): void
    {
        $message = $messageOf();
        $before = [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()];

        try {
            $message->withdraw(new UserId($withdrawerId), new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
            self::fail('The withdrawal was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }

        self::assertEquals($before, [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()]);
    }

    /**
     * @return iterable<string, array{string, bool}> resolver, is the team's lead
     */
    public static function allowedResolvers(): iterable
    {
        yield 'the author' => [self::LENA, false];
        yield 'the team\'s lead, who did not write it' => [self::OMAR, true];
    }

    #[Test]
    #[DataProvider('allowedResolvers')]
    public function theAuthorOrTheTeamsLeadResolvesAComment(string $resolverId, bool $isTeamLead): void
    {
        $comment = self::commentOfLena();
        $resolvedAt = new \DateTimeImmutable('2027-03-15 11:00:00+00:00');

        $comment->resolve(new UserId($resolverId), $isTeamLead, $resolvedAt);

        self::assertSame([$resolvedAt, $resolverId], [$comment->resolvedAt(), $comment->resolvedBy()?->toString()]);
        self::assertNull($comment->removedAt());
    }

    /**
     * @return iterable<string, array{\Closure(): Message, string, bool, string}>
     */
    public static function refusedResolutions(): iterable
    {
        yield 'an open question, by its asker' => [self::questionOfLenaToOmar(...), self::LENA, false, 'A question is closed by its answer, not by Resolve.'];
        yield 'an open question, by the team\'s lead' => [self::questionOfLenaToOmar(...), self::OMAR, true, 'A question is closed by its answer, not by Resolve.'];
        yield 'a removed comment' => [static function (): Message {
            $comment = self::commentOfLena();
            $comment->remove(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $comment;
        }, self::LENA, false, 'This comment was removed, so it cannot be resolved.'];
        yield 'a resolved comment' => [static function (): Message {
            $comment = self::commentOfLena();
            $comment->resolve(new UserId(self::LENA), false, new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $comment;
        }, self::OMAR, true, 'This comment is already resolved.'];
        yield 'someone else who is not the team\'s lead' => [self::commentOfLena(...), self::OMAR, false, 'Only the person who wrote a comment or the lead of the ticket\'s team can resolve it.'];
    }

    /**
     * @param \Closure(): Message $messageOf
     */
    #[Test]
    #[DataProvider('refusedResolutions')]
    public function aRefusedResolutionSaysWhyAndLeavesTheMessageAsItWas(\Closure $messageOf, string $resolverId, bool $isTeamLead, string $reason): void
    {
        $message = $messageOf();
        $before = [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()];

        try {
            $message->resolve(new UserId($resolverId), $isTeamLead, new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
            self::fail('The resolution was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }

        self::assertEquals($before, [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()]);
    }

    /**
     * @return iterable<string, array{\Closure(): Message}>
     */
    public static function repliableMessages(): iterable
    {
        yield 'an open comment' => [self::commentOfLena(...)];
        yield 'a resolved comment' => [static function (): Message {
            $comment = self::commentOfLena();
            $comment->resolve(new UserId(self::LENA), false, new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $comment;
        }];
        yield 'an open question' => [self::questionOfLenaToOmar(...)];
        yield 'an answered question' => [static function (): Message {
            $question = self::questionOfLenaToOmar();
            $question->answer(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $question;
        }];
    }

    /**
     * @param \Closure(): Message $messageOf
     */
    #[Test]
    #[DataProvider('repliableMessages')]
    public function aReplyIsAPlainReplyUnderTheMessageAndLeavesTheMessageAsItWas(\Closure $messageOf): void
    {
        $message = $messageOf();
        $before = [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()];
        $postedAt = new \DateTimeImmutable('2027-03-15 11:00:00+00:00');

        $reply = $message->reply(new ReplyId(self::REPLY), new UserId(self::OMAR), 'Yes, they count.', $postedAt);

        self::assertSame(
            [self::REPLY, self::ACME_FOODS, self::ID, self::OMAR, 'Yes, they count.', false, $postedAt],
            [$reply->id()->toString(), $reply->organizationId()->toString(), $reply->messageId()->toString(), $reply->authorId()->toString(), $reply->body(), $reply->isAnswer(), $reply->createdAt()],
        );
        self::assertEquals($before, [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()]);
    }

    /**
     * @return iterable<string, array{\Closure(): Message}>
     */
    public static function removedMessages(): iterable
    {
        yield 'a removed comment' => [static function (): Message {
            $comment = self::commentOfLena();
            $comment->remove(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $comment;
        }];
        yield 'a withdrawn question' => [static function (): Message {
            $question = self::questionOfLenaToOmar();
            $question->withdraw(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:30:00+00:00'));

            return $question;
        }];
    }

    /**
     * @param \Closure(): Message $messageOf
     */
    #[Test]
    #[DataProvider('removedMessages')]
    public function aReplyToARemovedMessageIsRefusedAndTheMessageStays(\Closure $messageOf): void
    {
        $message = $messageOf();
        $before = [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()];

        try {
            $message->reply(new ReplyId(self::REPLY), new UserId(self::OMAR), 'Yes, they count.', new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
            self::fail('The reply was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame('This message was removed, so you cannot reply to it.', $refusal->getMessage());
        }

        self::assertEquals($before, [$message->resolvedAt(), $message->resolvedBy(), $message->removedAt()]);
    }

    #[Test]
    public function aReplyWithABlankBodyIsRefusedAsAnInvalidBody(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A reply needs a body.'));

        self::commentOfLena()->reply(new ReplyId(self::REPLY), new UserId(self::OMAR), '   ', new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
    }

    private static function questionOfLenaToOmar(): Message
    {
        return Message::question(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Should cancelled orders count?', new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    private static function commentOfLena(): Message
    {
        return Message::comment(new MessageId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), 'The button moved.', new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }
}
