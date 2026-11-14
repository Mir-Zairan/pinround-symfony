<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\Event\MessageEdited;
use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Application\EditMessage\EditMessage;
use App\Conversation\Application\RemoveMessage\RemoveMessage;
use App\Notification\Infrastructure\Mercure\RefreshAttempt;
use App\Notification\Infrastructure\Mercure\RefreshTicketPages;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A real edit and a real removal, committed, relayed from the outbox by the worker onto async_notify, then delivered
 * from there on event.bus as the worker would: each queues one Leading refresh of its own ticket's pages and no bell
 * notification. The delivery is dispatched, not run through the worker, so the refresh is queued but never published
 * to the hub. OTHER_TICKET has a comment too, so a refresh of the wrong ticket would show.
 */
final class MessageEditedAndRemovedDeliveryTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c601';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c603';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c604';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000c605';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c611';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000c621';
    private const string OTHER_TICKETS_COMMENT = '0197a3c0-0000-7000-8000-00000000c622';
    private const string POSTED_AT = '2027-03-15 10:00:00+00';
    private const string ACTED_AT = '2027-03-15 10:05:00+00:00';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::ACTED_AT)));
        $this->threadRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, 'The button moves on small screens.', self::POSTED_AT);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::OTHER_TICKET, self::OTHER_TICKETS_COMMENT, self::LENA, 'The menu is slow.', self::POSTED_AT);
        $this->deleteOutboxRowsNaming(self::ACME_FOODS);
    }

    protected function tearDown(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS);
        $this->deleteOutboxRowsNaming(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function anEditDeliveredFromAsyncNotifyQueuesOneRefreshOfItsTicketAndNoNotification(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->editMessage()->edit(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::LENAS_COMMENT,
            new UserId(self::LENA),
            'The button hides on small screens.',
            new \DateTimeImmutable(self::ACTED_AT),
        ));

        $this->assertTheRelayedEventRefreshesOnlyItsTicket(
            new MessageEdited(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, new \DateTimeImmutable(self::ACTED_AT)),
        );
    }

    #[Test]
    public function aRemovalDeliveredFromAsyncNotifyQueuesOneRefreshOfItsTicketAndNoNotification(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->removeMessage()->remove(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::LENAS_COMMENT,
            new UserId(self::LENA),
            new \DateTimeImmutable(self::ACTED_AT),
        ));

        $this->assertTheRelayedEventRefreshesOnlyItsTicket(
            new MessageRemoved(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, new \DateTimeImmutable(self::ACTED_AT)),
        );
    }

    private function assertTheRelayedEventRefreshesOnlyItsTicket(MessageEdited|MessageRemoved $expectedEvent): void
    {
        self::runTheWorkerUntilIdle('outbox');
        $relayed = array_values(array_filter(
            $this->asyncNotify()->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $expectedEvent && self::TICKET === $envelope->getMessage()->ticketId,
        ));
        self::assertCount(1, $relayed, 'The event reached async_notify once.');
        self::assertEquals($expectedEvent, $relayed[0]->getMessage());

        self::getContainer()->get('event.bus')->dispatch($relayed[0]->with(new ReceivedStamp('async_notify')));

        self::assertEquals([new RefreshTicketPages(self::ACME_FOODS, self::TICKET, RefreshAttempt::Leading)], $this->acmeRefreshes());
        self::assertSame([], $this->acmeNotificationKinds());
    }

    /**
     * @return list<RefreshTicketPages> every refresh queued on async_notify for Acme Foods, whatever its ticket
     */
    private function acmeRefreshes(): array
    {
        $refreshes = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshTicketPages && self::ACME_FOODS === $message->organizationId) {
                $refreshes[] = $message;
            }
        }

        return $refreshes;
    }

    /**
     * @return list<mixed>
     */
    private function acmeNotificationKinds(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT kind FROM ntf_notification WHERE organization_id = ?',
            [self::ACME_FOODS],
        ));
    }

    /**
     * Only the outbox rows naming this test's organization: other tests delete outbox rows at the same time.
     */
    private function deleteOutboxRowsNaming(string $id): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$id.'%']);
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function editMessage(): EditMessage
    {
        $editMessage = self::getContainer()->get('test.conversation_edit_message');
        self::assertInstanceOf(EditMessage::class, $editMessage);

        return $editMessage;
    }

    private function removeMessage(): RemoveMessage
    {
        $removeMessage = self::getContainer()->get('test.conversation_remove_message');
        self::assertInstanceOf(RemoveMessage::class, $removeMessage);

        return $removeMessage;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function threadRows(): ThreadRows
    {
        return new ThreadRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
