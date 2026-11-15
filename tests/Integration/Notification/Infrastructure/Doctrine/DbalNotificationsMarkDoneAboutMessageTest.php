<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\Notification\Infrastructure\Doctrine\DbalNotifications;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalNotifications::markDoneAboutMessage on committed ntf_notification rows, as pinround_app. MESSAGE on SHOP-1 has
 * a question row to Mia held by quiet hours and reply rows to Lena and Omar; next to them sit rows it must leave alone:
 * an answer row about MESSAGE, a question about another message, a question with MESSAGE's id on another ticket, a
 * reply about MESSAGE already done and a ping. Beta Bakery holds the same rows under the same ids. Rows are inserted
 * out of user id order, so the returned order comes from the query. Rows sit in March 2027, whose partition the
 * migration always creates.
 */
final class DbalNotificationsMarkDoneAboutMessageTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e9c01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e9c02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e9c03';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000e9c04';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000e9c05';
    private const string MESSAGE = '0197a3c0-0000-7000-8000-0000000e9c06';
    private const string OTHER_MESSAGE = '0197a3c0-0000-7000-8000-0000000e9c07';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e9c11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e9c12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e9c13';
    private const string NOOR = '0197a3c0-0000-7000-8000-0000000e9c14';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000e9c15';

    private const string HELD_QUESTION_TO_MIA = '0197a3c0-0000-7000-8000-0000000e9d01';
    private const string REPLY_TO_OMAR = '0197a3c0-0000-7000-8000-0000000e9d02';
    private const string REPLY_TO_LENA = '0197a3c0-0000-7000-8000-0000000e9d03';
    private const string SECOND_REPLY_TO_OMAR = '0197a3c0-0000-7000-8000-0000000e9d04';
    private const string ANSWER_TO_OMAR = '0197a3c0-0000-7000-8000-0000000e9d05';
    private const string QUESTION_ABOUT_OTHER_MESSAGE = '0197a3c0-0000-7000-8000-0000000e9d06';
    private const string QUESTION_ON_OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000e9d07';
    private const string DONE_REPLY_TO_NOOR = '0197a3c0-0000-7000-8000-0000000e9d08';
    private const string PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000e9d09';

    private const string EARLIER_DONE_AT = '2027-03-15 11:00:00';
    private const string CLOSED_AT = '2027-03-15 12:30:00 UTC';
    private const string MARKED_DONE_AT = '2027-03-15 12:30:00';

    private ?SecondSession $secondSession = null;

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->ticketRows()->addOf($organizationId, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
            $this->ticketRows()->addOf($organizationId, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
            new NotificationRows($this->migrator(), $this->tenantContext())->addOf($organizationId, 'ntf_notification', [
                self::row($organizationId, self::HELD_QUESTION_TO_MIA, self::MIA, 'question', self::TICKET, self::MESSAGE, deliverAfter: '2027-03-16 08:00:00+00'),
                self::row($organizationId, self::REPLY_TO_OMAR, self::OMAR, 'reply', self::TICKET, self::MESSAGE),
                self::row($organizationId, self::REPLY_TO_LENA, self::LENA, 'reply', self::TICKET, self::MESSAGE),
                self::row($organizationId, self::SECOND_REPLY_TO_OMAR, self::OMAR, 'reply', self::TICKET, self::MESSAGE),
                self::row($organizationId, self::ANSWER_TO_OMAR, self::OMAR, 'answer', self::TICKET, self::MESSAGE),
                self::row($organizationId, self::QUESTION_ABOUT_OTHER_MESSAGE, self::LENA, 'question', self::TICKET, self::OTHER_MESSAGE),
                self::row($organizationId, self::QUESTION_ON_OTHER_TICKET, self::LENA, 'question', self::OTHER_TICKET, self::MESSAGE),
                self::row($organizationId, self::DONE_REPLY_TO_NOOR, self::NOOR, 'reply', self::TICKET, self::MESSAGE, doneAt: self::EARLIER_DONE_AT.'+00'),
                self::row($organizationId, self::PING_TO_OMAR, self::OMAR, 'ping', self::TICKET, null),
            ]);
        }
        self::getContainer()->get('cache.bell_count')->clear();
    }

    protected function tearDown(): void
    {
        $this->secondSession?->entityManager->getConnection()->close();
        $this->secondSession = null;
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get('doctrine')->resetManager();
        parent::tearDown();
    }

    #[Test]
    public function itMarksOnlyTheOpenRowsOfTheKindsAboutTheMessageOnTheTicketHeldOnesIncludedAndReturnsEachRecipientOnceInUserIdOrder(): void
    {
        $recipients = $this->markDoneAboutMessage([NotificationKind::Question, NotificationKind::Reply]);

        self::assertSame([self::LENA, self::OMAR, self::MIA], $recipients);
        self::assertSame([
            self::HELD_QUESTION_TO_MIA => self::MARKED_DONE_AT,
            self::REPLY_TO_OMAR => self::MARKED_DONE_AT,
            self::REPLY_TO_LENA => self::MARKED_DONE_AT,
            self::SECOND_REPLY_TO_OMAR => self::MARKED_DONE_AT,
            self::ANSWER_TO_OMAR => null,
            self::QUESTION_ABOUT_OTHER_MESSAGE => null,
            self::QUESTION_ON_OTHER_TICKET => null,
            self::DONE_REPLY_TO_NOOR => self::EARLIER_DONE_AT,
            self::PING_TO_OMAR => null,
        ], $this->doneAtById(self::ACME_FOODS));
        self::assertSame(self::untouchedDoneAt(), $this->doneAtById(self::BETA_BAKERY));
    }

    #[Test]
    public function onlyTheGivenKindsAreMarked(): void
    {
        $recipients = $this->markDoneAboutMessage([NotificationKind::Question]);

        self::assertSame([self::MIA], $recipients);
        self::assertSame(
            array_replace(self::untouchedDoneAt(), [self::HELD_QUESTION_TO_MIA => self::MARKED_DONE_AT]),
            $this->doneAtById(self::ACME_FOODS),
        );
    }

    #[Test]
    public function aSecondMarkFindsNothingOpenAndKeepsTheFirstDoneTime(): void
    {
        $this->markDoneAboutMessage([NotificationKind::Question, NotificationKind::Reply]);
        $doneAtAfterTheFirst = $this->doneAtById(self::ACME_FOODS);

        $recipients = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->notifications()->markDoneAboutMessage(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::MESSAGE,
            [NotificationKind::Question, NotificationKind::Reply],
            new \DateTimeImmutable('2027-03-15 13:00:00 UTC'),
        ));

        self::assertSame([], $recipients);
        self::assertSame($doneAtAfterTheFirst, $this->doneAtById(self::ACME_FOODS));
    }

    #[Test]
    public function aCommittedMarkDropsTheCachedBellCountOfEachRecipientAndLeavesOthers(): void
    {
        self::assertSame(4, $this->cachedBellCountOf(self::OMAR, 4));
        self::assertSame(1, $this->cachedBellCountOf(self::NOOR, 1));
        self::assertSame(4, $this->cachedBellCountOf(self::OMAR, 99), 'The count was not read from the cache.');

        $this->markDoneAboutMessage([NotificationKind::Question, NotificationKind::Reply]);

        self::assertSame(2, $this->cachedBellCountOf(self::OMAR, 2));
        self::assertSame(1, $this->cachedBellCountOf(self::NOOR, 99));
    }

    #[Test]
    public function aRolledBackMarkKeepsTheRowsOpenAndTheCachedBellCount(): void
    {
        self::assertSame(4, $this->cachedBellCountOf(self::OMAR, 4));

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->notifications()->markDoneAboutMessage(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::MESSAGE,
            [NotificationKind::Question, NotificationKind::Reply],
            new \DateTimeImmutable(self::CLOSED_AT),
        ));

        self::assertSame(4, $this->cachedBellCountOf(self::OMAR, 99));
        self::assertSame(self::untouchedDoneAt(), $this->doneAtById(self::ACME_FOODS));
    }

    #[Test]
    public function markingOutsideATransactionIsRefusedAndLeavesTheRowsOpen(): void
    {
        try {
            $this->notifications()->markDoneAboutMessage(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::MESSAGE, [NotificationKind::Question], new \DateTimeImmutable(self::CLOSED_AT));
            self::fail('Notifications about a message were marked done outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A notification is written in the transaction of the event it is about; none is open.', $refusal->getMessage());
        }

        self::assertSame(self::untouchedDoneAt(), $this->doneAtById(self::ACME_FOODS));
    }

    #[Test]
    public function lockingAboutAMessageOutsideATransactionIsRefused(): void
    {
        $this->expectExceptionObject(new \LogicException('A notification is written in the transaction of the event it is about; none is open.'));
        $this->notifications()->lockAboutMessage(new OrganizationId(self::ACME_FOODS), self::MESSAGE);
    }

    /**
     * The other session is NotifyRecipients halfway: it took the lock about MESSAGE to check the question is open and
     * has not committed. The mark must wait for it, or it could run before that session's row exists and miss it.
     */
    #[Test]
    public function aMarkWaitsWhileAnotherTransactionHoldsTheLockAboutTheMessage(): void
    {
        $failure = $this->markWhileTheSecondSessionHoldsTheLockAbout(self::ACME_FOODS, self::MESSAGE);

        self::assertNotNull($failure);
        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $failure->getSQLState());
        self::assertSame(self::untouchedDoneAt(), $this->doneAtById(self::ACME_FOODS));
    }

    #[Test]
    public function aLockAboutAnotherMessageOrTheSameMessageIdInAnotherOrganizationDoesNotHoldTheMarkUp(): void
    {
        self::assertNull($this->markWhileTheSecondSessionHoldsTheLockAbout(self::ACME_FOODS, self::OTHER_MESSAGE));
        self::assertNull($this->markWhileTheSecondSessionHoldsTheLockAbout(self::BETA_BAKERY, self::MESSAGE));
    }

    /**
     * Marks MESSAGE in Acme Foods, giving up after lock_timeout, while the second session holds lockAboutMessage on
     * ($organizationId, $messageId) in an open transaction; rolls both back.
     */
    private function markWhileTheSecondSessionHoldsTheLockAbout(string $organizationId, string $messageId): ?DriverException
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($organizationId, $messageId): ?DriverException {
            $holder = $this->secondSession()->entityManager->getConnection();
            $holder->beginTransaction();
            try {
                new DbalNotifications($holder, $this->bellCountCache())->lockAboutMessage(new OrganizationId($organizationId), $messageId);
                $this->connection()->executeStatement("SET LOCAL lock_timeout = '300ms'");
                try {
                    $this->notifications()->markDoneAboutMessage(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::MESSAGE, [NotificationKind::Question], new \DateTimeImmutable(self::CLOSED_AT));
                } catch (DriverException $failure) {
                    return $failure;
                }

                return null;
            } finally {
                $holder->rollBack();
            }
        });
    }

    /**
     * @param non-empty-list<NotificationKind> $kinds
     *
     * @return list<string> the returned recipient ids
     */
    private function markDoneAboutMessage(array $kinds): array
    {
        $recipients = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => $this->notifications()->markDoneAboutMessage(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::MESSAGE,
            $kinds,
            new \DateTimeImmutable(self::CLOSED_AT),
        ));

        return array_map(static fn (UserId $recipientId): string => $recipientId->toString(), $recipients);
    }

    /**
     * @return array<string, ?string> each seeded row's UTC done time by id, as setUp leaves them
     */
    private static function untouchedDoneAt(): array
    {
        return [
            self::HELD_QUESTION_TO_MIA => null,
            self::REPLY_TO_OMAR => null,
            self::REPLY_TO_LENA => null,
            self::SECOND_REPLY_TO_OMAR => null,
            self::ANSWER_TO_OMAR => null,
            self::QUESTION_ABOUT_OTHER_MESSAGE => null,
            self::QUESTION_ON_OTHER_TICKET => null,
            self::DONE_REPLY_TO_NOOR => self::EARLIER_DONE_AT,
            self::PING_TO_OMAR => null,
        ];
    }

    /**
     * @return array<string, ?string> the UTC done time by notification id, in id order
     */
    private function doneAtById(string $organizationId): array
    {
        $rows = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT id, to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS done_at FROM ntf_notification ORDER BY id",
        ));
        self::assertNotSame([], $rows);

        $doneAtById = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertTrue(null === $row['done_at'] || \is_string($row['done_at']));
            $doneAtById[$row['id']] = $row['done_at'];
        }

        return $doneAtById;
    }

    /**
     * @return array<string, mixed> an unread row from Raj, by column
     */
    private static function row(string $organizationId, string $notificationId, string $userId, string $kind, string $ticketId, ?string $messageId, ?string $deliverAfter = null, ?string $doneAt = null): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $notificationId,
            'user_id' => $userId,
            'kind' => $kind,
            'actor_id' => self::RAJ,
            'ticket_id' => $ticketId,
            'data' => json_encode(null === $messageId ? ['actorName' => 'Raj', 'ticketKey' => 'SHOP-1'] : ['actorName' => 'Raj', 'ticketKey' => 'SHOP-1', 'messageId' => $messageId], \JSON_THROW_ON_ERROR),
            'deliver_after' => $deliverAfter,
            'done_at' => $doneAt,
            'created_at' => $deliverAfter ?? '2027-03-15 10:00:00+00',
        ];
    }

    /**
     * The person's bell count as the cache answers it in Acme Foods: what it kept, or $freshCount when it kept none.
     */
    private function cachedBellCountOf(string $userId, int $freshCount): int
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellCountCache()->remember(new UserId($userId), static fn (): int => $freshCount));
    }

    private function notifications(): DbalNotifications
    {
        return new DbalNotifications($this->connection(), $this->bellCountCache());
    }

    private function bellCountCache(): BellCountCache
    {
        return self::getContainer()->get(BellCountCache::class);
    }

    private function secondSession(): SecondSession
    {
        return $this->secondSession ??= SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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
        return new TicketRows($this->migrator(), $this->tenantContext());
    }
}
