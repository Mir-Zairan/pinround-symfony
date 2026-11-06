<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The organization a message runs under; TenantStampMiddleware reads it (ADR-0005).
 */
final readonly class TenantStamp implements StampInterface
{
    // A string, so a transport serializer stores it as is; organizationId() validates it again after a round trip.
    private string $organizationId;

    public function __construct(OrganizationId $organizationId)
    {
        $this->organizationId = $organizationId->toString();
    }

    /**
     * @throws \InvalidArgumentException when a stored stamp holds no canonical UUIDv7
     */
    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }
}
