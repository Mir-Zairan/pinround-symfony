<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Digest\DigestItems;
use App\Notification\Application\Notify\NotificationKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Omar's digest through the DigestItems the digest job gets, as pinround_app under row-level security, on 20 March 2027
 * at 10:00 after a digest that covered up to 19 March 09:00. Omar is a member of the Shop team, which owns SHOP. Next to
 * his six listed rows sit the rows the digest must leave out: read, done, still held, under an hour old, made at the
 * previous digest's edge, of the answer and reply kinds, Mia's, Beta Bakery's and one about another team's ticket.
 */
final class DbalDigestItemsTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string SINCE = '2027-03-19 09:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000008901';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000008902';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000008903';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-000000008904';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000008905';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-000000008906';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000008911';
    private const string LENA = '0197a3c0-0000-7000-8000-000000008912';
    private const string MIA = '0197a3c0-0000-7000-8000-000000008913';
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-000000008921';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-000000008922';
    private const string OTHER_TEAM_TICKET = '0197a3c0-0000-7000-8000-000000008923';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-000000008924';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_PROJECT, 12, 'all', null, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::SHOP_PROJECT, 13, 'team', self::SHOP_TEAM, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::OTHER_TEAM_TICKET, self::SHOP_PROJECT, 14, 'team', self::OTHER_TEAM, self::LENA);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA, 3);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * Written out of order; two listed rows share a time, so the id breaks the tie.
     */
    #[Test]
    public function theDigestListsOnlyUnreadPingsAndQuestionsOfVisibleTicketsInItsWindowNewestFirst(): void
    {
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification('8a05', self::MIA, self::FOR_EVERYONE_TICKET, 'question', '2027-03-19 09:00:01+00'),
            self::notification('8a02', self::MIA, self::TEAM_ONLY_TICKET, 'question', '2027-03-19 12:00:00+00'),
            self::notification('8a03', self::LENA, self::TEAM_ONLY_TICKET, 'ping', '2027-03-20 09:00:00+00'),
            self::notification('8a01', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 08:30:00+00'),
            self::notification('8a04', self::MIA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-19 12:00:00+00'),
            self::notification('8a06', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-19 23:00:00+00', ['deliver_after' => '2027-03-20 07:00:00+00']),
            self::notification('8a10', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 07:00:00+00', ['read_at' => '2027-03-20 07:05:00+00']),
            self::notification('8a11', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 07:10:00+00', ['done_at' => '2027-03-20 07:15:00+00']),
            self::notification('8a12', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 07:20:00+00', ['deliver_after' => '2027-03-20 18:00:00+00']),
            self::notification('8a13', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 09:00:01+00'),
            self::notification('8a14', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-19 09:00:00+00'),
            self::notification('8a15', self::LENA, self::FOR_EVERYONE_TICKET, 'answer', '2027-03-20 07:30:00+00'),
            self::notification('8a16', self::LENA, self::FOR_EVERYONE_TICKET, 'reply', '2027-03-20 07:35:00+00'),
            [...self::notification('8a17', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 07:40:00+00'), 'user_id' => self::MIA],
            self::notification('8a18', self::LENA, self::OTHER_TEAM_TICKET, 'question', '2027-03-20 07:50:00+00'),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_notification', [
            [...self::notification('8a19', self::LENA, self::BAKERY_TICKET, 'ping', '2027-03-20 07:55:00+00'), 'organization_id' => self::BETA_BAKERY],
        ]);

        $digest = $this->digestOfOmar(self::SINCE);

        self::assertNotSame([], $digest->items);
        self::assertEquals([
            self::item('Lena', NotificationKind::Ping, 'SHOP-13', null, 'Shop', '2027-03-20 09:00:00+00'),
            self::item('Lena', NotificationKind::Ping, 'SHOP-12', 'Checkout button is hidden', null, '2027-03-20 08:30:00+00'),
            self::item('Lena', NotificationKind::Ping, 'SHOP-12', 'Checkout button is hidden', null, '2027-03-19 23:00:00+00'),
            self::item('Mia', NotificationKind::Ping, 'SHOP-12', 'Checkout button is hidden', null, '2027-03-19 12:00:00+00'),
            self::item('Mia', NotificationKind::Question, 'SHOP-13', null, 'Shop', '2027-03-19 12:00:00+00'),
            self::item('Mia', NotificationKind::Question, 'SHOP-12', 'Checkout button is hidden', null, '2027-03-19 09:00:01+00'),
        ], $digest->items);
        self::assertSame(6, $digest->itemCount);
        self::assertEquals(new \DateTimeImmutable('2027-03-20 09:00:00+00:00'), $digest->coveredUntil);
    }

    /**
     * 25 pings a minute apart: the newest 20 are listed and all 25 counted.
     */
    #[Test]
    public function aDigestListsTheNewestTwentyAndCountsTheRest(): void
    {
        $this->notificationRows()->executeOf(
            self::ACME_FOODS,
            "INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data, created_at)
             SELECT ?, CAST(format('0197a3c0-0000-7000-8000-000000008a%s', to_hex(128 + n)) AS uuid), ?, 'ping', ?, ?,
                 jsonb_build_object('actorName', 'Lena', 'ticketKey', 'SHOP-12'), CAST(? AS timestamptz) + n * interval '1 minute'
             FROM generate_series(1, 25) AS n",
            [self::ACME_FOODS, self::OMAR, self::LENA, self::FOR_EVERYONE_TICKET, '2027-03-19 10:00:00+00'],
        );

        $digest = $this->digestOfOmar(self::SINCE);

        self::assertCount(Digest::MAX_LISTED_ITEMS, $digest->items);
        self::assertSame(25, $digest->itemCount);
        self::assertSame(5, $digest->unlistedCount());
        self::assertEquals(new \DateTimeImmutable('2027-03-19 10:25:00+00:00'), $digest->items[0]->createdAt);
        self::assertEquals(new \DateTimeImmutable('2027-03-19 10:06:00+00:00'), $digest->items[19]->createdAt);
    }

    /**
     * A ping just inside the hour before $now would be listed by a later digest, so no digest made now lists it.
     */
    #[Test]
    public function aDigestWhoseWindowEndsBeforeItStartsIsEmpty(): void
    {
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification('8a01', self::LENA, self::FOR_EVERYONE_TICKET, 'ping', '2027-03-20 08:59:00+00'),
        ]);
        self::assertSame(1, $this->digestOfOmar(self::SINCE)->itemCount);

        foreach (['2027-03-20 09:00:00+00:00', '2027-03-20 09:30:00+00:00'] as $since) {
            $digest = $this->digestOfOmar($since);

            self::assertTrue($digest->isEmpty(), $since);
            self::assertSame([], $digest->items);
            self::assertEquals(new \DateTimeImmutable('2027-03-20 09:00:00+00:00'), $digest->coveredUntil);
        }
    }

    #[Test]
    public function aDigestOutsideATransactionIsRefused(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A digest is read in a transaction under the organization as tenant; none is open.');

        $this->digestItems()->digestOf(new UserId(self::OMAR), new \DateTimeImmutable(self::SINCE), new \DateTimeImmutable(self::NOW));
    }

    private function digestOfOmar(string $since): Digest
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): Digest => $this->digestItems()->digestOf(
            new UserId(self::OMAR),
            new \DateTimeImmutable($since),
            new \DateTimeImmutable(self::NOW),
        ));
    }

    private static function item(string $actorName, NotificationKind $kind, string $ticketKey, ?string $ticketTitle, ?string $teamName, string $createdAt): DigestItem
    {
        return new DigestItem($actorName, $kind, $ticketKey, $ticketTitle, $teamName, new \DateTimeImmutable($createdAt));
    }

    /**
     * @param string                $idSuffix the last four hex digits of the row's id
     * @param array<string, string> $change   the columns that differ from an open, unread one to Omar
     *
     * @return array<string, mixed>
     */
    private static function notification(string $idSuffix, string $actorId, string $ticketId, string $kind, string $createdAt, array $change = []): array
    {
        $names = [self::LENA => 'Lena', self::MIA => 'Mia'];

        return [
            'organization_id' => self::ACME_FOODS,
            'id' => '0197a3c0-0000-7000-8000-00000000'.$idSuffix,
            'user_id' => self::OMAR,
            'kind' => $kind,
            'actor_id' => $actorId,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $names[$actorId]], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            ...$change,
        ];
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::OMAR, self::LENA, self::MIA], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function digestItems(): DigestItems
    {
        $digestItems = self::getContainer()->get('test.notification_digest_items');
        \assert($digestItems instanceof DigestItems);

        return $digestItems;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
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
