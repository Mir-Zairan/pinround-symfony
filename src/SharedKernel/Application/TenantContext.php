<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

use App\SharedKernel\Domain\OrganizationId;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The organization the current request or message works for. TenantMiddleware reads it at the start of
 * each database transaction. The kernel resets it between requests, so worker mode never carries a
 * tenant over (ADR-0008).
 *
 * Moving from one organization to another one or to none first runs the TenantSwitchGuard; the first set() and
 * setting the same organization again do not.
 */
final class TenantContext implements ResetInterface
{
    private ?OrganizationId $organizationId = null;

    public function __construct(private readonly TenantSwitchGuard $switchGuard)
    {
    }

    /**
     * @throws TenantSwitchRefused and keeps the current organization
     */
    public function set(OrganizationId $organizationId): void
    {
        if (null !== $this->organizationId && !$this->organizationId->equals($organizationId)) {
            $this->switchGuard->prepareSwitch();
        }

        $this->organizationId = $organizationId;
    }

    public function get(): ?OrganizationId
    {
        return $this->organizationId;
    }

    /**
     * @throws TenantNotSet
     */
    public function require(): OrganizationId
    {
        return $this->organizationId ?? throw new TenantNotSet();
    }

    /**
     * @throws TenantSwitchRefused and keeps the current organization
     */
    public function clear(): void
    {
        if (null !== $this->organizationId) {
            $this->switchGuard->prepareSwitch();
        }

        $this->organizationId = null;
    }

    /**
     * Skips the guard: a reset must not fail, and the same kernel reset clears the entity manager (and so any
     * unsaved work) through the Doctrine registry.
     */
    public function reset(): void
    {
        $this->organizationId = null;
    }
}
