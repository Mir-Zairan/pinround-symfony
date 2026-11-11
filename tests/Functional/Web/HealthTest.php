<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Audit\Infrastructure\Health\NextAuditPartitionExists;
use App\Media\Infrastructure\Health\ObjectStorageIsReachable;
use App\SharedKernel\Infrastructure\Health\DatabaseAnswers;
use App\SharedKernel\Infrastructure\Health\MigrationsAreCurrent;
use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Ticketing\Infrastructure\Health\NextTicketEventPartitionExists;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * /health/live and /health/ready (ADR-0029) against the compose stack: PostgreSQL, both Valkeys and MinIO.
 */
final class HealthTest extends WebTestCase
{
    /** Every readiness check's service id, with its name. */
    private const array READINESS_CHECKS = [
        DatabaseAnswers::class => 'database',
        MigrationsAreCurrent::class => 'migrations',
        NextAuditPartitionExists::class => 'next_audit_partition',
        NextTicketEventPartitionExists::class => 'next_ticket_event_partition',
        'health.cache_valkey_answers' => 'cache_valkey',
        'health.state_valkey_answers' => 'state_valkey',
        ObjectStorageIsReachable::class => 'object_storage',
    ];

    // Inside the months the audit migration created partitions for (2026-11 to 2027-12), so next month always has one.
    private const string NOW = '2027-03-15 10:00:00 UTC';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $addedTicketEventPartitions = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->addedTicketEventPartitions as $partition) {
            self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('DROP TABLE '.$partition);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function internalCallers(): iterable
    {
        yield 'IPv4 loopback (the container healthcheck)' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'a private network (a load balancer)' => ['10.0.0.5'];
        yield 'the compose network' => ['172.18.0.4'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outsideCallersOnEachRoute(): iterable
    {
        // 203.0.113.0/24 and 2001:db8::/32 are in IpUtils::PRIVATE_SUBNETS, so they also prove that list is not used.
        foreach (['/health/live', '/health/ready'] as $path) {
            yield $path.' from a public IPv4' => [$path, '203.0.113.7'];
            yield $path.' from a public IPv6' => [$path, '2001:db8::7'];
            yield $path.' from a public IPv4 mapped to IPv6' => [$path, '::ffff:203.0.113.7'];
            yield $path.' from no address' => [$path, ''];
        }
    }

    #[Test]
    #[DataProvider('internalCallers')]
    public function liveAnswersOkToAnInternalCaller(string $clientIp): void
    {
        $this->client->request('GET', '/health/live', server: ['REMOTE_ADDR' => $clientIp]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"status":"ok"}', $this->client->getResponse()->getContent());
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    #[DataProvider('outsideCallersOnEachRoute')]
    public function anOutsideCallerGetsTheNotFoundOfAMissingRoute(string $path, string $clientIp): void
    {
        $missingRoutePage = $this->client->request('GET', '/no-such-page', server: ['REMOTE_ADDR' => $clientIp]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('No route found for "GET http://localhost/no-such-page" (404 Not Found)', $missingRoutePage->filter('title')->text());
        $missingRouteContentType = $this->client->getResponse()->headers->get('Content-Type');

        $healthPage = $this->client->request('GET', $path, server: ['REMOTE_ADDR' => $clientIp]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($missingRouteContentType, $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(\sprintf('No route found for "GET http://localhost%s" (404 Not Found)', $path), $healthPage->filter('title')->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function callerMistakes(): iterable
    {
        yield 'a missing route' => ['GET', '/no-such-page'];
        yield 'an outside caller of a health route' => ['GET', '/health/ready'];
        yield 'a wrong method' => ['POST', '/health/live'];
    }

    #[Test]
    #[DataProvider('callerMistakes')]
    public function aCallersMistakeIsLoggedAtDebugNotAsAnError(string $method, string $path): void
    {
        $capturedLogRecords = $this->capturedLogRecords();
        $capturedLogRecords->clear();

        $this->client->request($method, $path, server: ['REMOTE_ADDR' => '203.0.113.7']);

        self::assertResponseStatusCodeSame('POST' === $method ? 405 : 404);
        $levels = array_map(static fn (LogRecord $record): Level => $record->level, array_values(array_filter(
            $capturedLogRecords->all(),
            static fn (LogRecord $record): bool => str_starts_with($record->message, 'Uncaught PHP Exception'),
        )));
        self::assertSame([Level::Debug], $levels);
    }

    #[Test]
    public function readyRunsEveryReadinessCheckOnceWithTheDatabaseFirst(): void
    {
        /** @var \ArrayObject<int, string> $ranChecks */
        $ranChecks = new \ArrayObject();
        foreach (self::READINESS_CHECKS as $serviceId => $name) {
            self::getContainer()->set($serviceId, new readonly class($name, $ranChecks) implements ReadinessCheck {
                /**
                 * @param \ArrayObject<int, string> $ranChecks
                 */
                public function __construct(private string $name, private \ArrayObject $ranChecks)
                {
                }

                public function name(): string
                {
                    return $this->name;
                }

                public function verify(): void
                {
                    $this->ranChecks->append($this->name);
                }
            });
        }

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);
        $ranCheckNames = $ranChecks->getArrayCopy();
        // The checks that query the database rely on it having answered within its short timeout first.
        self::assertSame('database', $ranCheckNames[0] ?? null);
        self::assertEqualsCanonicalizing(array_values(self::READINESS_CHECKS), $ranCheckNames);
        // So a check added later cannot run unrecorded above: these are all the container tags.
        self::assertEqualsCanonicalizing(array_keys(self::READINESS_CHECKS), self::taggedReadinessCheckIds());
    }

    #[Test]
    public function readyAnswersOkWhenEveryDependencyAnswers(): void
    {
        $this->freezeTheClockAt(self::NOW);

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"status":"ok"}', $this->client->getResponse()->getContent());
    }

    #[Test]
    public function readyAnswersFailWhenNextMonthHasNoAuditPartition(): void
    {
        $this->freezeTheClockAt('2027-12-15 10:00:00 UTC');
        $capturedLogRecords = $this->capturedLogRecords();
        $capturedLogRecords->clear();

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('{"status":"fail"}', $this->client->getResponse()->getContent());
        $failedChecks = array_map(
            static fn (LogRecord $record): mixed => $record->context['check'] ?? null,
            array_values(array_filter($capturedLogRecords->all(), static fn (LogRecord $record): bool => Level::Warning === $record->level)),
        );
        self::assertSame(['next_audit_partition'], $failedChecks);
    }

    #[Test]
    public function readyAnswersOkWhenTheMigrationsTableIsReadAsTheAppRoleAsInProd(): void
    {
        $this->freezeTheClockAt(self::NOW);
        // Prod has no migrator connection: the migrations DependencyFactory uses the app's (Version20261107180000).
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrationsAsTheApp = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->defaultConnection()));
        self::getContainer()->set(MigrationsAreCurrent::class, new MigrationsAreCurrent($migrationsAsTheApp));
        self::assertSame('pinround_app', $this->defaultConnection()->fetchOne('SELECT current_user'));

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"status":"ok"}', $this->client->getResponse()->getContent());
    }

    #[Test]
    public function readyAnswersFailWhenPostgresqlDoesNotAnswerAndLogsOnlyThatCheck(): void
    {
        // Nothing listens on port 1.
        $unreachableDatabase = new Connection(['host' => '127.0.0.1', 'port' => 1] + $this->defaultConnection()->getParams(), new PgSQLDriver());
        self::getContainer()->set(DatabaseAnswers::class, new DatabaseAnswers($unreachableDatabase));
        // Would log a second warning if the checks after the database still ran.
        self::getContainer()->set(ObjectStorageIsReachable::class, new ObjectStorageIsReachable(''));
        $capturedLogRecords = $this->capturedLogRecords();
        $capturedLogRecords->clear();

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('{"status":"fail"}', $this->client->getResponse()->getContent());
        $warnings = array_values(array_filter(
            $capturedLogRecords->all(),
            static fn (LogRecord $record): bool => Level::Warning === $record->level,
        ));
        self::assertCount(1, $warnings);
        self::assertSame('A readiness check failed.', $warnings[0]->message);
        self::assertSame(
            ['check' => 'database', 'exception_class' => ConnectionException::class, 'sqlstate' => '08006', 'code' => 7],
            $warnings[0]->context,
        );
    }

    #[Test]
    public function neitherRouteOpensATransactionOrStartsASession(): void
    {
        $this->freezeTheClockAt(self::NOW);
        $this->client->disableReboot();
        $observed = [];
        $connection = $this->defaultConnection();
        $this->eventDispatcher()->addListener(
            KernelEvents::RESPONSE,
            static function (ResponseEvent $event) use (&$observed, $connection): void {
                $request = $event->getRequest();
                $observed[$request->getPathInfo()] = [
                    'isTransactionActive' => $connection->isTransactionActive(),
                    'isSessionStarted' => $request->hasSession() && $request->getSession()->isStarted(),
                ];
            },
        );

        $this->client->request('GET', '/health/live');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($connection->isConnected(), 'live touched the database');
        $this->client->request('GET', '/health/ready');
        self::assertResponseStatusCodeSame(200);
        // The same listener sees the transaction of a route that does not opt out (a public one, so no login starts a session).
        $this->client->request('GET', '/_test/tracing');
        self::assertResponseStatusCodeSame(200);

        self::assertSame(
            [
                '/health/live' => ['isTransactionActive' => false, 'isSessionStarted' => false],
                '/health/ready' => ['isTransactionActive' => false, 'isSessionStarted' => false],
                '/_test/tracing' => ['isTransactionActive' => true, 'isSessionStarted' => false],
            ],
            $observed,
        );
        self::assertSame([], $this->client->getCookieJar()->all());
    }

    /**
     * @return list<string> the ids of every service tagged as a ReadinessCheck, read from the compiled container
     */
    private static function taggedReadinessCheckIds(): array
    {
        $debugContainer = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('debug:container'));
        $debugContainer->execute(['--tag' => ReadinessCheck::class, '--format' => 'json']);
        $debugContainer->assertCommandIsSuccessful();
        $output = json_decode($debugContainer->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertIsArray($output['definitions'] ?? null);

        return array_map(strval(...), array_keys($output['definitions']));
    }

    /**
     * NextAuditPartitionExists and NextTicketEventPartitionExists read this clock. Call it before the test's first
     * request: the client reboots the kernel after it, which drops the frozen clock unless the test disabled reboots.
     * The migration made tkt_ticket_event partitions from the wall clock, so the job adds the frozen months' ones and
     * tearDown drops what it added.
     */
    private function freezeTheClockAt(string $now): void
    {
        self::getContainer()->set('clock', new MockClock($now));
        $this->addedTicketEventPartitions = [
            ...$this->addedTicketEventPartitions,
            ...new AddTicketEventPartitions(self::getContainer()->get('doctrine.dbal.migrator_connection'), new MockClock($now))(),
        ];
    }

    private function capturedLogRecords(): CapturedLogRecords
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);

        return $capturedLogRecords;
    }

    private function defaultConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        return self::getContainer()->get('event_dispatcher');
    }
}
