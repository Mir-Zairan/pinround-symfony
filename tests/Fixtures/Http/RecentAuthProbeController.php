<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\SharedKernel\UI\RequiresRecentAuth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A test-only sensitive action (config/routes/test_fixtures.yaml) marked RequiresRecentAuth, as the token, credential
 * and role pages of later phases are to be. The ^test_ routes are public, so it refuses a visitor who is not logged
 * in itself.
 */
#[AsController]
final readonly class RecentAuthProbeController
{
    public const string OPENED = 'The sensitive action ran.';

    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    #[RequiresRecentAuth]
    public function openSensitiveAction(): Response
    {
        if (!$this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException();
        }

        return new Response(self::OPENED);
    }
}
