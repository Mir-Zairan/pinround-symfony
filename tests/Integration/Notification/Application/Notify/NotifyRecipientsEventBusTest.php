<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAnswered;
use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\Event\ReplyPosted;
use App\Notification\Api\Event\PingSent;
use App\Notification\Application\Notify\NotifyRecipients;
use App\SharedKernel\Infrastructure\Messenger\MessageIdStamp;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
use App\Ticketing\Api\Event\TicketAssigned;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * NotifyRecipients wired to event.bus, one case per tag in config/services.yaml: each event committed under Acme Foods
 * goes through the outbox, the real relay puts it on async_notify, and its delivery from there, twice, writes one
 * ntf_notification row. The deliveries are dispatched on event.bus as the worker would, not run through the worker: on
 * async_notify the worker would also run the RefreshTicketPages that QueueTicketPagesRefreshHandler queues for each
 * of these events, which publishes to the hub. That handler also keeps a missing tag from throwing, so only the
 * missing row shows it. Everything commits, so tearDown removes the rows.
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

    protected function setUp(): void
    {
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
            $eventBus = self::getContainer()->get('event.bus');
            $eventBus->dispatch(new QuestionAsked(self::ACME_FOODS, self::teamOnlyTicketId(), self::QUESTION, $mia, $lena, new \DateTimeImmutable(self::ASKED_AT)));
            $eventBus->dispatch(new QuestionAnswered(self::ACME_FOODS, self::teamOnlyTicketId(), self::QUESTION, $lena, $mia, new \DateTimeImmutable(self::ANSWERED_AT)));
        });
        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        // In any order: the relay sends the events of one second in no set order (data-model.md, messenger_outbox).
        self::assertEqualsCanonicalizing([QuestionAsked::class, QuestionAnswered::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed));

        foreach ($relayed as $envelope) {
            self::deliverFromAsyncNotify($envelope);
            self::deliverFromAsyncNotify($envelope);
        }

        self::assertSame([
            ['question', $mia, $lena, null, self::QUESTION, null],
            ['answer', $lena, $mia, null, self::QUESTION, null],
        ], $this->acmeNotifications());
        $messageIds = array_values(array_map(self::messageIdOf(...), $relayed));
        self::assertCount(2, array_unique($messageIds));
        self::assertSame(2, $this->processedRowCount($messageIds));
    }

    #[Test]
    public function aPingAReplyAndAnAssignmentEachDeliveredTwiceOnTheEventBusNotifyOnce(): void
    {
        $lena = self::VIEWERS['Lena'][0];
        $mia = self::VIEWERS['Mia'][0];
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($lena, $mia): void {
            $eventBus = self::getContainer()->get('event.bus');
            $eventBus->dispatch(new PingSent(self::ACME_FOODS, self::teamOnlyTicketId(), self::PING, $mia, $lena, $mia, new \DateTimeImmutable(self::PINGED_AT)));
            $eventBus->dispatch(new ReplyPosted(self::ACME_FOODS, self::teamOnlyTicketId(), self::COMMENT, self::REPLY, $mia, $lena, new \DateTimeImmutable(self::REPLIED_AT)));
            $eventBus->dispatch(new TicketAssigned(self::ACME_FOODS, self::teamOnlyTicketId(), 'SHOP-1', null, $lena, $mia, new \DateTimeImmutable(self::ASSIGNED_AT)));
        });
        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        // In any order: the relay sends the events of one second in no set order (data-model.md, messenger_outbox).
        self::assertEqualsCanonicalizing([PingSent::class, ReplyPosted::class, TicketAssigned::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed));

        foreach ($relayed as $envelope) {
            self::deliverFromAsyncNotify($envelope);
            self::deliverFromAsyncNotify($envelope);
        }

        self::assertSame([
            ['ping', $lena, $mia, self::PING, null, null],
            ['reply', $mia, $lena, null, self::COMMENT, self::REPLY],
            ['assigned', $lena, $mia, null, null, null],
        ], $this->acmeNotifications());
        $messageIds = array_values(array_map(self::messageIdOf(...), $relayed));
        self::assertCount(3, array_unique($messageIds));
        self::assertSame(3, $this->processedRowCount($messageIds));
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
        self::migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [NotifyRecipients::HANDLER_NAME]);
        self::migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }
}
