<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

use App\SharedKernel\Domain\OrganizationId;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The organization the current request or message works for. TenantMiddleware reads it at the start of
 * each database transaction. The kernel resets it between requests, so worker mode never carries a
 * tenant over (ADR-0008).
 */
final class TenantContext implements ResetInterface
{
    private ?OrganizationId $organizationId = null;

    public function set(OrganizationId $organizationId): void
    {
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

    public function clear(): void
    {
        $this->organizationId = null;
    }

    public function reset(): void
    {
        $this->clear();
    }
}
