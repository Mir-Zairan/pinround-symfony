<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\Event\ReplyPosted;
use App\Notification\Application\Notify\NotifyRecipients;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * NotifyRecipients from the container on the real ledger, Ticketing and Organization reads and ntf_notification, on
 * the visibility cast's team-only SHOP ticket. Each case runs in one rolled-back transaction under Acme Foods, as
 * worker-notify handles a message under its organization, so neither notifications nor processed_message rows stay.
 */
final class NotifyRecipientsTest extends VisibilityLeakCast
{
    private const string MESSAGE = '0197a3c0-0000-7000-8000-0000000058f1';
    private const string REPLY = '0197a3c0-0000-7000-8000-0000000058f2';
    private const string SECOND_REPLY = '0197a3c0-0000-7000-8000-0000000058f3';
    private const string ASKED_AT = '2027-03-15 10:00:00 UTC';

    #[Test]
    public function aRedeliveredQuestionStillWritesOneNotificationWithTheSnapshotTheBellReads(): void
    {
        $rows = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::addOpenQuestion(self::teamOnlyTicketId(), self::MESSAGE, 'Lena', 'Raj', self::ASKED_AT);
            $question = $this->questionTo('Lena');
            $messageId = Uuid::v7()->toRfc4122();
            self::handle($question, $messageId);
            self::handle($question, $messageId);

            return $this->notificationsOf('Lena');
        });

        self::assertCount(1, $rows);
        self::assertSame(['question', self::VIEWERS['Raj'][0]], [$rows[0]['kind'], $rows[0]['actor_id']]);
        self::assertEquals(['actorName' => 'Raj', 'ticketKey' => $this->teamOnlyTicketKey(), 'messageId' => self::MESSAGE], $rows[0]['data']);
        self::assertNull($rows[0]['deliver_after']);
    }

    #[Test]
    public function aRecipientWhoMutedTheTicketGetsNoNotificationAndAnotherStillDoes(): void
    {
        $countsAfterAQuestionTo = fn (string $viewer): array => self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($viewer): array {
            self::connection()->executeStatement(
                "INSERT INTO ntf_mute (organization_id, user_id, ticket_id, created_at) VALUES (?, ?, ?, '2027-03-14 09:00:00+00')",
                [self::ACME_FOODS, self::VIEWERS['Lena'][0], self::teamOnlyTicketId()],
            );
            self::addOpenQuestion(self::teamOnlyTicketId(), self::MESSAGE, $viewer, 'Raj', self::ASKED_AT);
            self::handle($this->questionTo($viewer), Uuid::v7()->toRfc4122());

            return ['Lena' => \count($this->notificationsOf('Lena')), 'Mia' => \count($this->notificationsOf('Mia'))];
        });

        self::assertSame(['Lena' => 0, 'Mia' => 0], $countsAfterAQuestionTo('Lena'));
        self::assertSame(['Lena' => 0, 'Mia' => 1], $countsAfterAQuestionTo('Mia'));
    }

    #[Test]
    #[DataProvider('closedQuestions')]
    public function aQuestionClosedBeforeItsEventIsHandledWritesNoNotification(string $closingColumns): void
    {
        [$rows, $processedCount] = self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($closingColumns): array {
            self::addOpenQuestion(self::teamOnlyTicketId(), self::MESSAGE, 'Lena', 'Raj', self::ASKED_AT);
            self::assertSame(1, self::connection()->executeStatement(
                'UPDATE cnv_message SET '.$closingColumns.' WHERE organization_id = ? AND id = ?',
                [self::ACME_FOODS, self::MESSAGE],
            ));
            $messageId = Uuid::v7()->toRfc4122();
            self::handle($this->questionTo('Lena'), $messageId);
            $processedCount = self::connection()->fetchOne(
                'SELECT count(*) FROM processed_message WHERE handler = ? AND message_id = ?',
                [NotifyRecipients::HANDLER_NAME, $messageId],
            );

            return [$this->notificationsOf('Lena'), $processedCount];
        });

        self::assertSame([], $rows);
        self::assertSame(1, $processedCount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closedQuestions(): iterable
    {
        yield 'withdrawn' => ["removed_at = '2027-03-15 10:05:00+00'"];
        yield 'answered' => ["resolved_at = '2027-03-15 10:05:00+00', resolved_by = '".self::VIEWERS['Lena'][0]."'"];
    }

    #[Test]
    public function aReplyAt120SecondsFoldsIntoTheUnreadOneAndAt121SecondsAddsASecond(): void
    {
        $actorsAt120 = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->replyActorsAfterASecondReplyAt('+120 seconds'));
        $actorsAt121 = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->replyActorsAfterASecondReplyAt('+121 seconds'));

        self::assertSame([self::VIEWERS['Sara'][0]], $actorsAt120);
        self::assertSame([self::VIEWERS['Raj'][0], self::VIEWERS['Sara'][0]], $actorsAt121);
    }

    #[Test]
    public function aReplyToACommentRemovedBeforeItsEventIsHandledWritesNoNotificationWhileOneToAPresentCommentStillDoes(): void
    {
        [$removedRows, $removedProcessedCount] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::addComment(self::teamOnlyTicketId(), self::MESSAGE, 'Lena', self::ASKED_AT);
            self::assertSame(1, self::connection()->executeStatement(
                "UPDATE cnv_message SET removed_at = '2027-03-15 10:05:00+00' WHERE organization_id = ? AND id = ?",
                [self::ACME_FOODS, self::MESSAGE],
            ));
            $messageId = Uuid::v7()->toRfc4122();
            self::handle($this->replyToLenasComment(), $messageId);
            $processedCount = self::connection()->fetchOne(
                'SELECT count(*) FROM processed_message WHERE handler = ? AND message_id = ?',
                [NotifyRecipients::HANDLER_NAME, $messageId],
            );

            return [$this->notificationsOf('Lena'), $processedCount];
        });
        $presentRows = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::addComment(self::teamOnlyTicketId(), self::MESSAGE, 'Lena', self::ASKED_AT);
            self::handle($this->replyToLenasComment(), Uuid::v7()->toRfc4122());

            return $this->notificationsOf('Lena');
        });

        self::assertSame([], $removedRows);
        self::assertSame(1, $removedProcessedCount);
        self::assertSame([['reply', self::MESSAGE, self::REPLY]], array_map(static fn (array $row): array => [$row['kind'], $row['data']['messageId'] ?? null, $row['data']['replyId'] ?? null], $presentRows));
    }

    private function replyToLenasComment(): ReplyPosted
    {
        return new ReplyPosted(self::ACME_FOODS, self::teamOnlyTicketId(), self::MESSAGE, self::REPLY, self::VIEWERS['Lena'][0], self::VIEWERS['Raj'][0], new \DateTimeImmutable('2027-03-15 10:10:00 UTC'));
    }

    /**
     * @return list<mixed> the actor of each of Lena's reply notifications, oldest first
     */
    private function replyActorsAfterASecondReplyAt(string $offset): array
    {
        self::addComment(self::teamOnlyTicketId(), self::MESSAGE, 'Lena', self::ASKED_AT);
        $first = new \DateTimeImmutable(self::ASKED_AT);
        self::handle(new ReplyPosted(self::ACME_FOODS, self::teamOnlyTicketId(), self::MESSAGE, self::REPLY, self::VIEWERS['Lena'][0], self::VIEWERS['Raj'][0], $first), Uuid::v7()->toRfc4122());
        self::handle(new ReplyPosted(self::ACME_FOODS, self::teamOnlyTicketId(), self::MESSAGE, self::SECOND_REPLY, self::VIEWERS['Lena'][0], self::VIEWERS['Sara'][0], $first->modify($offset)), Uuid::v7()->toRfc4122());

        return array_column($this->notificationsOf('Lena'), 'actor_id');
    }

    private function questionTo(string $viewer): QuestionAsked
    {
        return new QuestionAsked(self::ACME_FOODS, self::teamOnlyTicketId(), self::MESSAGE, self::VIEWERS[$viewer][0], self::VIEWERS['Raj'][0], new \DateTimeImmutable(self::ASKED_AT));
    }

    private static function handle(QuestionAsked|ReplyPosted $event, string $messageId): void
    {
        $messageBeingHandled = self::getContainer()->get(MessageBeingHandled::class);
        $messageBeingHandled->set($messageId);
        try {
            self::getContainer()->get(NotifyRecipients::class)($event);
        } finally {
            $messageBeingHandled->reset();
        }
    }

    /**
     * @return list<array{kind: mixed, actor_id: mixed, data: array<mixed>, deliver_after: mixed}>
     */
    private function notificationsOf(string $viewer): array
    {
        $rows = self::connection()->fetchAllAssociative(
            'SELECT kind, actor_id, data::text AS data, deliver_after FROM ntf_notification WHERE organization_id = ? AND user_id = ? ORDER BY created_at, id',
            [self::ACME_FOODS, self::VIEWERS[$viewer][0]],
        );

        return array_map(static function (array $row): array {
            self::assertIsString($row['data']);
            $data = json_decode($row['data'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return ['kind' => $row['kind'], 'actor_id' => $row['actor_id'], 'data' => $data, 'deliver_after' => $row['deliver_after']];
        }, $rows);
    }

    private function teamOnlyTicketKey(): string
    {
        $key = self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): mixed => self::connection()->fetchOne(
            'SELECT key FROM tkt_ticket WHERE organization_id = ? AND id = ?',
            [self::ACME_FOODS, self::teamOnlyTicketId()],
        ));
        self::assertIsString($key);
        self::assertMatchesRegularExpression('/^SHOP-\d+\z/', $key);

        return $key;
    }
}
