<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\SendDigest;
use App\Notification\Infrastructure\Doctrine\DbalDeliveries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Notification\DeliveryRows;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The digest job's message for Lena on 3 Mar 2027, handled on the real command.bus as worker-notify receives it, its
 * email sent through async_notify and the mailer's test transport. Lena leads the Shop team; Mia pinged her about
 * SHOP-7, a ticket for everyone, at 08:30 and 11:30, and asked her a question about SHOP-8, restricted to Shop, at
 * 09:30. Both titles carry a marker: the restricted one must reach no email, and neither may reach a log.
 */
final class SendDigestHandlerTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e8b01';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000e8b02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e8b03';
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-0000000e8b04';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000e8b05';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e8b11';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e8b12';
    private const string LENA_ADDRESS = 'lena.8b11@acme-foods.example';
    private const string PING_AT_0830 = '0197a3c0-0000-7000-8000-0000000e8b21';
    private const string QUESTION_AT_0930 = '0197a3c0-0000-7000-8000-0000000e8b22';
    private const string PING_AT_1130 = '0197a3c0-0000-7000-8000-0000000e8b23';

    private const string FOR_EVERYONE_TITLE = 'Checkout button MARKER-e8b-everyone';
    private const string TEAM_ONLY_TITLE = 'Refund rules MARKER-e8b-team';
    private const string REFUSAL_FUNCTION = 'test_refuse_digest_delivery_e8b';

    private MockClock $clock;
    private ?SecondSession $secondSession = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2027-03-03 10:05:00 UTC');
        self::getContainer()->set('clock', $this->clock);
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_PROJECT, 7, 'all', self::SHOP_TEAM, self::MIA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::SHOP_PROJECT, 8, 'team', self::SHOP_TEAM, self::MIA);
        $this->notificationRows()->executeOf(
            self::ACME_FOODS,
            'UPDATE tkt_ticket SET title = CASE id WHEN ?::uuid THEN ? ELSE ? END WHERE organization_id = ?',
            [self::FOR_EVERYONE_TICKET, self::FOR_EVERYONE_TITLE, self::TEAM_ONLY_TITLE, self::ACME_FOODS],
        );
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification(self::PING_AT_0830, 'ping', self::FOR_EVERYONE_TICKET, 'SHOP-7', '2027-03-03 08:30:00+00'),
            self::notification(self::QUESTION_AT_0930, 'question', self::TEAM_ONLY_TICKET, 'SHOP-8', '2027-03-03 09:30:00+00'),
            self::notification(self::PING_AT_1130, 'ping', self::FOR_EVERYONE_TICKET, 'SHOP-7', '2027-03-03 11:30:00+00'),
        ]);
        $this->setQuietHours(false);
        $this->capturedLogRecords()->clear();
    }

    protected function tearDown(): void
    {
        $this->secondSession?->entityManager->getConnection()->close();
        $this->secondSession = null;
        $this->migrator()->executeStatement('DROP FUNCTION IF EXISTS '.self::REFUSAL_FUNCTION.'() CASCADE');
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /** P04.T8 criterion 2. */
    #[Test]
    public function runningTheJobTwiceInOneWindowSendsOneEmail(): void
    {
        $this->sendDigestToLena();
        $this->clock->modify('2027-03-03 10:20:00 UTC');
        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', self::LENA_ADDRESS);
        self::assertSame('1 thing waits on you in pinround', $email->getSubject());
        self::assertEmailTextBodyContains($email, '- Mia pinged you on SHOP-7: '.self::FOR_EVERYONE_TITLE."\n");
        self::assertSame([['digest:2027-03-03T09:00:00+00:00', '2027-03-03 10:05:00']], $this->deliveriesOfLena());
    }

    /**
     * The second session is a run for Lena at 11:59:59 that recorded its digest and has not committed. A run at 12:00:01
     * is in the next slot, so its window key differs and only Lena's digest lock keeps it from emailing too.
     */
    #[Test]
    public function aRunInTheNextSlotWaitsForAnOpenRunForTheSamePersonAndThenSendsNothing(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $firstRun = $this->secondSession()->entityManager->getConnection();
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $firstRun->beginTransaction();
        try {
            $firstRunDeliveries = new DbalDeliveries($firstRun, $tenantContext);
            $firstRunDeliveries->lockFor(new UserId(self::LENA));
            self::assertTrue($firstRunDeliveries->recordDigest(new UserId(self::LENA), 'digest:2027-03-03T09:00:00+00:00', new \DateTimeImmutable('2027-03-03 11:59:59 UTC')));
            $tenantContext->clear();

            $this->clock->modify('2027-03-03 12:00:01 UTC');
            $sqlStateWhileTheFirstRunIsOpen = $this->sqlStateOfARunForLenaThatGaveUpWaiting();
            self::getContainer()->get('doctrine')->resetManager();
            self::assertSame(0, $this->queuedEmailCount());

            $firstRun->commit();
        } finally {
            if ($firstRun->isTransactionActive()) {
                $firstRun->rollBack();
            }
            $tenantContext->clear();
        }
        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlStateWhileTheFirstRunIsOpen, 'The run read Lena\'s last digest while another run for her was open.');
        self::assertEmailCount(0);
        self::assertSame([['digest:2027-03-03T09:00:00+00:00', '2027-03-03 11:59:59']], $this->deliveriesOfLena());
    }

    #[Test]
    public function threeHoursLaterTheNextEmailListsOnlyWhatCameSinceAndNamesTheRestrictedTicketByItsTeam(): void
    {
        $this->sendDigestToLena();
        $this->clock->modify('2027-03-03 13:01:00 UTC');
        $this->sendDigestToLena();
        self::assertSame(1, $this->queuedEmailCount());
        $this->clock->modify('2027-03-03 13:05:00 UTC');
        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertEmailCount(2);
        $email = self::getMailerMessage(1);
        self::assertInstanceOf(Email::class, $email);
        // The 09:30 question was under an hour old at 10:05, so the second email lists it; the 08:30 ping is not again.
        self::assertSame('2 things wait on you in pinround', $email->getSubject());
        self::assertEmailTextBodyContains($email, "- Mia pinged you on SHOP-7: Checkout button MARKER-e8b-everyone\n");
        self::assertEmailTextBodyContains($email, "- Mia asked you a question on SHOP-8: a restricted ticket in team Shop\n");
        self::assertSame(
            [['digest:2027-03-03T09:00:00+00:00', '2027-03-03 10:05:00'], ['digest:2027-03-03T12:00:00+00:00', '2027-03-03 13:05:00']],
            $this->deliveriesOfLena(),
        );
    }

    #[Test]
    public function noEmailReachesLenaInHerQuietHoursAndOneDoesWhenTheyEnd(): void
    {
        $this->setQuietHours(true);
        $this->migrator()->executeStatement("UPDATE idn_user SET time_zone = 'Europe/Berlin' WHERE id = ?", [self::LENA]);

        // 22:00 in Berlin.
        $this->clock->modify('2027-03-03 21:00:00 UTC');
        $this->sendDigestToLena();
        self::assertSame(0, $this->queuedEmailCount());
        self::assertSame([], $this->deliveriesOfLena());

        // 09:05 in Berlin.
        $this->clock->modify('2027-03-04 08:05:00 UTC');
        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertEmailCount(1);
        self::assertSame([['digest:2027-03-04T06:00:00+00:00', '2027-03-04 08:05:00']], $this->deliveriesOfLena());
    }

    #[Test]
    public function withNothingDueNoEmailIsSentAndNoDigestIsRecorded(): void
    {
        // The 08:30 ping is read; at 10:05 the 09:30 question is under an hour old and the 11:30 ping is to come.
        $this->notificationRows()->executeOf(
            self::ACME_FOODS,
            "UPDATE ntf_notification SET read_at = '2027-03-03 09:00:00+00' WHERE organization_id = ? AND id = ?",
            [self::ACME_FOODS, self::PING_AT_0830],
        );

        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertEmailCount(0);
        self::assertSame([], $this->deliveriesOfLena());
    }

    #[Test]
    public function aFailureAfterTheDeliveryIsRecordedRollsItBackAndSendsNothing(): void
    {
        // Fires at commit, after the handler has recorded the digest and queued its email.
        $this->migrator()->executeStatement(
            'CREATE FUNCTION '.self::REFUSAL_FUNCTION."() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF NEW.user_id = '".self::LENA."'::uuid THEN RAISE EXCEPTION 'digest delivery refused by the test'; END IF;
                RETURN NULL;
            END \$\$",
        );
        $this->migrator()->executeStatement(
            'CREATE CONSTRAINT TRIGGER '.self::REFUSAL_FUNCTION.' AFTER INSERT ON ntf_delivery DEFERRABLE INITIALLY DEFERRED'
            .' FOR EACH ROW EXECUTE FUNCTION '.self::REFUSAL_FUNCTION.'()',
        );

        try {
            $this->sendDigestToLena();
            self::fail('The commit was not refused.');
        } catch (\Throwable $failure) {
            self::assertStringContainsString('digest delivery refused by the test', self::messagesOf($failure));
        }
        self::getContainer()->get('doctrine')->resetManager();
        $this->sendTheQueuedEmails();

        self::assertSame(0, $this->queuedEmailCount());
        self::assertEmailCount(0);
        self::assertSame([], $this->deliveriesOfLena());

        // The retry finds no digest recorded and sends the one email.
        $this->migrator()->executeStatement('DROP FUNCTION '.self::REFUSAL_FUNCTION.'() CASCADE');
        $this->clock->modify('2027-03-03 10:06:00 UTC');
        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertEmailCount(1);
        self::assertSame([['digest:2027-03-03T09:00:00+00:00', '2027-03-03 10:06:00']], $this->deliveriesOfLena());
    }

    #[Test]
    public function noTicketTitleReachesALogAndTheRestrictedOneReachesNoPartOfTheEmail(): void
    {
        $this->clock->modify('2027-03-03 13:05:00 UTC');
        $this->sendDigestToLena();
        $this->sendTheQueuedEmails();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $subject = (string) $email->getSubject();
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString(self::FOR_EVERYONE_TITLE, $text);
        self::assertStringContainsString('SHOP-8', $html);
        foreach (['subject' => $subject, 'text' => $text, 'html' => $html] as $part => $content) {
            self::assertStringNotContainsString('MARKER-e8b-team', $content, $part);
        }

        $records = $this->capturedLogRecords()->all();
        self::assertNotSame([], $records);
        $formatter = new JsonFormatter();
        foreach ($records as $record) {
            $line = $formatter->format($record);
            self::assertStringNotContainsString('MARKER-e8b', $line);
        }
    }

    /**
     * Handles a SendDigest as worker-notify does once it receives one from async_notify.
     */
    private function sendDigestToLena(): void
    {
        self::getContainer()->get('command.bus')->dispatch(new Envelope(
            new SendDigest(self::ACME_FOODS, self::LENA),
            [new TenantStamp(new OrganizationId(self::ACME_FOODS)), new ReceivedStamp('async_notify')],
        ));
    }

    /**
     * Sends Lena's digest under a lock_timeout, as PHP cannot wait in two places.
     *
     * @return string the SQLSTATE the run failed with
     */
    private function sqlStateOfARunForLenaThatGaveUpWaiting(): string
    {
        $this->connection()->executeStatement("SET lock_timeout = '300ms'");
        try {
            $this->sendDigestToLena();
        } catch (\Throwable $failure) {
            for ($cause = $failure; null !== $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof DriverException) {
                    return (string) $cause->getSQLState();
                }
            }

            throw $failure;
        } finally {
            $this->connection()->executeStatement('RESET lock_timeout');
        }

        self::fail('The run went through while another run for Lena was open.');
    }

    private function secondSession(): SecondSession
    {
        return $this->secondSession ??= SecondSession::besideTheApp(
            self::getContainer()->get('doctrine.orm.default_entity_manager'),
            self::getContainer()->get(TenantContext::class),
        );
    }

    private function sendTheQueuedEmails(): void
    {
        self::runTheWorkerUntilIdle('async_notify');
    }

    private function queuedEmailCount(): int
    {
        return \count(array_filter(
            $this->asyncNotify()->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof SendEmailMessage,
        ));
    }

    /**
     * @return list<array{string, string}> window key and UTC sent time of each of Lena's digests, oldest first
     */
    private function deliveriesOfLena(): array
    {
        /** @var list<array{string, string}> $deliveries */
        $deliveries = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT window_key, to_char(sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM ntf_delivery"
            .' WHERE organization_id = ? AND user_id = ? ORDER BY sent_at',
            [self::ACME_FOODS, self::LENA],
        ));

        return $deliveries;
    }

    private function setQuietHours(bool $isOn): void
    {
        $this->notificationRows()->executeOf(self::ACME_FOODS, 'DELETE FROM org_settings WHERE organization_id = ?', [self::ACME_FOODS]);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('quiet_hours', ?::boolean), '2027-03-01 10:00:00+00')",
            [self::ACME_FOODS, $isOn ? 'true' : 'false'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function notification(string $id, string $kind, string $ticketId, string $ticketKey, string $createdAt): array
    {
        return [
            'organization_id' => self::ACME_FOODS,
            'id' => $id,
            'user_id' => self::LENA,
            'kind' => $kind,
            'actor_id' => self::MIA,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => 'Mia', 'ticketKey' => $ticketKey], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ];
    }

    private static function messagesOf(\Throwable $failure): string
    {
        $messages = [];
        for ($cause = $failure; null !== $cause; $cause = $cause->getPrevious()) {
            $messages[] = $cause->getMessage();
        }

        return implode("\n", $messages);
    }

    private function removeRows(): void
    {
        $this->deliveryRows()->removeAllOf(self::ACME_FOODS);
        $this->shopTeamRows()->removeAllOf([self::LENA, self::MIA], self::ACME_FOODS);
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function capturedLogRecords(): CapturedLogRecords
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);

        return $capturedLogRecords;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }

    private function deliveryRows(): DeliveryRows
    {
        return new DeliveryRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
