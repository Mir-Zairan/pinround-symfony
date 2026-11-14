<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\Event\ReplyPosted;
use App\Notification\Application\Notify\NotifyRecipients;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
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
        $counts = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::connection()->executeStatement(
                "INSERT INTO ntf_mute (organization_id, user_id, ticket_id, created_at) VALUES (?, ?, ?, '2027-03-14 09:00:00+00')",
                [self::ACME_FOODS, self::VIEWERS['Lena'][0], self::teamOnlyTicketId()],
            );
            self::handle($this->questionTo('Lena'), Uuid::v7()->toRfc4122());
            self::handle($this->questionTo('Mia'), Uuid::v7()->toRfc4122());

            return ['Lena' => \count($this->notificationsOf('Lena')), 'Mia' => \count($this->notificationsOf('Mia'))];
        });

        self::assertSame(['Lena' => 0, 'Mia' => 1], $counts);
    }

    #[Test]
    public function aReplyAt120SecondsFoldsIntoTheUnreadOneAndAt121SecondsAddsASecond(): void
    {
        $actorsAt120 = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->replyActorsAfterASecondReplyAt('+120 seconds'));
        $actorsAt121 = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->replyActorsAfterASecondReplyAt('+121 seconds'));

        self::assertSame([self::VIEWERS['Sara'][0]], $actorsAt120);
        self::assertSame([self::VIEWERS['Raj'][0], self::VIEWERS['Sara'][0]], $actorsAt121);
    }

    /**
     * @return list<mixed> the actor of each of Lena's reply notifications, oldest first
     */
    private function replyActorsAfterASecondReplyAt(string $offset): array
    {
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
