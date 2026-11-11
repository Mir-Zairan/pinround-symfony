<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Domain\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketVisibilityTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a0a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a0a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a0c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-00000000a0c2';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a0b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a0b2';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a0b3';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a0b5';

    #[Test]
    public function aTicketForEveryoneIsVisibleToAnOutsider(): void
    {
        self::assertTrue(self::outsider()->isVisible(self::ticket('all', self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketIsVisibleInAnyTeamOfTheViewer(): void
    {
        $viewer = self::viewer(self::OMAR, [self::OTHER_TEAM, self::SHOP_TEAM]);

        self::assertTrue($viewer->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketOfAnotherTeamIsHiddenFromATeamMember(): void
    {
        $viewer = self::viewer(self::OMAR, [self::SHOP_TEAM]);

        self::assertFalse($viewer->isVisible(self::restrictedOf(self::OTHER_TEAM)));
    }

    #[Test]
    #[DataProvider('peopleColumns')]
    public function aRestrictedTicketIsVisibleToEachPersonOnIt(string $column): void
    {
        $people = ['reporterId' => self::user(self::MIA), $column => self::user(self::OMAR)];
        $ticket = new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), 'team', self::team(self::SHOP_TEAM), ...$people);

        self::assertTrue(self::outsider()->isVisible($ticket));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleColumns(): iterable
    {
        foreach (['reporterId', 'assigneeId', 'pendingAssigneeId', 'reviewerId', 'testerId', 'waitingOnUserId'] as $column) {
            yield $column => [$column];
        }
    }

    #[Test]
    public function aRestrictedTicketIsVisibleToAnAdminWithoutATeamRole(): void
    {
        $admin = self::viewer(self::OMAR, [], isAdmin: true);

        self::assertTrue($admin->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsVisibleToTheTriager(): void
    {
        $triager = self::viewer(self::OMAR, [], triagerId: self::OMAR);

        self::assertTrue($triager->isVisible(self::ticket('team', null)));
    }

    #[Test]
    public function aRestrictedTicketWithATeamIsHiddenFromTheTriager(): void
    {
        $triager = self::viewer(self::OMAR, [], triagerId: self::OMAR);

        self::assertFalse($triager->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsHiddenFromAnyoneButTheTriager(): void
    {
        self::assertFalse(self::outsider()->isVisible(self::ticket('team', null)));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsHiddenFromEveryoneWhenTheOrganizationHasNoTriager(): void
    {
        $viewer = TicketVisibility::for(new OrganizationId(self::ACME_FOODS), self::user(self::OMAR), [], false, null);

        self::assertSame(
            [false, false, true],
            [$viewer->isTriager(), $viewer->isVisible(self::ticket('team', null)), $viewer->isVisible(self::ticket('all', null))],
        );
    }

    #[Test]
    public function aRestrictedTicketIsHiddenFromAnOutsider(): void
    {
        self::assertFalse(self::outsider()->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aManagerWithoutATeamRoleIsAnOutsider(): void
    {
        $manager = self::viewer(self::OMAR, []);

        self::assertSame(
            [true, false],
            [$manager->isVisible(self::ticket('all', self::SHOP_TEAM)), $manager->isVisible(self::restrictedOf(self::SHOP_TEAM))],
        );
    }

    #[Test]
    public function noTicketOfAnotherOrganizationIsVisibleNotEvenToAnAdminOnIt(): void
    {
        $sameIdsElsewhere = TicketVisibility::for(new OrganizationId(self::BETA_BAKERY), self::user(self::OMAR), [self::team(self::SHOP_TEAM)], true, self::user(self::OMAR));
        $acmeTickets = [
            self::ticket('all', self::SHOP_TEAM),
            self::restrictedOf(self::SHOP_TEAM),
            self::ticket('team', null),
            new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), 'team', self::team(self::OTHER_TEAM), self::user(self::OMAR)),
        ];

        self::assertSame([false, false, false, false], array_map($sameIdsElsewhere->isVisible(...), $acmeTickets));
        self::assertSame([true, true, true, true], array_map(self::viewer(self::OMAR, [self::SHOP_TEAM], true, self::OMAR)->isVisible(...), $acmeTickets));
    }

    #[Test]
    public function theViewerIsTheTriagerOnlyWhenTheIdsMatch(): void
    {
        self::assertSame(
            [true, false],
            [
                self::viewer(self::OMAR, [], triagerId: strtoupper(self::OMAR))->isTriager(),
                self::viewer(self::OMAR, [], triagerId: self::SARA)->isTriager(),
            ],
        );
    }

    #[Test]
    public function aVisibilityOtherThanAllOrTeamIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::ticket('private', null);
    }

    #[Test]
    public function theFactsKnowExactlyTheVisibilitiesOfTheTicket(): void
    {
        self::assertEqualsCanonicalizing(
            array_column(Visibility::cases(), 'value'),
            array_values(new \ReflectionClass(TicketVisibilityFacts::class)->getConstants()),
        );
    }

    #[Test]
    public function theInvolvedPeopleLeaveOutTheEmptyColumns(): void
    {
        $ticket = new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), 'team', null, self::user(self::MIA), testerId: self::user(self::OMAR));

        self::assertSame([self::MIA, self::OMAR], array_map(static fn (UserId $person): string => $person->toString(), $ticket->involvedUserIds()));
    }

    /**
     * Omar of Acme Foods: in another team than SHOP's only, not an admin, not the triager (Sara is).
     */
    private static function outsider(): TicketVisibility
    {
        return self::viewer(self::OMAR, [self::OTHER_TEAM]);
    }

    /**
     * A viewer of Acme Foods.
     *
     * @param list<string> $teamIds
     */
    private static function viewer(string $userId, array $teamIds, bool $isAdmin = false, string $triagerId = self::SARA): TicketVisibility
    {
        return TicketVisibility::for(
            new OrganizationId(self::ACME_FOODS),
            self::user($userId),
            array_map(self::team(...), $teamIds),
            $isAdmin,
            self::user($triagerId),
        );
    }

    /**
     * A ticket of Acme Foods that Mia reported.
     */
    private static function ticket(string $visibility, ?string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), $visibility, null === $teamId ? null : self::team($teamId), self::user(self::MIA));
    }

    /**
     * A restricted ticket of Acme Foods that Mia reported and Lena is assigned to.
     */
    private static function restrictedOf(string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), 'team', self::team($teamId), self::user(self::MIA), self::user(self::LENA));
    }

    private static function user(string $id): UserId
    {
        return new UserId($id);
    }

    private static function team(string $id): TeamId
    {
        return new TeamId($id);
    }
}
