<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use Doctrine\ORM\EntityManagerInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In worker mode (ADR-0008) one kernel handles request after request; disableReboot() keeps the browser on one
 * kernel the same way. The kernel's services_resetter, run when the next main request starts, must leave it no
 * tenant and an open entity manager, through the test-only endpoints in config/routes/test_fixtures.yaml.
 */
final class WorkerResetTest extends WebTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function theNextRequestHasNoTenantAfterARequestThatEndedNormally(): void
    {
        $this->client->request('POST', '/_test/worker-reset/'.self::ORG_A);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => self::ORG_A, 'isEntityManagerOpen' => true], $this->responseJson());

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
    }

    #[Test]
    public function theNextRequestHasNoTenantAndAnOpenEntityManagerAfterARequestThatThrew(): void
    {
        $this->client->request('POST', '/_test/worker-reset/'.self::ORG_A.'/fail');
        self::assertResponseStatusCodeSame(500);
        self::assertSame(self::ORG_A, $this->tenantContext()->get()?->toString());
        self::assertFalse($this->entityManager()->isOpen());

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
    }

    #[Test]
    public function theNextRequestStartsANewTraceWithNoParentAfterARequestThatThrew(): void
    {
        $exporter = $this->client->getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $this->client->request('GET', '/_test/tracing/fail');
        self::assertResponseStatusCodeSame(500);
        $this->client->request('GET', '/_test/tracing');
        self::assertResponseStatusCodeSame(200);

        $spans = ExportedSpans::of($exporter);
        self::assertCount(2, $spans);
        self::assertNotSame($spans[0]->getTraceId(), $spans[1]->getTraceId());
        self::assertFalse(SpanContextValidator::isValidSpanId($spans[1]->getParentSpanId()));
        self::assertNull($this->client->getContainer()->get(CurrentSpan::class)->span());
    }

    private function tenantContext(): TenantContext
    {
        return $this->client->getContainer()->get(TenantContext::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->client->getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function responseJson(): mixed
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
