<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Application\CreateTeam;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\OrgRoles;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\Application\RecordingOrganizationEvents;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CreateTeam on the real org_team table as pinround_app: the slug it picks from the names the organization's teams
 * have already. Names are unique per organization in any letter case, so the clash comes from names that differ only
 * in punctuation.
 */
final class CreateTeamTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ba1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001bb1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000001b52';

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
    public function aSecondNameWithTheSameSlugGetsTheFirstFreeSuffix(): void
    {
        $slugs = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->addLena(self::ACME_FOODS);
            $shopWeb = $this->createTeam(self::ACME_FOODS, 'Shop Web');
            $shopDashWeb = $this->createTeam(self::ACME_FOODS, 'Shop-Web');
            $shopWebBang = $this->createTeam(self::ACME_FOODS, 'shop web!');

            return [$this->storedSlugOf($shopWeb), $this->storedSlugOf($shopDashWeb), $this->storedSlugOf($shopWebBang)];
        });

        self::assertSame(['shop-web', 'shop-web-2', 'shop-web-3'], $slugs);
    }

    /** A longer slug that starts with the same words is no clash. */
    #[Test]
    public function aLongerSlugWithTheSameStartLeavesTheShortOneFree(): void
    {
        $slug = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): string {
            $this->addLena(self::ACME_FOODS);
            $this->createTeam(self::ACME_FOODS, 'Shop Web Checkout');

            return $this->storedSlugOf($this->createTeam(self::ACME_FOODS, 'Shop Web'));
        });

        self::assertSame('shop-web', $slug);
    }

    #[Test]
    public function anotherOrganizationsTeamLeavesTheSlugFree(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addLena(self::ACME_FOODS);
            $this->createTeam(self::ACME_FOODS, 'Shop Web');
        });

        $slug = $this->transactions()->rolledBackAs(self::BETA_BAKERY, function (): string {
            $this->addLena(self::BETA_BAKERY);

            return $this->storedSlugOf($this->createTeam(self::BETA_BAKERY, 'Shop Web'));
        });

        self::assertSame('shop-web', $slug);
    }

    #[Test]
    public function theDatabaseRefusesASecondTeamWithTheSameSlug(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/org_team_organization_id_slug_key/');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $this->addLena(self::ACME_FOODS);
            $this->createTeam(self::ACME_FOODS, 'Shop Web');
            $this->connection()->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, '0197a3c0-0000-7000-8000-000000001bc9', 'Shop-Web', 'shop-web', ?, now())",
                [self::ACME_FOODS, self::LENA],
            );
        });
    }

    private function addLena(string $organizationId): void
    {
        new DoctrineMemberships($this->entityManager())->add(new Membership(new OrganizationId($organizationId), new UserId(self::LENA), new OrgRoles(), new \DateTimeImmutable('2027-03-15 09:00:00 UTC')));
    }

    private function createTeam(string $organizationId, string $name): TeamId
    {
        $createTeam = new CreateTeam(new DoctrineTeams($this->entityManager()), new DoctrineMemberships($this->entityManager()), new RecordingOrganizationEvents());

        return $createTeam(new OrganizationId($organizationId), $name, new UserId(self::LENA), Job::Developer, null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    private function storedSlugOf(TeamId $teamId): string
    {
        $slug = $this->connection()->fetchOne('SELECT slug FROM org_team WHERE id = ?', [$teamId->toString()]);
        self::assertIsString($slug);

        return $slug;
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
