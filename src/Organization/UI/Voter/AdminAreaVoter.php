<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Identity\Api\LoginAssurance;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides AdminPermission::AREA (ADR-0012, ADR-0013): only an Admin of the organization the request works in, logged
 * in with their password in this session (never remember-me or a token, personas-and-roles.md note 6), with a
 * confirmed two-step login.
 *
 * The hackathon flag APP_HACKATHON_SKIP_ADMIN_MFA waives only the two-step login (ADR-0012, Hackathon exception), and
 * the Kernel refuses to boot with it outside dev and test. Delete the flag with P01.T6.
 *
 * @extends DenyByDefaultVoter<OrganizationId>
 */
final class AdminAreaVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly LoginAssurance $loginAssurance,
        #[Autowire(param: 'app.hackathon_skip_admin_mfa')]
        private readonly bool $isTwoStepLoginWaived,
    ) {
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
            && ($this->isTwoStepLoginWaived || $this->loginAssurance->hasConfirmedTwoStepLogin($token));
    }
}
