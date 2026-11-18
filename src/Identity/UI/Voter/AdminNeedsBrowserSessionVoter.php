<?php

declare(strict_types=1);

namespace App\Identity\UI\Voter;

use App\Identity\Api\LoginAssurance;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Denies every admin-only attribute, on any subject, to a request a personal access token authenticated (ADR-0012,
 * ADR-0013, ADR-0021): admin work needs a browser session. Admin-only are every ADMIN_* attribute and those in
 * ADMIN_ONLY_ATTRIBUTES. The strategy is unanimous, so this deny outweighs the grant of AdminAreaVoter, AiSettingsVoter,
 * OrganizationVoter and any admin voter added later. It never grants: for any other actor or attribute it abstains, and
 * the attribute's own voter decides.
 */
final readonly class AdminNeedsBrowserSessionVoter implements CacheableVoterInterface
{
    public const string ADMIN_ATTRIBUTE_PREFIX = 'ADMIN_';

    /**
     * The admin-only attributes without the prefix: Organization's OrganizationPermission::ADMIN and ::INVITE, which
     * OrganizationVoter grants an Admin whatever their login. Spelled out because Identity may not see Organization's
     * Api; AdminNeedsBrowserSessionVoterTest asks them by Organization's constants, so a rename fails it.
     */
    public const array ADMIN_ONLY_ATTRIBUTES = ['ORG_ADMIN', 'ORG_INVITE'];

    public const string REFUSAL_REASON = 'Admin work needs a browser session, not a personal access token.';

    public function __construct(private LoginAssurance $loginAssurance)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return str_starts_with($attribute, self::ADMIN_ATTRIBUTE_PREFIX) || \in_array($attribute, self::ADMIN_ONLY_ATTRIBUTES, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return true;
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        if (!$this->loginAssurance->isTokenAuthenticated($token)) {
            return self::ACCESS_ABSTAIN;
        }

        foreach ($attributes as $attribute) {
            if (\is_string($attribute) && $this->supportsAttribute($attribute)) {
                $vote?->addReason(self::REFUSAL_REASON);

                return self::ACCESS_DENIED;
            }
        }

        return self::ACCESS_ABSTAIN;
    }
}
