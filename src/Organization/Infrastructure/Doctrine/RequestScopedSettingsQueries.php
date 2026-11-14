<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\PingSettingsQueries;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The organization's settings read at most once per organization and request, in one query that gives the step policy,
 * the triager, the ping limit and the auto-clear and quiet-hours switches together: the Team page, the workflow voter and the workflow caches each ask for the
 * step policy, each ticket visibility check for the triager, and the ticket page's ping sheets for the ping limit. The kernel resets it at the start of the next request or message
 * (ADR-0008), so a policy change takes effect from the next request on. The tenant is part of the key, because under
 * another tenant row-level security hides the row and the answer is the default. A stored value that
 * StoredSettingsQueries refuses fails every read, the ping limit's too, as the one query reads them all.
 */
final class RequestScopedSettingsQueries implements SettingsQueries, PingSettingsQueries, ResetInterface
{
    /** @var array<string, StoredSettings> by organization id and tenant */
    private array $settingsOfThisRequest = [];

    public function __construct(
        #[Autowire(service: DbalSettingsQueries::class)]
        private readonly StoredSettingsQueries $storedSettingsQueries,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
    {
        return $this->settingsOf($organizationId)->stepPolicy;
    }

    public function triagerOf(OrganizationId $organizationId): ?UserId
    {
        return $this->settingsOf($organizationId)->triagerId;
    }

    public function pingLimitHoursOf(OrganizationId $organizationId): int
    {
        return $this->settingsOf($organizationId)->pingLimitHours;
    }

    public function isAutoClearOn(OrganizationId $organizationId): bool
    {
        return $this->settingsOf($organizationId)->isAutoClearOn;
    }

    public function isQuietHoursOn(OrganizationId $organizationId): bool
    {
        return $this->settingsOf($organizationId)->isQuietHoursOn;
    }

    public function reset(): void
    {
        $this->settingsOfThisRequest = [];
    }

    private function settingsOf(OrganizationId $organizationId): StoredSettings
    {
        return $this->settingsOfThisRequest[$this->cacheKeyOf($organizationId)] ??= $this->storedSettingsQueries->settingsOf($organizationId);
    }

    private function cacheKeyOf(OrganizationId $organizationId): string
    {
        return $organizationId->toString().' '.($this->tenantContext->get()?->toString() ?? 'none');
    }
}
