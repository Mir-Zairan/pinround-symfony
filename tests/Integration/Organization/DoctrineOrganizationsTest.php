<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Organization;
use App\Organization\Domain\OrganizationStatus;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineOrganizations on the real org_organization table as pinround_app, under row-level security.
 */
final class DoctrineOrganizationsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000002a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000002b1';

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
    public function anOrganizationIsSavedAndReadBackWhileItIsTheTenant(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($createdAt): ?Organization {
            $this->organizations()->add(new Organization(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods', $createdAt));
            $this->entityManager()->clear();

            return $this->organizations()->byId(new OrganizationId(self::ACME_FOODS));
        });

        self::assertNotNull($found);
        self::assertSame(['Acme Foods', 'acme-foods', OrganizationStatus::Active], [$found->name(), $found->slug(), $found->status()]);
        self::assertEquals($createdAt, $found->createdAt());
    }

    #[Test]
    public function anotherOrganizationIsNotFoundByItsId(): void
    {
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->organizations()->add(
            new Organization(new OrganizationId(self::BETA_BAKERY), 'Beta Bakery', 'beta-bakery', new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));

        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Organization => $this->organizations()->byId(new OrganizationId(self::BETA_BAKERY))));
        self::assertNotNull($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?Organization => $this->organizations()->byId(new OrganizationId(self::BETA_BAKERY))));
    }

    #[Test]
    public function aSlugAnotherOrganizationHasIsRefusedThoughThatOrganizationIsInvisible(): void
    {
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->organizations()->add(
            new Organization(new OrganizationId(self::BETA_BAKERY), 'Beta Bakery', 'bakery', new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->organizations()->add(
            new Organization(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'bakery', new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));
    }

    #[Test]
    public function newIdentitiesAreUuidV7AndDiffer(): void
    {
        self::assertFalse($this->organizations()->nextIdentity()->equals($this->organizations()->nextIdentity()));
    }

    private function organizations(): DoctrineOrganizations
    {
        return new DoctrineOrganizations($this->entityManager());
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
