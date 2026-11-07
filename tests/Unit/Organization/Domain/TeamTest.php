<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\AlreadyATeamMember;
use App\Organization\Domain\BlankTeamName;
use App\Organization\Domain\Job;
use App\Organization\Domain\NotATeamMember;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamHasOneLead;
use App\Organization\Domain\TeamMemberView;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\TeamRoleChange;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TeamTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000052';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000053';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000054';

    #[Test]
    public function aNewTeamHasItsLeadAsItsOnlyMemberWithRoleLeadAndTheGivenJob(): void
    {
        $team = $this->shopTeamLedByLena();

        self::assertSame(self::SHOP_TEAM, $team->id()->toString());
        self::assertSame(self::ACME_FOODS, $team->organizationId()->toString());
        self::assertSame('Shop', $team->name());
        self::assertSame(self::LENA, $team->leadUserId()->toString());
        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer]], self::rolesOf($team));
        self::assertEquals(self::createdAt(), $team->createdAt());
        self::assertEquals(self::createdAt(), $team->members()[0]->joinedAt);
    }

    #[Test]
    public function aBlankNameIsRefusedAndANameIsTrimmed(): void
    {
        self::assertSame('Shop', new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), "  Shop\n", new UserId(self::LENA), Job::Developer, self::createdAt())->name());

        $this->expectException(BlankTeamName::class);
        new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), " \t", new UserId(self::LENA), Job::Developer, self::createdAt());
    }

    #[Test]
    public function membersAndViewersAreAddedWithTheirJob(): void
    {
        $team = $this->shopTeamLedByLena();

        $team->addMember(new UserId(self::OMAR), TeamRole::Member, Job::Developer, self::addedAt());
        $team->addMember(new UserId(self::MIA), TeamRole::Viewer, Job::Tester, self::addedAt());

        self::assertSame(
            [[self::LENA, TeamRole::Lead, Job::Developer], [self::OMAR, TeamRole::Member, Job::Developer], [self::MIA, TeamRole::Viewer, Job::Tester]],
            self::rolesOf($team),
        );
        self::assertEquals(self::addedAt(), $team->memberOf(new UserId(self::MIA))?->joinedAt);
    }

    #[Test]
    public function aSecondLeadCannotBeAddedAsAMember(): void
    {
        $team = $this->shopTeamLedByLena();

        try {
            $team->addMember(new UserId(self::OMAR), TeamRole::Lead, Job::Developer, self::addedAt());
            self::fail('A second lead was added.');
        } catch (TeamHasOneLead) {
        }

        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer]], self::rolesOf($team));
    }

    #[Test]
    public function aPersonIsAddedToATeamOnlyOnce(): void
    {
        $team = $this->shopTeamLedByLena();

        $this->expectException(AlreadyATeamMember::class);
        $team->addMember(new UserId(self::LENA), TeamRole::Member, Job::Developer, self::addedAt());
    }

    #[Test]
    public function appointingALeadLeavesExactlyOneLeadAndMakesTheOldLeadAMember(): void
    {
        $team = $this->shopTeamLedByLena();
        $team->addMember(new UserId(self::OMAR), TeamRole::Viewer, Job::Developer, self::addedAt());
        $team->addMember(new UserId(self::MIA), TeamRole::Member, Job::Tester, self::addedAt());

        $changes = $team->appointLead(new UserId(self::OMAR));

        self::assertSame(self::OMAR, $team->leadUserId()->toString());
        self::assertSame(
            [[self::LENA, TeamRole::Member, Job::Developer], [self::OMAR, TeamRole::Lead, Job::Developer], [self::MIA, TeamRole::Member, Job::Tester]],
            self::rolesOf($team),
        );
        self::assertSame([[self::OMAR, TeamRole::Viewer, TeamRole::Lead], [self::LENA, TeamRole::Lead, TeamRole::Member]], self::changesOf(...$changes));
    }

    #[Test]
    public function appointingTheCurrentLeadChangesNothing(): void
    {
        $team = $this->shopTeamLedByLena();

        self::assertSame([], $team->appointLead(new UserId(self::LENA)));
        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer]], self::rolesOf($team));
    }

    #[Test]
    public function onlyAMemberOfTheTeamCanBeAppointedLead(): void
    {
        $team = $this->shopTeamLedByLena();

        try {
            $team->appointLead(new UserId(self::OMAR));
            self::fail('Someone outside the team became its lead.');
        } catch (NotATeamMember) {
        }

        self::assertSame(self::LENA, $team->leadUserId()->toString());
        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer]], self::rolesOf($team));
    }

    #[Test]
    public function aMemberBecomesAViewerAndBackAndEachChangeIsReturned(): void
    {
        $team = $this->shopTeamLedByLena();
        $team->addMember(new UserId(self::OMAR), TeamRole::Member, Job::Developer, self::addedAt());

        $toViewer = $team->changeTeamRole(new UserId(self::OMAR), TeamRole::Viewer);
        self::assertSame(TeamRole::Viewer, $team->memberOf(new UserId(self::OMAR))?->teamRole);
        $toMember = $team->changeTeamRole(new UserId(self::OMAR), TeamRole::Member);

        self::assertSame([[self::OMAR, TeamRole::Member, TeamRole::Viewer], [self::OMAR, TeamRole::Viewer, TeamRole::Member]], self::changesOf($toViewer, $toMember));
        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer], [self::OMAR, TeamRole::Member, Job::Developer]], self::rolesOf($team));
    }

    #[Test]
    public function changingToTheRoleAPersonHasAlreadyChangesNothing(): void
    {
        $team = $this->shopTeamLedByLena();
        $team->addMember(new UserId(self::OMAR), TeamRole::Viewer, Job::Developer, self::addedAt());

        self::assertNull($team->changeTeamRole(new UserId(self::OMAR), TeamRole::Viewer));
    }

    #[Test]
    public function changeTeamRoleCannotMakeASecondLead(): void
    {
        $team = $this->shopTeamLedByLena();
        $team->addMember(new UserId(self::OMAR), TeamRole::Member, Job::Developer, self::addedAt());

        try {
            $team->changeTeamRole(new UserId(self::OMAR), TeamRole::Lead);
            self::fail('A second lead was made.');
        } catch (TeamHasOneLead) {
        }

        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer], [self::OMAR, TeamRole::Member, Job::Developer]], self::rolesOf($team));
    }

    #[Test]
    public function changeTeamRoleCannotLeaveTheTeamWithoutALead(): void
    {
        $team = $this->shopTeamLedByLena();

        try {
            $team->changeTeamRole(new UserId(self::LENA), TeamRole::Member);
            self::fail('The lead stepped down without a new lead.');
        } catch (TeamHasOneLead) {
        }

        self::assertSame(self::LENA, $team->leadUserId()->toString());
        self::assertSame([[self::LENA, TeamRole::Lead, Job::Developer]], self::rolesOf($team));
    }

    #[Test]
    public function changeTeamRoleNeedsAMemberOfTheTeam(): void
    {
        $this->expectException(NotATeamMember::class);

        $this->shopTeamLedByLena()->changeTeamRole(new UserId(self::OMAR), TeamRole::Viewer);
    }

    #[Test]
    public function memberOfFindsAPersonOrNobody(): void
    {
        $team = $this->shopTeamLedByLena();

        self::assertSame(TeamRole::Lead, $team->memberOf(new UserId(self::LENA))?->teamRole);
        self::assertNull($team->memberOf(new UserId(self::OMAR)));
    }

    private static function createdAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    private static function addedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-16 09:30:00 UTC');
    }

    private function shopTeamLedByLena(): Team
    {
        return new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, self::createdAt());
    }

    /**
     * @return list<array{string, TeamRole, Job}>
     */
    private static function rolesOf(Team $team): array
    {
        return array_map(
            static fn (TeamMemberView $member): array => [$member->userId->toString(), $member->teamRole, $member->job],
            $team->members(),
        );
    }

    /**
     * @return list<array{string, TeamRole, TeamRole}>
     */
    private static function changesOf(?TeamRoleChange ...$changes): array
    {
        return array_map(
            static fn (?TeamRoleChange $change): array => null === $change ? throw new \LogicException('No change was returned.') : [$change->userId->toString(), $change->from, $change->to],
            array_values($changes),
        );
    }
}
