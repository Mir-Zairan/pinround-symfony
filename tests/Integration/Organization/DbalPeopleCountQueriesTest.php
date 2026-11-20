<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Infrastructure\Doctrine\DbalPeopleCountQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * DbalPeopleCountQueries as pinround_app on committed org_membership and org_invitation rows, in a transaction under a
 * tenant, as inside a request. The clock stands in 2027, after the wall clock, so a count that ignored it would count
 * the expired invitation.
 */
final class DbalPeopleCountQueriesTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 12:00:00 UTC';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000fa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000fb1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000fc1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000fc2';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000fc3';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000000fc4';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS, self::LENA, '{}');
            $this->addMembership(self::ACME_FOODS, self::OMAR, '{}');
            $this->addMembership(self::ACME_FOODS, self::SARA, '{admin}');
            $this->addInvitation(self::ACME_FOODS, '0197a3c0-0000-7000-8000-000000000fd1', 'mia@acme-foods.example', '2027-03-23 11:00:00', null);
            $this->addInvitation(self::ACME_FOODS, '0197a3c0-0000-7000-8000-000000000fd2', 'noor@acme-foods.example', '2027-03-20 12:00:01', null);
            // Accepted (its person is a member already), expired, and expiring at now: none of them counts.
            $this->addInvitation(self::ACME_FOODS, '0197a3c0-0000-7000-8000-000000000fd3', 'omar@acme-foods.example', '2027-03-23 11:00:00', '2027-03-19 11:00:00');
            $this->addInvitation(self::ACME_FOODS, '0197a3c0-0000-7000-8000-000000000fd4', 'ben@acme-foods.example', '2027-03-19 11:00:00', null);
            $this->addInvitation(self::ACME_FOODS, '0197a3c0-0000-7000-8000-000000000fd5', 'eva@acme-foods.example', '2027-03-20 12:00:00', null);
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addMembership(self::BETA_BAKERY, self::RAJ, '{admin}');
            $this->addMembership(self::BETA_BAKERY, self::LENA, '{}');
            $this->addInvitation(self::BETA_BAKERY, '0197a3c0-0000-7000-8000-000000000fd6', 'mia@acme-foods.example', '2027-03-23 11:00:00', null);
        });
    }

    protected function tearDown(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theCountIsTheMembersPlusTheInvitationsStillOpenAtTheClocksNow(): void
    {
        self::assertSame(3 + 2, $this->peopleCountOf(self::ACME_FOODS, self::ACME_FOODS, self::NOW));
        // A second earlier, the invitation that expires at now is still open; a day later, only the members are left.
        self::assertSame(3 + 3, $this->peopleCountOf(self::ACME_FOODS, self::ACME_FOODS, '2027-03-20 11:59:59 UTC'));
        self::assertSame(3, $this->peopleCountOf(self::ACME_FOODS, self::ACME_FOODS, '2027-03-24 12:00:00 UTC'));
    }

    #[Test]
    public function anotherOrganizationsPeopleAreNeverCounted(): void
    {
        self::assertSame(2 + 1, $this->peopleCountOf(self::BETA_BAKERY, self::BETA_BAKERY, self::NOW));
        self::assertSame(0, $this->peopleCountOf(self::ACME_FOODS, self::BETA_BAKERY, self::NOW), 'row-level security hides the asked organization');
        self::assertSame(0, $this->peopleCountOf(self::BETA_BAKERY, self::ACME_FOODS, self::NOW));
    }

    private function peopleCountOf(string $tenantId, string $askedId, string $now): int
    {
        $queries = new DbalPeopleCountQueries($this->connection(), new MockClock($now));

        return $this->transactions()->rolledBackAs($tenantId, static fn (): int => $queries->peopleCountOf(new OrganizationId($askedId)));
    }

    private function addMembership(string $organizationId, string $userId, string $orgRoles): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $userId, $orgRoles],
        );
    }

    private function addInvitation(string $organizationId, string $id, string $email, string $expiresAt, ?string $acceptedAt): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_invitation (organization_id, id, email, org_roles, invited_by, created_at, expires_at, accepted_at) VALUES (?, ?, ?, '{}', ?, '2027-03-15 10:00:00+00', ?::timestamptz, ?::timestamptz)",
            [$organizationId, $id, $email, self::SARA, $expiresAt.'+00', null === $acceptedAt ? null : $acceptedAt.'+00'],
        );
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
