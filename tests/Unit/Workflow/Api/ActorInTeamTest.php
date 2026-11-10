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
     * @return iterable<string, array{string|null, string|null, bool, bool, bool}> team role, job, leads the team,
     *                                                                             moves tickets, is a tester
     */
    public static function teamRolesAndJobs(): iterable
    {
        yield 'lead and developer' => ['lead', 'developer', true, true, false];
        yield 'member and tester' => ['member', 'tester', false, true, true];
        yield 'viewer and tester' => ['viewer', 'tester', false, false, true];
        yield 'member with the job other' => ['member', 'other', false, true, false];
        yield 'no role and no job' => [null, null, false, false, false];
    }

    #[Test]
    #[DataProvider('teamRolesAndJobs')]
    public function onlyALeadLeadsAndOnlyALeadOrMemberMovesTickets(?string $teamRole, ?string $job, bool $leadsTheTeam, bool $movesTickets, bool $isTester): void
    {
        $actor = new ActorInTeam(new UserId(self::LENA), $teamRole, $job);

        self::assertSame($leadsTheTeam, $actor->leadsTheTeam());
        self::assertSame($movesTickets, $actor->movesTickets());
        self::assertSame($isTester, $actor->isTester());
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
