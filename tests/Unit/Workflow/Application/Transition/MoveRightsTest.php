<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Application\Transition\MoveRights;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoveRightsTest extends TestCase
{
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
    public function onlyALeadLeadsOnlyALeadOrMemberMovesTicketsAndOnlyTheJobTesterIsATester(?string $teamRole, ?string $job, bool $leadsTheTeam, bool $movesTickets, bool $isTester): void
    {
        $actor = new ActorInTeam(new UserId(GuardFixtures::LENA), $teamRole, $job);
        $moveRights = new MoveRights();

        self::assertSame($leadsTheTeam, $moveRights->leadsTheTeam($actor));
        self::assertSame($movesTickets, $moveRights->movesTickets($actor));
        self::assertSame($isTester, $moveRights->isTester($actor));
    }
}
