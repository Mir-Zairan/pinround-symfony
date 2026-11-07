<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\SharedKernel\Infrastructure\Health\DatabaseAnswers;
use App\SharedKernel\Infrastructure\Health\ObjectStorageIsReachable;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\ConnectionException;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * /health/live and /health/ready (ADR-0029) against the compose stack: PostgreSQL, both Valkeys and MinIO.
 */
final class HealthTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
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

    #[Test]
    public function readyAnswersOkWhenEveryDependencyAnswers(): void
    {
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
        // The same listener sees the transaction of a route that does not opt out.
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        self::assertSame(
            [
                '/health/live' => ['isTransactionActive' => false, 'isSessionStarted' => false],
                '/health/ready' => ['isTransactionActive' => false, 'isSessionStarted' => false],
                '/' => ['isTransactionActive' => true, 'isSessionStarted' => false],
            ],
            $observed,
        );
        self::assertSame([], $this->client->getCookieJar()->all());
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
