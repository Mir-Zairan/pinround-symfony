<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\StepPolicy;
use App\Organization\Application\ChangeStepPolicy;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ChangeStepPolicy through DoctrineOrgSettingsStore on the real org_settings table as pinround_app.
 */
final class ChangeStepPolicyTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001da1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000001dc1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000001dc2';

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
    public function itChangesTheStepPolicyOnlyAndRaisesTheVersion(): void
    {
        $this->addSettings();
        // A triager other than the first admin, so a change that rewrote the defaults would show.
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->entityManager()->getConnection()->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{triager_id}', to_jsonb(?::text)), version = version + 1",
            [self::LENA],
        ));
        $dataBefore = $this->storedData();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->changeStepPolicy(StepPolicy::Team));

        $dataAfter = $this->storedData();
        self::assertSame('team', $dataAfter['step_policy']);
        self::assertSame(self::LENA, $dataAfter['triager_id']);
        unset($dataBefore['step_policy'], $dataAfter['step_policy']);
        // assertEquals: jsonb keeps the keys, not their order.
        self::assertEquals($dataBefore, $dataAfter);
        self::assertSame(3, $this->storedVersion());
    }

    #[Test]
    public function settingsChangedSinceTheyWereLoadedAreNotOverwritten(): void
    {
        $this->addSettings();

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $store = new DoctrineOrgSettingsStore($this->entityManager());
            $store->of(new OrganizationId(self::ACME_FOODS));
            // Another request's committed change, after these settings were loaded.
            $this->entityManager()->getConnection()->executeStatement('UPDATE org_settings SET version = version + 1');

            $this->expectException(OptimisticLockException::class);
            $this->changeStepPolicy(StepPolicy::Locked);
        });
    }

    #[Test]
    public function anOrganizationWithoutSettingsIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The organization has no settings.');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->changeStepPolicy(StepPolicy::Locked));
    }

    private function changeStepPolicy(StepPolicy $stepPolicy): void
    {
        new ChangeStepPolicy(new DoctrineOrgSettingsStore($this->entityManager()))(
            new OrganizationId(self::ACME_FOODS),
            $stepPolicy,
            new UserId(self::SARA),
            new \DateTimeImmutable('2027-03-16 09:00:00 UTC'),
        );
    }

    private function addSettings(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => new DoctrineOrgSettingsStore($this->entityManager())->add(
            new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));
    }

    /**
     * @return array<mixed> the stored data as decoded JSON
     */
    private function storedData(): array
    {
        $data = $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): mixed => $this->entityManager()->getConnection()->fetchOne('SELECT data FROM org_settings'),
        );
        self::assertIsString($data);
        $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function storedVersion(): mixed
    {
        return $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): mixed => $this->entityManager()->getConnection()->fetchOne('SELECT version FROM org_settings'),
        );
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }
}
