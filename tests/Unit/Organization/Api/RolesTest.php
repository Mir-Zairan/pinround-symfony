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

    #[Test]
    public function noRolesIsNeitherAdminNorManagerAndInNoTeam(): void
    {
        $roles = Roles::none();

        self::assertFalse($roles->isAdmin());
        self::assertFalse($roles->isManager());
        self::assertFalse($roles->isInTeam(new TeamId(self::TEAM_A)));
        self::assertFalse($roles->isLeadOf(new TeamId(self::TEAM_A)));
        self::assertSame([], $roles->teamIds());
    }

    #[Test]
    public function orgRolesAreReportedEachOnTheirOwn(): void
    {
        $adminOnly = new Roles(true, false, [], []);
        $managerOnly = new Roles(false, true, [], []);

        self::assertTrue($adminOnly->isAdmin());
        self::assertFalse($adminOnly->isManager());
        self::assertFalse($managerOnly->isAdmin());
        self::assertTrue($managerOnly->isManager());
    }

    #[Test]
    public function aLeadIsInTheTeamAndAnyOtherTeamRoleIsInTheTeamWithoutLeadingIt(): void
    {
        $roles = new Roles(false, false, [new TeamId(self::TEAM_A), new TeamId(self::TEAM_B)], [new TeamId(self::TEAM_A)]);

        self::assertTrue($roles->isLeadOf(new TeamId(self::TEAM_A)));
        self::assertTrue($roles->isInTeam(new TeamId(self::TEAM_A)));
        self::assertFalse($roles->isLeadOf(new TeamId(self::TEAM_B)));
        self::assertTrue($roles->isInTeam(new TeamId(self::TEAM_B)));
        self::assertFalse($roles->isLeadOf(new TeamId(self::TEAM_C)));
        self::assertFalse($roles->isInTeam(new TeamId(self::TEAM_C)));
    }

    #[Test]
    public function teamIdsKeepTheGivenOrder(): void
    {
        $roles = new Roles(false, false, [new TeamId(self::TEAM_B), new TeamId(self::TEAM_A)], []);

        self::assertSame(
            [self::TEAM_B, self::TEAM_A],
            array_map(static fn (TeamId $teamId): string => $teamId->toString(), $roles->teamIds()),
        );
    }

    #[Test]
    public function aTeamIdComparesByValueNotByInstance(): void
    {
        $roles = new Roles(false, false, [new TeamId(strtoupper(self::TEAM_A))], [new TeamId(self::TEAM_A)]);

        self::assertTrue($roles->isLeadOf(new TeamId(self::TEAM_A)));
    }

    #[Test]
    public function aLedTeamMustBeOneOfTheUsersTeams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A team the user leads must be one of their teams.');

        new Roles(false, false, [new TeamId(self::TEAM_A)], [new TeamId(self::TEAM_B)]);
    }
}
