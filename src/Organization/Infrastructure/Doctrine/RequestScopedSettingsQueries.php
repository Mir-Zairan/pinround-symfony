<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * DbalSettingsQueries read at most once per organization and request: the Team page, the workflow voter and the
 * workflow caches each ask for the step policy. The kernel resets it at the start of the next request or message
 * (ADR-0008), so a policy change takes effect from the next request on. The tenant is part of the key, because under
 * another tenant row-level security hides the row and the answer is the default.
 */
final class RequestScopedSettingsQueries implements SettingsQueries, ResetInterface
{
    /** @var array<string, StepPolicy> by organization id and tenant */
    private array $stepPoliciesOfThisRequest = [];

    public function __construct(
        #[Autowire(service: DbalSettingsQueries::class)]
        private readonly SettingsQueries $settingsQueries,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
    {
        $key = $organizationId->toString().' '.($this->tenantContext->get()?->toString() ?? 'none');

        return $this->stepPoliciesOfThisRequest[$key] ??= $this->settingsQueries->stepPolicyOf($organizationId);
    }

    public function reset(): void
    {
        $this->stepPoliciesOfThisRequest = [];
    }
}
