<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\Identity\Api\CurrentUser;
use App\Identity\Api\LoginAssurance;
use App\Organization\Api\AdminPermission;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A test-only endpoint under /mcp and /api/v1 (config/routes/test_fixtures.yaml) that reports who a personal access
 * token authenticated, the organization the request works in, and the organizations whose idn_api_token rows the
 * request transaction can see, which row-level security limits to the tenant's; or how each ADMIN_* attribute is
 * decided on the tenant, with the reasons of the deciding votes.
 */
#[AsController]
final readonly class TokenProbeController
{
    public function __construct(
        private CurrentUser $currentUser,
        private TenantContext $tenantContext,
        private LoginAssurance $loginAssurance,
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function reportTokenActor(): JsonResponse
    {
        $access = $this->loginAssurance->apiTokenAccessOf($this->tokenStorage->getToken());

        return new JsonResponse([
            'userId' => $this->currentUser->id()?->toString(),
            'organizationId' => $this->tenantContext->get()?->toString(),
            'tokenId' => $access?->tokenId,
            'scopes' => $access?->scopes,
            'visibleTokenOrganizationIds' => $this->connection->fetchFirstColumn('SELECT DISTINCT organization_id::text FROM idn_api_token ORDER BY 1'),
        ]);
    }

    public function reportAdminDecisions(): JsonResponse
    {
        $decisions = [];
        foreach ([AdminPermission::AREA, AdminPermission::AI] as $attribute) {
            $accessDecision = new AccessDecision();
            $decisions[$attribute] = [
                'isGranted' => $this->authorizationChecker->isGranted($attribute, $this->tenantContext->get(), $accessDecision),
                'message' => $accessDecision->getMessage(),
            ];
        }

        return new JsonResponse($decisions);
    }
}
