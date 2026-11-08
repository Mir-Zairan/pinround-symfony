<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Gives the actor set up for the token's InMemoryUser, by its identifier; any other token user has no actor, as one
 * without a login or a tenant has none.
 */
final readonly class FixedCurrentActor implements CurrentActor
{
    /**
     * @param array<string, ActorContext> $actorsByUserIdentifier
     */
    public function __construct(private array $actorsByUserIdentifier)
    {
    }

    public function actorFor(mixed $tokenUser): ?ActorContext
    {
        return $tokenUser instanceof InMemoryUser ? $this->actorsByUserIdentifier[$tokenUser->getUserIdentifier()] ?? null : null;
    }
}
