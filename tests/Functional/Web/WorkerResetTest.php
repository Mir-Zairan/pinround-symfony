<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Organization\SendsInvitationLinks;
use Doctrine\ORM\EntityManagerInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * In worker mode (ADR-0008) one kernel handles request after request; disableReboot() keeps the browser on one
 * kernel the same way. The kernel's services_resetter, run when the next main request starts, must leave it no
 * tenant and an open entity manager, through the test-only endpoints in config/routes/test_fixtures.yaml.
 */
final class WorkerResetTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use SendsInvitationLinks;

    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function noLoginSurvivesIntoTheNextRequestWithoutTheSessionCookie(): void
    {
        $this->logIn($this->client, $this->createUserWithPassword('Lena Berg'));
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
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
    public function theNextRequestHasNoTenantAndAnOpenEntityManagerAfterARefusedInvitationJoin(): void
    {
        $this->client->setServerParameter('HTTPS', 'on');
        $link = $this->invite($this->createOrganization('Acme Foods'), 'mia.'.bin2hex(random_bytes(4)).'@acme-foods.example');
        $this->client->request('GET', (string) parse_url($link, \PHP_URL_PATH));
        self::assertResponseRedirects('/invite', 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        $this->client->submitForm('Join', ['name' => 'Mia Lund', 'password' => 'elevenchars']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->tenantContext()->get());
        self::assertFalse($this->entityManager()->isOpen(), 'the refused accept no longer closes the entity manager, so this test proves nothing');

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
    }

    /**
     * Closing the connection ends its transaction without a ROLLBACK reaching the driver, so only the reset can tell
     * WorkflowCache the transaction is over; until it does, an invalidation outside a transaction waits for a commit.
     */
    #[Test]
    public function theNextRequestInvalidatesTheWorkflowCacheAtOnceAfterARequestClosedTheConnectionInsideATransaction(): void
    {
        $this->client->request('POST', '/_test/worker-reset/workflow-cache/'.self::ORG_A.'/close-then-fail');
        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->client->getContainer()->get('doctrine.dbal.default_connection')->isConnected());

        $this->client->request('POST', '/_test/worker-reset/workflow-cache/'.self::ORG_A.'/invalidate');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['entry' => 'gone after the invalidation'], $this->responseJson());
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

        $roots = array_values(array_filter(
            ExportedSpans::of($exporter),
            static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId()),
        ));
        self::assertCount(2, $roots);
        self::assertSame('HTTP GET test_tracing_fail', $roots[0]->getName());
        self::assertSame('HTTP GET test_tracing_respond', $roots[1]->getName());
        self::assertNotSame($roots[0]->getTraceId(), $roots[1]->getTraceId());
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
