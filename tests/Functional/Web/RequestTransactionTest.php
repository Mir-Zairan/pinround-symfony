<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Tests\Integration\Tenancy\TenantProbeTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Loader\YamlFileLoader;

/**
 * Proves the one-transaction-per-request rule of RequestTransaction through the test-only endpoints in
 * config/routes/test_fixtures.yaml, which write to zz_tenant_probe.
 */
final class RequestTransactionTest extends WebTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const array TEST_ROUTE_NAMES = [
        'test_request_transaction_report',
        'test_request_transaction_insert',
        'test_request_transaction_insert_then_throw',
        'test_request_transaction_insert_then_fail_commit',
        'test_request_transaction_leave_nested_open',
        'test_request_transaction_failing_sub_request',
        'test_request_transaction_lose_connection',
        'test_worker_reset_report',
        'test_worker_reset_enter',
        'test_worker_reset_enter_then_fail',
        'test_worker_reset_workflow_cache_close_then_fail',
        'test_worker_reset_workflow_cache_invalidate',
        'test_worker_reset_team_people_cache_close_then_fail',
        'test_worker_reset_team_people_cache_invalidate',
        'test_current_actor_report',
        'test_admin_area',
        'test_log_leak',
        'test_recent_auth',
        'test_recent_auth_forward',
        'test_recent_auth_class',
        'test_tracing_respond',
        'test_tracing_fail',
        'test_tracing_forbid',
        'test_tracing_sub_request',
        'test_tracing_server_error',
        'test_tracing_dispatch',
        'test_tracing_query',
        'test_menu_shell',
    ];

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
    public function theTransactionBeginsAtKernelRequestPriority6(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $requestTransaction = self::getContainer()->get(RequestTransaction::class);

        $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$requestTransaction, 'beginTransaction']);

        self::assertSame(6, $priority);
    }

    #[Test]
    public function theTestOnlyRoutesExistInTheTestKernelAndNowhereElse(): void
    {
        $router = self::getContainer()->get('router');
        $testRouteNames = array_values(array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $name): bool => str_starts_with($name, 'test_'),
        ));
        self::assertEqualsCanonicalizing(self::TEST_ROUTE_NAMES, $testRouteNames);

        foreach (['dev', 'prod'] as $environment) {
            $loader = new YamlFileLoader(new FileLocator(self::$kernel?->getProjectDir().'/config/routes'), $environment);
            self::assertSame([], $loader->load('test_fixtures.yaml')->all(), $environment);
        }
        $testLoader = new YamlFileLoader(new FileLocator(self::$kernel?->getProjectDir().'/config/routes'), 'test');
        self::assertEqualsCanonicalizing(self::TEST_ROUTE_NAMES, array_keys($testLoader->load('test_fixtures.yaml')->all()));
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

    /**
     * @return iterable<string, array{\Closure(): \Throwable, array<string, mixed>}>
     */
    public static function rollBackFailures(): iterable
    {
        yield 'a driver error' => [
            static fn (): \Throwable => new class('ROLLBACK failed on purpose, row value 42', '25P01', 7) extends AbstractException {},
            ['exception_class' => DriverException::class, 'sqlstate' => '25P01', 'code' => 7],
        ];
        yield 'any other error' => [
            static fn (): \Throwable => new \RuntimeException('ROLLBACK failed on purpose, row value 42'),
            ['exception_class' => \RuntimeException::class, 'sqlstate' => null, 'code' => 0],
        ];
    }

    /**
     * @param \Closure(): \Throwable $rollBackFailure
     * @param array<string, mixed>   $expectedContext
     */
    #[Test]
    #[DataProvider('rollBackFailures')]
    public function aRollbackThatFailsIsLoggedWithoutItsMessageAndClosesTheConnection(\Closure $rollBackFailure, array $expectedContext): void
    {
        $rollBackFails = new class(new PgSQLDriver(), $rollBackFailure) extends AbstractDriverMiddleware {
            /**
             * @param \Closure(): \Throwable $rollBackFailure
             */
            public function __construct(Driver $driver, private readonly \Closure $rollBackFailure)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->rollBackFailure) extends AbstractConnectionMiddleware {
                    /**
                     * @param \Closure(): \Throwable $rollBackFailure
                     */
                    public function __construct(DriverConnection $connection, private readonly \Closure $rollBackFailure)
                    {
                        parent::__construct($connection);
                    }

                    public function rollBack(): void
                    {
                        throw ($this->rollBackFailure)();
                    }
                };
            }
        };
        $connection = new Connection($this->connection()->getParams(), $rollBackFails);
        $logger = new BufferingLogger();
        $requestTransaction = new RequestTransaction($connection, $logger);
        $requestTransaction->beginTransaction($this->mainRequestEvent());

        $requestTransaction->reset();

        self::assertFalse($connection->isTransactionActive());
        self::assertFalse($connection->isConnected());
        self::assertSame(
            [['warning', 'Rolling back the request transaction failed; closing the connection instead.', $expectedContext]],
            $logger->cleanLogs(),
        );
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
