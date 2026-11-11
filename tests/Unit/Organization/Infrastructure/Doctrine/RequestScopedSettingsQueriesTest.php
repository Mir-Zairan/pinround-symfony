<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\RequestScopedSettingsQueries;
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

    /** @var array<string, StepPolicy> by organization id; what the database holds */
    private array $storedPolicies;

    /** @var array<string, ?UserId> by organization id; what the database holds */
    private array $storedTriagers;

    /** @var list<string> the organization ids read from the database, in order */
    private array $reads = [];

    /** @var list<string> the organization ids whose triager was read from the database, in order */
    private array $triagerReads = [];

    private RequestScopedSettingsQueries $settingsQueries;

    protected function setUp(): void
    {
        $this->storedPolicies = [self::ACME_FOODS => StepPolicy::Team, self::BETA_BAKERY => StepPolicy::Locked];
        $this->storedTriagers = [self::ACME_FOODS => null, self::BETA_BAKERY => new UserId(self::SARA)];
        $this->tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
        $database = new readonly class($this) implements SettingsQueries {
            public function __construct(private RequestScopedSettingsQueriesTest $test)
            {
            }

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                return $this->test->readFromTheDatabase($organizationId);
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return $this->test->readTriagerFromTheDatabase($organizationId);
            }
        };
        $this->settingsQueries = new RequestScopedSettingsQueries($database, $this->tenantContext);
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
    }

    public function readFromTheDatabase(OrganizationId $organizationId): StepPolicy
    {
        $this->reads[] = $organizationId->toString();

        return $this->storedPolicies[$organizationId->toString()] ?? StepPolicy::Extend;
    }

    public function readTriagerFromTheDatabase(OrganizationId $organizationId): ?UserId
    {
        $this->triagerReads[] = $organizationId->toString();

        return $this->storedTriagers[$organizationId->toString()] ?? null;
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
    public function aChangeIsSeenAfterTheResetBetweenRequests(): void
    {
        $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        $this->storedPolicies[self::ACME_FOODS] = StepPolicy::Locked;

        self::assertSame(StepPolicy::Team, $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS)));
        $this->settingsQueries->reset();
        self::assertSame(StepPolicy::Locked, $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS)));
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

    #[Test]
    public function noTriagerIsReadOnceHoweverOftenItIsAskedFor(): void
    {
        foreach (range(1, 3) as $ignored) {
            self::assertNull($this->settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS)));
        }

        self::assertSame([self::ACME_FOODS], $this->triagerReads);
    }

    #[Test]
    public function aTriagerIsReadOnceAndAChangeIsSeenAfterTheReset(): void
    {
        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $this->settingsQueries->triagerOf(new OrganizationId(self::BETA_BAKERY));
        $this->storedTriagers[self::BETA_BAKERY] = null;

        self::assertSame(self::SARA, $this->settingsQueries->triagerOf(new OrganizationId(self::BETA_BAKERY))?->toString());
        $this->settingsQueries->reset();
        self::assertNull($this->settingsQueries->triagerOf(new OrganizationId(self::BETA_BAKERY)));
        self::assertSame([self::BETA_BAKERY, self::BETA_BAKERY], $this->triagerReads);
    }

    #[Test]
    public function eachTriagerIsReadPerOrganizationAndTenantApartFromTheStepPolicy(): void
    {
        $this->settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        $this->settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS));
        self::assertSame(self::SARA, $this->settingsQueries->triagerOf(new OrganizationId(self::BETA_BAKERY))?->toString());

        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $this->settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS));

        self::assertSame([self::ACME_FOODS, self::BETA_BAKERY, self::ACME_FOODS], $this->triagerReads);
        self::assertSame([self::ACME_FOODS], $this->reads);
    }
}
