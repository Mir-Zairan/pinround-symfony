<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAnswered;
use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\Event\ReplyPosted;
use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason;
use App\Notification\Api\Event\PingSent;
use App\Notification\Application\Notify\NotifyRecipients;
use App\Notification\Application\Realtime\RefreshBell;
use App\SharedKernel\Infrastructure\Messenger\MessageIdStamp;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
use App\Ticketing\Api\Event\TicketAssigned;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * NotifyRecipients wired to event.bus, one case per tag in config/services.yaml: each event committed under Acme Foods
 * goes through the outbox, the real relay puts it on async_notify, and its delivery from there, twice, writes one
 * ntf_notification row. The deliveries are dispatched on event.bus as the worker would, not run through the worker: on
 * async_notify the worker would also run the RefreshTicketPages that QueueTicketPagesRefreshHandler queues for each
 * of these events, which publishes to the hub. That handler also keeps a missing tag from throwing, so only the
 * missing row shows it. Each row also sends one RefreshBell to async_notify, held by its stamp until the delivery's
 * event.bus transaction commits, and none for a retried delivery or a ping held past the frozen clock; a cleared ping
 * writes no row and refreshes the bell through PushBellOnPingCleared, and an answer marks the question row done
 * through MarkMessageNotificationsDone, which refreshes its recipient's bell once. NotifyRecipients reads each ping's
 * ntf_ping row for its delivery time, writes a question row only while its cnv_message row is open and a reply row
 * only while the message replied to is not removed, so those cases store them first. Everything commits, so tearDown removes the rows.
 */
final class NotifyRecipientsEventBusTest extends VisibilityLeakCast
{
    use RunsTheWorkerUntilIdle;

    private const string QUESTION = '0197a3c0-0000-7000-8000-0000000058c1';
    private const string ASKED_AT = '2027-03-15 10:00:00 UTC';
    private const string ANSWERED_AT = '2027-03-15 11:00:00 UTC';
    private const string PING = '0197a3c0-0000-7000-8000-0000000058d1';
    private const string PINGED_AT = '2027-03-16 09:00:00 UTC';
    private const string COMMENT = '0197a3c0-0000-7000-8000-0000000058c2';
    private const string REPLY = '0197a3c0-0000-7000-8000-0000000058e1';
    private const string REPLIED_AT = '2027-03-16 10:00:00 UTC';
    private const string ASSIGNED_AT = '2027-03-16 11:00:00 UTC';
    private const string NOW = '2027-03-17 12:00:00 UTC';
    private const string HELD_PING = '0197a3c0-0000-7000-8000-0000000058d2';
    private const string HELD_PING_DELIVERED_AT = '2027-03-18 07:00:00 UTC';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->removeCommittedRows();
    }

    protected function tearDown(): void
    {
        $this->removeCommittedRows();
        parent::tearDown();
    }

    #[Test]
    public function aQuestionAndItsAnswerEachDeliveredTwiceOnTheEventBusNotifyOnce(): void
    {
        $lena = self::VIEWERS['Lena'][0];
        $mia = self::VIEWERS['Mia'][0];
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($lena, $mia): void {
            self::addOpenQuestion(self::teamOnlyTicketId(), self::QUESTION, 'Mia', 'Lena', self::ASKED_AT);
            $eventBus = self::getContainer()->get('event.bus');
            $eventBus->dispatch(new QuestionAsked(self::ACME_FOODS, self::teamOnlyTicketId(), self::QUESTION, $mia, $lena, new \DateTimeImmutable(self::ASKED_AT)));
            $eventBus->dispatch(new QuestionAnswered(self::ACME_FOODS, self::teamOnlyTicketId(), self::QUESTION, $lena, $mia, new \DateTimeImmutable(self::ANSWERED_AT)));
        });
        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        // In any order: the relay sends the events of one second in no set order (data-model.md, messenger_outbox).
        self::assertEqualsCanonicalizing([QuestionAsked::class, QuestionAnswered::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed));
        // Asked first, as the question was: the answer marks done the question row that delivery writes.
        usort($relayed, static fn (Envelope $one, Envelope $other): int => ($one->getMessage() instanceof QuestionAnswered) <=> ($other->getMessage() instanceof QuestionAnswered));

        foreach ($relayed as $envelope) {
            self::deliverFromAsyncNotify($envelope);
            self::deliverFromAsyncNotify($envelope);
        }

        self::assertSame([
            ['question', $mia, $lena, null, self::QUESTION, null],
            ['answer', $lena, $mia, null, self::QUESTION, null],
        ], $this->acmeNotifications());
        $messageIds = array_map(self::messageIdOf(...), $relayed);
        self::assertCount(2, array_unique($messageIds));
        self::assertSame(2, $this->processedRowCount($messageIds));
        self::assertEqualsCanonicalizing([
            [self::ACME_FOODS, $mia, null],
            [self::ACME_FOODS, $lena, 'Mia answered your question.'],
            [self::ACME_FOODS, $mia, null],
        ], $this->sentBellRefreshes());
        self::assertSame([['question', true], ['answer', false]], $this->acmeNotificationsDone());
    }

    #[Test]
    public function aPingAReplyAndAnAssignmentEachDeliveredTwiceOnTheEventBusNotifyOnce(): void
    {
        $lena = self::VIEWERS['Lena'][0];
        $mia = self::VIEWERS['Mia'][0];
        $sara = self::VIEWERS['Sara'][0];
        self::addCommittedPing(self::PING, $mia, $lena, null, self::PINGED_AT);
        self::addCommittedPing(self::HELD_PING, $lena, $sara, self::HELD_PING_DELIVERED_AT, self::NOW);
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($lena, $mia, $sara): void {
            self::addComment(self::teamOnlyTicketId(), self::COMMENT, 'Mia', self::PINGED_AT);
            $eventBus = self::getContainer()->get('event.bus');
            $eventBus->dispatch(new PingSent(self::ACME_FOODS, self::teamOnlyTicketId(), self::PING, $mia, $lena, $mia, new \DateTimeImmutable(self::PINGED_AT)));
            $eventBus->dispatch(new ReplyPosted(self::ACME_FOODS, self::teamOnlyTicketId(), self::COMMENT, self::REPLY, $mia, $lena, new \DateTimeImmutable(self::REPLIED_AT)));
            $eventBus->dispatch(new TicketAssigned(self::ACME_FOODS, self::teamOnlyTicketId(), 'SHOP-1', null, $lena, $mia, new \DateTimeImmutable(self::ASSIGNED_AT)));
            $eventBus->dispatch(new PingSent(self::ACME_FOODS, self::teamOnlyTicketId(), self::HELD_PING, $lena, $sara, $lena, new \DateTimeImmutable(self::HELD_PING_DELIVERED_AT)));
        });
        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        // In any order: the relay sends the events of one second in no set order (data-model.md, messenger_outbox).
        self::assertEqualsCanonicalizing([PingSent::class, ReplyPosted::class, TicketAssigned::class, PingSent::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed));

        foreach ($relayed as $envelope) {
            self::deliverFromAsyncNotify($envelope);
            self::deliverFromAsyncNotify($envelope);
        }

        self::assertSame([
            ['ping', $lena, $mia, self::PING, null, null],
            ['reply', $mia, $lena, null, self::COMMENT, self::REPLY],
            ['assigned', $lena, $mia, null, null, null],
            ['ping', $sara, $lena, self::HELD_PING, null, null],
        ], $this->acmeNotifications());
        self::assertSame([[self::PING, null], [self::HELD_PING, '2027-03-18 07:00:00+00']], $this->pingRowsDeliverAfter());
        $messageIds = array_values(array_map(self::messageIdOf(...), $relayed));
        self::assertCount(4, array_unique($messageIds));
        self::assertSame(4, $this->processedRowCount($messageIds));
        self::assertEqualsCanonicalizing([
            [self::ACME_FOODS, $lena, null],
            [self::ACME_FOODS, $mia, null],
            [self::ACME_FOODS, $lena, null],
        ], $this->sentBellRefreshes());
    }

    #[Test]
    public function aClearedPingDeliveredOnTheEventBusRefreshesTheBellOfThePersonPingedAndWritesNoRow(): void
    {
        $lena = self::VIEWERS['Lena'][0];
        $mia = self::VIEWERS['Mia'][0];
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($lena, $mia): void {
            self::getContainer()->get('event.bus')->dispatch(new PingCleared(self::ACME_FOODS, self::teamOnlyTicketId(), self::PING, $mia, $lena, PingClearedReason::Done, $lena, new \DateTimeImmutable(self::NOW)));
        });
        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        self::assertSame([PingCleared::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed));

        self::deliverFromAsyncNotify($relayed[0]);

        self::assertSame([], $this->acmeNotifications());
        self::assertSame([[self::ACME_FOODS, $lena, null]], $this->sentBellRefreshes());
    }

    private static function addCommittedPing(string $pingId, string $fromId, string $toId, ?string $deliverAfter, string $createdAt): void
    {
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO ntf_ping (organization_id, id, ticket_id, from_id, to_id, body, deliver_after, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [self::ACME_FOODS, $pingId, self::teamOnlyTicketId(), $fromId, $toId, 'Any news?', $deliverAfter, $createdAt],
        ));
    }

    /**
     * @return list<list<mixed>> ping id and deliver_after of every Acme Foods ping notification, by ping id
     */
    private function pingRowsDeliverAfter(): array
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::connection()->fetchAllNumeric(
            "SELECT data->>'pingId', deliver_after FROM ntf_notification WHERE organization_id = ? AND kind = 'ping' ORDER BY data->>'pingId'",
            [self::ACME_FOODS],
        ));
    }

    private static function deliverFromAsyncNotify(Envelope $envelope): void
    {
        self::getContainer()->get('event.bus')->dispatch($envelope->with(new ReceivedStamp('async_notify')));
    }

    /**
     * @return list<list<mixed>> kind, recipient, sender, ping id, message id and reply id of every Acme Foods
     *                           notification, oldest first
     */
    private function acmeNotifications(): array
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::connection()->fetchAllNumeric(
            "SELECT kind, user_id, actor_id, data->>'pingId', data->>'messageId', data->>'replyId' FROM ntf_notification WHERE organization_id = ? ORDER BY created_at, id",
            [self::ACME_FOODS],
        ));
    }

    /**
     * @return list<list<mixed>> kind and whether it is done of every Acme Foods notification, oldest first
     */
    private function acmeNotificationsDone(): array
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::connection()->fetchAllNumeric(
            'SELECT kind, done_at IS NOT NULL FROM ntf_notification WHERE organization_id = ? ORDER BY created_at, id',
            [self::ACME_FOODS],
        ));
    }

    /**
     * @param list<string> $messageIds
     */
    private function processedRowCount(array $messageIds): int
    {
        $count = self::migrator()->fetchOne(
            'SELECT count(*) FROM processed_message WHERE handler = ? AND message_id = ANY (?::uuid[])',
            [NotifyRecipients::HANDLER_NAME, '{'.implode(',', $messageIds).'}'],
        );
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<array{string, string, string|null}> organization, user and toast of every RefreshBell async_notify
     *                                                  got so far
     */
    private function sentBellRefreshes(): array
    {
        $refreshes = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshBell) {
                $refreshes[] = [$message->organizationId, $message->userId, $message->toast];
            }
        }

        return $refreshes;
    }

    private static function messageIdOf(Envelope $envelope): string
    {
        $stamp = $envelope->last(MessageIdStamp::class);
        self::assertInstanceOf(MessageIdStamp::class, $stamp);

        return $stamp->messageId();
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function removeCommittedRows(): void
    {
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM ntf_notification WHERE organization_id = ?',
            [self::ACME_FOODS],
        ));
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM ntf_ping WHERE organization_id = ? AND id IN (?, ?)',
            [self::ACME_FOODS, self::PING, self::HELD_PING],
        ));
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM cnv_message WHERE organization_id = ? AND id IN (?, ?)',
            [self::ACME_FOODS, self::QUESTION, self::COMMENT],
        ));
        self::migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [NotifyRecipients::HANDLER_NAME]);
        self::migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }
}
