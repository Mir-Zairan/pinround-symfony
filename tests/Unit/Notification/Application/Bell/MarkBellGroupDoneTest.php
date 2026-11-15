<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Bell;

use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason as ClearedReasonOfEvent;
use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\MarkBellGroupDone;
use App\Notification\Domain\Ping;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\PingId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\Ping\InMemoryPings;
use App\Tests\Unit\Notification\Application\Ping\RecordingPingEvents;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Done on Omar's group from Lena about SHOP-1, next to Mia's group from Lena about the same ticket. Beside the bell
 * rows sit pings on SHOP-1: two open ones from Lena to Omar (one held until exactly now), one from Lena to Omar still
 * held by quiet hours, one from Mia to Omar, one from Lena to Mia, and one from Lena to Omar already cleared. Ticketing
 * finds SHOP-1 visible to Omar only when the test says so.
 */
final class MarkBellGroupDoneTest extends TestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c0001';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c0011';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c0012';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c0013';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000c0021';

    private const string LENAS_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c0031';
    private const string LENAS_PING_TO_OMAR_HELD_UNTIL_NOW = '0197a3c0-0000-7000-8000-0000000c0032';
    private const string LENAS_PING_TO_OMAR_STILL_HELD = '0197a3c0-0000-7000-8000-0000000c0033';
    private const string MIAS_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c0034';
    private const string LENAS_PING_TO_MIA = '0197a3c0-0000-7000-8000-0000000c0035';
    private const string LENAS_CLEARED_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c0036';

    private InMemoryBellInbox $bellInbox;
    private InMemoryPings $pings;
    private RecordingPingEvents $pingEvents;

    protected function setUp(): void
    {
        $this->bellInbox = new InMemoryBellInbox();
        $this->bellInbox->add(self::groupOf(self::OMAR), 'SHOP-1', 2);
        $this->bellInbox->add(self::groupOf(self::MIA), 'SHOP-1', 1);

        $this->pings = new InMemoryPings();
        $this->pings->actAsTenant(new OrganizationId(self::ACME_FOODS));
        $this->sendPing(self::LENAS_PING_TO_OMAR, self::LENA, self::OMAR, null);
        $this->sendPing(self::LENAS_PING_TO_OMAR_HELD_UNTIL_NOW, self::LENA, self::OMAR, self::NOW);
        $this->sendPing(self::LENAS_PING_TO_OMAR_STILL_HELD, self::LENA, self::OMAR, '2027-03-20 10:00:01+00:00');
        $this->sendPing(self::MIAS_PING_TO_OMAR, self::MIA, self::OMAR, null);
        $this->sendPing(self::LENAS_PING_TO_MIA, self::LENA, self::MIA, null);
        $this->sendPing(self::LENAS_CLEARED_PING_TO_OMAR, self::LENA, self::OMAR, null)
            ->clear(PingClearedReason::Acted, new \DateTimeImmutable('2027-03-20 08:00:00+00:00'));

        $this->pingEvents = new RecordingPingEvents();
    }

    #[Test]
    public function doneMarksTheViewersGroupOnATicketTheyMaySeeAndLeavesOthers(): void
    {
        $marked = $this->markDoneAsOmar(sender: self::LENA, isTicketVisible: true);

        self::assertSame(2, $marked);
        self::assertSame(0, $this->bellInbox->openCountOfGroup(self::groupOf(self::OMAR)));
        self::assertSame(1, $this->bellInbox->openCountOfGroup(self::groupOf(self::MIA)));
    }

    #[Test]
    public function doneClearsTheSendersDeliveredPingsToTheViewerAsDoneWithOneEventEach(): void
    {
        $this->markDoneAsOmar(sender: self::LENA, isTicketVisible: true);

        $now = new \DateTimeImmutable(self::NOW);
        self::assertSame([
            self::LENAS_PING_TO_OMAR => ['done', self::NOW],
            self::LENAS_PING_TO_OMAR_HELD_UNTIL_NOW => ['done', self::NOW],
            self::LENAS_PING_TO_OMAR_STILL_HELD => [null, null],
            self::MIAS_PING_TO_OMAR => [null, null],
            self::LENAS_PING_TO_MIA => [null, null],
            self::LENAS_CLEARED_PING_TO_OMAR => ['acted', '2027-03-20 08:00:00+00:00'],
        ], $this->pingStates());
        self::assertEquals([
            new PingCleared(self::ACME_FOODS, self::TICKET, self::LENAS_PING_TO_OMAR, self::LENA, self::OMAR, ClearedReasonOfEvent::Done, self::OMAR, $now),
            new PingCleared(self::ACME_FOODS, self::TICKET, self::LENAS_PING_TO_OMAR_HELD_UNTIL_NOW, self::LENA, self::OMAR, ClearedReasonOfEvent::Done, self::OMAR, $now),
        ], $this->pingEvents->published());
    }

    #[Test]
    public function doneOnATicketTheViewerMayNoLongerSeeMarksAndClearsNothing(): void
    {
        $marked = $this->markDoneAsOmar(sender: self::LENA, isTicketVisible: false);

        self::assertSame(0, $marked);
        self::assertSame(2, $this->bellInbox->openCountOfGroup(self::groupOf(self::OMAR)));
        self::assertSame(1, $this->bellInbox->openCountOfGroup(self::groupOf(self::MIA)));
        $this->assertNoPingCleared();
    }

    #[Test]
    public function doneOnAGroupWithNoOpenNotificationLeavesItsSendersPingsOpen(): void
    {
        // Mia's ping to Omar is open, but his bell has no open notification from Mia about the ticket.
        $marked = $this->markDoneAsOmar(sender: self::MIA, isTicketVisible: true);

        self::assertSame(0, $marked);
        $this->assertNoPingCleared();
    }

    private function markDoneAsOmar(string $sender, bool $isTicketVisible): int
    {
        return new MarkBellGroupDone($this->ticketQueries($isTicketVisible), $this->bellInbox, $this->pings, $this->pingEvents)
            ->markDone(self::omarsVisibility(), new UserId($sender), new TicketId(self::TICKET), new \DateTimeImmutable(self::NOW));
    }

    private function assertNoPingCleared(): void
    {
        self::assertSame([
            self::LENAS_PING_TO_OMAR => [null, null],
            self::LENAS_PING_TO_OMAR_HELD_UNTIL_NOW => [null, null],
            self::LENAS_PING_TO_OMAR_STILL_HELD => [null, null],
            self::MIAS_PING_TO_OMAR => [null, null],
            self::LENAS_PING_TO_MIA => [null, null],
            self::LENAS_CLEARED_PING_TO_OMAR => ['acted', '2027-03-20 08:00:00+00:00'],
        ], $this->pingStates());
        self::assertSame([], $this->pingEvents->published());
    }

    /**
     * @return array<string, array{?string, ?string}> the cleared reason and time of each ping by id
     */
    private function pingStates(): array
    {
        $states = [];
        foreach ($this->pings->stored() as $ping) {
            $states[$ping->id()->toString()] = [$ping->clearedReason()?->value, $ping->clearedAt()?->format('Y-m-d H:i:sP')];
        }

        return $states;
    }

    private function sendPing(string $pingId, string $fromId, string $toId, ?string $deliverAfter): Ping
    {
        $ping = Ping::send(
            new PingId($pingId),
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            new UserId($fromId),
            new UserId($toId),
            'Any news on this one?',
            null === $deliverAfter ? null : new \DateTimeImmutable($deliverAfter),
            new \DateTimeImmutable('2027-03-20 09:00:00+00:00'),
        );
        $this->pings->add($ping);

        return $ping;
    }

    /**
     * Ticketing's visibility check: the ticket ids it gets back, here all or none.
     */
    private function ticketQueries(bool $isTicketVisible): TicketQueries
    {
        $ticketQueries = self::createStub(TicketQueries::class);
        $ticketQueries->method('visibleTicketIdsOf')->willReturnCallback(
            static fn (TicketVisibility $viewer, array $ticketIds): array => $isTicketVisible ? array_values($ticketIds) : [],
        );

        return $ticketQueries;
    }

    private static function omarsVisibility(): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, null);
    }

    private static function groupOf(string $recipientId): BellGroupKey
    {
        return new BellGroupKey(new UserId($recipientId), new UserId(self::LENA), new TicketId(self::TICKET));
    }
}
