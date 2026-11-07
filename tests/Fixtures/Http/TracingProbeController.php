<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test-only endpoints (config/routes/test_fixtures.yaml) for the request span: one logs a record while the span is
 * active, the other throws. Neither reads the query string, headers or body, so a marker in them can only reach a
 * span through the tracing code itself.
 */
#[AsController]
final readonly class TracingProbeController
{
    public const string LOG_MESSAGE = 'Tracing probe record.';
    public const string FAILURE_MESSAGE = 'The tracing probe failed with a private detail.';

    public function __construct(private LoggerInterface $logger, private HttpKernelInterface $httpKernel)
    {
    }

    public function respondAndLog(): JsonResponse
    {
        $this->logger->info(self::LOG_MESSAGE);

        return new JsonResponse(['ok' => true]);
    }

    public function fail(): never
    {
        throw new \DomainException(self::FAILURE_MESSAGE);
    }

    public function forbid(): never
    {
        throw new AccessDeniedHttpException('The tracing probe refuses.');
    }

    /**
     * Runs a failing sub-request, which the kernel turns into an error response the main request ignores.
     */
    public function respondAfterAFailingSubRequest(Request $request): JsonResponse
    {
        $subRequest = $request->duplicate(attributes: ['_controller' => self::class.'::fail']);
        $subResponse = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);

        return new JsonResponse(['subRequestStatus' => $subResponse->getStatusCode()]);
    }
}
