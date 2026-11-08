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
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots each environment's own compiled container, not only the test one: the test container keeps every private
 * service as its own service, while dev and prod inline them. Inlined, a reference loop from the connection's
 * middlewares back to the connection made the dumped container create the connection before it set the
 * middlewares, so dev and prod ran with no TenantMiddleware and row-level security refused every tenant row.
 */
final class DefaultConnectionMiddlewareTest extends TestCase
{
    /**
     * Each environment with the debug mode it runs in: DoctrineBundle adds its logging and profiling middlewares only
     * with debug on, so prod is checked with debug off, as it runs.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev', true];
        yield 'prod' => ['prod', false];
        yield 'test' => ['test', true];
    }

    #[Test]
    #[DataProvider('environments')]
    public function theDefaultConnectionRunsThroughTheTenantFilterAndTracingMiddlewares(string $environment, bool $isDebug): void
    {
        $kernel = new Kernel($environment, $isDebug);
        // With debug on, the kernel rebuilds a container that is older than the config or a service class. With debug
        // off it never checks, so it builds into a new directory of its own instead of trusting var/cache/prod.
        $freshBuildDir = $isDebug ? null : sys_get_temp_dir().'/pinround-'.$environment.'-container-'.bin2hex(random_bytes(6));
        $kernel->reboot($freshBuildDir);

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
            if (null !== $freshBuildDir) {
                new Filesystem()->remove($freshBuildDir);
            }
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
