<?php

declare(strict_types=1);

namespace App\Review\UI\Media;

use App\Media\Api\MediaTargetAuthorizer;
use App\Organization\Api\CurrentActor;
use App\Review\Api\Notebooks;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Lets a person upload a picture for one of their own notebooks (ADR-0046), by its id: the one Notebooks::get finds,
 * which NotebookVoter grants its owner alone. The decision is for the request's logged-in actor, so anyone else asked
 * about, and an organization other than the actor's, is refused, as is a malformed or unknown id and another person's
 * notebook. Notebooks::addScreenshot checks again that the picture is the person's own when it is added.
 */
final readonly class NotebookMediaTarget implements MediaTargetAuthorizer
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private Notebooks $notebooks,
    ) {
    }

    public function targetKind(): string
    {
        return Notebooks::UPLOAD_TARGET_KIND;
    }

    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetId): bool
    {
        $actor = $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser());
        if (null === $actor || !$actor->userId()->equals($userId) || !$actor->organizationId()->equals($organizationId)) {
            return false;
        }

        return null !== $this->notebooks->get($targetId);
    }
}
