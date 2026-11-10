<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\Event\OrganizationEvent;
use App\Organization\Application\OrganizationEvents;
use App\Organization\Application\SetOrgRoles;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\OrganizationNeedsAnAdmin;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\TeamSlug;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SetOrgRoles and SetTeamRole run by two sessions at once, as two requests would: the app's entity manager holds its
 * change open while a second entity manager on its own connection tries the opposite change. The second waits on the
 * first one's row locks (lock_timeout ends the wait here, as PHP cannot wait in two places), and once the first
 * commits, it sees the committed rows: the organization keeps an admin and the team one lead.
 */
final class ConcurrentRoleChangesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000006a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000006c1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000652';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000653';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000654';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000656';

    private ?EntityManagerInterface $otherSession = null;

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $memberships = new DoctrineMemberships($this->entityManager());
            $joinedAt = new \DateTimeImmutable('2027-03-01 09:00:00 UTC');
            $memberships->add(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(OrgRole::Admin), $joinedAt));
            $memberships->add(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new OrgRoles(OrgRole::Admin), $joinedAt));
            $memberships->add(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), new OrgRoles(), $joinedAt));
            $memberships->add(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), new OrgRoles(), $joinedAt));
            $team = new Team(new TeamId(self::SHOP_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop', new TeamSlug('shop'), new UserId(self::LENA), Job::Developer, $joinedAt);
            $team->addMember(new UserId(self::OMAR), TeamRole::Member, Job::Developer, $joinedAt);
            $team->addMember(new UserId(self::MIA), TeamRole::Member, Job::Tester, $joinedAt);
            new DoctrineTeams($this->entityManager())->add($team);
        });
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
    }

    protected function tearDown(): void
    {
        $this->otherSession?->getConnection()->close();
        $this->otherSession = null;
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function twoAdminsRemovingEachOthersAdminRoleAtOnceLeaveOneAdmin(): void
    {
        $setOrgRoles = new SetOrgRoles(new DoctrineMemberships($this->entityManager()), self::discardedEvents());
        $secondSession = $this->secondSession();
        $otherSession = $secondSession->entityManager;
        $otherSetOrgRoles = new SetOrgRoles(new DoctrineMemberships($otherSession), self::discardedEvents());
        $saraRemovesLena = static fn () => $otherSetOrgRoles(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(), new UserId(self::SARA), self::now());

        $this->connection()->beginTransaction();
        $setOrgRoles(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new OrgRoles(), new UserId(self::LENA), self::now());

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $secondSession->sqlStateOfFailedAttempt($saraRemovesLena), 'The second change did not wait for the first.');

        $this->connection()->commit();
        $otherSession->getConnection()->beginTransaction();
        try {
            $saraRemovesLena();
            self::fail('The second change removed the last admin.');
        } catch (OrganizationNeedsAnAdmin) {
        } finally {
            $otherSession->getConnection()->rollBack();
        }

        self::assertSame([self::LENA], $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchFirstColumn(
            "SELECT user_id FROM org_membership WHERE 'admin' = ANY (org_roles)",
        )));
    }

    #[Test]
    public function twoLeadAppointmentsAtOnceLeaveOneLead(): void
    {
        $setTeamRole = new SetTeamRole(new DoctrineTeams($this->entityManager()), new DoctrineMemberships($this->entityManager()), self::discardedEvents());
        $secondSession = $this->secondSession();
        $otherSession = $secondSession->entityManager;
        $otherSetTeamRole = new SetTeamRole(new DoctrineTeams($otherSession), new DoctrineMemberships($otherSession), self::discardedEvents());
        $appointMia = static fn () => $otherSetTeamRole(new TeamId(self::SHOP_TEAM), new UserId(self::MIA), TeamRole::Lead, Job::Tester, new UserId(self::SARA), self::now());

        $this->connection()->beginTransaction();
        $setTeamRole(new TeamId(self::SHOP_TEAM), new UserId(self::OMAR), TeamRole::Lead, Job::Developer, new UserId(self::LENA), self::now());

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $secondSession->sqlStateOfFailedAttempt($appointMia), 'The second appointment did not wait for the first.');

        $this->connection()->commit();
        $otherSession->getConnection()->transactional(static fn () => $appointMia());

        self::assertSame(
            [[self::LENA, 'member'], [self::OMAR, 'member'], [self::MIA, 'lead']],
            $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchAllNumeric(
                'SELECT user_id, team_role FROM org_team_member WHERE team_id = ? ORDER BY user_id',
                [self::SHOP_TEAM],
            )),
        );
        self::assertSame([self::MIA], $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchFirstColumn(
            'SELECT lead_user_id FROM org_team WHERE id = ?',
            [self::SHOP_TEAM],
        )));
    }

    private function secondSession(): SecondSession
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSession = $secondSession->entityManager;

        return $secondSession;
    }

    private static function discardedEvents(): OrganizationEvents
    {
        return new class implements OrganizationEvents {
            public function publish(OrganizationEvent $event): void
            {
            }
        };
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
