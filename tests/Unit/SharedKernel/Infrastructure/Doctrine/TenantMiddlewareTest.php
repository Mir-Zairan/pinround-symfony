<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantMiddlewareTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    /** @var list<string> */
    private array $driverCalls = [];

    private ?\Throwable $setTenantFailure = null;

    private ?\Throwable $rollBackFailure = null;

    #[Test]
    public function afterBeginItSetsTheOrgIdAsABoundTransactionLocalParameter(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new OrganizationId(self::ORG_A));

        $this->connectThroughTheMiddleware($tenantContext)->beginTransaction();

        self::assertSame(
            [
                'begin',
                "prepare SELECT set_config('app.org_id', ?, true)",
                'bind 1 '.self::ORG_A.' '.ParameterType::STRING->name,
                'execute',
            ],
            $this->driverCalls,
        );
    }

    #[Test]
    public function withoutATenantItOnlyBegins(): void
    {
        $this->connectThroughTheMiddleware(new TenantContext())->beginTransaction();

        self::assertSame(['begin'], $this->driverCalls);
    }

    #[Test]
    public function itReadsTheTenantAtEachBeginNotWhenConnecting(): void
    {
        $tenantContext = new TenantContext();
        $connection = $this->connectThroughTheMiddleware($tenantContext);

        $tenantContext->set(new OrganizationId(self::ORG_A));
        $connection->beginTransaction();

        self::assertSame(
            ['begin', "prepare SELECT set_config('app.org_id', ?, true)", 'bind 1 '.self::ORG_A.' STRING', 'execute'],
            $this->driverCalls,
        );
    }

    #[Test]
    public function whenSettingTheTenantFailsItRollsBackAndRethrowsTheFailure(): void
    {
        $this->setTenantFailure = new \RuntimeException('set_config failed');

        $thrown = $this->beginWithOrgA();

        self::assertSame($this->setTenantFailure, $thrown);
        self::assertSame(
            ['begin', "prepare SELECT set_config('app.org_id', ?, true)", 'bind 1 '.self::ORG_A.' STRING', 'execute', 'rollback'],
            $this->driverCalls,
        );
    }

    #[Test]
    public function whenTheRollbackAlsoFailsTheSetTenantFailureIsStillThrown(): void
    {
        $this->setTenantFailure = new \RuntimeException('set_config failed');
        $this->rollBackFailure = new \RuntimeException('rollback failed');

        $thrown = $this->beginWithOrgA();

        self::assertSame($this->setTenantFailure, $thrown);
        self::assertSame(
            ['begin', "prepare SELECT set_config('app.org_id', ?, true)", 'bind 1 '.self::ORG_A.' STRING', 'execute', 'rollback'],
            $this->driverCalls,
        );
    }

    private function beginWithOrgA(): ?\Throwable
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new OrganizationId(self::ORG_A));
        $connection = $this->connectThroughTheMiddleware($tenantContext);

        try {
            $connection->beginTransaction();
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private function connectThroughTheMiddleware(TenantContext $tenantContext): DriverConnection
    {
        $statement = self::createStub(Statement::class);
        $statement->method('bindValue')->willReturnCallback(function (int|string $param, mixed $value, ParameterType $type): void {
            $this->driverCalls[] = \sprintf('bind %s %s %s', $param, \is_string($value) ? $value : get_debug_type($value), $type->name);
        });
        $statement->method('execute')->willReturnCallback(function (): Result {
            $this->driverCalls[] = 'execute';

            return null === $this->setTenantFailure ? self::createStub(Result::class) : throw $this->setTenantFailure;
        });

        $driverConnection = self::createStub(DriverConnection::class);
        $driverConnection->method('beginTransaction')->willReturnCallback(function (): void {
            $this->driverCalls[] = 'begin';
        });
        $driverConnection->method('rollBack')->willReturnCallback(function (): void {
            $this->driverCalls[] = 'rollback';
            if (null !== $this->rollBackFailure) {
                throw $this->rollBackFailure;
            }
        });
        $driverConnection->method('prepare')->willReturnCallback(function (string $sql) use ($statement): Statement {
            $this->driverCalls[] = 'prepare '.$sql;

            return $statement;
        });

        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturn($driverConnection);

        return new TenantMiddleware($tenantContext)->wrap($driver)->connect([]);
    }
}
