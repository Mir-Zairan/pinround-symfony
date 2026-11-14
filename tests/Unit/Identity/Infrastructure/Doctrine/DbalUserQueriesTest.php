<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Doctrine;

use App\Identity\Infrastructure\Doctrine\DbalUserQueries;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbalUserQueriesTest extends TestCase
{
    #[Test]
    public function noUserIdsGiveNoUsersWithoutAQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        self::assertSame([], new DbalUserQueries($connection)->byIds([]));
    }

    #[Test]
    public function noUserIdsGiveNoTimeZonesWithoutAQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        self::assertSame([], new DbalUserQueries($connection)->timeZonesOf([]));
    }
}
