<?php

declare(strict_types=1);

namespace App\Identity\UI\Voter;

use App\Identity\Application\OAuthConnectionAccess;
use App\Identity\Application\OAuthConnectionOwner;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * OAuthConnectionAccess through the app's access decision manager, so OAuthConnectionVoter decides with every other
 * voter, on the request's token; with none, Symfony decides for a NullToken and OAuthConnectionVoter denies.
 */
final readonly class SymfonyOAuthConnectionAccess implements OAuthConnectionAccess
{
    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function isGranted(string $attribute, OAuthConnectionOwner $connection): bool
    {
        return $this->authorizationChecker->isGranted($attribute, $connection);
    }
}
