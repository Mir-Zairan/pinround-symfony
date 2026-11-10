<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Api;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActorInTeamTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';

    /**
     * @return iterable<string, array{string|null, string|null}> team role, job
     */
    public static function validTeamRolesAndJobs(): iterable
    {
        yield 'lead and developer' => ['lead', 'developer'];
        yield 'member and tester' => ['member', 'tester'];
        yield 'viewer and other' => ['viewer', 'other'];
        yield 'no role and no job' => [null, null];
    }

    #[Test]
    #[DataProvider('validTeamRolesAndJobs')]
    public function keepsEveryNamedTeamRoleAndJobAsGiven(?string $teamRole, ?string $job): void
    {
        $actor = new ActorInTeam(new UserId(self::LENA), $teamRole, $job);

        self::assertSame($teamRole, $actor->teamRole);
        self::assertSame($job, $actor->job);
    }

    #[Test]
    public function anUnknownTeamRoleIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"manager" is not a team role.');

        new ActorInTeam(new UserId(self::LENA), 'manager', 'developer');
    }

    #[Test]
    public function anUnknownJobIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"designer" is not a job.');

        new ActorInTeam(new UserId(self::LENA), 'member', 'designer');
    }
}
