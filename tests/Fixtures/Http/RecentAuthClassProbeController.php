<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\SharedKernel\UI\RequiresRecentAuth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A test-only controller (config/routes/test_fixtures.yaml) marked RequiresRecentAuth on the class, so every action of
 * it needs the proof. The ^test_ routes are public, so it refuses a visitor who is not logged in itself.
 */
#[AsController]
#[RequiresRecentAuth]
final readonly class RecentAuthClassProbeController
{
    public const string OPENED = 'An action of the marked controller ran.';

    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function openAction(): Response
    {
        if (!$this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException();
        }

        return new Response(self::OPENED);
    }
}
