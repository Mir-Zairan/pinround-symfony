<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\Organization\Api\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A test-only endpoint (config/routes/test_fixtures.yaml) that reports the actor a voter would get for the request's
 * token, asking twice the way two voters of one request would.
 */
#[AsController]
final readonly class CurrentActorProbeController
{
    public function __construct(private CurrentActor $currentActor, private TokenStorageInterface $tokenStorage)
    {
    }

    public function reportActor(): JsonResponse
    {
        $tokenUser = $this->tokenStorage->getToken()?->getUser();
        $actor = $this->currentActor->actorFor($tokenUser);
        if (null === $actor) {
            return new JsonResponse(['actor' => null]);
        }

        return new JsonResponse(['actor' => [
            'userId' => $actor->userId()->toString(),
            'organizationId' => $actor->organizationId()->toString(),
            'isAdmin' => $actor->roles()->isAdmin(),
            'isManager' => $actor->roles()->isManager(),
            'isReusedWithinTheRequest' => $actor === $this->currentActor->actorFor($tokenUser),
        ]]);
    }
}
