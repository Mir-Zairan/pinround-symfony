<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Organization\Api\AdminPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides AdminPermission::AI (ADR-0013, FR-ADM-05): whoever may enter the admin pages of the organization may change
 * its AI settings. It asks ADMIN_AREA, so AdminAreaVoter alone owns the admin login rule (a token or a stale code is
 * refused here too, personas-and-roles.md note 6).
 *
 * @extends DenyByDefaultVoter<OrganizationId>
 */
final class AiSettingsVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
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
        return $this->accessDecisionManager->decide($token, [AdminPermission::AREA], $subject);
    }
}
