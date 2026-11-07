<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineOrgSettingsStore on the real org_settings table as pinround_app, under row-level security.
 */
final class DoctrineOrgSettingsStoreTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000551';

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        parent::tearDown();
    }

    #[Test]
    public function theDefaultsAreSavedAsJsonbWithVersionOneAndReadBack(): void
    {
        [$settings, $storedRow] = new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class))->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->store()->add(new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
            $this->entityManager()->clear();

            return [
                $this->store()->of(new OrganizationId(self::ACME_FOODS)),
                $this->entityManager()->getConnection()->fetchAssociative(
                    "SELECT version, jsonb_typeof(data) AS data_type, data->>'triager_id' AS triager_id FROM org_settings WHERE organization_id = ?",
                    [self::ACME_FOODS],
                ),
            ];
        });

        self::assertInstanceOf(OrgSettings::class, $settings);
        self::assertSame(['version' => 1, 'data_type' => 'object', 'triager_id' => self::SARA], $storedRow);
        self::assertSame(1, $settings->version());
        self::assertSame(self::SARA, $settings->triagerId()->toString());
        // assertEquals: jsonb keeps the keys, not their order.
        self::assertEquals(new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'))->data(), $settings->data());
    }

    #[Test]
    public function anOrganizationWithoutSettingsHasNone(): void
    {
        self::assertNull(new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class))->rolledBackAs(
            self::ACME_FOODS,
            fn (): ?OrgSettings => $this->store()->of(new OrganizationId(self::ACME_FOODS)),
        ));
    }

    private function store(): DoctrineOrgSettingsStore
    {
        return new DoctrineOrgSettingsStore($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }
}
