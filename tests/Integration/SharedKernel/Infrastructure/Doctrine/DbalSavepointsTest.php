<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\Savepoints;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalSavepoints on the real connection. A write is a transaction-local setting (set_config(..., true)), which
 * PostgreSQL undoes on ROLLBACK TO SAVEPOINT like any row, so the test needs no table.
 */
final class DbalSavepointsTest extends KernelTestCase
{
    private const string SETTING = 'pinround.savepoint_probe';

    protected function tearDown(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        parent::tearDown();
    }

    #[Test]
    public function aWriteThatReturnsIsKeptInTheCallersTransaction(): void
    {
        $this->connection()->beginTransaction();
        $this->write('outside');

        $settingInside = $this->savepoints()->run(function (): mixed {
            $this->write('inside');

            return $this->setting();
        });

        self::assertSame('inside', $settingInside);
        self::assertSame('inside', $this->setting());
        self::assertTrue($this->connection()->isTransactionActive());
    }

    #[Test]
    public function aWriteThatThrowsIsUndoneAloneAndTheCallersTransactionStaysUsable(): void
    {
        $this->connection()->beginTransaction();
        $this->write('outside');

        $refusal = null;
        try {
            $this->savepoints()->run(function (): mixed {
                $this->write('inside');
                if ('inside' === $this->setting()) {
                    throw new \DomainException('refused');
                }

                return $this->setting();
            });
        } catch (\DomainException $caught) {
            $refusal = $caught;
        }

        self::assertSame('refused', $refusal?->getMessage(), 'The refusal was not rethrown.');

        self::assertSame('outside', $this->setting());
        self::assertSame(1, $this->connection()->getTransactionNestingLevel());
        $this->connection()->commit();
    }

    #[Test]
    public function withNoTransactionOpenNothingRuns(): void
    {
        $hasRun = false;

        try {
            $this->savepoints()->run(static function () use (&$hasRun): void {
                $hasRun = true;
            });
            self::fail('A savepoint ran outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A savepoint is part of an open transaction; none is open.', $refusal->getMessage());
        }

        self::assertFalse($hasRun);
        self::assertFalse($this->connection()->isTransactionActive());
    }

    private function write(string $value): void
    {
        $this->connection()->fetchOne('SELECT set_config(?, ?, true)', [self::SETTING, $value]);
    }

    private function setting(): mixed
    {
        return $this->connection()->fetchOne('SELECT current_setting(?, true)', [self::SETTING]);
    }

    private function savepoints(): Savepoints
    {
        return self::getContainer()->get(Savepoints::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
