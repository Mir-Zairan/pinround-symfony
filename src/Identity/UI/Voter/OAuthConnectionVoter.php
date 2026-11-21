<?php

declare(strict_types=1);

namespace App\Identity\UI\Voter;

use App\Identity\Api\CurrentUser;
use App\Identity\Api\LoginAssurance;
use App\Identity\Api\OAuthConnectionRefused;
use App\Identity\Application\OAuthConnectionOwner;
use App\Identity\Application\OAuthConnectionPermission;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides OAuthConnectionPermission::END (ADR-0013, ADR-0050): the connection's own person may end it, and so may
 * whoever may enter the admin pages of its organization. It asks ADMIN_AREA on the connection's organization, so
 * AdminAreaVoter alone owns the admin login rule and an Admin of another organization is refused. A request made with
 * a token never qualifies: connections are ended from browser pages, and a client signs out at the revoke endpoint.
 *
 * @extends DenyByDefaultVoter<OAuthConnectionOwner>
 */
final class OAuthConnectionVoter extends DenyByDefaultVoter
{
    /**
     * Organization's AdminPermission::AREA, spelled out because Identity may not see Organization's Api;
     * OAuthConnectionVoterTest compares it with Organization's constant, so a rename fails it.
     */
    public const string ADMIN_AREA_ATTRIBUTE = 'ADMIN_AREA';

    public const string TOKEN_REFUSAL_REASON = 'Ending a connection needs a browser session, not a token.';

    public function __construct(
        private readonly LoginAssurance $loginAssurance,
        private readonly CurrentUser $currentUser,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [OAuthConnectionPermission::END];
    }

    protected function subjectClass(): string
    {
        return OAuthConnectionOwner::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        if ($this->loginAssurance->isTokenAuthenticated($token)) {
            $vote?->addReason(self::TOKEN_REFUSAL_REASON);

            return false;
        }
        $userId = $this->currentUser->idOf($token->getUser());
        if (null === $userId) {
            return false;
        }
        if ($userId->equals($subject->userId) || $this->accessDecisionManager->decide($token, [self::ADMIN_AREA_ATTRIBUTE], $subject->organizationId)) {
            return true;
        }
        $vote?->addReason(OAuthConnectionRefused::NOT_YOURS);

        return false;
    }
}
