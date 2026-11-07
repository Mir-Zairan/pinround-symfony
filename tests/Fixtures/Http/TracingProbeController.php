<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

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

    public function __construct(private LoggerInterface $logger)
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
}
