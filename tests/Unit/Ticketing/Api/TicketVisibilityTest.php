<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketVisibilityTest extends TestCase
{
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a0c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-00000000a0c2';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a0b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a0b2';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a0b3';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a0b5';

    #[Test]
    public function aTicketForEveryoneIsVisibleToAnOutsider(): void
    {
        self::assertTrue(self::outsider()->isVisible(new TicketVisibilityFacts('all', self::team(self::SHOP_TEAM), self::user(self::MIA))));
    }

    #[Test]
    public function aRestrictedTicketIsVisibleInAnyTeamOfTheViewer(): void
    {
        $viewer = TicketVisibility::for(self::user(self::OMAR), [self::team(self::OTHER_TEAM), self::team(self::SHOP_TEAM)], false, self::user(self::SARA));

        self::assertTrue($viewer->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketOfAnotherTeamIsHiddenFromATeamMember(): void
    {
        $viewer = TicketVisibility::for(self::user(self::OMAR), [self::team(self::SHOP_TEAM)], false, self::user(self::SARA));

        self::assertFalse($viewer->isVisible(self::restrictedOf(self::OTHER_TEAM)));
    }

    #[Test]
    #[DataProvider('peopleColumns')]
    public function aRestrictedTicketIsVisibleToEachPersonOnIt(string $column): void
    {
        $people = ['reporterId' => self::user(self::MIA), $column => self::user(self::OMAR)];
        $ticket = new TicketVisibilityFacts('team', self::team(self::SHOP_TEAM), ...$people);

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
        $admin = TicketVisibility::for(self::user(self::OMAR), [], true, self::user(self::SARA));

        self::assertTrue($admin->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsVisibleToTheTriager(): void
    {
        $triager = TicketVisibility::for(self::user(self::OMAR), [], false, self::user(self::OMAR));

        self::assertTrue($triager->isVisible(new TicketVisibilityFacts('team', null, self::user(self::MIA))));
    }

    #[Test]
    public function aRestrictedTicketWithATeamIsHiddenFromTheTriager(): void
    {
        $triager = TicketVisibility::for(self::user(self::OMAR), [], false, self::user(self::OMAR));

        self::assertFalse($triager->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsHiddenFromAnyoneButTheTriager(): void
    {
        self::assertFalse(self::outsider()->isVisible(new TicketVisibilityFacts('team', null, self::user(self::MIA))));
    }

    #[Test]
    public function aRestrictedTicketIsHiddenFromAnOutsider(): void
    {
        self::assertFalse(self::outsider()->isVisible(self::restrictedOf(self::SHOP_TEAM)));
    }

    #[Test]
    public function aManagerWithoutATeamRoleIsAnOutsider(): void
    {
        $manager = TicketVisibility::for(self::user(self::OMAR), [], false, self::user(self::SARA));

        self::assertSame(
            [true, false],
            [$manager->isVisible(new TicketVisibilityFacts('all', self::team(self::SHOP_TEAM), self::user(self::MIA))), $manager->isVisible(self::restrictedOf(self::SHOP_TEAM))],
        );
    }

    #[Test]
    public function theViewerIsTheTriagerOnlyWhenTheIdsMatch(): void
    {
        self::assertSame(
            [true, false],
            [
                TicketVisibility::for(self::user(self::OMAR), [], false, self::user(strtoupper(self::OMAR)))->isTriager(),
                TicketVisibility::for(self::user(self::OMAR), [], false, self::user(self::SARA))->isTriager(),
            ],
        );
    }

    #[Test]
    public function aVisibilityOtherThanAllOrTeamIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TicketVisibilityFacts('private', null, self::user(self::MIA));
    }

    #[Test]
    public function theInvolvedPeopleLeaveOutTheEmptyColumns(): void
    {
        $ticket = new TicketVisibilityFacts('team', null, self::user(self::MIA), testerId: self::user(self::OMAR));

        self::assertSame([self::MIA, self::OMAR], array_map(static fn (UserId $person): string => $person->toString(), $ticket->involvedUserIds()));
    }

    /**
     * Omar: in another team than SHOP's only, not an admin, not the triager (Sara is).
     */
    private static function outsider(): TicketVisibility
    {
        return TicketVisibility::for(self::user(self::OMAR), [self::team(self::OTHER_TEAM)], false, self::user(self::SARA));
    }

    private static function restrictedOf(string $teamId): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts('team', self::team($teamId), self::user(self::MIA), self::user(self::LENA));
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
