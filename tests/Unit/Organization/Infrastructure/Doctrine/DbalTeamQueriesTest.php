<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Doctrine;

use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
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

    #[Test]
    public function jobOfRefusesAJobThatIsNotAMemberJob(): void
    {
        // The table's CHECK constraint keeps such a value out, so only a stub connection can return one.
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('designer');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_team_member holds an unknown job.');

        new DbalTeamQueries($connection)->jobOf(new TeamId('0197a3c0-0000-7000-8000-0000000005d1'), new UserId('0197a3c0-0000-7000-8000-0000000005c3'));
    }

    #[Test]
    public function peopleByJobRefusesAJobThatIsNotAMemberJob(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['user_id' => '0197a3c0-0000-7000-8000-0000000005c3', 'job' => 'designer']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_team_member holds an unknown job.');

        new DbalTeamQueries($connection)->peopleByJob(new TeamId('0197a3c0-0000-7000-8000-0000000005d1'));
    }
}
