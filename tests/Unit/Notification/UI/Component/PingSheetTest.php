<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\UI\Component;

use App\Notification\Application\Ping\PingWindow;
use App\Notification\Domain\PingId;
use App\Notification\UI\Component\PingSheet;
use App\Notification\UI\PingedState;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\PingSettingsQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\Ping\InMemoryPingWindow;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

final class PingSheetTest extends TestCase
{
    private const string NOW = '2027-03-15 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c5201';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c5211';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c5212';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c5213';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000c5214';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000c5221';

    private InMemoryPingWindow $pingWindow;

    /** @var list<list<string>> the recipients of each read that reached the window */
    private array $windowReads = [];

    protected function setUp(): void
    {
        $this->pingWindow = new InMemoryPingWindow();
    }

    /**
     * @return iterable<string, array{?string, string}> the Now line and the draft
     */
    public static function nowLinesAndDrafts(): iterable
    {
        yield 'a Now line with its full stop' => ['Lena plans it.', 'Hi Lena, could you look at SHOP-1? Now: Lena plans it.'];
        yield 'a Now line without an end mark' => ['Code review', 'Hi Lena, could you look at SHOP-1? Now: Code review.'];
        yield 'a Now line that asks' => ['Who checks it?', 'Hi Lena, could you look at SHOP-1? Now: Who checks it?'];
        yield 'a Now line with spaces around it' => ["  Omar fixes it. \n", 'Hi Lena, could you look at SHOP-1? Now: Omar fixes it.'];
        yield 'no Now line, a ticket no team owns' => [null, 'Hi Lena, could you look at SHOP-1?'];
        yield 'an empty Now line' => ['  ', 'Hi Lena, could you look at SHOP-1?'];
    }

    #[Test]
    #[DataProvider('nowLinesAndDrafts')]
    public function theDraftQuotesTheNowLineWithOneEndMark(?string $nowText, string $expectedDraft): void
    {
        $sheet = $this->sheetFor('Lena', 4);
        $sheet->nowText = $nowText;

        self::assertSame($expectedDraft, $sheet->draftMessage());
    }

    #[Test]
    public function theNoteNamesTheLimitInHours(): void
    {
        self::assertSame('Lena gets one notification. You can ping again after 8 hours (set by your admin).', $this->sheetFor('Lena', 8)->limitNote());
    }

    #[Test]
    public function theNoteSaysOneHourForALimitOfOne(): void
    {
        self::assertSame('Lena gets one notification. You can ping again after 1 hour (set by your admin).', $this->sheetFor('Lena', 1)->limitNote());
    }

    #[Test]
    public function aPersonPingedWithinTheLimitShowsTheWaitLeftAndEveryRowAsksTheWindowOnce(): void
    {
        $this->takeSlot(self::LENA, 'c5231', 8);
        $clock = new MockClock(self::NOW);
        $clock->modify('+90 minutes');
        $pingedState = $this->pingedState();

        $lenasSheet = $this->mountedSheetFor(self::LENA, [self::OMAR, self::LENA, self::MIA], $pingedState, $clock);
        $miasSheet = $this->mountedSheetFor(self::MIA, [self::OMAR, self::LENA, self::MIA], $pingedState, $clock);

        self::assertTrue($lenasSheet->isShown);
        self::assertSame('again in 7 h', $lenasSheet->againIn);
        self::assertSame(8, $lenasSheet->limitHours);
        self::assertTrue($miasSheet->isShown);
        self::assertNull($miasSheet->againIn);
        self::assertSame(8, $miasSheet->limitHours);
        self::assertSame([[self::OMAR, self::LENA, self::MIA]], $this->windowReads);
    }

    #[Test]
    public function aRowMissingFromThePeopleIsAskedAlongWithThem(): void
    {
        $this->takeSlot(self::RAJ, 'c5232', 4);

        $rajsSheet = $this->mountedSheetFor(self::RAJ, [self::LENA], $this->pingedState(), new MockClock(self::NOW));

        self::assertSame('again in 4 h', $rajsSheet->againIn);
        self::assertSame([[self::LENA, self::RAJ]], $this->windowReads);
    }

    #[Test]
    public function theViewersOwnRowShowsNothingAndAsksNothing(): void
    {
        $ownSheet = $this->mountedSheetFor(self::OMAR, [self::OMAR, self::LENA], $this->pingedState(), new MockClock(self::NOW), isViewer: true);

        self::assertFalse($ownSheet->isShown);
        self::assertSame([], $this->windowReads);
    }

    #[Test]
    public function withNoActorTheRowShowsNothingAndAsksNothing(): void
    {
        $sheet = $this->mountedSheetFor(self::LENA, [self::OMAR, self::LENA], $this->pingedState(), new MockClock(self::NOW), hasActor: false);

        self::assertFalse($sheet->isShown);
        self::assertSame([], $this->windowReads);
    }

    /**
     * The texts read only the props, so the sheet is built without its services.
     */
    private function sheetFor(string $firstName, int $limitHours): PingSheet
    {
        $sheet = new \ReflectionClass(PingSheet::class)->newInstanceWithoutConstructor();
        $sheet->ticketKey = 'SHOP-1';
        $sheet->toFirstName = $firstName;
        $sheet->limitHours = $limitHours;

        return $sheet;
    }

    private function takeSlot(string $toUserId, string $pingSuffix, int $hours): void
    {
        $this->pingWindow->take(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), new UserId($toUserId), new TicketId(self::SHOP_1), new PingId('0197a3c0-0000-7000-8000-0000000'.$pingSuffix), $hours, new \DateTimeImmutable(self::NOW));
    }

    /**
     * As Omar of Acme Foods.
     *
     * @param list<string> $peopleUserIds
     */
    private function mountedSheetFor(string $toUserId, array $peopleUserIds, PingedState $pingedState, MockClock $clock, bool $isViewer = false, bool $hasActor = true): PingSheet
    {
        $currentActor = self::createStub(CurrentActor::class);
        $currentActor->method('actorFor')->willReturn($hasActor ? new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()) : null);

        $sheet = new PingSheet($pingedState, $currentActor, self::createStub(Security::class), $clock);
        $sheet->ticketId = self::SHOP_1;
        $sheet->ticketKey = 'SHOP-1';
        $sheet->toUserId = $toUserId;
        $sheet->toFirstName = 'Lena';
        $sheet->isViewer = $isViewer;
        $sheet->peopleUserIds = $peopleUserIds;
        $sheet->readPingedState();

        return $sheet;
    }

    /**
     * Over the in-memory window, recording the recipients of each read that reaches it, inside an open transaction
     * where Omar's Acme Foods waits 8 hours (any other organization would wait 24).
     */
    private function pingedState(): PingedState
    {
        $pingSettingsQueries = self::createStub(PingSettingsQueries::class);
        $pingSettingsQueries->method('pingLimitHoursOf')->willReturnCallback(static fn (OrganizationId $organizationId): int => self::ACME_FOODS === $organizationId->toString() ? 8 : 24);
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        return new PingedState(new readonly class($this->pingWindow, $this) implements PingWindow {
            public function __construct(private InMemoryPingWindow $window, private PingSheetTest $test)
            {
            }

            public function take(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId, int $hours, \DateTimeImmutable $at): void
            {
                $this->window->take($organizationId, $fromUserId, $toUserId, $ticketId, $pingId, $hours, $at);
            }

            public function release(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId): void
            {
                $this->window->release($organizationId, $fromUserId, $toUserId, $ticketId, $pingId);
            }

            public function heldUntilOf(OrganizationId $organizationId, UserId $fromUserId, array $toUserIds, TicketId $ticketId): array
            {
                $this->test->recordRead($toUserIds);

                return $this->window->heldUntilOf($organizationId, $fromUserId, $toUserIds, $ticketId);
            }
        }, $pingSettingsQueries, self::createStub(Transactions::class), $connection);
    }

    /**
     * @internal for the recording window
     *
     * @param list<UserId> $toUserIds
     */
    public function recordRead(array $toUserIds): void
    {
        $this->windowReads[] = array_map(static fn (UserId $toUserId): string => $toUserId->toString(), $toUserIds);
    }
}
