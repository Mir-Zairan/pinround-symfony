<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Doctrine;

use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbalTeamQueriesTest extends TestCase
{
    #[Test]
    public function noTeamIdsGiveAnEmptyListWithoutAQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        self::assertSame([], new DbalTeamQueries($connection)->summariesOf(new OrganizationId('0197a3c0-0000-7000-8000-0000000005a1'), []));
    }
}
