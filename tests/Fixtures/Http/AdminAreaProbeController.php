<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\Organization\Api\AdminPermission;
use App\SharedKernel\Application\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A test-only admin page (config/routes/test_fixtures.yaml) guarded by ADMIN_AREA on the organization the request
 * works in, as the admin pages of P08 and P11 are to be. Without an organization the subject is null, which no voter
 * decides, so the request is denied.
 */
#[AsController]
final readonly class AdminAreaProbeController
{
    public function __construct(private AuthorizationCheckerInterface $authorizationChecker, private TenantContext $tenantContext)
    {
    }

    public function openAdminArea(): JsonResponse
    {
        $organizationId = $this->tenantContext->get();
        if (!$this->authorizationChecker->isGranted(AdminPermission::AREA, $organizationId)) {
            throw new AccessDeniedException();
        }

        return new JsonResponse(['organizationId' => $organizationId?->toString()]);
    }
}
