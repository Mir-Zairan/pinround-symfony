<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\SharedKernel\UI\RequiresRecentAuth;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A test-only sensitive action (config/routes/test_fixtures.yaml) marked RequiresRecentAuth, as the token, credential
 * and role pages of later phases are to be, and an unmarked action that reaches it through a sub-request, as
 * AbstractController::forward() does. The ^test_ routes are public, so the sensitive action refuses a visitor who is
 * not logged in itself.
 */
#[AsController]
final readonly class RecentAuthProbeController
{
    public const string OPENED = 'The sensitive action ran.';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private RequestStack $requestStack,
        private HttpKernelInterface $httpKernel,
    ) {
    }

    #[RequiresRecentAuth]
    public function openSensitiveAction(): Response
    {
        if (!$this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException();
        }

        return new Response(self::OPENED);
    }

    public function forwardToSensitiveAction(): Response
    {
        $request = $this->requestStack->getCurrentRequest() ?? throw new \LogicException('A forward needs a request.');
        $subRequest = $request->duplicate([], null, ['_controller' => self::class.'::openSensitiveAction']);

        return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }
}
