<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Doctrine;

use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\RequestScopedSettingsQueries;
use App\Organization\Infrastructure\Doctrine\StoredSettings;
use App\Organization\Infrastructure\Doctrine\StoredSettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestScopedSettingsQueriesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000000b5';

    private TenantContext $tenantContext;

    /** @var array<string, StoredSettings> by organization id; what the database holds */
    private array $storedSettings;

    /** @var list<string> the organization ids read from the database, in order: one entry per query */
    private array $reads = [];

    private RequestScopedSettingsQueries $settingsQueries;

    protected function setUp(): void
    {
        $this->storedSettings = [
            self::ACME_FOODS => new StoredSettings(StepPolicy::Team, null),
            self::BETA_BAKERY => new StoredSettings(StepPolicy::Locked, new UserId(self::SARA)),
        ];
        $this->tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
        $database = new readonly class($this) implements StoredSettingsQueries {
            public function __construct(private RequestScopedSettingsQueriesTest $test)
            {
            }

            public function settingsOf(OrganizationId $organizationId): StoredSettings
            {
                return $this->test->readFromTheDatabase($organizationId);
            }
        };
        $this->settingsQueries = new RequestScopedSettingsQueries($database, $this->tenantContext);
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
    }

    public function readFromTheDatabase(OrganizationId $organizationId): StoredSettings
    {
        $this->reads[] = $organizationId->toString();

        return $this->storedSettings[$organizationId->toString()] ?? new StoredSettings(StepPolicy::Extend, null);
    }

    #[Test]
    public function thePolicyIsReadOnceHoweverOftenItIsAskedFor(): void
    {
        foreach (range(1, 5) as $ignored) {
            self::assertSame(StepPolicy::Team, $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS)));
        }

        self::assertSame([self::ACME_FOODS], $this->reads);
    }

    #[Test]
    public function theStepPolicyAndTheTriagerShareOneRead(): void
    {
        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $organizationId = new OrganizationId(self::BETA_BAKERY);

        $triagerFirst = $this->settingsQueries->triagerOf($organizationId)?->toString();
        $policyAfter = $this->settingsQueries->stepPolicyOf($organizationId);
        $this->settingsQueries->reset();
        $policyFirst = $this->settingsQueries->stepPolicyOf($organizationId);
        $triagerAfter = $this->settingsQueries->triagerOf($organizationId)?->toString();

        self::assertSame([self::SARA, StepPolicy::Locked, StepPolicy::Locked, self::SARA], [$triagerFirst, $policyAfter, $policyFirst, $triagerAfter]);
        self::assertSame([self::BETA_BAKERY, self::BETA_BAKERY], $this->reads);
    }

    #[Test]
    public function noTriagerIsReadOnceHoweverOftenItIsAskedFor(): void
    {
        foreach (range(1, 3) as $ignored) {
            self::assertNull($this->settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS)));
        }

        self::assertSame([self::ACME_FOODS], $this->reads);
    }

    #[Test]
    public function aChangeIsSeenAfterTheResetBetweenRequests(): void
    {
        $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        $this->storedSettings[self::ACME_FOODS] = new StoredSettings(StepPolicy::Locked, new UserId(self::SARA));

        self::assertSame([StepPolicy::Team, null], [$this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS)), $this->settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS))]);
        $this->settingsQueries->reset();
        self::assertSame([StepPolicy::Locked, self::SARA], [$this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS)), $this->settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS))?->toString()]);
    }

    #[Test]
    public function eachOrganizationAndTenantIsReadOnItsOwn(): void
    {
        $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        self::assertSame(StepPolicy::Locked, $this->settingsQueries->stepPolicyOf(new OrganizationId(self::BETA_BAKERY)));

        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));

        self::assertSame([self::ACME_FOODS, self::BETA_BAKERY, self::ACME_FOODS], $this->reads);
    }
}
