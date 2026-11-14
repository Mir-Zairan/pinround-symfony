<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Bell\BellInbox;
use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Mercure\RefreshBellHandler;
use App\Notification\Infrastructure\Scheduler\ReleaseDueNotifications;
use App\Notification\Infrastructure\Scheduler\ReleaseDueNotificationsHandler;
use App\Notification\Infrastructure\Scheduler\ReleaseHeldNotifications;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\SharedKernel\Infrastructure\Scheduler\DefaultSchedule;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

/**
 * The release after quiet hours on 3 Mar 2027 at 09:00 UTC, through the resolver, the real command.bus, async_notify
 * and the bell push. Acme Foods holds two notifications for Omar and one for Mia until 09:00 or earlier, one for Omar
 * until tomorrow, and has one for Omar that was never held; Beta Bakery holds one for Lena; Cedar Cafe holds one only
 * until tomorrow. Other tests share the database and may hold rows of their own organizations, so what the resolver
 * and the run send is read for these three organizations only, and only their messages are handled.
 */
final class ReleaseHeldNotificationsTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e6001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e6002';
    private const string CEDAR_CAFE = '0197a3c0-0000-7000-8000-0000000e6003';
    private const array ORGANIZATIONS = [self::ACME_FOODS, self::BETA_BAKERY, self::CEDAR_CAFE];
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e6011';
    private const string ACME_TICKET = '0197a3c0-0000-7000-8000-0000000e6021';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000e6022';
    private const string CEDAR_TICKET = '0197a3c0-0000-7000-8000-0000000e6023';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e6031';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e6032';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e6033';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000e6034';

    private const string OMAR_DUE_EARLIER = '0197a3c0-0000-7000-8000-0000000e6041';
    private const string OMAR_DUE_NOW = '0197a3c0-0000-7000-8000-0000000e6042';
    private const string MIA_DUE = '0197a3c0-0000-7000-8000-0000000e6043';
    private const string OMAR_DUE_TOMORROW = '0197a3c0-0000-7000-8000-0000000e6044';
    private const string OMAR_NEVER_HELD = '0197a3c0-0000-7000-8000-0000000e6045';
    private const string LENA_DUE = '0197a3c0-0000-7000-8000-0000000e6046';
    private const string CEDAR_DUE_TOMORROW = '0197a3c0-0000-7000-8000-0000000e6047';

    private const string NOW = '2027-03-03 09:00:00+00';
    private const string TOMORROW = '2027-03-04 09:00:00+00';

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
        $this->ticketRows()->removeAllOf(...self::ORGANIZATIONS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::ACME_TICKET, self::SHOP_PROJECT, self::RAJ);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::SHOP_PROJECT, self::RAJ);
        $this->ticketRows()->addOf(self::CEDAR_CAFE, self::CEDAR_TICKET, self::SHOP_PROJECT, self::RAJ);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification(self::ACME_FOODS, self::OMAR_DUE_EARLIER, self::OMAR, self::ACME_TICKET, '2027-03-03 07:00:00+00'),
            self::notification(self::ACME_FOODS, self::OMAR_DUE_NOW, self::OMAR, self::ACME_TICKET, self::NOW),
            self::notification(self::ACME_FOODS, self::MIA_DUE, self::MIA, self::ACME_TICKET, '2027-03-03 08:30:00+00'),
            self::notification(self::ACME_FOODS, self::OMAR_DUE_TOMORROW, self::OMAR, self::ACME_TICKET, self::TOMORROW),
            self::notification(self::ACME_FOODS, self::OMAR_NEVER_HELD, self::OMAR, self::ACME_TICKET, null),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_notification', [
            self::notification(self::BETA_BAKERY, self::LENA_DUE, self::LENA, self::BETA_TICKET, '2027-03-03 08:00:00+00'),
        ]);
        $this->notificationRows()->addOf(self::CEDAR_CAFE, 'ntf_notification', [
            self::notification(self::CEDAR_CAFE, self::CEDAR_DUE_TOMORROW, self::LENA, self::CEDAR_TICKET, self::TOMORROW),
        ]);
        self::getContainer()->get('cache.bell_count')->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RefreshBellHandler::HANDLER_NAME]);
        $this->ticketRows()->removeAllOf(...self::ORGANIZATIONS);
        self::getContainer()->get('doctrine')->resetManager();
        parent::tearDown();
    }

    /** Not limited to some environments like the partition jobs: it uses the app connection, which prod has. */
    #[Test]
    public function theDefaultScheduleRunsTheReleaseEveryFiveMinutesOnce(): void
    {
        $schedule = self::getContainer()->get(DefaultSchedule::class);

        $releaseTasks = [];
        foreach ($schedule->getRecurringMessages() as $task) {
            $context = new MessageContext('default', $task->getId(), $task->getTrigger(), new \DateTimeImmutable(self::NOW));
            foreach ($task->getMessages($context) as $message) {
                if ($message instanceof ServiceCallMessage && ReleaseHeldNotifications::class === $message->getServiceId()) {
                    $releaseTasks[] = $task->getTrigger().' '.$message->getMethod();
                }
            }
        }

        self::assertSame(['every 5 minutes __invoke'], $releaseTasks);
    }

    #[Test]
    public function theResolverReturnsOnlyTheIdOfEachOrganizationWithADueNotificationOnce(): void
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM organizations_with_due_notifications(?)', [self::NOW]);

        self::assertSame(
            [['organization_id' => self::ACME_FOODS], ['organization_id' => self::BETA_BAKERY]],
            array_values(array_filter($rows, static fn (array $row): bool => \in_array($row['organization_id'] ?? null, self::ORGANIZATIONS, true))),
        );
    }

    #[Test]
    public function aRunReleasesTheDueNotificationsOnceKeepsTheOthersHeldAndPushesEachRecipientsBellOnce(): void
    {
        // Omar's count is cached at 06:59, when only his never-held notification is open.
        self::assertSame(1, $this->transactions()->committedAs(
            self::ACME_FOODS,
            static fn (): int => self::getContainer()->get(BellInbox::class)->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-03 06:59:00+00')),
        ));

        $this->runTheReleaseForTheseOrganizations([self::ACME_FOODS, self::BETA_BAKERY]);

        self::assertSame(
            [self::OMAR_DUE_EARLIER => null, self::OMAR_DUE_NOW => null, self::MIA_DUE => null, self::OMAR_DUE_TOMORROW => self::TOMORROW, self::OMAR_NEVER_HELD => null],
            $this->deliveryTimesOf(self::ACME_FOODS),
        );
        self::assertSame([self::LENA_DUE => null], $this->deliveryTimesOf(self::BETA_BAKERY));
        self::assertSame([self::CEDAR_DUE_TOMORROW => self::TOMORROW], $this->deliveryTimesOf(self::CEDAR_CAFE));
        self::assertEqualsCanonicalizing(
            [self::ACME_FOODS.' '.self::OMAR, self::ACME_FOODS.' '.self::MIA, self::BETA_BAKERY.' '.self::LENA],
            $this->bellPushesSent(),
        );
        $badgesByTopic = [];
        foreach ($this->published() as $update) {
            $topics = $update->getTopics();
            self::assertCount(1, $topics);
            self::assertIsString($topics[0]);
            self::assertSame(1, preg_match('#<span class="badge bell__badge" aria-hidden="true">(\d+)</span>#', $update->getData(), $badge));
            $badgesByTopic[$topics[0]] = $badge[1];
        }
        self::assertSame(
            [
                'https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR => '3',
                'https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::MIA => '1',
                'https://pinround.app/o/'.self::BETA_BAKERY.'/u/'.self::LENA => '1',
            ],
            array_filter($badgesByTopic, static fn (string $topic): bool => str_contains($topic, self::ACME_FOODS) || str_contains($topic, self::BETA_BAKERY), \ARRAY_FILTER_USE_KEY),
        );
    }

    #[Test]
    public function aSecondRunFindsNothingToReleaseAndAReleaseSentAgainPushesNothing(): void
    {
        $this->runTheReleaseForTheseOrganizations([self::ACME_FOODS, self::BETA_BAKERY]);
        $this->asyncNotify()->reset();
        $publishedBefore = \count($this->published());

        self::assertSame([], array_values(array_intersect(self::ORGANIZATIONS, $this->release()())));
        self::assertSame([], $this->releasesSentForTheseOrganizations());

        self::getContainer()->get('command.bus')->dispatch(new ReleaseDueNotifications(self::ACME_FOODS), [new TenantStamp(new OrganizationId(self::ACME_FOODS))]);
        $this->handleOnlyTheMessagesOfTheseOrganizations();

        self::assertSame([], $this->bellPushesSent());
        self::assertCount($publishedBefore, $this->published());
        self::assertSame(self::TOMORROW, $this->deliveryTimesOf(self::ACME_FOODS)[self::OMAR_DUE_TOMORROW]);
    }

    #[Test]
    public function theReleaseOfOneOrganizationLeavesAnotherOrganizationsNotificationsHeld(): void
    {
        self::getContainer()->get('command.bus')->dispatch(new ReleaseDueNotifications(self::BETA_BAKERY), [new TenantStamp(new OrganizationId(self::BETA_BAKERY))]);
        $this->handleOnlyTheMessagesOfTheseOrganizations();

        self::assertSame([self::LENA_DUE => null], $this->deliveryTimesOf(self::BETA_BAKERY));
        self::assertSame(
            [self::OMAR_DUE_EARLIER => '2027-03-03 07:00:00+00', self::OMAR_DUE_NOW => self::NOW, self::MIA_DUE => '2027-03-03 08:30:00+00', self::OMAR_DUE_TOMORROW => self::TOMORROW, self::OMAR_NEVER_HELD => null],
            $this->deliveryTimesOf(self::ACME_FOODS),
        );
        self::assertSame([self::BETA_BAKERY.' '.self::LENA], $this->bellPushesSent());
    }

    #[Test]
    public function aReleaseNamingAnotherOrganizationThanTheOneSetIsRefusedAndReleasesNothing(): void
    {
        $handler = self::getContainer()->get(ReleaseDueNotificationsHandler::class);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, static function () use ($handler): void {
                $handler(new ReleaseDueNotifications(self::BETA_BAKERY));
            });
            self::fail('A release of Beta Bakery ran under Acme Foods.');
        } catch (MessageForAnotherTenantRefused) {
            // The failed transaction closed the entity manager.
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame([self::LENA_DUE => '2027-03-03 08:00:00+00'], $this->deliveryTimesOf(self::BETA_BAKERY));
        self::assertSame('2027-03-03 07:00:00+00', $this->deliveryTimesOf(self::ACME_FOODS)[self::OMAR_DUE_EARLIER]);
        self::assertSame([], $this->bellPushesSent());
    }

    /**
     * Runs the periodic task, checks it sent one stamped release to each of $expected among this test's organizations,
     * and handles only those.
     *
     * @param list<string> $expected
     */
    private function runTheReleaseForTheseOrganizations(array $expected): void
    {
        self::assertSame($expected, array_values(array_intersect($this->release()(), self::ORGANIZATIONS)));
        self::assertSame($expected, $this->releasesSentForTheseOrganizations());
        $this->handleOnlyTheMessagesOfTheseOrganizations();
    }

    /**
     * @return list<string> the organization of each release sent for this test's organizations, checked against its TenantStamp
     */
    private function releasesSentForTheseOrganizations(): array
    {
        $organizationIds = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $release = $envelope->getMessage();
            if ($release instanceof ReleaseDueNotifications && \in_array($release->organizationId, self::ORGANIZATIONS, true)) {
                self::assertSame($release->organizationId, $envelope->last(TenantStamp::class)?->organizationId()->toString());
                $organizationIds[] = $release->organizationId;
            }
        }

        return $organizationIds;
    }

    /** Puts back only the queued messages of this test's organizations, so rows other tests hold are never released here. */
    private function handleOnlyTheMessagesOfTheseOrganizations(): void
    {
        $asyncNotify = $this->asyncNotify();
        $ours = array_filter(
            $asyncNotify->getSent(),
            static fn (Envelope $envelope): bool => \in_array($envelope->last(TenantStamp::class)?->organizationId()->toString(), self::ORGANIZATIONS, true),
        );
        $asyncNotify->reset();
        foreach ($ours as $envelope) {
            $asyncNotify->send($envelope);
        }
        self::runTheWorkerUntilIdle('async_notify');
    }

    /**
     * @return list<string> "organization user" of each bell push sent for this test's organizations
     */
    private function bellPushesSent(): array
    {
        $pushes = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $refresh = $envelope->getMessage();
            if ($refresh instanceof RefreshBell && \in_array($refresh->organizationId, self::ORGANIZATIONS, true)) {
                self::assertNull($refresh->toast);
                $pushes[] = $refresh->organizationId.' '.$refresh->userId;
            }
        }

        return $pushes;
    }

    /**
     * @return array<string, string|null> deliver_after by notification id, in id order
     */
    private function deliveryTimesOf(string $organizationId): array
    {
        /** @var array<string, string|null> $deliveryTimes */
        $deliveryTimes = $this->transactions()->committedAs($organizationId, fn (): array => $this->connection()->fetchAllKeyValue(
            "SELECT id, to_char(deliver_after AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM ntf_notification WHERE organization_id = ? ORDER BY id",
            [$organizationId],
        ));

        return $deliveryTimes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function notification(string $organizationId, string $id, string $userId, string $ticketId, ?string $deliverAfter): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $id,
            'user_id' => $userId,
            'kind' => 'ping',
            'actor_id' => self::RAJ,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => 'Raj', 'ticketKey' => 'SHOP-1'], \JSON_THROW_ON_ERROR),
            'deliver_after' => $deliverAfter,
            'created_at' => '2027-03-02 22:00:00+00',
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

    private function release(): ReleaseHeldNotifications
    {
        return self::getContainer()->get(ReleaseHeldNotifications::class);
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
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
