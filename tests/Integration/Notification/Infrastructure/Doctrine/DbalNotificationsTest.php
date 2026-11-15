<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\Notification\Infrastructure\Doctrine\DbalNotifications;
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
 * DbalNotifications on the real ntf_notification table as pinround_app. Rows sit in March 2027, a month whose partition
 * the migration always creates.
 */
final class DbalNotificationsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000006b01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000006b02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000006b03';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-000000006b04';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000006b05';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-000000006b06';
    private const string MESSAGE = '0197a3c0-0000-7000-8000-000000006b07';
    private const string OTHER_MESSAGE = '0197a3c0-0000-7000-8000-000000006b08';
    private const string LENA = '0197a3c0-0000-7000-8000-000000006b11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000006b12';
    private const string MIA = '0197a3c0-0000-7000-8000-000000006b13';
    private const string FIRST_REPLY_AT = '2027-03-15 10:00:00 UTC';
    private const string HELD_UNTIL = '2027-03-16 08:00:00 UTC';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA);
        self::getContainer()->get('cache.bell_count')->clear();
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get('doctrine')->resetManager();
        parent::tearDown();
    }

    #[Test]
    public function aNotificationIsWrittenWithItsSnapshotAndAnIdOfItsInstant(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:00:00.250 UTC');
        $ping = new NewNotification(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), NotificationKind::Ping, new UserId(self::LENA), new TicketId(self::TICKET), ['actorName' => 'Lena', 'ticketKey' => 'SHOP-1', 'pingId' => self::MESSAGE], $createdAt, $createdAt);

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($ping));

        $rows = $this->rowsOf(self::ACME_FOODS);
        // jsonb keeps its own key order, so the data is compared by key.
        self::assertEquals(
            [[self::OMAR, 'ping', self::LENA, self::TICKET, ['actorName' => 'Lena', 'ticketKey' => 'SHOP-1', 'pingId' => self::MESSAGE], '2027-03-15 10:00:00.250', '2027-03-15 10:00:00.250', '2027-03-15 10:00:00.250']],
            array_map(static fn (array $row): array => [$row['user_id'], $row['kind'], $row['actor_id'], $row['ticket_id'], $row['data'], $row['deliver_after'], $row['created_at'], $row['id_instant']], $rows),
        );
    }

    #[Test]
    public function aNotificationThatIsNeverHeldHasNoDeliverAfter(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT)));

        self::assertSame([null], array_column($this->rowsOf(self::ACME_FOODS), 'deliver_after'));
    }

    #[Test]
    public function addingOutsideATransactionIsRefusedAndWritesNothing(): void
    {
        try {
            $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT));
            self::fail('A notification outside a transaction must be refused.');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('none is open', $refusal->getMessage());
        }

        self::assertSame([], $this->rowsOf(self::ACME_FOODS));
    }

    #[Test]
    public function markingPingsDoneOutsideATransactionIsRefusedAndLeavesThemOpen(): void
    {
        $at = new \DateTimeImmutable(self::FIRST_REPLY_AT);
        $ping = new NewNotification(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), NotificationKind::Ping, new UserId(self::LENA), new TicketId(self::TICKET), ['actorName' => 'Lena', 'ticketKey' => 'SHOP-1', 'pingId' => self::MESSAGE], $at);
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($ping));
        $rowsBefore = $this->rowsOf(self::ACME_FOODS);
        self::assertSame([null], array_column($rowsBefore, 'done_at'));

        try {
            $this->notifications()->markPingsDone(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), new TicketId(self::TICKET), $at->modify('+1 minute'));
            self::fail('Pings were marked done outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A notification is written in the transaction of the event it is about; none is open.', $refusal->getMessage());
        }

        self::assertSame($rowsBefore, $this->rowsOf(self::ACME_FOODS));
    }

    #[Test]
    public function aReplyFoldsOnlyIntoTheRecipientsUnreadOpenReplyAboutTheSameMessageSinceTheGivenTime(): void
    {
        $this->seedRowsAroundTheFoldTarget();
        $betaRowsBefore = $this->rowsOf(self::BETA_BAKERY);
        $acmeRowsBefore = $this->rowsOf(self::ACME_FOODS);
        self::assertCount(7, $acmeRowsBefore);

        $second = $this->reply(self::OMAR, self::MESSAGE, self::MIA, '2027-03-15 10:02:00 UTC');
        $isFolded = $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notifications()->foldIntoUnreadReply($second, new \DateTimeImmutable(self::FIRST_REPLY_AT)));

        self::assertTrue($isFolded);
        $acmeRowsAfter = $this->rowsOf(self::ACME_FOODS);
        $changed = array_values(array_udiff($acmeRowsAfter, $acmeRowsBefore, static fn (array $a, array $b): int => $a <=> $b));
        self::assertEquals(
            [[self::OMAR, 'reply', self::MIA, ['actorName' => 'Mia', 'ticketKey' => 'SHOP-1', 'messageId' => self::MESSAGE, 'replyId' => 'reply-by-'.self::MIA], '2027-03-15 10:02:00.000']],
            array_map(static fn (array $row): array => [$row['user_id'], $row['kind'], $row['actor_id'], $row['data'], $row['created_at']], $changed),
        );
        self::assertCount(7, $acmeRowsAfter);
        self::assertSame($betaRowsBefore, $this->rowsOf(self::BETA_BAKERY));
    }

    #[Test]
    public function aReplyWithNoUnreadReplySinceTheGivenTimeFoldsNothing(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT)));
        $rowsBefore = $this->rowsOf(self::ACME_FOODS);

        $late = $this->reply(self::OMAR, self::MESSAGE, self::MIA, '2027-03-15 10:02:01 UTC');
        $isFolded = $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notifications()->foldIntoUnreadReply($late, new \DateTimeImmutable('2027-03-15 10:00:01 UTC')));

        self::assertFalse($isFolded);
        self::assertSame($rowsBefore, $this->rowsOf(self::ACME_FOODS));
    }

    #[Test]
    public function aCommittedNotificationDropsTheRecipientsCachedBellCountAndLeavesOthers(): void
    {
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 1));
        self::assertSame(1, $this->cachedBellCountOf(self::MIA, 1));
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 2), 'The count was not read from the cache.');

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT)));

        self::assertSame(2, $this->cachedBellCountOf(self::OMAR, 2));
        self::assertSame(1, $this->cachedBellCountOf(self::MIA, 2));
    }

    #[Test]
    public function aRolledBackNotificationKeepsTheCachedBellCount(): void
    {
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 1));

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT)));

        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 2));
    }

    #[Test]
    public function aFoldDropsTheRecipientsCachedBellCount(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT)));
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 1));

        $second = $this->reply(self::OMAR, self::MESSAGE, self::MIA, '2027-03-15 10:02:00 UTC');
        $isFolded = $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notifications()->foldIntoUnreadReply($second, new \DateTimeImmutable(self::FIRST_REPLY_AT)));

        self::assertTrue($isFolded);
        self::assertSame(2, $this->cachedBellCountOf(self::OMAR, 2));
    }

    #[Test]
    public function aFoldThatFindsNoReplyKeepsTheCachedBellCount(): void
    {
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 1));

        $second = $this->reply(self::OMAR, self::MESSAGE, self::MIA, '2027-03-15 10:02:00 UTC');
        $isFolded = $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->notifications()->foldIntoUnreadReply($second, new \DateTimeImmutable(self::FIRST_REPLY_AT)));

        self::assertFalse($isFolded);
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 2));
    }

    #[Test]
    public function foldingANotificationThatIsNotAReplyIsRefused(): void
    {
        $question = new NewNotification(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), NotificationKind::Question, new UserId(self::LENA), new TicketId(self::TICKET), ['actorName' => 'Lena', 'ticketKey' => 'SHOP-1', 'messageId' => self::MESSAGE], new \DateTimeImmutable(self::FIRST_REPLY_AT));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Only a reply notification about a message folds into another.');
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): bool => $this->notifications()->foldIntoUnreadReply($question, new \DateTimeImmutable(self::FIRST_REPLY_AT)));
    }

    #[Test]
    public function aReplyFoldsOnlyIntoAReplyHeldUntilTheSameTime(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT));
            $this->notifications()->add($this->reply(self::OMAR, self::OTHER_MESSAGE, self::LENA, '2027-03-16 08:30:00 UTC', '2027-03-16 08:30:00 UTC'));
        });
        $since = new \DateTimeImmutable(self::FIRST_REPLY_AT);

        $isFolded = [
            'held into shown' => $this->foldOrAdd($this->reply(self::OMAR, self::MESSAGE, self::MIA, self::HELD_UNTIL, self::HELD_UNTIL), $since),
            'held into held until the same time' => $this->foldOrAdd($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::HELD_UNTIL, self::HELD_UNTIL), $since),
            'shown into held until later' => $this->foldOrAdd($this->reply(self::OMAR, self::OTHER_MESSAGE, self::MIA, '2027-03-16 08:10:00 UTC'), $since),
            'held into held until another time' => $this->foldOrAdd($this->reply(self::OMAR, self::OTHER_MESSAGE, self::MIA, '2027-03-17 08:30:00 UTC', '2027-03-17 08:30:00 UTC'), $since),
        ];

        self::assertSame(
            ['held into shown' => false, 'held into held until the same time' => true, 'shown into held until later' => false, 'held into held until another time' => false],
            $isFolded,
        );
        self::assertSame(
            [
                [self::MESSAGE, self::LENA, '2027-03-15 10:00:00.000', null],
                [self::MESSAGE, self::LENA, '2027-03-16 08:00:00.000', '2027-03-16 08:00:00.000'],
                [self::OTHER_MESSAGE, self::MIA, '2027-03-16 08:10:00.000', null],
                [self::OTHER_MESSAGE, self::LENA, '2027-03-16 08:30:00.000', '2027-03-16 08:30:00.000'],
                [self::OTHER_MESSAGE, self::MIA, '2027-03-17 08:30:00.000', '2027-03-17 08:30:00.000'],
            ],
            array_map(static fn (array $row): array => [$row['data']['messageId'] ?? null, $row['actor_id'], $row['created_at'], $row['deliver_after']], $this->rowsOf(self::ACME_FOODS)),
        );
    }

    #[Test]
    public function aShownReplyFoldsIntoAReplyWhoseHoldEndedBeforeTheReleaseRan(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::HELD_UNTIL, self::HELD_UNTIL)));

        $since = new \DateTimeImmutable(self::FIRST_REPLY_AT);

        $isFolded = [
            'as the hold ends' => $this->foldOrAdd($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::HELD_UNTIL), $since),
            'after the hold ended' => $this->foldOrAdd($this->reply(self::OMAR, self::MESSAGE, self::MIA, '2027-03-16 08:02:00 UTC'), $since),
        ];

        self::assertSame(['as the hold ends' => true, 'after the hold ended' => true], $isFolded);
        self::assertSame(
            [[self::MIA, '2027-03-16 08:02:00.000', '2027-03-16 08:00:00.000']],
            array_map(static fn (array $row): array => [$row['actor_id'], $row['created_at'], $row['deliver_after']], $this->rowsOf(self::ACME_FOODS)),
        );
    }

    #[Test]
    public function aReleaseClearsTheOrganizationsDueRowsAndReturnsEachRecipientOnceInUserIdOrder(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $notifications = $this->notifications();
            $notifications->add($this->reply(self::MIA, self::MESSAGE, self::LENA, '2027-03-16 07:00:00 UTC', '2027-03-16 07:00:00 UTC'));
            $notifications->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, '2027-03-16 07:30:00 UTC', '2027-03-16 07:30:00 UTC'));
            $notifications->add($this->reply(self::OMAR, self::OTHER_MESSAGE, self::LENA, self::HELD_UNTIL, self::HELD_UNTIL));
            $notifications->add($this->reply(self::OMAR, self::MESSAGE, self::MIA, '2027-03-17 08:00:00 UTC', '2027-03-17 08:00:00 UTC'));
            $notifications->add($this->reply(self::LENA, self::MESSAGE, self::MIA, self::FIRST_REPLY_AT));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->notifications()->add(
            new NewNotification(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), NotificationKind::Ping, new UserId(self::OMAR), new TicketId(self::BETA_TICKET), ['actorName' => 'Omar', 'ticketKey' => 'BETA-1'], new \DateTimeImmutable('2027-03-16 07:00:00 UTC'), new \DateTimeImmutable('2027-03-16 07:00:00 UTC')),
        ));
        $betaRowsBefore = $this->rowsOf(self::BETA_BAKERY);
        self::assertSame(1, $this->cachedBellCountOf(self::OMAR, 1));
        self::assertSame(1, $this->cachedBellCountOf(self::LENA, 1));

        $released = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->notifications()->releaseDue(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::HELD_UNTIL)));

        self::assertSame([self::OMAR, self::MIA], array_map(static fn (UserId $recipientId): string => $recipientId->toString(), $released));
        self::assertSame([null, null, null, null, '2027-03-17 08:00:00.000'], array_column($this->rowsOf(self::ACME_FOODS), 'deliver_after'));
        self::assertSame($betaRowsBefore, $this->rowsOf(self::BETA_BAKERY));
        self::assertSame(2, $this->cachedBellCountOf(self::OMAR, 2));
        self::assertSame(1, $this->cachedBellCountOf(self::LENA, 2));
    }

    #[Test]
    public function releasingOutsideATransactionIsRefusedAndKeepsTheRowsHeld(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notifications()->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT, self::FIRST_REPLY_AT)));
        $rowsBefore = $this->rowsOf(self::ACME_FOODS);
        self::assertSame(['2027-03-15 10:00:00.000'], array_column($rowsBefore, 'deliver_after'));

        try {
            $this->notifications()->releaseDue(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::HELD_UNTIL));
            self::fail('Notifications were released outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A notification is written in the transaction of the event it is about; none is open.', $refusal->getMessage());
        }

        self::assertSame($rowsBefore, $this->rowsOf(self::ACME_FOODS));
    }

    /**
     * The fold target (Omar's unread reply about MESSAGE at 10:00) and six rows the fold must leave alone: another
     * person's, a read one, a done one, one about another message, a question about the same message, one older than
     * the fold window; and Beta Bakery's reply with the same person and message ids.
     */
    private function seedRowsAroundTheFoldTarget(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $notifications = $this->notifications();
            $notifications->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, self::FIRST_REPLY_AT));
            $notifications->add($this->reply(self::MIA, self::MESSAGE, self::LENA, '2027-03-15 10:00:10 UTC'));
            $notifications->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, '2027-03-15 10:00:20 UTC'));
            $notifications->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, '2027-03-15 10:00:30 UTC'));
            $notifications->add($this->reply(self::OMAR, self::OTHER_MESSAGE, self::LENA, '2027-03-15 10:00:40 UTC'));
            $notifications->add(new NewNotification(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), NotificationKind::Question, new UserId(self::LENA), new TicketId(self::TICKET), ['actorName' => 'Lena', 'ticketKey' => 'SHOP-1', 'messageId' => self::MESSAGE], new \DateTimeImmutable('2027-03-15 10:00:50 UTC')));
            $notifications->add($this->reply(self::OMAR, self::MESSAGE, self::LENA, '2027-03-15 09:59:59 UTC'));
            $this->connection()->executeStatement("UPDATE ntf_notification SET read_at = '2027-03-15 10:01:00+00' WHERE organization_id = ? AND created_at = '2027-03-15 10:00:20+00'", [self::ACME_FOODS]);
            $this->connection()->executeStatement("UPDATE ntf_notification SET done_at = '2027-03-15 10:01:00+00' WHERE organization_id = ? AND created_at = '2027-03-15 10:00:30+00'", [self::ACME_FOODS]);
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->notifications()->add(
            new NewNotification(new OrganizationId(self::BETA_BAKERY), new UserId(self::OMAR), NotificationKind::Reply, new UserId(self::LENA), new TicketId(self::BETA_TICKET), ['actorName' => 'Lena', 'ticketKey' => 'BETA-1', 'messageId' => self::MESSAGE, 'replyId' => 'reply-by-'.self::LENA], new \DateTimeImmutable('2027-03-15 10:01:00 UTC')),
        ));
    }

    /**
     * Folds $reply in one committed transaction or, when nothing takes it, adds it as NotifyRecipients does.
     */
    private function foldOrAdd(NewNotification $reply, \DateTimeImmutable $since): bool
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, function () use ($reply, $since): bool {
            $isFolded = $this->notifications()->foldIntoUnreadReply($reply, $since);
            if (!$isFolded) {
                $this->notifications()->add($reply);
            }

            return $isFolded;
        });
    }

    private function reply(string $recipientId, string $messageId, string $actorId, string $at, ?string $deliverAfter = null): NewNotification
    {
        $actorName = [self::LENA => 'Lena', self::MIA => 'Mia'][$actorId] ?? 'Omar';

        return new NewNotification(
            new OrganizationId(self::ACME_FOODS),
            new UserId($recipientId),
            NotificationKind::Reply,
            new UserId($actorId),
            new TicketId(self::TICKET),
            ['actorName' => $actorName, 'ticketKey' => 'SHOP-1', 'messageId' => $messageId, 'replyId' => 'reply-by-'.$actorId],
            new \DateTimeImmutable($at),
            null === $deliverAfter ? null : new \DateTimeImmutable($deliverAfter),
        );
    }

    /**
     * @return list<array{user_id: mixed, kind: mixed, actor_id: mixed, ticket_id: mixed, data: array<mixed>, deliver_after: mixed, created_at: mixed, read_at: mixed, done_at: mixed, id_instant: mixed}>
     */
    private function rowsOf(string $organizationId): array
    {
        $rows = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT user_id, kind, actor_id, ticket_id, data::text AS data,
                    to_char(deliver_after AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') AS deliver_after,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') AS created_at,
                    read_at::text AS read_at, done_at::text AS done_at,
                    to_char(to_timestamp(('x' || substr(replace(id::text, '-', ''), 1, 12))::bit(48)::bigint / 1000.0) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.MS') AS id_instant
             FROM ntf_notification WHERE organization_id = ? ORDER BY created_at, id",
            [$organizationId],
        ));

        return array_map(static function (array $row): array {
            self::assertIsString($row['data']);
            $data = json_decode($row['data'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [
                'user_id' => $row['user_id'],
                'kind' => $row['kind'],
                'actor_id' => $row['actor_id'],
                'ticket_id' => $row['ticket_id'],
                'data' => $data,
                'deliver_after' => $row['deliver_after'],
                'created_at' => $row['created_at'],
                'read_at' => $row['read_at'],
                'done_at' => $row['done_at'],
                'id_instant' => $row['id_instant'],
            ];
        }, $rows);
    }

    private function notifications(): DbalNotifications
    {
        return new DbalNotifications($this->connection(), $this->bellCountCache());
    }

    /**
     * The person's bell count as the cache answers it in Acme Foods: what it kept, or $freshCount when it kept none.
     */
    private function cachedBellCountOf(string $userId, int $freshCount): int
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellCountCache()->remember(new UserId($userId), static fn (): int => $freshCount));
    }

    private function bellCountCache(): BellCountCache
    {
        return self::getContainer()->get(BellCountCache::class);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
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
