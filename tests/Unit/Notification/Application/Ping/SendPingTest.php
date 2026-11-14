<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Ping;

use App\Notification\Api\Event\PingSent;
use App\Notification\Api\PingMessageRefused;
use App\Notification\Api\PingRecipientCannotView;
use App\Notification\Api\PingTooSoon;
use App\Notification\Api\PingToYourself;
use App\Notification\Application\Ping\SendPing;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\PingSettingsQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPosition;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SendPingTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000005101';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000005102';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000005103';
    private const string TICKET_KEY = 'SHOP-12';
    private const string LENA = '0197a3c0-0000-7000-8000-000000005111';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000005112';
    private const string MIA = '0197a3c0-0000-7000-8000-000000005113';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000005114';
    private const int PING_LIMIT_HOURS = 8;

    private InMemoryPingWindow $pingWindow;
    private InMemoryPings $pings;
    private RecordingPingEvents $pingEvents;
    private SendPing $sendPing;

    protected function setUp(): void
    {
        $this->pingWindow = new InMemoryPingWindow();
        $this->pings = new InMemoryPings();
        $this->pingEvents = new RecordingPingEvents();
        // Lena and Omar are in the ticket's team; Mia is a member outside it; Raj left the organization, but a team
        // row of his remains, so only the membership check keeps him out.
        $teamsByUser = [self::LENA => [self::SHOP_TEAM], self::OMAR => [self::SHOP_TEAM], self::RAJ => [self::SHOP_TEAM]];
        $this->sendPing = new SendPing(
            self::ticketQueries(),
            self::ticketVisibilities($teamsByUser),
            self::membershipQueries([self::LENA, self::OMAR, self::MIA]),
            self::pingSettingsQueries(self::PING_LIMIT_HOURS),
            $this->pingWindow,
            $this->pings,
            $this->pingEvents,
        );
    }

    #[Test]
    public function aPingStoresOnePingAndPublishesPingSentAtItsDeliveryTime(): void
    {
        $this->ping(self::LENA, self::OMAR, "  Can you look at this today?\n", self::at('10:00'));

        $stored = $this->pings->stored();
        self::assertCount(1, $stored);
        self::assertSame(
            [self::ACME_FOODS, self::TICKET, self::LENA, self::OMAR, 'Can you look at this today?', null],
            [$stored[0]->organizationId()->toString(), $stored[0]->ticketId()->toString(), $stored[0]->fromId()->toString(), $stored[0]->toId()->toString(), $stored[0]->body(), $stored[0]->deliverAfter()],
        );
        self::assertEquals(self::at('10:00'), $stored[0]->createdAt());
        self::assertEquals(
            [new PingSent(self::ACME_FOODS, self::TICKET, $stored[0]->id()->toString(), self::LENA, self::OMAR, self::LENA, self::at('10:00'))],
            $this->pingEvents->published(),
        );
    }

    #[Test]
    public function aPingToYourselfIsRefusedAndTakesNothing(): void
    {
        $this->assertRefused(PingToYourself::class, 'You cannot ping yourself.', fn () => $this->ping(self::LENA, self::LENA, 'Note to self'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedMessages(): iterable
    {
        yield 'empty' => [''];
        yield 'only whitespace' => [" \t\r\n "];
        yield '501 characters' => [str_repeat('é', 501)];
    }

    #[Test]
    #[DataProvider('refusedMessages')]
    public function aMessageEmptyOrOverFiveHundredCharactersIsRefused(string $body): void
    {
        $this->assertRefused(PingMessageRefused::class, 'Write a message of 1 to 500 characters.', fn () => $this->ping(self::LENA, self::OMAR, $body));
    }

    #[Test]
    public function aMessageOfFiveHundredCharactersAfterTrimmingIsSent(): void
    {
        $this->ping(self::LENA, self::OMAR, '  '.str_repeat('é', 500).'  ');

        self::assertSame([str_repeat('é', 500)], array_map(static fn ($ping): string => $ping->body(), $this->pings->stored()));
    }

    #[Test]
    public function aSenderWhoCannotSeeTheTicketGetsTicketNotFound(): void
    {
        $this->assertRefused(TicketNotFound::class, null, fn () => $this->ping(self::MIA, self::OMAR, 'Any news?'));
    }

    #[Test]
    public function anUnknownTicketKeyGetsTicketNotFound(): void
    {
        $this->assertRefused(TicketNotFound::class, null, fn () => $this->sendPing->ping(new OrganizationId(self::ACME_FOODS), 'SHOP-999', new UserId(self::LENA), new UserId(self::OMAR), 'Any news?', self::at('10:00')));
    }

    #[Test]
    public function aRecipientWhoCannotSeeTheTicketIsRefused(): void
    {
        $this->assertRefused(PingRecipientCannotView::class, 'This person cannot see this ticket.', fn () => $this->ping(self::LENA, self::MIA, 'Any news?'));
    }

    #[Test]
    public function aRecipientWhoIsNotAMemberIsRefusedEvenWithATeamRowLeft(): void
    {
        $this->assertRefused(PingRecipientCannotView::class, 'This person cannot see this ticket.', fn () => $this->ping(self::LENA, self::RAJ, 'Any news?'));
    }

    #[Test]
    public function aSecondPingWithinTheLimitIsRefusedWithWhenTheSlotEnds(): void
    {
        $this->ping(self::LENA, self::OMAR, 'Any news?', self::at('10:00'));

        try {
            $this->ping(self::LENA, self::OMAR, 'Any news now?', self::at('11:30'));
            self::fail('The second ping within the limit was sent.');
        } catch (PingTooSoon $refusal) {
            self::assertEquals(self::at('18:00'), $refusal->againAt);
        }

        self::assertCount(1, $this->pings->stored());
        self::assertCount(1, $this->pingEvents->published());
    }

    #[Test]
    public function aPingAfterTheLimitEndsIsSent(): void
    {
        $this->ping(self::LENA, self::OMAR, 'Any news?', self::at('10:00'));
        $this->ping(self::LENA, self::OMAR, 'Any news now?', self::at('18:00'));

        self::assertCount(2, $this->pings->stored());
    }

    #[Test]
    public function aFailedStoreReleasesTheSlotAndRethrows(): void
    {
        $failure = new \RuntimeException('The database went away.');
        $this->pings->failNextAdd($failure);

        $this->assertSameFailureRethrown($failure, fn () => $this->ping(self::LENA, self::OMAR, 'Any news?', self::at('10:00')));

        self::assertSame([0, [], []], [$this->pingWindow->heldSlotCount(), $this->pings->stored(), $this->pingEvents->published()]);
        $this->ping(self::LENA, self::OMAR, 'Any news?', self::at('10:05'));
        self::assertCount(1, $this->pings->stored());
    }

    #[Test]
    public function aFailedPublishReleasesTheSlotAndRethrows(): void
    {
        $failure = new \LogicException('A ping event is published in the transaction of its change; none is open.');
        $this->pingEvents->failNextPublish($failure);

        $this->assertSameFailureRethrown($failure, fn () => $this->ping(self::LENA, self::OMAR, 'Any news?', self::at('10:00')));

        self::assertSame(0, $this->pingWindow->heldSlotCount());
    }

    #[Test]
    public function aFailedStoreRethrowsItsOwnFailureWhenTheReleaseCannotReachTheStore(): void
    {
        $failure = new \RuntimeException('The database went away.');
        $this->pings->failNextAdd($failure);
        $this->pingWindow->pauseReleases();

        $this->assertSameFailureRethrown($failure, fn () => $this->ping(self::LENA, self::OMAR, 'Any news?', self::at('10:00')));

        self::assertSame([1, []], [$this->pingWindow->heldSlotCount(), $this->pings->stored()]);
    }

    private function ping(string $fromUserId, string $toUserId, string $body, ?\DateTimeImmutable $at = null): void
    {
        $this->sendPing->ping(new OrganizationId(self::ACME_FOODS), self::TICKET_KEY, new UserId($fromUserId), new UserId($toUserId), $body, $at ?? self::at('10:00'));
    }

    /**
     * @param class-string<\DomainException> $refusalClass
     * @param \Closure(): void               $attempt
     */
    private function assertRefused(string $refusalClass, ?string $message, \Closure $attempt): void
    {
        try {
            $attempt();
            self::fail('The ping was sent.');
        } catch (\DomainException $refusal) {
            self::assertSame($refusalClass, $refusal::class);
            if (null !== $message) {
                self::assertSame($message, $refusal->getMessage());
            }
        }

        self::assertSame([[], [], 0], [$this->pings->stored(), $this->pingEvents->published(), $this->pingWindow->heldSlotCount()]);
    }

    /**
     * @param \Closure(): void $attempt
     */
    private function assertSameFailureRethrown(\Throwable $failure, \Closure $attempt): void
    {
        try {
            $attempt();
            self::fail('The failure was swallowed.');
        } catch (\Throwable $thrown) {
            self::assertSame($failure, $thrown);
        }
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 '.$time.':00+00:00');
    }

    private static function ticketQueries(): TicketQueries
    {
        $facts = new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::LENA));
        $page = new TicketPageView(
            new TicketId(self::TICKET),
            self::TICKET_KEY,
            'Checkout button does nothing',
            '',
            'bug',
            'major',
            null,
            null,
            [],
            self::at('09:00'),
            'Shop',
            'In progress',
            'doing',
            'Doing',
            'Lena',
            null,
            $facts,
            new TicketTurnSnapshot(new TicketId(self::TICKET), '0197a3c0-0000-7000-8000-000000005121', '0197a3c0-0000-7000-8000-000000005122', 'doing', 'doing', new TeamId(self::SHOP_TEAM), new UserId(self::LENA), 'Lena', [], [], TicketVisibilityFacts::TEAM_ONLY, 'major', null, new UserId(self::LENA)),
            [],
            1,
        );

        // Like DbalTicketQueries: a ticket the viewer may not see answers null, exactly as an unknown key.
        return new readonly class($page) implements TicketQueries {
            public function __construct(private TicketPageView $page)
            {
            }

            public function list(ListFilter $filter, ?Cursor $cursor = null): array
            {
                throw new \LogicException('SendPing does not list tickets.');
            }

            public function pageOf(string $key, TicketVisibility $viewer): ?TicketPageView
            {
                return $key === $this->page->key && $viewer->isVisible($this->page->visibilityFacts) ? $this->page : null;
            }

            public function positionOf(string $key, ListFilter $filter): ?TicketPosition
            {
                throw new \LogicException('SendPing does not place tickets.');
            }
        };
    }

    /**
     * @param array<string, list<string>> $teamIdsByUserId
     */
    private static function ticketVisibilities(array $teamIdsByUserId): TicketVisibilities
    {
        return new readonly class($teamIdsByUserId) implements TicketVisibilities {
            /**
             * @param array<string, list<string>> $teamIdsByUserId
             */
            public function __construct(private array $teamIdsByUserId)
            {
            }

            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                $teamIds = array_map(static fn (string $teamId): TeamId => new TeamId($teamId), $this->teamIdsByUserId[$viewerId->toString()] ?? []);

                return TicketVisibility::for($organizationId, $viewerId, $teamIds, false, null);
            }
        };
    }

    /**
     * @param list<string> $memberIds
     */
    private static function membershipQueries(array $memberIds): MembershipQueries
    {
        return new readonly class($memberIds) implements MembershipQueries {
            /**
             * @param list<string> $memberIds
             */
            public function __construct(private array $memberIds)
            {
            }

            public function organizationsOf(UserId $userId): array
            {
                throw new \LogicException('SendPing does not list organizations.');
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                throw new \LogicException('SendPing does not read roles.');
            }

            public function membersAmong(OrganizationId $organizationId, array $userIds): array
            {
                $members = array_values(array_filter($userIds, fn (UserId $userId): bool => \in_array($userId->toString(), $this->memberIds, true)));
                usort($members, static fn (UserId $left, UserId $right): int => $left->toString() <=> $right->toString());

                return $members;
            }
        };
    }

    /**
     * @param 1|4|8|24 $pingLimitHours
     */
    private static function pingSettingsQueries(int $pingLimitHours): PingSettingsQueries
    {
        return new readonly class($pingLimitHours) implements PingSettingsQueries {
            /**
             * @param 1|4|8|24 $pingLimitHours
             */
            public function __construct(private int $pingLimitHours)
            {
            }

            public function pingLimitHoursOf(OrganizationId $organizationId): int
            {
                return $this->pingLimitHours;
            }
        };
    }
}
