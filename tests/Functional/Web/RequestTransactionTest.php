<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Tests\Integration\Tenancy\TenantProbeTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proves the one-transaction-per-request rule of RequestTransaction through the test-only endpoints in
 * config/routes/test_fixtures.yaml, which write to zz_tenant_probe.
 */
final class RequestTransactionTest extends WebTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    private KernelBrowser $client;

    public static function setUpBeforeClass(): void
    {
        self::probeTable()->recreate();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::probeTable()->drop();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::probeTable()->removeAllRows();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    #[Test]
    public function aRequestRunsInsideOneTransactionAndLeavesNoneOpen(): void
    {
        $this->client->request('GET', '/_test/request-transaction');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['isTransactionActive' => true, 'nestingLevel' => 1], $this->responseJson());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function theTransactionBeginsAfterTheSecurityFirewallFillsTheTenant(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $subscriber = self::getContainer()->get(RequestTransaction::class);

        $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$subscriber, 'beginTransaction']);

        self::assertSame(7, $priority);
    }

    #[Test]
    public function aSuccessfulRequestCommitsItsWrite(): void
    {
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/kept');

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(['kept'], $this->committedLabels());
    }

    #[Test]
    public function aRequestThatThrowsCommitsNothingAndLeavesNoTransactionOpen(): void
    {
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/thrown-away/throw');

        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame([], $this->committedLabels());
    }

    #[Test]
    public function aFailedCommitBecomesAnErrorResponseAndLeavesNoTransactionOpen(): void
    {
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/not-committed/fail-commit');

        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame([], $this->committedLabels());
    }

    #[Test]
    public function aRequestThatLeavesANestedTransactionOpenCommitsNothing(): void
    {
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/half-done/leave-nested-open');

        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame([], $this->committedLabels());
    }

    #[Test]
    public function aFailingSubRequestNeitherEndsNorBeginsTheMainTransaction(): void
    {
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/around-sub-request/failing-sub-request');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['isTransactionActive' => true, 'visibleRowsWithLabel' => 1], $this->responseJson());
        self::assertSame(['around-sub-request'], $this->committedLabels());
    }

    #[Test]
    public function aLostConnectionStillGivesAnErrorResponseAndCommitsNothing(): void
    {
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/lost/lose-connection');

        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame([], $this->committedLabels());
    }

    #[Test]
    public function theNextRequestOnTheSameKernelStartsWithAFreshTransaction(): void
    {
        $this->client->disableReboot();
        $this->client->request('POST', '/_test/request-transaction/'.self::ORG_A.'/thrown-away/throw');
        self::assertResponseStatusCodeSame(500);

        $this->client->request('GET', '/_test/request-transaction');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['isTransactionActive' => true, 'nestingLevel' => 1], $this->responseJson());
    }

    #[Test]
    public function aWorkerResetRollsBackATransactionTheRequestNeverEnded(): void
    {
        $requestTransaction = self::getContainer()->get(RequestTransaction::class);
        $requestTransaction->beginTransaction($this->mainRequestEvent());

        $requestTransaction->reset();

        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function aMainRequestThatFindsAnOpenRequestTransactionRollsItBackFirst(): void
    {
        $requestTransaction = self::getContainer()->get(RequestTransaction::class);
        $requestTransaction->beginTransaction($this->mainRequestEvent());

        $requestTransaction->beginTransaction($this->mainRequestEvent());

        self::assertSame(1, $this->connection()->getTransactionNestingLevel());
        $requestTransaction->reset();
    }

    #[Test]
    public function aRollbackThatFailsClosesTheConnectionInsteadOfThrowing(): void
    {
        $rollBackFails = new class(new PgSQLDriver()) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function rollBack(): void
                    {
                        throw new \RuntimeException('ROLLBACK failed on purpose');
                    }
                };
            }
        };
        $connection = new Connection($this->connection()->getParams(), $rollBackFails);
        $requestTransaction = new RequestTransaction($connection);
        $requestTransaction->beginTransaction($this->mainRequestEvent());

        $requestTransaction->reset();

        self::assertFalse($connection->isTransactionActive());
        self::assertFalse($connection->isConnected());
    }

    private static function probeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * @return list<mixed>
     */
    private function committedLabels(): array
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ORG_A));
        $labels = $this->connection()->transactional(
            static fn (Connection $connection): array => $connection->fetchFirstColumn('SELECT label FROM zz_tenant_probe ORDER BY label'),
        );
        $tenantContext->clear();

        return $labels;
    }

    private function responseJson(): mixed
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function mainRequestEvent(): RequestEvent
    {
        return new RequestEvent(self::$kernel ?? throw new \LogicException('No kernel.'), Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
    }
}
