<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Security;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Application\TenantContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps each actor it builds until the kernel resets it at the start of the next request, so role changes take effect
 * from the next request on (ADR-0008).
 */
final class RequestScopedCurrentActor implements CurrentActor, ResetInterface
{
    /** @var array<string, ActorContext> by user id and organization id */
    private array $actorsOfThisRequest = [];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly TenantContext $tenantContext,
        private readonly MembershipQueries $membershipQueries,
    ) {
    }

    public function actorFor(mixed $tokenUser): ?ActorContext
    {
        $userId = $this->currentUser->idOf($tokenUser);
        $organizationId = $this->tenantContext->get();
        if (null === $userId || null === $organizationId) {
            return null;
        }

        return $this->actorsOfThisRequest[$userId->toString().' '.$organizationId->toString()]
            ??= new ActorContext($userId, $organizationId, $this->membershipQueries->rolesOf($userId, $organizationId));
    }

    public function reset(): void
    {
        $this->actorsOfThisRequest = [];
    }
}
