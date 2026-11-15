<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DbalPingSettingsQueries;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalPingSettingsQueries as pinround_app on committed org_settings rows, in a transaction under a tenant, as inside a
 * request.
 */
final class DbalPingSettingsQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002ca1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002cb1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000002cc1';

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
    public function aNewOrganizationWaitsFourHours(): void
    {
        self::assertSame(4, $this->pingLimitHoursAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function allowedPingLimits(): iterable
    {
        yield 'one hour' => ['1', 1];
        yield 'four hours' => ['4', 4];
        yield 'eight hours' => ['8', 8];
        yield 'a day' => ['24', 24];
    }

    #[Test]
    #[DataProvider('allowedPingLimits')]
    public function eachAllowedPingLimitIsReadBack(string $storedJson, int $expectedHours): void
    {
        self::assertSame($expectedHours, $this->pingLimitHoursWith(self::ACME_FOODS, "jsonb_set(data, '{ping_limit_hours}', '".$storedJson."')"));
    }

    #[Test]
    public function anotherOrganizationsPingLimitIsNeverRead(): void
    {
        $this->storePingLimit(self::ACME_FOODS, 24);
        $this->storePingLimit(self::BETA_BAKERY, 8);

        // Each tenant reads its own limit, so the defaults below come from row-level security hiding the other row.
        self::assertSame(24, $this->pingLimitHoursAs(self::ACME_FOODS, self::ACME_FOODS));
        self::assertSame(8, $this->pingLimitHoursAs(self::BETA_BAKERY, self::BETA_BAKERY));
        self::assertSame(4, $this->pingLimitHoursAs(self::BETA_BAKERY, self::ACME_FOODS));
        self::assertSame(4, $this->pingLimitHoursAs(self::ACME_FOODS, self::BETA_BAKERY));
    }

    #[Test]
    public function noTenantReadsTheDefault(): void
    {
        $this->storePingLimit(self::ACME_FOODS, 24);

        self::assertSame(4, $this->queries()->pingLimitHoursOf(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function anOrganizationWithoutSettingsWaitsFourHours(): void
    {
        $this->storePingLimit(self::BETA_BAKERY, 24);
        $this->rows()->removeAllOf(self::ACME_FOODS);

        self::assertSame(4, $this->pingLimitHoursAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    #[Test]
    public function settingsWithoutAPingLimitWaitFourHours(): void
    {
        $this->storePingLimit(self::ACME_FOODS, 24);

        self::assertSame(4, $this->pingLimitHoursWith(self::ACME_FOODS, "data - 'ping_limit_hours'"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPingLimits(): iterable
    {
        yield 'a number not offered' => ['6'];
        yield 'zero' => ['0'];
        yield 'a fraction' => ['4.5'];
        yield 'a word' => ['"four"'];
        yield 'an object' => ['{"hours": 4}'];
    }

    #[Test]
    #[DataProvider('unknownPingLimits')]
    public function anUnknownStoredPingLimitIsRefused(string $storedJson): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_settings holds an unknown ping limit.');

        $this->pingLimitHoursWith(self::ACME_FOODS, "jsonb_set(data, '{ping_limit_hours}', '".$storedJson."')");
    }

    #[Test]
    public function aNewOrganizationClearsPingsOnActionAndHoldsNothing(): void
    {
        self::assertSame([true, false], $this->switchesAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    #[Test]
    public function eachStoredSwitchIsReadBack(): void
    {
        self::assertSame([false, true], $this->switchesWith(self::ACME_FOODS, "data || '{\"auto_clear\": false, \"quiet_hours\": true}'::jsonb"));
    }

    #[Test]
    public function anotherOrganizationsSwitchesAreNeverRead(): void
    {
        $this->storeSwitches(self::BETA_BAKERY, false, true);

        self::assertSame([false, true], $this->switchesAs(self::BETA_BAKERY, self::BETA_BAKERY));
        self::assertSame([true, false], $this->switchesAs(self::ACME_FOODS, self::BETA_BAKERY));
    }

    #[Test]
    public function noTenantReadsTheDefaultSwitches(): void
    {
        $this->storeSwitches(self::ACME_FOODS, false, true);
        $organizationId = new OrganizationId(self::ACME_FOODS);

        self::assertSame([true, false], [$this->queries()->isAutoClearOn($organizationId), $this->queries()->isQuietHoursOn($organizationId)]);
    }

    #[Test]
    public function anOrganizationWithoutSettingsHasTheDefaultSwitches(): void
    {
        $this->storeSwitches(self::BETA_BAKERY, false, true);
        $this->rows()->removeAllOf(self::ACME_FOODS);

        self::assertSame([true, false], $this->switchesAs(self::ACME_FOODS, self::ACME_FOODS));
    }

    #[Test]
    public function settingsWithoutTheSwitchesHaveTheDefaults(): void
    {
        self::assertSame([true, false], $this->switchesWith(self::ACME_FOODS, "data - 'auto_clear' - 'quiet_hours'"));
    }

    #[Test]
    public function switchesStoredAsJsonNullHaveTheDefaults(): void
    {
        self::assertSame([true, false], $this->switchesWith(self::ACME_FOODS, "data || '{\"auto_clear\": null, \"quiet_hours\": null}'::jsonb"));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unknownSwitches(): iterable
    {
        yield 'auto_clear as a word' => ['auto_clear', '"yes"', 'org_settings holds an unknown auto_clear switch.'];
        yield 'auto_clear as a number' => ['auto_clear', '1', 'org_settings holds an unknown auto_clear switch.'];
        yield 'auto_clear as the string true' => ['auto_clear', '"true"', 'org_settings holds an unknown auto_clear switch.'];
        yield 'quiet_hours as a word' => ['quiet_hours', '"on"', 'org_settings holds an unknown quiet_hours switch.'];
        yield 'quiet_hours as an object' => ['quiet_hours', '{"on": true}', 'org_settings holds an unknown quiet_hours switch.'];
    }

    #[Test]
    #[DataProvider('unknownSwitches')]
    public function anUnknownStoredSwitchIsRefused(string $key, string $storedJson, string $expectedMessage): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->switchesWith(self::ACME_FOODS, "jsonb_set(data, '{".$key."}', '".$storedJson."')");
    }

    /**
     * @return array{bool, bool} auto-clear, then quiet hours
     */
    private function switchesAs(string $tenantId, string $askedId): array
    {
        return $this->transactions()->rolledBackAs($tenantId, function () use ($askedId): array {
            $organizationId = new OrganizationId($askedId);

            return [$this->queries()->isAutoClearOn($organizationId), $this->queries()->isQuietHoursOn($organizationId)];
        });
    }

    /**
     * Reads both switches after replacing the organization's settings data with $newData, all rolled back.
     *
     * @return array{bool, bool} auto-clear, then quiet hours
     */
    private function switchesWith(string $organizationId, string $newData): array
    {
        return $this->transactions()->rolledBackAs($organizationId, function () use ($organizationId, $newData): array {
            $this->connection()->executeStatement('UPDATE org_settings SET data = '.$newData);

            return [$this->queries()->isAutoClearOn(new OrganizationId($organizationId)), $this->queries()->isQuietHoursOn(new OrganizationId($organizationId))];
        });
    }

    private function storeSwitches(string $organizationId, bool $isAutoClearOn, bool $isQuietHoursOn): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->connection()->executeStatement(
            "UPDATE org_settings SET data = data || jsonb_build_object('auto_clear', ?::boolean, 'quiet_hours', ?::boolean)",
            [$isAutoClearOn ? 'true' : 'false', $isQuietHoursOn ? 'true' : 'false'],
        ));
    }

    private function pingLimitHoursAs(string $tenantId, string $askedId): int
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): int => $this->queries()->pingLimitHoursOf(new OrganizationId($askedId)));
    }

    /**
     * Reads the ping limit after replacing the organization's settings data with $newData, all rolled back.
     */
    private function pingLimitHoursWith(string $organizationId, string $newData): int
    {
        return $this->transactions()->rolledBackAs($organizationId, function () use ($organizationId, $newData): int {
            $this->connection()->executeStatement('UPDATE org_settings SET data = '.$newData);

            return $this->queries()->pingLimitHoursOf(new OrganizationId($organizationId));
        });
    }

    private function storePingLimit(string $organizationId, int $hours): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->connection()->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{ping_limit_hours}', to_jsonb(?::int))",
            [$hours],
        ));
    }

    private function queries(): DbalPingSettingsQueries
    {
        return new DbalPingSettingsQueries($this->connection());
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
