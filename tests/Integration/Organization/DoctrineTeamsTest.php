<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Job;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamMember;
use App\Organization\Domain\TeamRole;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTeams on the real org_team and org_team_member tables as pinround_app, under row-level security.
 */
final class DoctrineTeamsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000004a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000004c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000004c2';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000452';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000453';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000454';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000000455';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function aTeamIsSavedWithItsMembersAndReadBack(): void
    {
        [$team, $roles, $storedLead] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->teams()->add($this->shopTeam());
            $this->entityManager()->clear();
            $team = $this->teams()->byId(new TeamId(self::SHOP_TEAM));
            self::assertNotNull($team);

            return [
                $team,
                self::rolesOf($team),
                $this->entityManager()->getConnection()->fetchOne('SELECT lead_user_id FROM org_team WHERE id = ?', [self::SHOP_TEAM]),
            ];
        });

        self::assertSame(['Shop', self::ACME_FOODS, self::LENA], [$team->name(), $team->organizationId()->toString(), $team->leadUserId()->toString()]);
        self::assertSame(self::LENA, $storedLead);
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), $team->createdAt());
        self::assertSame(
            [[self::LENA, TeamRole::Lead, Job::Developer], [self::OMAR, TeamRole::Member, Job::Developer], [self::MIA, TeamRole::Member, Job::Tester]],
            $roles,
        );
    }

    #[Test]
    public function aNewLeadAndTheDemotedOldLeadAreSaved(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->teams()->add($this->shopTeam()));

        [$leadUserId, $roles] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $team = $this->teams()->byId(new TeamId(self::SHOP_TEAM));
            self::assertNotNull($team);
            $team->appointLead(new UserId(self::OMAR));
            $team->addMember(new UserId(self::RAJ), TeamRole::Viewer, Job::Other, new \DateTimeImmutable('2027-03-16 09:00:00 UTC'));
            $this->teams()->save($team);
            $this->entityManager()->clear();
            $savedTeam = $this->teams()->byId(new TeamId(self::SHOP_TEAM));
            self::assertNotNull($savedTeam);

            return [$savedTeam->leadUserId()->toString(), self::rolesOf($savedTeam)];
        });

        self::assertSame(self::OMAR, $leadUserId);
        self::assertSame(
            [
                [self::LENA, TeamRole::Member, Job::Developer],
                [self::OMAR, TeamRole::Lead, Job::Developer],
                [self::MIA, TeamRole::Member, Job::Tester],
                [self::RAJ, TeamRole::Viewer, Job::Other],
            ],
            $roles,
        );
    }

    #[Test]
    public function aSecondTeamWithTheSameNameInAnotherLetterCaseIsRefused(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $this->teams()->add($this->shopTeam());
            $this->teams()->add(new Team(new TeamId(self::OTHER_TEAM), new OrganizationId(self::ACME_FOODS), 'SHOP', new UserId(self::MIA), Job::Tester, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        });
    }

    #[Test]
    public function anUnknownTeamIsNotFound(): void
    {
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Team => $this->teams()->byId(new TeamId(self::OTHER_TEAM))));
    }

    private function shopTeam(): Team
    {
        $team = new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $team->addMember(new UserId(self::OMAR), TeamRole::Member, Job::Developer, new \DateTimeImmutable('2027-03-15 10:05:00 UTC'));
        $team->addMember(new UserId(self::MIA), TeamRole::Member, Job::Tester, new \DateTimeImmutable('2027-03-15 10:06:00 UTC'));

        return $team;
    }

    /**
     * Reads the members, so call it inside the transaction: the team loads them on first use.
     *
     * @return list<array{string, TeamRole, Job}> by user id, as a loaded team keeps no join order
     */
    private static function rolesOf(Team $team): array
    {
        $roles = array_map(
            static fn (TeamMember $member): array => [$member->userId()->toString(), $member->teamRole(), $member->job()],
            $team->members(),
        );
        usort($roles, static fn (array $one, array $other): int => $one[0] <=> $other[0]);

        return $roles;
    }

    private function teams(): DoctrineTeams
    {
        return new DoctrineTeams($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
