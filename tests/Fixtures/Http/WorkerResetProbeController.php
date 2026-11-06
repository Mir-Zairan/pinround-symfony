<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Test-only endpoints (config/routes/test_fixtures.yaml) that leave a tenant and a closed entity manager behind,
 * and report what the next request on the same worker starts with.
 */
#[AsController]
final readonly class WorkerResetProbeController
{
    public function __construct(private TenantContext $tenantContext, private EntityManagerInterface $entityManager)
    {
    }

    public function reportWorkerState(): JsonResponse
    {
        return new JsonResponse([
            'organizationId' => $this->tenantContext->get()?->toString(),
            'isEntityManagerOpen' => $this->entityManager->isOpen(),
        ]);
    }

    public function enterOrganization(string $organizationId): JsonResponse
    {
        $this->tenantContext->set(new OrganizationId($organizationId));

        return $this->reportWorkerState();
    }

    /**
     * Closes the entity manager the way a failed flush does, then fails.
     */
    public function enterOrganizationThenFail(string $organizationId): never
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        $this->entityManager->close();

        throw new \RuntimeException('The controller failed after entering the organization.');
    }
}
