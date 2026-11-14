<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\UI;

use App\Notification\Application\Ping\PingWindow;
use App\Notification\Domain\PingId;
use App\Notification\UI\PingedState;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\Ping\InMemoryPingWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingedStateTest extends TestCase
{
    private const string NOW = '2027-03-15 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c5101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c5102';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c5111';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c5112';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c5113';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000c5114';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000c5121';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000c5122';

    private InMemoryPingWindow $pingWindow;

    /** @var list<array{string, string, string, list<string>}> organization, sender, ticket and recipients, per read */
    private array $reads = [];

    protected function setUp(): void
    {
        $this->pingWindow = new InMemoryPingWindow();
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function waits(): iterable
    {
        yield 'under a minute, rounded up' => [59, 'again in 1 min'];
        yield 'exactly a minute' => [60, 'again in 1 min'];
        yield 'a second over a minute' => [61, 'again in 2 min'];
        yield 'a second under an hour' => [3599, 'again in 60 min'];
        yield 'exactly an hour' => [3600, 'again in 1 h'];
        yield 'a second over an hour, rounded up' => [3601, 'again in 2 h'];
        yield 'the whole limit of 24 hours' => [86400, 'again in 24 h'];
        yield 'now' => [0, 'again in 1 min'];
        yield 'already passed' => [-90, 'again in 1 min'];
    }

    #[Test]
    #[DataProvider('waits')]
    public function theWaitIsShownInMinutesUnderAnHourAndInHoursFromAnHourRoundedUp(int $secondsLeft, string $againIn): void
    {
        $at = new \DateTimeImmutable(self::NOW);

        self::assertSame($againIn, PingedState::againInOf($at->modify(\sprintf('%+d seconds', $secondsLeft)), $at));
    }

    #[Test]
    public function onlyTheRecipientsWhoseSlotTheSenderHoldsOnThisTicketAreShownPinged(): void
    {
        $this->takeSlot(self::ACME_FOODS, self::LENA, self::OMAR, self::TICKET, 4);
        $this->takeSlot(self::ACME_FOODS, self::LENA, self::MIA, self::OTHER_TICKET, 4);
        $this->takeSlot(self::ACME_FOODS, self::RAJ, self::MIA, self::TICKET, 4);
        $this->takeSlot(self::BETA_BAKERY, self::LENA, self::MIA, self::TICKET, 4);

        $heldUntilById = $this->pingedState()->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR, self::MIA]));

        self::assertEquals([self::OMAR => new \DateTimeImmutable('2027-03-15 14:00:00+00:00')], $heldUntilById);
    }

    #[Test]
    public function theSameRecipientsInAnyOrderAreReadOncePerRequest(): void
    {
        $this->takeSlot(self::ACME_FOODS, self::LENA, self::OMAR, self::TICKET, 4);
        $pingedState = $this->pingedState();

        $first = $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR, self::MIA]));
        $second = $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::MIA, self::OMAR]));

        self::assertNotSame([], $first);
        self::assertSame($first, $second);
        self::assertSame([[self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR, self::MIA]]], $this->reads);
    }

    #[Test]
    public function anotherOrganizationSenderTicketOrRecipientListIsReadOnItsOwn(): void
    {
        $pingedState = $this->pingedState();

        $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR]));
        $pingedState->heldUntilOf(...$this->ask(self::BETA_BAKERY, self::LENA, self::TICKET, [self::OMAR]));
        $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::RAJ, self::TICKET, [self::OMAR]));
        $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::OTHER_TICKET, [self::OMAR]));
        $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR, self::MIA]));

        self::assertSame([
            [self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR]],
            [self::BETA_BAKERY, self::LENA, self::TICKET, [self::OMAR]],
            [self::ACME_FOODS, self::RAJ, self::TICKET, [self::OMAR]],
            [self::ACME_FOODS, self::LENA, self::OTHER_TICKET, [self::OMAR]],
            [self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR, self::MIA]],
        ], $this->reads);
    }

    #[Test]
    public function afterAResetASlotTakenMeanwhileIsShown(): void
    {
        $pingedState = $this->pingedState();
        $before = $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR]));
        $this->takeSlot(self::ACME_FOODS, self::LENA, self::OMAR, self::TICKET, 8);

        $pingedState->reset();
        $after = $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR]));

        self::assertSame([], $before);
        self::assertEquals([self::OMAR => new \DateTimeImmutable('2027-03-15 18:00:00+00:00')], $after);
        self::assertCount(2, $this->reads);
    }

    #[Test]
    public function whileTheWindowIsPausedNobodyIsShownPingedAndTheWindowIsAskedOnce(): void
    {
        $this->takeSlot(self::ACME_FOODS, self::LENA, self::OMAR, self::TICKET, 4);
        $this->pingWindow->pauseReads();
        $pingedState = $this->pingedState();

        $first = $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR]));
        $second = $pingedState->heldUntilOf(...$this->ask(self::ACME_FOODS, self::LENA, self::TICKET, [self::OMAR]));

        self::assertSame([], $first);
        self::assertSame([], $second);
        self::assertCount(1, $this->reads);
        self::assertSame(1, $this->pingWindow->heldSlotCount());
    }

    private function takeSlot(string $organization, string $from, string $to, string $ticket, int $hours): void
    {
        $this->pingWindow->take(new OrganizationId($organization), new UserId($from), new UserId($to), new TicketId($ticket), new PingId('0197a3c0-0000-7000-8000-0000000c5199'), $hours, new \DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<string> $recipients
     *
     * @return array{OrganizationId, UserId, TicketId, list<UserId>}
     */
    private function ask(string $organization, string $from, string $ticket, array $recipients): array
    {
        return [
            new OrganizationId($organization),
            new UserId($from),
            new TicketId($ticket),
            array_map(static fn (string $recipient): UserId => new UserId($recipient), $recipients),
        ];
    }

    /**
     * Over the in-memory window, recording each read that reaches it.
     */
    private function pingedState(): PingedState
    {
        return new PingedState(new class($this->pingWindow, $this) implements PingWindow {
            public function __construct(private readonly InMemoryPingWindow $window, private readonly PingedStateTest $test)
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
                $this->test->recordRead($organizationId, $fromUserId, $toUserIds, $ticketId);

                return $this->window->heldUntilOf($organizationId, $fromUserId, $toUserIds, $ticketId);
            }
        });
    }

    /**
     * @internal for the recording window
     *
     * @param list<UserId> $toUserIds
     */
    public function recordRead(OrganizationId $organizationId, UserId $fromUserId, array $toUserIds, TicketId $ticketId): void
    {
        $this->reads[] = [
            $organizationId->toString(),
            $fromUserId->toString(),
            $ticketId->toString(),
            array_map(static fn (UserId $toUserId): string => $toUserId->toString(), $toUserIds),
        ];
    }
}
