<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Membership;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineMemberships on the real org_membership table as pinround_app, under row-level security.
 */
final class DoctrineMembershipsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000003a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000003b1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000351';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000352';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000353';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000000355';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000354';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aMembershipIsSavedWithItsRolesAndReadBack(): void
    {
        $joinedAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        [$found, $storedRoles] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($joinedAt): array {
            $this->memberships()->add(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(OrgRole::Manager, OrgRole::Admin), $joinedAt));
            $this->entityManager()->clear();

            return [
                $this->memberships()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA)),
                $this->entityManager()->getConnection()->fetchOne('SELECT org_roles::text FROM org_membership WHERE user_id = ?', [self::LENA]),
            ];
        });

        self::assertInstanceOf(Membership::class, $found);
        self::assertSame(['admin', 'manager'], $found->orgRoles()->values());
        self::assertSame('{admin,manager}', $storedRoles);
        self::assertEquals($joinedAt, $found->joinedAt());
    }

    #[Test]
    public function changedRolesAreSaved(): void
    {
        $this->addMembershipsOfBothOrganizations();

        $roles = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): ?array {
            $sara = $this->memberships()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA));
            self::assertNotNull($sara);
            $sara->changeRoles(new OrgRoles(), $this->memberships()->lockAdminsOf(new OrganizationId(self::ACME_FOODS)));
            $this->memberships()->save($sara);
            $this->entityManager()->clear();

            return $this->memberships()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA))?->orgRoles()->values();
        });

        self::assertSame([], $roles);
    }

    #[Test]
    public function anUnknownMemberOrAMemberOfAnotherOrganizationIsNotFound(): void
    {
        $this->addMembershipsOfBothOrganizations();

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->memberships()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA)),
            $this->memberships()->of(new OrganizationId(self::BETA_BAKERY), new UserId(self::MIA)),
        ]);

        self::assertSame([null, null], $found);
    }

    #[Test]
    public function lockAdminsOfReturnsTheAdminsOfTheTenantOnlyInUserIdOrder(): void
    {
        $this->addMembershipsOfBothOrganizations();

        [$acmeAdmins, $betaAdmins] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            self::userIdsOf($this->memberships()->lockAdminsOf(new OrganizationId(self::ACME_FOODS))),
            self::userIdsOf($this->memberships()->lockAdminsOf(new OrganizationId(self::BETA_BAKERY))),
        ]);

        self::assertSame([self::SARA, self::LENA], $acmeAdmins);
        self::assertSame([], $betaAdmins);
    }

    #[Test]
    public function lockAdminsOfRefreshesAMembershipLoadedBeforeTheLock(): void
    {
        $this->addMembershipsOfBothOrganizations();

        $roles = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $lena = $this->memberships()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA));
            self::assertNotNull($lena);
            $this->entityManager()->getConnection()->executeStatement("UPDATE org_membership SET org_roles = '{admin}' WHERE user_id = ?", [self::LENA]);

            $this->memberships()->lockAdminsOf(new OrganizationId(self::ACME_FOODS));

            return $lena->orgRoles()->values();
        });

        self::assertSame(['admin'], $roles);
    }

    #[Test]
    public function lockAdminsOfRefusesToRunOutsideATransaction(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Locking the admins of an organization needs an open transaction.');
        $this->memberships()->lockAdminsOf(new OrganizationId(self::ACME_FOODS));
    }

    #[Test]
    public function lockAdminsOfHoldsARowLockOnEachAdminAndNoOtherMemberUntilTheTransactionEnds(): void
    {
        $this->addMembershipsOfBothOrganizations();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $otherSession = new CloseOnFailedBeginConnection(
            $this->entityManager()->getConnection()->getParams(),
            new TenantMiddleware($tenantContext)->wrap(new PgSQLDriver()),
        );
        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();
        try {
            $this->memberships()->lockAdminsOf(new OrganizationId(self::ACME_FOODS));

            foreach ([self::SARA, self::LENA] as $admin) {
                try {
                    self::lockRowNowait($otherSession, $admin);
                    self::fail('The admin row of '.$admin.' was not locked.');
                } catch (DriverException $exception) {
                    self::assertSame('55P03', $exception->getSQLState());
                }
            }
            self::assertSame([self::OMAR], self::lockRowNowait($otherSession, self::OMAR));
            self::assertSame([self::RAJ], self::lockRowNowait($otherSession, self::RAJ));
        } finally {
            $connection->rollBack();
        }

        self::assertSame([self::SARA], self::lockRowNowait($otherSession, self::SARA));
        $otherSession->close();
        $this->entityManager()->clear();
        $tenantContext->clear();
    }

    /**
     * @return list<mixed> the user id when the lock was taken
     *
     * @throws DriverException with SQLSTATE 55P03 when another transaction holds the row
     */
    private static function lockRowNowait(Connection $session, string $userId): array
    {
        return $session->transactional(static fn (Connection $session): array => $session->fetchFirstColumn(
            'SELECT user_id FROM org_membership WHERE user_id = ? FOR UPDATE NOWAIT',
            [$userId],
        ));
    }

    /**
     * Acme Foods: Sara admin, Lena admin and manager, Omar no role, Raj manager. Beta Bakery: Mia admin.
     */
    private function addMembershipsOfBothOrganizations(): void
    {
        $joinedAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($joinedAt): void {
            $acmeFoods = new OrganizationId(self::ACME_FOODS);
            $this->memberships()->add(new Membership($acmeFoods, new UserId(self::SARA), new OrgRoles(OrgRole::Admin), $joinedAt));
            $this->memberships()->add(new Membership($acmeFoods, new UserId(self::LENA), new OrgRoles(OrgRole::Admin, OrgRole::Manager), $joinedAt));
            $this->memberships()->add(new Membership($acmeFoods, new UserId(self::OMAR), new OrgRoles(), $joinedAt));
            $this->memberships()->add(new Membership($acmeFoods, new UserId(self::RAJ), new OrgRoles(OrgRole::Manager), $joinedAt));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->memberships()->add(
            new Membership(new OrganizationId(self::BETA_BAKERY), new UserId(self::MIA), new OrgRoles(OrgRole::Admin), $joinedAt),
        ));
    }

    /**
     * @param list<Membership> $memberships
     *
     * @return list<string>
     */
    private static function userIdsOf(array $memberships): array
    {
        return array_map(static fn (Membership $membership): string => $membership->userId()->toString(), $memberships);
    }

    private function memberships(): DoctrineMemberships
    {
        return new DoctrineMemberships($this->entityManager());
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
