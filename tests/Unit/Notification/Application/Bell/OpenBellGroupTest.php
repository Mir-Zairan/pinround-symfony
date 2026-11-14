<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Bell;

use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\OpenBellGroup;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Open on Omar's group from Lena about SHOP-1, next to Mia's group from Lena about the same ticket. Ticketing finds
 * SHOP-1 visible to Omar only when the test says so.
 */
final class OpenBellGroupTest extends TestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c0001';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c0011';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c0012';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c0013';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000c0021';

    private InMemoryBellInbox $bellInbox;

    protected function setUp(): void
    {
        $this->bellInbox = new InMemoryBellInbox();
        $this->bellInbox->add(self::groupOf(self::OMAR), 'SHOP-1', 2);
        $this->bellInbox->add(self::groupOf(self::MIA), 'SHOP-1', 1);
    }

    #[Test]
    public function openMarksTheViewersGroupReadOnATicketTheyMaySeeAndGivesItsKey(): void
    {
        $ticketKey = $this->openBellGroup(isTicketVisible: true)->open(self::omarsVisibility(), new UserId(self::LENA), new TicketId(self::TICKET), new \DateTimeImmutable(self::NOW));

        self::assertSame('SHOP-1', $ticketKey);
        self::assertSame(2, $this->bellInbox->readCountOfGroup(self::groupOf(self::OMAR)));
        self::assertSame(0, $this->bellInbox->readCountOfGroup(self::groupOf(self::MIA)));
    }

    #[Test]
    public function openOnATicketTheViewerMayNoLongerSeeGivesNoKeyAndMarksNothing(): void
    {
        $ticketKey = $this->openBellGroup(isTicketVisible: false)->open(self::omarsVisibility(), new UserId(self::LENA), new TicketId(self::TICKET), new \DateTimeImmutable(self::NOW));

        self::assertNull($ticketKey);
        self::assertSame(0, $this->bellInbox->readCountOfGroup(self::groupOf(self::OMAR)));
        self::assertSame(2, $this->bellInbox->openCountOfGroup(self::groupOf(self::OMAR)));
    }

    private function openBellGroup(bool $isTicketVisible): OpenBellGroup
    {
        return new OpenBellGroup($this->ticketQueries($isTicketVisible), $this->bellInbox);
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
