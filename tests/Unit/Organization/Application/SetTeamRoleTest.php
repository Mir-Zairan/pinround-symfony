<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\NotAnOrganizationMember;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamHasOneLead;
use App\Organization\Domain\TeamMemberView;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\TeamSlug;
use App\Organization\Domain\UnknownTeam;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetTeamRoleTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000005c1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000552';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000553';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000554';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000556';

    #[Test]
    public function aNewLeadRecordsTheNewLeadAndTheOldLeadBecomingAMember(): void
    {
        $team = self::shopTeam();
        $teams = new InMemoryTeams($team);
        $events = new RecordingOrganizationEvents();

        $this->setTeamRole($teams, self::allMembers(), $events)(new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), TeamRole::Lead, Job::Developer, new UserId(self::SARA), self::now());

        self::assertSame(self::OMAR, $team->leadUserId()->toString());
        self::assertSame([[self::LENA, TeamRole::Member], [self::OMAR, TeamRole::Lead]], self::rolesOf($team));
        self::assertSame(['lockById', 'save'], $teams->calls);
        self::assertEquals(
            [
                new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::OMAR, 'member', 'lead', self::SARA, self::now()),
                new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::LENA, 'lead', 'member', self::SARA, self::now()),
            ],
            $events->published,
        );
    }

    #[Test]
    public function anOrganizationMemberOutsideTheTeamJoinsWithTheRoleAndJob(): void
    {
        $team = self::shopTeam();
        $events = new RecordingOrganizationEvents();

        $this->setTeamRole(new InMemoryTeams($team), self::allMembers(), $events)(new TeamId(self::SHOP_TEAM), new UserId(self::MIA), TeamRole::Viewer, Job::Tester, null, self::now());

        self::assertEquals(new TeamMemberView(new UserId(self::MIA), TeamRole::Viewer, Job::Tester, self::now()), $team->memberOf(new UserId(self::MIA)));
        self::assertEquals([new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::MIA, null, 'viewer', null, self::now())], $events->published);
    }

    #[Test]
    public function anOrganizationMemberOutsideTheTeamCanJoinAsItsLead(): void
    {
        $team = self::shopTeam();
        $events = new RecordingOrganizationEvents();

        $this->setTeamRole(new InMemoryTeams($team), self::allMembers(), $events)(new TeamId(self::SHOP_TEAM), new UserId(self::MIA), TeamRole::Lead, Job::Tester, null, self::now());

        self::assertSame(self::MIA, $team->leadUserId()->toString());
        self::assertEquals(
            [
                new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::MIA, null, 'lead', null, self::now()),
                new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::LENA, 'lead', 'member', null, self::now()),
            ],
            $events->published,
        );
    }

    #[Test]
    public function aMemberMovesToViewerAndOnlyThatIsRecorded(): void
    {
        $team = self::shopTeam();
        $events = new RecordingOrganizationEvents();

        $this->setTeamRole(new InMemoryTeams($team), self::allMembers(), $events)(new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), TeamRole::Viewer, Job::Other, null, self::now());

        self::assertEquals(new TeamMemberView(new UserId(self::OMAR), TeamRole::Viewer, Job::Developer, new \DateTimeImmutable('2027-03-01 09:05:00 UTC')), $team->memberOf(new UserId(self::OMAR)));
        self::assertEquals([new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::OMAR, 'member', 'viewer', null, self::now())], $events->published);
    }

    #[Test]
    public function theRoleThePersonHasAlreadySavesAndRecordsNothing(): void
    {
        $teams = new InMemoryTeams(self::shopTeam());
        $events = new RecordingOrganizationEvents();

        $this->setTeamRole($teams, self::allMembers(), $events)(new TeamId(self::SHOP_TEAM), new UserId(self::LENA), TeamRole::Lead, Job::Developer, null, self::now());

        self::assertSame(['lockById'], $teams->calls);
        self::assertSame([], $events->published);
    }

    #[Test]
    public function aUserWhoIsNoMemberOfTheOrganizationCannotJoinTheTeam(): void
    {
        $team = self::shopTeam();
        $teams = new InMemoryTeams($team);
        $events = new RecordingOrganizationEvents();
        $membersWithoutMia = new InMemoryMemberships(self::member(self::LENA), self::member(self::OMAR));

        try {
            $this->setTeamRole($teams, $membersWithoutMia, $events)(new TeamId(self::SHOP_TEAM), new UserId(self::MIA), TeamRole::Member, Job::Tester, null, self::now());
            self::fail('A user outside the organization joined the team.');
        } catch (NotAnOrganizationMember $refused) {
            self::assertSame('Only a member of the organization can join a team.', $refused->getMessage());
        }

        self::assertNull($team->memberOf(new UserId(self::MIA)));
        self::assertSame(['lockById'], $teams->calls);
        self::assertSame([], $events->published);
    }

    #[Test]
    public function theLeadCannotStepDownWithoutANewLead(): void
    {
        $events = new RecordingOrganizationEvents();

        $this->expectException(TeamHasOneLead::class);
        try {
            $this->setTeamRole(new InMemoryTeams(self::shopTeam()), self::allMembers(), $events)(new TeamId(self::SHOP_TEAM), new UserId(self::LENA), TeamRole::Member, Job::Developer, null, self::now());
        } finally {
            self::assertSame([], $events->published);
        }
    }

    #[Test]
    public function anUnknownTeamIsRefused(): void
    {
        $this->expectException(UnknownTeam::class);

        $this->setTeamRole(new InMemoryTeams(), self::allMembers(), new RecordingOrganizationEvents())(new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), TeamRole::Member, Job::Developer, null, self::now());
    }

    private function setTeamRole(InMemoryTeams $teams, InMemoryMemberships $memberships, RecordingOrganizationEvents $events): SetTeamRole
    {
        return new SetTeamRole($teams, $memberships, $events);
    }

    /** Lena leads, Omar is a member; Mia is in the organization but not in the team. */
    private static function shopTeam(): Team
    {
        $team = new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new TeamSlug('shop'), new UserId(self::LENA), Job::Developer, new \DateTimeImmutable('2027-03-01 09:00:00 UTC'));
        $team->addMember(new UserId(self::OMAR), TeamRole::Member, Job::Developer, new \DateTimeImmutable('2027-03-01 09:05:00 UTC'));

        return $team;
    }

    private static function allMembers(): InMemoryMemberships
    {
        return new InMemoryMemberships(self::member(self::LENA), self::member(self::OMAR), self::member(self::MIA));
    }

    private static function member(string $userId): Membership
    {
        return new Membership(new OrganizationId(self::ACME_FOODS), new UserId($userId), new OrgRoles(), new \DateTimeImmutable('2027-03-01 09:00:00 UTC'));
    }

    /**
     * @return list<array{string, TeamRole}>
     */
    private static function rolesOf(Team $team): array
    {
        return array_map(static fn (TeamMemberView $member): array => [$member->userId->toString(), $member->teamRole], $team->members());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }
}
