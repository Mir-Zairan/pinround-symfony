<?php

declare(strict_types=1);

namespace App\Media\UI\Voter;

use App\Media\Api\MediaPermission;
use App\Media\Api\UploadTarget;
use App\Media\Application\MediaTargetAuthorizers;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides MediaPermission UPLOAD on an UploadTarget (ADR-0011, ADR-0013): the MediaTargetAuthorizer of the target's kind
 * decides for the request's actor in their organization. Denied with nobody logged in, with no tenant, and for a kind
 * no authorizer decides.
 *
 * The authorizers read under row-level security, so it needs an open transaction under the tenant.
 *
 * @extends DenyByDefaultVoter<UploadTarget>
 */
final class UploadVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly MediaTargetAuthorizers $mediaTargetAuthorizers,
    ) {
    }

    protected function attributes(): array
    {
        return [MediaPermission::UPLOAD];
    }

    protected function subjectClass(): string
    {
        return UploadTarget::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());

        return null !== $actor && $this->mediaTargetAuthorizers->mayAttach($actor->organizationId(), $actor->userId(), $subject->kind, $subject->id);
    }
}
