<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\MessageKind;
use App\Conversation\Domain\Reply;
use App\Conversation\Domain\ReplyId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Message and Reply saved through Doctrine and found again in a cleared EntityManager, so every field comes back from
 * cnv_message and cnv_reply, not from the identity map. The fields no domain method sets yet are written as SQL.
 */
final class ConversationMappingTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004201';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000004202';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004203';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004204';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004205';
    private const string MIA = '0197a3c0-0000-7000-8000-000000004206';
    private const string COMMENT = '0197a3c0-0000-7000-8000-000000004207';
    private const string QUESTION = '0197a3c0-0000-7000-8000-000000004208';
    private const string ANSWER = '0197a3c0-0000-7000-8000-000000004209';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function aCommentComesBackWithEveryField(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist(Message::comment(new MessageId(self::COMMENT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), 'The button moves on small screens. ✓', new \DateTimeImmutable('2027-03-15 10:00:00+00:00')));
            $this->entityManager()->flush();
        });
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE cnv_message SET resolved_at = '2027-03-16 09:30:00+00', resolved_by = ?, edited_at = '2027-03-15 10:20:00+00', removed_at = '2027-03-17 08:00:00+00' WHERE id = ?",
            [self::MIA, self::COMMENT],
        ));

        $comment = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?Message => $this->findMessage(self::COMMENT));

        self::assertNotNull($comment);
        self::assertSame(
            [self::COMMENT, self::ACME_FOODS, self::TICKET, self::LENA, MessageKind::Comment, null, 'The button moves on small screens. ✓', self::MIA],
            [$comment->id()->toString(), $comment->organizationId()->toString(), $comment->ticketId()->toString(), $comment->authorId()->toString(), $comment->kind(), $comment->toUserId(), $comment->body(), $comment->resolvedBy()?->toString()],
        );
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:00:00+00:00'), $comment->createdAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-16 09:30:00+00:00'), $comment->resolvedAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:20:00+00:00'), $comment->editedAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-17 08:00:00+00:00'), $comment->removedAt());
    }

    #[Test]
    public function anOpenQuestionComesBackWithThePersonAskedAndNothingElseSet(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist($this->question());
            $this->entityManager()->flush();
        });

        $question = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?Message => $this->findMessage(self::QUESTION));

        self::assertNotNull($question);
        self::assertSame(
            [self::QUESTION, self::ACME_FOODS, self::TICKET, self::LENA, MessageKind::Question, self::OMAR, 'Which build shows it?', null, null, null, null],
            [$question->id()->toString(), $question->organizationId()->toString(), $question->ticketId()->toString(), $question->authorId()->toString(), $question->kind(), $question->toUserId()?->toString(), $question->body(), $question->resolvedAt(), $question->resolvedBy(), $question->editedAt(), $question->removedAt()],
        );
        self::assertEquals(new \DateTimeImmutable('2027-03-15 11:00:00+00:00'), $question->createdAt());
    }

    #[Test]
    public function aReplyComesBackWithEveryField(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist($this->question());
            $this->entityManager()->persist(Reply::post(new ReplyId(self::ANSWER), new OrganizationId(self::ACME_FOODS), new MessageId(self::QUESTION), new UserId(self::OMAR), 'Build 42, on a phone.', true, new \DateTimeImmutable('2027-03-15 11:15:00+00:00')));
            $this->entityManager()->flush();
        });
        $unchangedReply = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?Reply => $this->findReply());
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE cnv_reply SET edited_at = '2027-03-15 11:20:00+00', removed_at = '2027-03-15 12:00:00+00' WHERE id = ?",
            [self::ANSWER],
        ));

        $reply = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?Reply => $this->findReply());

        self::assertNotNull($unchangedReply);
        self::assertSame([null, null], [$unchangedReply->editedAt(), $unchangedReply->removedAt()]);
        self::assertNotNull($reply);
        self::assertSame(
            [self::ANSWER, self::ACME_FOODS, self::QUESTION, self::OMAR, 'Build 42, on a phone.', true],
            [$reply->id()->toString(), $reply->organizationId()->toString(), $reply->messageId()->toString(), $reply->authorId()->toString(), $reply->body(), $reply->isAnswer()],
        );
        self::assertEquals(new \DateTimeImmutable('2027-03-15 11:15:00+00:00'), $reply->createdAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-15 11:20:00+00:00'), $reply->editedAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-15 12:00:00+00:00'), $reply->removedAt());
    }

    #[Test]
    public function savingAFoundMessageAndReplyAgainKeepsOneRowEach(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $question = $this->question();
            $reply = Reply::post(new ReplyId(self::ANSWER), new OrganizationId(self::ACME_FOODS), new MessageId(self::QUESTION), new UserId(self::OMAR), 'Build 42.', true, new \DateTimeImmutable('2027-03-15 11:15:00+00:00'));
            $this->entityManager()->persist($question);
            $this->entityManager()->persist($reply);
            $this->entityManager()->flush();
            $this->entityManager()->persist($question);
            $this->entityManager()->persist($reply);
            $this->entityManager()->flush();
        });

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist($this->findMessage(self::QUESTION) ?? throw new \LogicException('The question was not saved.'));
            $this->entityManager()->persist($this->findReply() ?? throw new \LogicException('The reply was not saved.'));
            $this->entityManager()->flush();
        });

        self::assertSame(
            [1, 1],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->connection()->fetchOne('SELECT count(*) FROM cnv_message WHERE ticket_id = ?', [self::TICKET]),
                $this->connection()->fetchOne('SELECT count(*) FROM cnv_reply WHERE message_id = ?', [self::QUESTION]),
            ]),
        );
    }

    private function question(): Message
    {
        return Message::question(new MessageId(self::QUESTION), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which build shows it?', new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
    }

    private function findMessage(string $messageId): ?Message
    {
        return $this->entityManager()->find(Message::class, ['organizationId' => self::ACME_FOODS, 'id' => $messageId]);
    }

    private function findReply(): ?Reply
    {
        return $this->entityManager()->find(Reply::class, ['organizationId' => self::ACME_FOODS, 'id' => self::ANSWER]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
