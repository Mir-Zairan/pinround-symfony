<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Doctrine;

use App\Organization\Api\MemberJob;
use App\Organization\Infrastructure\Doctrine\TeamMemberRows;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TeamMemberRowsTest extends TestCase
{
    #[Test]
    public function jobOfMapsEachStoredJob(): void
    {
        self::assertSame(MemberJob::Developer, TeamMemberRows::jobOf('developer'));
        self::assertSame(MemberJob::Tester, TeamMemberRows::jobOf('tester'));
        self::assertSame(MemberJob::Other, TeamMemberRows::jobOf('other'));
    }

    #[Test]
    public function jobOfRefusesAJobThatIsNotAMemberJob(): void
    {
        // The table's CHECK constraint keeps such a value out, so only a hand-made row can hold one.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_team_member holds an unknown job.');

        TeamMemberRows::jobOf('Developer');
    }

    #[Test]
    public function jobOfRefusesAJobThatIsNotAString(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('org_team_member holds an unknown job.');

        TeamMemberRows::jobOf(null);
    }

    #[Test]
    public function userIdOfMapsAStoredUuid(): void
    {
        self::assertSame('0197a3c0-0000-7000-8000-0000000005c3', TeamMemberRows::userIdOf('0197a3c0-0000-7000-8000-0000000005c3')->toString());
    }

    #[Test]
    public function userIdOfRefusesAValueThatIsNotAString(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A team member id from the database is not a uuid.');

        TeamMemberRows::userIdOf(42);
    }
}
