<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Test-only endpoint (config/routes/test_fixtures.yaml) that puts the request's summary field everywhere a careless
 * module could log it: under a key no allow-list has, under a sensitive key, in a bound SQL value and in an exception
 * message. It then fails, so the test env's fingers_crossed handler writes every record of the request.
 */
#[AsController]
final readonly class LogLeakProbeController
{
    public const string SUMMARY_MESSAGE = 'Leak probe saw the summary "{summary}".';
    public const string DESCRIPTION_MESSAGE = 'Leak probe saw the description "{description}".';

    public function __construct(private LoggerInterface $logger, private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function logTheSummaryThenFail(Request $request, string $organizationId): never
    {
        $summary = $request->getPayload()->getString('summary');
        $this->tenantContext->set(new OrganizationId($organizationId));

        $this->logger->info(self::SUMMARY_MESSAGE, ['summary' => $summary]);
        $this->logger->info(self::DESCRIPTION_MESSAGE, ['description' => $summary]);
        $this->connection->fetchOne('SELECT length(?)', [$summary]);

        throw new \RuntimeException('Leak probe failed on '.$summary);
    }
}
