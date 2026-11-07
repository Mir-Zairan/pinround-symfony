<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Kernel;
use App\SharedKernel\Infrastructure\Doctrine\TenantDriver;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilterMiddleware;
use App\SharedKernel\Infrastructure\Tracing\TracingDbalDriver;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Boots each environment's own compiled container, not only the test one: the test container keeps every private
 * service as its own service, while dev and prod inline them. Inlined, a reference loop from the connection's
 * middlewares back to the connection made the dumped container create the connection before it set the
 * middlewares, so dev and prod ran with no TenantMiddleware and row-level security refused every tenant row.
 */
final class DefaultConnectionMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
        yield 'test' => ['test'];
    }

    #[Test]
    #[DataProvider('environments')]
    public function theDefaultConnectionRunsThroughTheTenantFilterAndTracingMiddlewares(string $environment): void
    {
        // Debug on, so the container is rebuilt whenever config or a service class changed since the last build.
        $kernel = new Kernel($environment, true);
        $kernel->boot();

        try {
            $connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
            $appMiddlewares = array_map(self::appMiddlewareOf(...), self::driverChain($connection->getDriver()));

            // Outermost first; DoctrineBundle's own middlewares in between differ by environment and are left out.
            self::assertSame(
                [TenantFilterMiddleware::class, TenantDriver::class, TracingDbalDriver::class],
                array_values(array_filter($appMiddlewares, static fn (?string $middleware): bool => null !== $middleware)),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return list<Driver> the outermost driver first
     */
    private static function driverChain(Driver $driver): array
    {
        $chain = [$driver];
        while ($driver instanceof AbstractDriverMiddleware) {
            $wrappedDriver = new \ReflectionProperty(AbstractDriverMiddleware::class, 'wrappedDriver')->getValue($driver);
            self::assertInstanceOf(Driver::class, $wrappedDriver);
            $driver = $wrappedDriver;
            $chain[] = $driver;
        }

        return $chain;
    }

    /**
     * TenantFilterMiddleware's driver is an anonymous class, known by the middleware it holds.
     */
    private static function appMiddlewareOf(Driver $driver): ?string
    {
        if ($driver instanceof TenantDriver || $driver instanceof TracingDbalDriver) {
            return $driver::class;
        }

        $driverClass = new \ReflectionObject($driver);
        if ($driverClass->hasProperty('middleware') && $driverClass->getProperty('middleware')->getValue($driver) instanceof TenantFilterMiddleware) {
            return TenantFilterMiddleware::class;
        }

        return null;
    }
}
