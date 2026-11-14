<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Digest\SendDigest;
use App\Notification\Infrastructure\Scheduler\QueueDigests;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Ticketing\TicketRows;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The digest job on 12 May 2027 at 10:05 UTC, through digest_recipients, the real command.bus and async_notify.
 * Acme Foods has a ping for Omar and a question for Lena, both over an hour old, and a ping for Mia made at 09:30;
 * Beta Bakery has a question for Omar and a ping for Lena. Other tests share the database and may hold due rows of
 * their own organizations, so only these two organizations' messages are read.
 */
final class QueueDigestsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fa101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000fa102';
    private const array ORGANIZATIONS = [self::ACME_FOODS, self::BETA_BAKERY];
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000fa111';
    private const string ACME_TICKET = '0197a3c0-0000-7000-8000-0000000fa121';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000fa122';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000fa131';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000fa132';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000fa133';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000fa134';

    private const string NOW = '2027-05-12 10:05:00+00';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->ticketRows()->removeAllOf(...self::ORGANIZATIONS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::ACME_TICKET, self::SHOP_PROJECT, self::RAJ);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::SHOP_PROJECT, self::RAJ);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000fa141', self::OMAR, 'ping', '2027-05-12 08:00:00+00'),
            self::notification(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000fa142', self::LENA, 'question', '2027-05-12 07:00:00+00'),
            self::notification(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000fa143', self::MIA, 'ping', '2027-05-12 09:30:00+00'),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_notification', [
            self::notification(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-0000000fa144', self::OMAR, 'question', '2027-05-12 06:00:00+00'),
            self::notification(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-0000000fa145', self::LENA, 'ping', '2027-05-12 09:00:00+00'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(...self::ORGANIZATIONS);
        parent::tearDown();
    }

    #[Test]
    public function aRunQueuesOneSendDigestStampedWithItsOrganizationOnAsyncNotifyPerPersonWithADueItem(): void
    {
        $queued = self::getContainer()->get(QueueDigests::class)();

        $expected = [
            self::ACME_FOODS.' '.self::LENA,
            self::ACME_FOODS.' '.self::OMAR,
            self::BETA_BAKERY.' '.self::LENA,
            self::BETA_BAKERY.' '.self::OMAR,
        ];
        self::assertEqualsCanonicalizing($expected, self::pairsOfTheseOrganizations($queued));
        self::assertEqualsCanonicalizing($expected, $this->digestsSentForTheseOrganizations());
    }

    #[Test]
    public function aRunWithNothingDueQueuesNothingForTheseOrganizations(): void
    {
        // At 08:30 Omar's 08:00 ping, Lena's Beta ping and Mia's ping are under an hour old or not made yet; the two
        // questions are not, so they are marked read here.
        $this->notificationRows()->executeOf(self::ACME_FOODS, "UPDATE ntf_notification SET read_at = '2027-05-12 08:10:00+00' WHERE organization_id = ? AND kind = 'question'", [self::ACME_FOODS]);
        $this->notificationRows()->executeOf(self::BETA_BAKERY, "UPDATE ntf_notification SET read_at = '2027-05-12 08:10:00+00' WHERE organization_id = ? AND kind = 'question'", [self::BETA_BAKERY]);
        $job = new QueueDigests(
            self::getContainer()->get('doctrine.dbal.default_connection'),
            self::getContainer()->get('command.bus'),
            new MockClock(new \DateTimeImmutable('2027-05-12 08:30:00+00')),
            new RecordingLogger(),
        );

        self::assertSame([], self::pairsOfTheseOrganizations($job()));
        self::assertSame([], $this->digestsSentForTheseOrganizations());
    }

    #[Test]
    public function aRunPastTheCapQueuesOnlyTheCapAndLogsAWarningThatNamesNoPersonOrTicket(): void
    {
        $logger = new RecordingLogger();
        $job = new QueueDigests(
            self::getContainer()->get('doctrine.dbal.default_connection'),
            self::getContainer()->get('command.bus'),
            new MockClock(new \DateTimeImmutable(self::NOW)),
            $logger,
            3,
        );

        $queued = $job();

        self::assertCount(3, $queued);
        self::assertCount(3, $this->asyncNotify()->getSent());
        self::assertSame(
            [['warning', 'The digest job found more than {cap} people to email; it queued {cap} of them and leaves the rest to a later run.', ['cap' => 3]]],
            $logger->records,
        );
    }

    #[Test]
    public function aRunAtOrUnderTheCapLogsNothing(): void
    {
        $logger = new RecordingLogger();
        $job = new QueueDigests(
            self::getContainer()->get('doctrine.dbal.default_connection'),
            self::getContainer()->get('command.bus'),
            new MockClock(new \DateTimeImmutable(self::NOW)),
            $logger,
            1000,
        );

        self::assertNotSame([], self::pairsOfTheseOrganizations($job()));
        self::assertSame([], $logger->records);
    }

    /**
     * digest_recipients offers a person every run while their old items wait unread, also after their digest went
     * out, so a cap that always kept the same people first would leave the others without a digest for good.
     */
    #[Test]
    public function runsPastTheCapAtTheNextQuartersReachEveryPerson(): void
    {
        // From 10:30 Mia's ping is an hour old too; read, it leaves four people for a cap of three.
        $this->notificationRows()->executeOf(self::ACME_FOODS, "UPDATE ntf_notification SET read_at = '2027-05-12 09:40:00+00' WHERE organization_id = ? AND user_id = ?", [self::ACME_FOODS, self::MIA]);
        $reached = [];
        foreach (['10:05', '10:20', '10:35', '10:50', '11:05', '11:20', '11:35', '11:50'] as $time) {
            $job = new QueueDigests(
                self::getContainer()->get('doctrine.dbal.default_connection'),
                self::getContainer()->get('command.bus'),
                new MockClock(new \DateTimeImmutable('2027-05-12 '.$time.':00+00')),
                new RecordingLogger(),
                3,
            );
            $reached = [...$reached, ...self::pairsOfTheseOrganizations($job())];
        }

        self::assertEqualsCanonicalizing(
            [self::ACME_FOODS.' '.self::LENA, self::ACME_FOODS.' '.self::OMAR, self::BETA_BAKERY.' '.self::LENA, self::BETA_BAKERY.' '.self::OMAR],
            array_values(array_unique($reached)),
        );
    }

    /**
     * @param list<SendDigest> $queued
     *
     * @return list<string> "organization user" of each queued digest of this test's organizations
     */
    private static function pairsOfTheseOrganizations(array $queued): array
    {
        return array_values(array_map(
            static fn (SendDigest $sendDigest): string => $sendDigest->organizationId.' '.$sendDigest->userId,
            array_filter($queued, static fn (SendDigest $sendDigest): bool => \in_array($sendDigest->organizationId, self::ORGANIZATIONS, true)),
        ));
    }

    /**
     * @return list<string> "organization user" of each SendDigest on async_notify for this test's organizations, each
     *                      checked against its TenantStamp
     */
    private function digestsSentForTheseOrganizations(): array
    {
        $pairs = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $sendDigest = $envelope->getMessage();
            if ($sendDigest instanceof SendDigest && \in_array($sendDigest->organizationId, self::ORGANIZATIONS, true)) {
                self::assertSame($sendDigest->organizationId, $envelope->last(TenantStamp::class)?->organizationId()->toString());
                $pairs[] = $sendDigest->organizationId.' '.$sendDigest->userId;
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private static function notification(string $organizationId, string $id, string $userId, string $kind, string $createdAt): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $id,
            'user_id' => $userId,
            'kind' => $kind,
            'actor_id' => self::RAJ,
            'ticket_id' => self::ACME_FOODS === $organizationId ? self::ACME_TICKET : self::BETA_TICKET,
            'data' => json_encode(['actorName' => 'Raj', 'ticketKey' => 'SHOP-1'], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ];
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
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
