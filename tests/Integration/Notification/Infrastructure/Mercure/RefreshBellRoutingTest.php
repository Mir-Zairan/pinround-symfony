<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Mercure;

use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Mercure\RefreshBellHandler;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A bell push for Omar in Acme Foods through the real command.bus, async_notify routing, BellInbox and stream template,
 * on 20 Mar 2027. Omar has two open pings on SHOP-1 ("Checkout button is hidden"), next to a done one, one held for
 * quiet hours and one to Mia, which the count must leave out; the ticket's title and key are what a bell card would
 * show and the push must not.
 */
final class RefreshBellRoutingTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000be101';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000be111';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000be121';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000be131';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000be132';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000be133';
    private const string NOW = '2027-03-20 10:00:00 UTC';
    private const string TICKET_TITLE = 'Checkout button is hidden';

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        self::getContainer()->set('mercure.hub.default.traceable', new MockHub(
            'https://localhost/.well-known/mercure',
            new StaticTokenProvider('publisher-token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'urn:uuid:published';
            },
        ));
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_1, self::SHOP_PROJECT, self::LENA);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::pingToOmar('0197a3c0-0000-7000-8000-0000000be141', '2027-03-15 11:00:00+00'),
            self::pingToOmar('0197a3c0-0000-7000-8000-0000000be142', '2027-03-16 11:00:00+00'),
            [...self::pingToOmar('0197a3c0-0000-7000-8000-0000000be143', '2027-03-17 11:00:00+00'), 'done_at' => '2027-03-17 12:00:00+00'],
            [...self::pingToOmar('0197a3c0-0000-7000-8000-0000000be144', '2027-03-18 11:00:00+00'), 'deliver_after' => '2027-03-20 18:00:00+00'],
            [...self::pingToOmar('0197a3c0-0000-7000-8000-0000000be145', '2027-03-19 11:00:00+00'), 'user_id' => self::MIA],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RefreshBellHandler::HANDLER_NAME]);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        self::getContainer()->get('doctrine')->resetManager();
        parent::tearDown();
    }

    #[Test]
    public function aPushSentFromATenantGoesThroughAsyncNotifyAndPublishesTheBadgeAFreshFrameAndTheToastToThatUserOnly(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, static function (): void {
            self::getContainer()->get('command.bus')->dispatch(new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.'));
        });

        self::assertSame([], $this->published(), 'Nothing is published where the push is sent.');
        self::assertSame([RefreshBell::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $this->asyncNotify()->getSent()));
        self::runTheWorkerUntilIdle('async_notify');

        $published = $this->published();
        self::assertCount(1, $published);
        $update = $published[0];
        self::assertSame(['https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertNull($update->getType());
        $payload = $update->getData();
        self::assertStringContainsString('<turbo-stream action="replace" target="bell-badge"><template><span id="bell-badge"><span class="badge bell__badge" aria-hidden="true">2</span>', $payload);
        self::assertStringContainsString('<span class="visually-hidden">2 open</span>', $payload);
        self::assertMatchesRegularExpression('#<turbo-stream action="replace" target="bell-frame"><template><turbo-frame id="bell-frame" src="/bell" loading="lazy" data-bell-target="frame">\s*<p class="bell-popover__loading">Loading pings…</p>\s*</turbo-frame>#', $payload);
        self::assertStringContainsString('<turbo-stream action="update" target="toast-region">', $payload);
        self::assertStringContainsString('data-toast-message-value="Lena answered your question."', $payload);
        self::assertSame(3, substr_count($payload, '<turbo-stream '));
        self::assertStringNotContainsString(self::TICKET_TITLE, $payload);
        self::assertStringNotContainsString('SHOP-1', $payload);
        self::assertStringNotContainsString('<form', $payload);
        self::assertStringNotContainsStringIgnoringCase('_token', $payload);
        self::assertStringNotContainsStringIgnoringCase('csrf', $payload);
    }

    #[Test]
    public function aPushDeliveredTwiceOnAsyncNotifyPublishesOneToast(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, static function (): void {
            self::getContainer()->get('command.bus')->dispatch(new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.'));
        });
        $asyncNotify = $this->asyncNotify();
        self::assertCount(1, $asyncNotify->getSent());
        $asyncNotify->send($asyncNotify->getSent()[0]);

        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(2, $asyncNotify->getSent());
        $toastStreams = array_filter($this->published(), static fn (Update $update): bool => str_contains($update->getData(), 'data-toast-message-value="Lena answered your question."'));
        self::assertCount(1, $toastStreams);
        self::assertCount(1, $this->published());
    }

    #[Test]
    public function aPushWithNoToastHasNoToastStreamAndATextToastIsEscaped(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, static function (): void {
            $commandBus = self::getContainer()->get('command.bus');
            $commandBus->dispatch(new RefreshBell(self::ACME_FOODS, self::OMAR, null));
            $commandBus->dispatch(new RefreshBell(self::ACME_FOODS, self::OMAR, '<b>Lena</b> answered your question.'));
        });
        self::runTheWorkerUntilIdle('async_notify');

        $published = $this->published();
        self::assertCount(2, $published);
        self::assertSame(2, substr_count($published[0]->getData(), '<turbo-stream '));
        self::assertStringNotContainsString('toast-region', $published[0]->getData());
        self::assertStringContainsString('data-toast-message-value="&lt;b&gt;Lena&lt;/b&gt; answered your question."', $published[1]->getData());
    }

    /**
     * @return array<string, mixed>
     */
    private static function pingToOmar(string $id, string $createdAt): array
    {
        return [
            'organization_id' => self::ACME_FOODS,
            'id' => $id,
            'user_id' => self::OMAR,
            'kind' => 'ping',
            'actor_id' => self::LENA,
            'ticket_id' => self::SHOP_1,
            'data' => json_encode(['actorName' => 'Lena', 'ticketKey' => 'SHOP-1'], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ];
    }

    /**
     * The updates the hub got so far; read through a call, as the hub's callback adds to them behind the analyser's back.
     *
     * @return list<Update>
     */
    private function published(): array
    {
        return $this->published;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
