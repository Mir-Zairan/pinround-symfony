<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\StepPolicy;
use App\Organization\Application\ChangeStepPolicy;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\Organization\Infrastructure\Doctrine\StoredSettings;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalSettingsQueries as pinround_app on committed org_settings rows, in a transaction under a tenant, as inside a
 * request.
 */
final class DbalSettingsQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ca1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001cb1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000001cc1';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->transactions()->committedAs($organizationId, fn () => new DoctrineOrgSettingsStore($this->entityManager())->add(
                new OrgSettings(new OrganizationId($organizationId), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
            ));
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aNewOrganizationHasTheExtendPolicyAndLockedAfterChangingItToLocked(): void
    {
        self::assertSame(StepPolicy::Extend, $this->stepPolicyAs(self::ACME_FOODS, self::ACME_FOODS));

        $this->changeStepPolicy(self::ACME_FOODS, StepPolicy::Locked);

        self::assertSame(StepPolicy::Locked, $this->stepPolicyAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    #[Test]
    public function anotherOrganizationsPolicyIsNeverRead(): void
    {
        $this->changeStepPolicy(self::ACME_FOODS, StepPolicy::Locked);
        $this->changeStepPolicy(self::BETA_BAKERY, StepPolicy::Team);

        // Each tenant reads its own policy, so the defaults below come from row-level security hiding the other row.
        self::assertSame(StepPolicy::Locked, $this->stepPolicyAs(self::ACME_FOODS, self::ACME_FOODS));
        self::assertSame(StepPolicy::Team, $this->stepPolicyAs(self::BETA_BAKERY, self::BETA_BAKERY));
        self::assertSame(StepPolicy::Extend, $this->stepPolicyAs(self::BETA_BAKERY, self::ACME_FOODS));
        self::assertSame(StepPolicy::Extend, $this->stepPolicyAs(self::ACME_FOODS, self::BETA_BAKERY));
    }

    #[Test]
    public function noTenantReadsNoPolicy(): void
    {
        $this->changeStepPolicy(self::ACME_FOODS, StepPolicy::Locked);

        self::assertSame(StepPolicy::Extend, $this->queries()->stepPolicyOf(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function anOrganizationWithoutSettingsHasTheExtendPolicy(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);

        self::assertSame(StepPolicy::Extend, $this->stepPolicyAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    #[Test]
    public function settingsWithoutAStepPolicyHaveTheExtendPolicy(): void
    {
        $this->changeStepPolicy(self::ACME_FOODS, StepPolicy::Locked);

        self::assertSame(StepPolicy::Extend, $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): StepPolicy {
            $this->connection()->executeStatement("UPDATE org_settings SET data = data - 'step_policy'");

            return $this->queries()->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        }));
    }

    #[Test]
    public function anUnknownStoredPolicyIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_settings holds an unknown step policy.');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): StepPolicy {
            $this->connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{step_policy}', '\"open\"')");

            return $this->queries()->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        });
    }

    #[Test]
    public function aNewOrganizationHasItsFirstAdminAsTriager(): void
    {
        self::assertSame(self::SARA, $this->triagerAs(self::ACME_FOODS, self::ACME_FOODS)?->toString());
    }

    #[Test]
    public function anotherOrganizationsTriagerIsNeverRead(): void
    {
        self::assertSame(self::SARA, $this->triagerAs(self::BETA_BAKERY, self::BETA_BAKERY)?->toString());
        self::assertNull($this->triagerAs(self::BETA_BAKERY, self::ACME_FOODS));
    }

    #[Test]
    public function noTenantReadsNoTriager(): void
    {
        self::assertNull($this->queries()->triagerOf(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function anOrganizationWithoutSettingsHasNoTriager(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);

        self::assertNull($this->triagerAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    #[Test]
    public function settingsWithoutATriagerHaveNoTriager(): void
    {
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, function (): ?UserId {
            $this->connection()->executeStatement("UPDATE org_settings SET data = data - 'triager_id'");

            return $this->queries()->triagerOf(new OrganizationId(self::ACME_FOODS));
        }));
    }

    #[Test]
    public function aStoredTriagerThatIsNotAUserIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): ?UserId {
            $this->connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{triager_id}', '\"sara\"')");

            return $this->queries()->triagerOf(new OrganizationId(self::ACME_FOODS));
        });
    }

    /**
     * Locked is not the default, Sara is not null and both switches are the opposite of theirs, so a settings read
     * that mixed the keys up or fell back to the defaults would differ; the query log shows one query for all.
     */
    #[Test]
    public function theStepPolicyAndTheTriagerComeInOneQuery(): void
    {
        $this->changeStepPolicy(self::ACME_FOODS, StepPolicy::Locked);
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');

        $settings = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData): StoredSettings {
            $this->connection()->executeStatement(
                "UPDATE org_settings SET data = data || '{\"ping_limit_hours\": 8, \"auto_clear\": false, \"quiet_hours\": true}'::jsonb",
            );
            $debugData->reset();

            return $this->queries()->settingsOf(new OrganizationId(self::ACME_FOODS));
        });

        self::assertSame(
            [StepPolicy::Locked, self::SARA, 8, false, true],
            [$settings->stepPolicy, $settings->triagerId?->toString(), $settings->pingLimitHours, $settings->isAutoClearOn, $settings->isQuietHoursOn],
        );
        $selects = array_filter(array_merge(...array_values($debugData->getData())), static fn (array $query): bool => \is_string($query['sql']) && str_contains($query['sql'], 'org_settings'));
        self::assertCount(1, $selects);
    }

    #[Test]
    public function anotherOrganizationsSettingsAreNeverReadTogether(): void
    {
        $this->changeStepPolicy(self::BETA_BAKERY, StepPolicy::Team);

        $settings = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): StoredSettings => $this->queries()->settingsOf(new OrganizationId(self::BETA_BAKERY)));

        self::assertSame(
            [StepPolicy::Extend, null, 4, true, false],
            [$settings->stepPolicy, $settings->triagerId, $settings->pingLimitHours, $settings->isAutoClearOn, $settings->isQuietHoursOn],
        );
    }

    #[Test]
    public function anOrganizationWithoutSettingsHasTheDefaultsTogether(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);

        $settings = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): StoredSettings => $this->queries()->settingsOf(new OrganizationId(self::ACME_FOODS)));

        self::assertSame(
            [StepPolicy::Extend, null, 4, true, false],
            [$settings->stepPolicy, $settings->triagerId, $settings->pingLimitHours, $settings->isAutoClearOn, $settings->isQuietHoursOn],
        );
    }

    #[Test]
    public function anUnknownStoredSwitchIsRefusedTogether(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_settings holds an unknown quiet_hours switch.');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): StoredSettings {
            $this->connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{quiet_hours}', '\"yes\"')");

            return $this->queries()->settingsOf(new OrganizationId(self::ACME_FOODS));
        });
    }

    #[Test]
    public function anUnknownStoredPingLimitIsRefusedTogether(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_settings holds an unknown ping limit.');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): StoredSettings {
            $this->connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{ping_limit_hours}', '2')");

            return $this->queries()->settingsOf(new OrganizationId(self::ACME_FOODS));
        });
    }

    private function triagerAs(string $tenantId, string $askedId): ?UserId
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): ?UserId => $this->queries()->triagerOf(new OrganizationId($askedId)));
    }

    private function stepPolicyAs(string $tenantId, string $askedId): StepPolicy
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): StepPolicy => $this->queries()->stepPolicyOf(new OrganizationId($askedId)));
    }

    private function changeStepPolicy(string $organizationId, StepPolicy $stepPolicy): void
    {
        $this->transactions()->committedAs($organizationId, fn () => new ChangeStepPolicy(new DoctrineOrgSettingsStore($this->entityManager()))(
            new OrganizationId($organizationId),
            $stepPolicy,
            new UserId(self::SARA),
            new \DateTimeImmutable('2027-03-16 09:00:00 UTC'),
        ));
    }

    private function queries(): DbalSettingsQueries
    {
        return new DbalSettingsQueries($this->connection());
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
