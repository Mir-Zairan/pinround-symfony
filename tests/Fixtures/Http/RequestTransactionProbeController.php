<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test-only endpoints (config/routes/test_fixtures.yaml) that report and exercise the request transaction.
 */
#[AsController]
final readonly class RequestTransactionProbeController
{
    public function __construct(private Connection $connection, private HttpKernelInterface $httpKernel)
    {
    }

    public function reportTransaction(): JsonResponse
    {
        return new JsonResponse([
            'isTransactionActive' => $this->connection->isTransactionActive(),
            'nestingLevel' => $this->connection->getTransactionNestingLevel(),
        ]);
    }

    public function insertLabel(string $organizationId, string $label): JsonResponse
    {
        $this->insertProbeRow($organizationId, $label);

        return new JsonResponse(['inserted' => $label]);
    }

    public function insertLabelThenThrow(string $organizationId, string $label): never
    {
        $this->insertProbeRow($organizationId, $label);

        throw new \RuntimeException('The controller failed after its write.');
    }

    public function insertLabelThenFailTheCommit(string $organizationId, string $label): JsonResponse
    {
        $this->insertProbeRow($organizationId, $label);
        $this->connection->setRollbackOnly();

        return new JsonResponse(['inserted' => $label]);
    }

    public function leaveANestedTransactionOpen(string $organizationId, string $label): JsonResponse
    {
        $this->connection->beginTransaction();
        $this->insertProbeRow($organizationId, $label);

        return new JsonResponse(['inserted' => $label]);
    }

    public function insertLabelAroundAFailingSubRequest(Request $request, string $organizationId, string $label): JsonResponse
    {
        $this->insertProbeRow($organizationId, $label);

        $subRequest = $request->duplicate(attributes: ['_controller' => self::class.'::failInASubRequest']);
        $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);

        return new JsonResponse([
            'isTransactionActive' => $this->connection->isTransactionActive(),
            'visibleRowsWithLabel' => $this->connection->fetchOne('SELECT count(*) FROM zz_tenant_probe WHERE label = ?', [$label]),
        ]);
    }

    public function failInASubRequest(): never
    {
        throw new \RuntimeException('The sub-request failed.');
    }

    public function insertLabelThenLoseTheConnection(string $organizationId, string $label): never
    {
        $this->insertProbeRow($organizationId, $label);
        $this->connection->executeQuery('SELECT pg_terminate_backend(pg_backend_pid())');

        throw new \LogicException('The connection survived its own termination.');
    }

    /**
     * No firewall sets the tenant yet, so the probe sets it for the request transaction the way TenantMiddleware does.
     */
    private function insertProbeRow(string $organizationId, string $label): void
    {
        $this->connection->executeQuery("SELECT set_config('app.org_id', ?, true)", [$organizationId]);
        $this->connection->executeStatement(
            'INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)',
            [$organizationId, $label],
        );
    }
}
