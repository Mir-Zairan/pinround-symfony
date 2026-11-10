<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Job;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamMemberView;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\TeamSlug;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTeams on the real org_team and org_team_member tables as pinround_app, under row-level security. The tests
 * that change a lead commit, because the one-lead constraint is checked at COMMIT.
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
        [$team, $roles, $storedRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->teams()->add($this->shopTeam());
            $this->entityManager()->clear();
            $team = $this->teams()->byId(new TeamId(self::SHOP_TEAM));
            self::assertNotNull($team);

            return [
                $team,
                self::rolesOf($team),
                $this->connection()->fetchAssociative('SELECT organization_id, lead_user_id FROM org_team WHERE id = ?', [self::SHOP_TEAM]),
            ];
        });

        self::assertSame(['Shop', 'shop', self::ACME_FOODS, self::LENA], [$team->name(), $team->slug()->toString(), $team->organizationId()->toString(), $team->leadUserId()->toString()]);
        self::assertSame(['organization_id' => self::ACME_FOODS, 'lead_user_id' => self::LENA], $storedRow);
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), $team->createdAt());
        self::assertSame(
            [[self::LENA, TeamRole::Lead, Job::Developer], [self::OMAR, TeamRole::Member, Job::Developer], [self::MIA, TeamRole::Member, Job::Tester]],
            $roles,
        );
    }

    #[Test]
    public function aNewLeadAndTheDemotedOldLeadAreCommitted(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->teams()->add($this->shopTeam()));

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $team = $this->teams()->lockById(new TeamId(self::SHOP_TEAM));
            self::assertNotNull($team);
            $team->appointLead(new UserId(self::OMAR));
            $team->changeTeamRole(new UserId(self::MIA), TeamRole::Viewer);
            $team->addMember(new UserId(self::RAJ), TeamRole::Viewer, Job::Other, new \DateTimeImmutable('2027-03-16 09:00:00 UTC'));
            $this->teams()->save($team);
        });

        [$leadUserId, $roles] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $team = $this->teams()->byId(new TeamId(self::SHOP_TEAM));
            self::assertNotNull($team);

            return [$team->leadUserId()->toString(), self::rolesOf($team)];
        });
        self::assertSame(self::OMAR, $leadUserId);
        self::assertSame(
            [
                [self::LENA, TeamRole::Member, Job::Developer],
                [self::OMAR, TeamRole::Lead, Job::Developer],
                [self::MIA, TeamRole::Viewer, Job::Tester],
                [self::RAJ, TeamRole::Viewer, Job::Other],
            ],
            $roles,
        );
    }

    #[Test]
    public function aSecondLeadRowIsRefusedAtCommit(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->teams()->add($this->shopTeam()));
        $updatedRowCount = null;

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, function () use (&$updatedRowCount): void {
                $updatedRowCount = $this->connection()->executeStatement(
                    "UPDATE org_team_member SET team_role = 'lead' WHERE user_id = ?",
                    [self::OMAR],
                );
            });
            self::fail('A second lead was committed.');
        } catch (DriverException $exception) {
            self::assertSame(1, $updatedRowCount, 'The UPDATE itself was accepted; only the COMMIT refuses it.');
            self::assertSame('23P01', $exception->getSQLState());
            self::assertStringContainsString('org_team_member_one_lead_excl', $exception->getMessage());
        }

        self::assertSame([self::LENA], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            "SELECT user_id FROM org_team_member WHERE team_role = 'lead'",
        )));
    }

    /**
     * The new lead is promoted before the old one is demoted, so for a moment the team has two leads: the constraint
     * is deferred to COMMIT, by which time only one is left.
     */
    #[Test]
    public function twoLeadsInsideATransactionAreAcceptedWhenOneIsLeftAtCommit(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->teams()->add($this->shopTeam()));

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            self::assertSame(1, $this->connection()->executeStatement("UPDATE org_team_member SET team_role = 'lead' WHERE user_id = ?", [self::OMAR]));
            self::assertSame(1, $this->connection()->executeStatement("UPDATE org_team_member SET team_role = 'member' WHERE user_id = ?", [self::LENA]));
        });

        self::assertSame([self::OMAR], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            "SELECT user_id FROM org_team_member WHERE team_role = 'lead'",
        )));
    }

    #[Test]
    public function lockByIdHoldsTheTeamRowUntilTheTransactionEnds(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->teams()->add($this->shopTeam()));
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $otherSession = new CloseOnFailedBeginConnection(
            $this->connection()->getParams(),
            new TenantMiddleware($tenantContext)->wrap(new PgSQLDriver()),
        );
        $lockTeamRowNowait = static fn (): array => $otherSession->transactional(static fn (Connection $session): array => $session->fetchFirstColumn(
            'SELECT id FROM org_team WHERE id = ? FOR UPDATE NOWAIT',
            [self::SHOP_TEAM],
        ));

        $this->connection()->beginTransaction();
        try {
            self::assertNotNull($this->teams()->lockById(new TeamId(self::SHOP_TEAM)));
            try {
                $lockTeamRowNowait();
                self::fail('The team row was not locked.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $this->connection()->rollBack();
        }

        self::assertSame([self::SHOP_TEAM], $lockTeamRowNowait());
        $otherSession->close();
        $this->entityManager()->clear();
        $tenantContext->clear();
    }

    #[Test]
    public function lockByIdRefusesToRunOutsideATransaction(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Locking a team needs an open transaction.');
        $this->teams()->lockById(new TeamId(self::SHOP_TEAM));
    }

    #[Test]
    public function aSecondTeamWithTheSameNameInAnotherLetterCaseIsRefused(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $this->teams()->add($this->shopTeam());
            $this->teams()->add(new Team(new TeamId(self::OTHER_TEAM), new OrganizationId(self::ACME_FOODS), 'SHOP', new TeamSlug('shop-2'), new UserId(self::MIA), Job::Tester, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        });
    }

    #[Test]
    public function anUnknownTeamIsNotFound(): void
    {
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Team => $this->teams()->byId(new TeamId(self::OTHER_TEAM))));
    }

    private function shopTeam(): Team
    {
        $team = new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new TeamSlug('shop'), new UserId(self::LENA), Job::Developer, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
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
            static fn (TeamMemberView $member): array => [$member->userId->toString(), $member->teamRole, $member->job],
            $team->members(),
        );
        usort($roles, static fn (array $one, array $other): int => $one[0] <=> $other[0]);

        return $roles;
    }

    private function teams(): DoctrineTeams
    {
        return new DoctrineTeams($this->entityManager());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
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
