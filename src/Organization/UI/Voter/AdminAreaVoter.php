<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Identity\Api\LoginAssurance;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides AdminPermission::AREA (ADR-0012, ADR-0013): only an Admin of the organization the request works in, logged
 * in with their password in this session (never remember-me or a token, personas-and-roles.md note 6), with two-step
 * login on and a code typed in this session at most ADMIN_STEP_UP_SECONDS ago (FR-ID-06). SendAdminToTwoStepProof
 * sends a refused Admin to the setup page or to "Confirm it's you".
 *
 * @extends DenyByDefaultVoter<OrganizationId>
 */
final class AdminAreaVoter extends DenyByDefaultVoter
{
    public const int ADMIN_STEP_UP_SECONDS = 3600;

    public function __construct(private readonly CurrentActor $currentActor, private readonly LoginAssurance $loginAssurance)
    {
    }

    protected function attributes(): array
    {
        return [AdminPermission::AREA];
    }

    protected function subjectClass(): string
    {
        return OrganizationId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor || !$actor->organizationId()->equals($subject) || !$actor->roles()->isAdmin()) {
            return false;
        }

        return $this->loginAssurance->isPasswordSession($token)
            && $this->loginAssurance->hasConfirmedTwoStepLoginWithin($token, self::ADMIN_STEP_UP_SECONDS);
    }
}
