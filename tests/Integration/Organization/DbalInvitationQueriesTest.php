<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\InvitationQueries;
use App\Organization\Api\PendingInvitation;
use App\Organization\Infrastructure\Doctrine\DbalInvitationQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * DbalInvitationQueries as pinround_app on committed org_invitation rows, in a transaction under a tenant, as inside a
 * request. The clock stands in 2027, after the wall clock, so a query that ignored it would list the expired ones.
 */
final class DbalInvitationQueriesTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 12:00:00 UTC';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000ea1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000eb1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000e51';
    // Ids run against the invitation times, and the two invited at the same second tell id DESC from id ASC.
    private const string NEWEST = '0197a3c0-0000-7000-8000-000000000ec1';
    private const string SAME_SECOND_LOWER_ID = '0197a3c0-0000-7000-8000-000000000ec2';
    private const string SAME_SECOND_HIGHER_ID = '0197a3c0-0000-7000-8000-000000000ec3';
    private const string OLDEST = '0197a3c0-0000-7000-8000-000000000ec4';
    private const string ACCEPTED = '0197a3c0-0000-7000-8000-000000000ec5';
    private const string EXPIRED = '0197a3c0-0000-7000-8000-000000000ec6';
    private const string EXPIRES_NOW = '0197a3c0-0000-7000-8000-000000000ec7';
    private const string AT_BETA = '0197a3c0-0000-7000-8000-000000000ec8';
    // created_at keeps whole seconds, so these two, invited 0.3 seconds apart, are ordered by id.
    private const string SUB_SECOND_EARLIER = '0197a3c0-0000-7000-8000-000000000ec9';
    private const string SUB_SECOND_LATER = '0197a3c0-0000-7000-8000-000000000eca';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addInvitation(self::ACME_FOODS, self::NEWEST, 'omar@acme-foods.example', '2027-03-20 11:00:00', '2027-03-23 11:00:00');
            $this->addInvitation(self::ACME_FOODS, self::SAME_SECOND_LOWER_ID, 'mia@acme-foods.example', '2027-03-19 09:00:00', '2027-03-22 09:00:00');
            $this->addInvitation(self::ACME_FOODS, self::SAME_SECOND_HIGHER_ID, 'raj@acme-foods.example', '2027-03-19 09:00:00', '2027-03-22 09:00:00');
            $this->addInvitation(self::ACME_FOODS, self::OLDEST, 'lena@acme-foods.example', '2027-03-18 09:00:00', '2027-03-21 09:00:00');
            $this->addInvitation(self::ACME_FOODS, self::ACCEPTED, 'omar@acme-foods.example', '2027-03-19 10:00:00', '2027-03-22 10:00:00', '2027-03-19 11:00:00');
            $this->addInvitation(self::ACME_FOODS, self::EXPIRED, 'sara@acme-foods.example', '2027-03-17 11:00:00', '2027-03-20 11:00:00');
            $this->addInvitation(self::ACME_FOODS, self::EXPIRES_NOW, 'ben@acme-foods.example', '2027-03-17 12:00:00', '2027-03-20 12:00:00');
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addInvitation(self::BETA_BAKERY, self::AT_BETA, 'omar@acme-foods.example', '2027-03-20 11:30:00', '2027-03-23 11:30:00');
        });
    }

    protected function tearDown(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function pendingOfListsOnlyOpenInvitationsNewestFirstThenByIdDescending(): void
    {
        // ACCEPTED has not expired yet, so its absence comes from accepted_at.
        $pending = $this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 10);

        self::assertSame([self::NEWEST, self::SAME_SECOND_HIGHER_ID, self::SAME_SECOND_LOWER_ID, self::OLDEST], self::idsOf($pending));
        self::assertSame('omar@acme-foods.example', $pending[0]->email());
        self::assertEquals(new \DateTimeImmutable('2027-03-20 11:00:00 UTC'), $pending[0]->invitedAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-23 11:00:00 UTC'), $pending[0]->expiresAt());
    }

    #[Test]
    public function anInvitationIsPendingUntilTheClockReachesItsExpiry(): void
    {
        // A second before EXPIRED expires both are listed; at NOW, the second EXPIRES_NOW expires at, neither is.
        self::assertSame(
            [self::NEWEST, self::SAME_SECOND_HIGHER_ID, self::SAME_SECOND_LOWER_ID, self::OLDEST, self::EXPIRES_NOW, self::EXPIRED],
            self::idsOf($this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 10, null, '2027-03-20 10:59:59 UTC')),
        );
        self::assertNotContains(self::EXPIRES_NOW, self::idsOf($this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 10)));
    }

    #[Test]
    public function theInvitationsOfAnotherOrganizationAreInvisible(): void
    {
        // Under Beta Bakery itself its invitation is there, so the empty answers come from the organization and from
        // row-level security.
        self::assertSame([self::AT_BETA], self::idsOf($this->pendingOf(self::BETA_BAKERY, self::BETA_BAKERY, 10)));
        self::assertSame([], self::idsOf($this->pendingOf(self::ACME_FOODS, self::BETA_BAKERY, 10)));
        self::assertSame([], self::idsOf($this->pendingOf(self::BETA_BAKERY, self::ACME_FOODS, 10)));
        self::assertSame([], $this->queries()->pendingOf(new OrganizationId(self::ACME_FOODS), 10));
    }

    #[Test]
    public function pendingOfReturnsAtMostTheLimit(): void
    {
        self::assertSame([self::NEWEST, self::SAME_SECOND_HIGHER_ID], self::idsOf($this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 2)));
        self::assertSame([self::NEWEST], self::idsOf($this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 1)));
    }

    #[Test]
    public function afterContinuesThePageWithTheNextOlderInvitations(): void
    {
        $firstPage = $this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 2);
        $secondPage = $this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 2, $firstPage[1]);

        self::assertSame([self::SAME_SECOND_LOWER_ID, self::OLDEST], self::idsOf($secondPage));
        self::assertSame([], $this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 2, $secondPage[1]));
    }

    #[Test]
    public function pagingOneByOneGivesInvitationsOfTheSameSecondEachExactlyOnce(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addInvitation(self::ACME_FOODS, self::SUB_SECOND_EARLIER, 'noor@acme-foods.example', '2027-03-16 08:00:00.100000', '2027-03-23 08:00:00');
            $this->addInvitation(self::ACME_FOODS, self::SUB_SECOND_LATER, 'mia.tester@acme-foods.example', '2027-03-16 08:00:00.400000', '2027-03-23 08:00:00');
        });

        $seen = [];
        $after = null;
        do {
            $page = $this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, 1, $after);
            $seen = [...$seen, ...self::idsOf($page)];
            $after = $page[0] ?? null;
        } while (null !== $after && \count($seen) < 20);

        self::assertSame(
            [self::NEWEST, self::SAME_SECOND_HIGHER_ID, self::SAME_SECOND_LOWER_ID, self::OLDEST, self::SUB_SECOND_LATER, self::SUB_SECOND_EARLIER],
            $seen,
        );
    }

    #[Test]
    public function aLimitOutsideOneToTheMaximumIsRefused(): void
    {
        self::assertCount(4, $this->pendingOf(self::ACME_FOODS, self::ACME_FOODS, InvitationQueries::MAX_LIMIT));

        foreach ([0, InvitationQueries::MAX_LIMIT + 1] as $limit) {
            try {
                $this->queries()->pendingOf(new OrganizationId(self::ACME_FOODS), $limit);
                self::fail(\sprintf('A limit of %d was accepted.', $limit));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringEndsWith(\sprintf('not %d.', $limit), $exception->getMessage());
            }
        }
    }

    /**
     * @return list<PendingInvitation>
     */
    private function pendingOf(string $tenantId, string $askedId, int $limit, ?PendingInvitation $after = null, string $now = self::NOW): array
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): array => $this->queries($now)->pendingOf(new OrganizationId($askedId), $limit, $after));
    }

    /**
     * @param list<PendingInvitation> $pending
     *
     * @return list<string>
     */
    private static function idsOf(array $pending): array
    {
        return array_map(static fn (PendingInvitation $invitation): string => $invitation->id(), $pending);
    }

    private function queries(string $now = self::NOW): DbalInvitationQueries
    {
        return new DbalInvitationQueries($this->connection(), new MockClock($now));
    }

    private function addInvitation(string $organizationId, string $id, string $email, string $createdAt, string $expiresAt, ?string $acceptedAt = null): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_invitation (organization_id, id, email, org_roles, invited_by, created_at, expires_at, accepted_at) VALUES (?, ?, ?, '{}', ?, ?::timestamptz, ?::timestamptz, ?::timestamptz)",
            [$organizationId, $id, $email, self::SARA, $createdAt.'+00', $expiresAt.'+00', null === $acceptedAt ? null : $acceptedAt.'+00'],
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
