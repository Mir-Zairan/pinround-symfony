<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Organization\Api\AdminPermission;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides AdminPermission::AI (ADR-0013, FR-ADM-05): only an Admin of the organization the request works in may change
 * its AI settings. The login checks are AdminAreaVoter's, which the Admin › AI tab asks first.
 *
 * @extends DenyByDefaultVoter<OrganizationId>
 */
final class AiSettingsVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentActor $currentActor)
    {
    }

    protected function attributes(): array
    {
        return [AdminPermission::AI];
    }

    protected function subjectClass(): string
    {
        return OrganizationId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());

        return null !== $actor && $actor->organizationId()->equals($subject) && $actor->roles()->isAdmin();
    }
}
