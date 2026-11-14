<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Api;

use App\Organization\Api\Roles;
use App\SharedKernel\Domain\TeamId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    private const string TEAM_A = '0197a3c0-0000-7000-8000-0000000007a1';
    private const string TEAM_B = '0197a3c0-0000-7000-8000-0000000007a2';
    private const string TEAM_C = '0197a3c0-0000-7000-8000-0000000007a3';
    private const string TEAM_D = '0197a3c0-0000-7000-8000-0000000007a4';

    #[Test]
    public function noRolesIsNeitherAdminNorManagerAndInNoTeam(): void
    {
        $roles = Roles::none();

        self::assertFalse($roles->isAdmin());
        self::assertFalse($roles->isManager());
        self::assertSame(['lead' => false, 'member' => false, 'viewer' => false, 'inTeam' => false], self::teamRoleOf($roles, self::TEAM_A));
        self::assertSame([], $roles->teamIds());
    }

    #[Test]
    public function orgRolesAreReportedEachOnTheirOwn(): void
    {
        $adminOnly = new Roles(true, false, [], [], []);
        $managerOnly = new Roles(false, true, [], [], []);

        self::assertTrue($adminOnly->isAdmin());
        self::assertFalse($adminOnly->isManager());
        self::assertFalse($managerOnly->isAdmin());
        self::assertTrue($managerOnly->isManager());
    }

    #[Test]
    public function eachTeamRoleIsToldApartAndEachIsInTheTeam(): void
    {
        $roles = new Roles(false, false, [new TeamId(self::TEAM_A)], [new TeamId(self::TEAM_B)], [new TeamId(self::TEAM_C)]);

        self::assertSame(['lead' => true, 'member' => false, 'viewer' => false, 'inTeam' => true], self::teamRoleOf($roles, self::TEAM_A));
        self::assertSame(['lead' => false, 'member' => true, 'viewer' => false, 'inTeam' => true], self::teamRoleOf($roles, self::TEAM_B));
        self::assertSame(['lead' => false, 'member' => false, 'viewer' => true, 'inTeam' => true], self::teamRoleOf($roles, self::TEAM_C));
        self::assertSame(['lead' => false, 'member' => false, 'viewer' => false, 'inTeam' => false], self::teamRoleOf($roles, self::TEAM_D));
    }

    #[Test]
    public function teamIdsAreEveryTeamWithARoleInIdOrder(): void
    {
        $roles = new Roles(false, false, [new TeamId(self::TEAM_D)], [new TeamId(self::TEAM_C), new TeamId(self::TEAM_A)], [new TeamId(self::TEAM_B)]);

        self::assertSame(
            [self::TEAM_A, self::TEAM_B, self::TEAM_C, self::TEAM_D],
            array_map(static fn (TeamId $teamId): string => $teamId->toString(), $roles->teamIds()),
        );
    }

    #[Test]
    public function aTeamIdComparesByValueNotByInstance(): void
    {
        $roles = new Roles(false, false, [new TeamId(strtoupper(self::TEAM_A))], [], []);

        self::assertTrue($roles->isLeadOf(new TeamId(self::TEAM_A)));
    }

    #[Test]
    public function teamSlugOfGivesTheSlugOfEachTeamByIdInAnyCase(): void
    {
        $roles = new Roles(false, false, [new TeamId(self::TEAM_A)], [new TeamId(self::TEAM_B)], [], [
            strtoupper(self::TEAM_A) => 'shop',
            self::TEAM_B => 'mobile-app',
        ]);

        self::assertSame('shop', $roles->teamSlugOf(new TeamId(self::TEAM_A)));
        self::assertSame('mobile-app', $roles->teamSlugOf(new TeamId(self::TEAM_B)));
        self::assertNull($roles->teamSlugOf(new TeamId(self::TEAM_C)), 'a team whose slug was not given has none');
    }

    #[Test]
    public function rolesWithoutSlugsHaveNoTeamSlug(): void
    {
        self::assertNull(new Roles(false, false, [new TeamId(self::TEAM_A)], [], [])->teamSlugOf(new TeamId(self::TEAM_A)));
        self::assertNull(Roles::none()->teamSlugOf(new TeamId(self::TEAM_A)));
    }

    #[Test]
    public function anAdminOrManagerMayAskOrPingOnAnyTicketWithoutReportingIt(): void
    {
        $team = new TeamId(self::TEAM_A);

        self::assertTrue(new Roles(true, false, [], [], [])->mayAskOrPingOn($team, false));
        self::assertTrue(new Roles(false, true, [], [], [])->mayAskOrPingOn($team, false));
        self::assertTrue(new Roles(true, false, [], [], [])->mayAskOrPingOn(null, false));
        self::assertTrue(new Roles(false, true, [], [], [])->mayAskOrPingOn(null, false));
        self::assertTrue(new Roles(false, true, [], [], [$team])->mayAskOrPingOn($team, false), 'a Manager who views the team still asks');
    }

    #[Test]
    public function aLeadOrMemberOfTheTicketsTeamMayAskOrPingWithoutReportingIt(): void
    {
        $team = new TeamId(self::TEAM_A);
        $otherTeam = new TeamId(self::TEAM_B);

        self::assertTrue(new Roles(false, false, [$team], [], [])->mayAskOrPingOn($team, false));
        self::assertTrue(new Roles(false, false, [], [$team], [])->mayAskOrPingOn($team, false));
        self::assertFalse(new Roles(false, false, [$otherTeam], [$team], [])->mayAskOrPingOn(new TeamId(self::TEAM_C), false), 'a role in another team is no role in this one');
        self::assertFalse(new Roles(false, false, [$team], [], [])->mayAskOrPingOn(null, false), 'a team role says nothing of a ticket with no team');
    }

    #[Test]
    public function aViewerOfTheTicketsTeamMayNotAskOrPingEvenAsItsReporter(): void
    {
        $team = new TeamId(self::TEAM_A);

        self::assertFalse(new Roles(false, false, [], [], [$team])->mayAskOrPingOn($team, false));
        self::assertFalse(new Roles(false, false, [], [], [$team])->mayAskOrPingOn($team, true));
    }

    #[Test]
    public function someoneWithNoRoleOnTheTicketMayAskOrPingOnlyAsItsReporter(): void
    {
        $team = new TeamId(self::TEAM_A);
        $roleElsewhere = new Roles(false, false, [], [], [new TeamId(self::TEAM_B)]);

        self::assertFalse(Roles::none()->mayAskOrPingOn($team, false));
        self::assertTrue(Roles::none()->mayAskOrPingOn($team, true));
        self::assertFalse(Roles::none()->mayAskOrPingOn(null, false));
        self::assertTrue(Roles::none()->mayAskOrPingOn(null, true));
        self::assertFalse($roleElsewhere->mayAskOrPingOn($team, false));
        self::assertTrue($roleElsewhere->mayAskOrPingOn($team, true));
    }

    #[Test]
    public function aSlugKeyThatIsNotATeamIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Roles(false, false, [], [], [], ['shop' => 'shop']);
    }

    #[Test]
    public function aTeamCannotHaveTwoRoles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A user has one team role per team.');

        new Roles(false, false, [new TeamId(self::TEAM_A)], [], [new TeamId(self::TEAM_A)]);
    }

    #[Test]
    public function aTeamCannotBeGivenTwiceInOneRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Roles(false, false, [], [new TeamId(self::TEAM_A), new TeamId(self::TEAM_A)], []);
    }

    /**
     * @return array{lead: bool, member: bool, viewer: bool, inTeam: bool}
     */
    private static function teamRoleOf(Roles $roles, string $teamId): array
    {
        $team = new TeamId($teamId);

        return ['lead' => $roles->isLeadOf($team), 'member' => $roles->isMemberOf($team), 'viewer' => $roles->isViewerOf($team), 'inTeam' => $roles->isInTeam($team)];
    }
}
