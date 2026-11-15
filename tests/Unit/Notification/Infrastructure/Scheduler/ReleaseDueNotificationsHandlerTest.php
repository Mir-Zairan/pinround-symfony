<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Scheduler\ReleaseDueNotifications;
use App\Notification\Infrastructure\Scheduler\ReleaseDueNotificationsHandler;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\AutoClear\InMemoryNotifications;
use App\Tests\Unit\Notification\Application\Realtime\BellRefreshesDouble;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The release at 09:00 UTC on 3 Mar 2027 in Acme Foods. Omar has two rows due (Mia's id sorts after his, but she is
 * added first), Mia one, Omar one due tomorrow and one never held; Beta Bakery holds one for Lena that is also due.
 */
final class ReleaseDueNotificationsHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e7001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e7002';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000e7021';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e7031';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e7032';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e7033';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000e7034';
    private const string NOW = '2027-03-03 09:00:00 UTC';

    private TenantContext $tenantContext;
    private InMemoryNotifications $notifications;
    private BellRefreshesDouble $bellRefreshes;
    private ReleaseDueNotificationsHandler $handler;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->notifications = new InMemoryNotifications();
        foreach ([
            [self::ACME_FOODS, self::MIA, '2027-03-03 08:30:00 UTC'],
            [self::ACME_FOODS, self::OMAR, '2027-03-03 07:00:00 UTC'],
            [self::ACME_FOODS, self::OMAR, self::NOW],
            [self::ACME_FOODS, self::OMAR, '2027-03-04 09:00:00 UTC'],
            [self::ACME_FOODS, self::OMAR, null],
            [self::BETA_BAKERY, self::LENA, '2027-03-03 08:00:00 UTC'],
        ] as [$organizationId, $recipientId, $deliverAfter]) {
            $this->notifications->add(new NewNotification(
                new OrganizationId($organizationId),
                new UserId($recipientId),
                NotificationKind::Ping,
                new UserId(self::RAJ),
                new TicketId(self::TICKET),
                ['actorName' => 'Raj', 'ticketKey' => 'SHOP-1'],
                new \DateTimeImmutable($deliverAfter ?? '2027-03-02 22:00:00 UTC'),
                null === $deliverAfter ? null : new \DateTimeImmutable($deliverAfter),
            ));
        }
        $this->bellRefreshes = new BellRefreshesDouble();
        $this->handler = new ReleaseDueNotificationsHandler($this->notifications, $this->tenantContext, $this->bellRefreshes, new MockClock(new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function itReleasesTheOrganizationsDueRowsAndPushesEachRecipientsBellOnceInUserIdOrder(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        ($this->handler)(new ReleaseDueNotifications(self::ACME_FOODS));

        self::assertEquals([null, null, null, new \DateTimeImmutable('2027-03-04 09:00:00 UTC'), null, new \DateTimeImmutable('2027-03-03 08:00:00 UTC')], $this->notifications->deliveryTimes());
        self::assertEquals(
            [[new RefreshBell(self::ACME_FOODS, self::OMAR, null), null], [new RefreshBell(self::ACME_FOODS, self::MIA, null), null]],
            $this->bellRefreshes->sent,
        );
    }

    #[Test]
    public function aSecondReleaseFindsNothingAndPushesNothing(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        ($this->handler)(new ReleaseDueNotifications(self::ACME_FOODS));
        $this->bellRefreshes->sent = [];

        ($this->handler)(new ReleaseDueNotifications(self::ACME_FOODS));

        self::assertSame([], $this->bellRefreshes->sent);
    }

    #[Test]
    public function aReleaseNamingAnotherOrganizationThanTheOneSetIsRefusedAndReleasesNothing(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $deliveryTimesBefore = $this->notifications->deliveryTimes();

        try {
            ($this->handler)(new ReleaseDueNotifications(self::BETA_BAKERY));
            self::fail('A release of Beta Bakery ran under Acme Foods.');
        } catch (MessageForAnotherTenantRefused $refusal) {
            self::assertSame('A message for another organization cannot be dispatched or handled while this organization is set.', $refusal->getMessage());
        }

        self::assertSame($deliveryTimesBefore, $this->notifications->deliveryTimes());
        self::assertSame([], $this->bellRefreshes->sent);
    }

    #[Test]
    public function aReleaseWithNoOrganizationSetIsRefusedAndReleasesNothing(): void
    {
        $deliveryTimesBefore = $this->notifications->deliveryTimes();
        self::assertCount(5, array_filter($deliveryTimesBefore), 'The rows held before the release.');

        try {
            ($this->handler)(new ReleaseDueNotifications(self::ACME_FOODS));
            self::fail('A release ran with no organization set.');
        } catch (TenantNotSet $refusal) {
            self::assertSame('No organization is set for this unit of work.', $refusal->getMessage());
        }

        self::assertSame($deliveryTimesBefore, $this->notifications->deliveryTimes());
        self::assertSame([], $this->bellRefreshes->sent);
    }
}
