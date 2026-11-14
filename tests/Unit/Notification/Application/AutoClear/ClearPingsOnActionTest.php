<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\AutoClear;

use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason as ClearedReasonOfEvent;
use App\Notification\Application\AutoClear\ClearPingsOnAction;
use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Domain\Ping;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\PingId;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\Ping\InMemoryPings;
use App\Tests\Unit\Notification\Application\Ping\RecordingPingEvents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClearPingsOnActionTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007a02';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000007a03';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000007a04';
    private const string LENA = '0197a3c0-0000-7000-8000-000000007a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000007a12';
    private const string MIA = '0197a3c0-0000-7000-8000-000000007a13';
    // Ids out of the creation order, so clearing by id order would differ from oldest first.
    private const string OMARS_LATER_PING = '0197a3c0-0000-7000-8000-000000007a21';
    private const string OMARS_EARLIER_PING = '0197a3c0-0000-7000-8000-000000007a22';
    private const string MIAS_PING = '0197a3c0-0000-7000-8000-000000007a23';
    private const string OMARS_PING_ON_OTHER_TICKET = '0197a3c0-0000-7000-8000-000000007a24';
    private const string OMARS_DONE_PING = '0197a3c0-0000-7000-8000-000000007a25';
    private const string OMARS_PING_IN_BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007a26';

    private InMemoryPings $pings;
    private InMemoryNotifications $notifications;
    private RecordingPingEvents $pingEvents;

    protected function setUp(): void
    {
        $this->pings = new InMemoryPings();
        $this->pings->actAsTenant(new OrganizationId(self::ACME_FOODS));
        $this->notifications = new InMemoryNotifications();
        $this->pingEvents = new RecordingPingEvents();

        $this->sendPing(self::OMARS_LATER_PING, self::ACME_FOODS, self::TICKET, self::LENA, self::OMAR, '09:40');
        $this->sendPing(self::OMARS_EARLIER_PING, self::ACME_FOODS, self::TICKET, self::MIA, self::OMAR, '09:10');
        $this->sendPing(self::MIAS_PING, self::ACME_FOODS, self::TICKET, self::LENA, self::MIA, '09:20');
        $this->sendPing(self::OMARS_PING_ON_OTHER_TICKET, self::ACME_FOODS, self::OTHER_TICKET, self::LENA, self::OMAR, '09:30');
        $this->sendPing(self::OMARS_DONE_PING, self::ACME_FOODS, self::TICKET, self::LENA, self::OMAR, '08:00')
            ->clear(PingClearedReason::Done, self::at('08:30'));
        $this->sendPing(self::OMARS_PING_IN_BETA_BAKERY, self::BETA_BAKERY, self::TICKET, self::LENA, self::OMAR, '09:00');

        // Bell rows, in this order: Omar's two pings on the ticket, Omar's question on it, Mia's ping, Omar's ping on
        // the other ticket, Omar's ping on the ticket in Beta Bakery.
        $this->addBellRow(self::ACME_FOODS, self::OMAR, NotificationKind::Ping, self::TICKET);
        $this->addBellRow(self::ACME_FOODS, self::OMAR, NotificationKind::Ping, self::TICKET);
        $this->addBellRow(self::ACME_FOODS, self::OMAR, NotificationKind::Question, self::TICKET);
        $this->addBellRow(self::ACME_FOODS, self::MIA, NotificationKind::Ping, self::TICKET);
        $this->addBellRow(self::ACME_FOODS, self::OMAR, NotificationKind::Ping, self::OTHER_TICKET);
        $this->addBellRow(self::BETA_BAKERY, self::OMAR, NotificationKind::Ping, self::TICKET);
    }

    #[Test]
    public function actingClearsOnlyTheActorsOpenPingsOnThatTicketOldestFirst(): void
    {
        $at = self::at('10:00');

        self::assertSame(2, $this->clearPingsOnAction(true)->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), $at));

        self::assertEquals([
            new PingCleared(self::ACME_FOODS, self::TICKET, self::OMARS_EARLIER_PING, self::MIA, self::OMAR, ClearedReasonOfEvent::Acted, self::OMAR, $at),
            new PingCleared(self::ACME_FOODS, self::TICKET, self::OMARS_LATER_PING, self::LENA, self::OMAR, ClearedReasonOfEvent::Acted, self::OMAR, $at),
        ], $this->pingEvents->published());
        self::assertEquals([
            self::OMARS_LATER_PING => [$at, PingClearedReason::Acted],
            self::OMARS_EARLIER_PING => [$at, PingClearedReason::Acted],
            self::MIAS_PING => [null, null],
            self::OMARS_PING_ON_OTHER_TICKET => [null, null],
            self::OMARS_DONE_PING => [self::at('08:30'), PingClearedReason::Done],
            self::OMARS_PING_IN_BETA_BAKERY => [null, null],
        ], $this->clearingsById());
        self::assertSame([$at, $at, null, null, null, null], $this->notifications->doneTimes());
    }

    #[Test]
    public function withAutoClearOffActingClearsNothing(): void
    {
        self::assertSame(0, $this->clearPingsOnAction(false)->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), self::at('10:00')));

        self::assertSame([], $this->pingEvents->published());
        self::assertSame([null, null], [$this->clearingsById()[self::OMARS_LATER_PING][0], $this->clearingsById()[self::OMARS_EARLIER_PING][0]]);
        self::assertSame([null, null, null, null, null, null], $this->notifications->doneTimes());
    }

    #[Test]
    public function aSecondActionClearsNothingMore(): void
    {
        $clearPingsOnAction = $this->clearPingsOnAction(true);
        $clearPingsOnAction->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), self::at('10:00'));

        self::assertSame(0, $clearPingsOnAction->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), self::at('10:05')));

        self::assertCount(2, $this->pingEvents->published());
        self::assertEquals([self::at('10:00'), PingClearedReason::Acted], $this->clearingsById()[self::OMARS_LATER_PING]);
        self::assertEquals([self::at('10:00'), self::at('10:00'), null, null, null, null], $this->notifications->doneTimes());
    }

    /**
     * A ping bell row whose ping is no longer open stays as it is: only clearing a ping finishes its row.
     */
    #[Test]
    public function anActorWithNoOpenPingChangesNoBellRow(): void
    {
        self::assertSame(0, $this->clearPingsOnAction(true)->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::OTHER_TICKET), new UserId(self::MIA), self::at('10:00')));

        self::assertSame([], $this->pingEvents->published());
        self::assertSame([null, null, null, null, null, null], $this->notifications->doneTimes());
    }

    private function clearPingsOnAction(bool $isAutoClearOn): ClearPingsOnAction
    {
        return new ClearPingsOnAction(self::pingSettingsQueries($isAutoClearOn), $this->pings, $this->notifications, $this->pingEvents);
    }

    private function sendPing(string $pingId, string $organizationId, string $ticketId, string $fromId, string $toId, string $time): Ping
    {
        $ping = Ping::send(new PingId($pingId), new OrganizationId($organizationId), new TicketId($ticketId), new UserId($fromId), new UserId($toId), 'Any news?', null, self::at($time));
        $this->pings->add($ping);

        return $ping;
    }

    private function addBellRow(string $organizationId, string $recipientId, NotificationKind $kind, string $ticketId): void
    {
        $this->notifications->add(new NewNotification(new OrganizationId($organizationId), new UserId($recipientId), $kind, new UserId(self::LENA), new TicketId($ticketId), [], self::at('09:00')));
    }

    /**
     * @return array<string, array{?\DateTimeImmutable, ?PingClearedReason}> cleared at and why, by ping id
     */
    private function clearingsById(): array
    {
        $clearings = [];
        foreach ($this->pings->stored() as $ping) {
            $clearings[$ping->id()->toString()] = [$ping->clearedAt(), $ping->clearedReason()];
        }

        return $clearings;
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-03 '.$time.':00+00:00');
    }

    private static function pingSettingsQueries(bool $isAutoClearOn): PingSettingsQueries
    {
        return new readonly class($isAutoClearOn) implements PingSettingsQueries {
            public function __construct(private bool $isAutoClearOn)
            {
            }

            public function pingLimitHoursOf(OrganizationId $organizationId): int
            {
                return 4;
            }

            public function isAutoClearOn(OrganizationId $organizationId): bool
            {
                return $this->isAutoClearOn;
            }

            public function isQuietHoursOn(OrganizationId $organizationId): bool
            {
                return false;
            }
        };
    }
}
