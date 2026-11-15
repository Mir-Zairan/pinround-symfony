<?php

declare(strict_types=1);

namespace App\Media\UI\Voter;

use App\Media\Api\MediaPermission;
use App\Media\Api\UploadedMedia;
use App\Media\Api\UploadedMediaId;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides MediaPermission VIEW_UPLOAD_STATE on an UploadedMediaId (ADR-0013): granted only when the request's actor
 * uploaded that media object in their organization, which it reads in one query through UploadedMedia. Denied with
 * nobody logged in, for a malformed or unknown id, and for another person's or organization's picture.
 *
 * It needs an open transaction under the actor's tenant, as UploadedMedia does.
 *
 * @extends DenyByDefaultVoter<UploadedMediaId>
 */
final class UploadStateVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly UploadedMedia $uploadedMedia,
    ) {
    }

    protected function attributes(): array
    {
        return [MediaPermission::VIEW_UPLOAD_STATE];
    }

    protected function subjectClass(): string
    {
        return UploadedMediaId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor) {
            return false;
        }

        return isset($this->uploadedMedia->statesOf($actor->organizationId(), $actor->userId(), [$subject->id])[strtolower($subject->id)]);
    }
}
