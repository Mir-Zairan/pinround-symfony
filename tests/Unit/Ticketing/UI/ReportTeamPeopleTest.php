<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\UI\ReportTeamPeople;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportTeamPeopleTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e501';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e502';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e503';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000e504';

    #[Test]
    public function theLeadIsThePersonMarkedLeadWhereverTheyStand(): void
    {
        $lead = ReportTeamPeople::leadOf([self::omar(), self::mia(), self::lena()]);

        self::assertSame(self::LENA, $lead?->userId->toString());
    }

    #[Test]
    public function aTeamWithNoLeadHasNone(): void
    {
        self::assertNull(ReportTeamPeople::leadOf([self::omar(), self::mia()]));
        self::assertNull(ReportTeamPeople::leadOf([]));
    }

    #[Test]
    public function everyoneButATesterCanBeGivenAReportInTheGivenOrder(): void
    {
        $giveTo = ReportTeamPeople::giveToPeopleOf([self::omar(), self::mia(), self::lena(), self::raj()]);

        self::assertSame(
            [self::OMAR, self::LENA, self::RAJ],
            array_map(static fn (PossibleAssignee $person): string => $person->userId->toString(), $giveTo),
        );
    }

    #[Test]
    public function aTeamOfTestersHasNobodyToGiveAReportTo(): void
    {
        self::assertSame([], ReportTeamPeople::giveToPeopleOf([self::mia()]));
    }

    private static function lena(): PossibleAssignee
    {
        return new PossibleAssignee(new UserId(self::LENA), 'Lena', 'Lena Weber', MemberJob::Developer, true);
    }

    private static function omar(): PossibleAssignee
    {
        return new PossibleAssignee(new UserId(self::OMAR), 'Omar', 'Omar Demir', MemberJob::Developer, false);
    }

    private static function mia(): PossibleAssignee
    {
        return new PossibleAssignee(new UserId(self::MIA), 'Mia', 'Mia Rossi', MemberJob::Tester, false);
    }

    private static function raj(): PossibleAssignee
    {
        return new PossibleAssignee(new UserId(self::RAJ), 'Raj', 'Raj Patel', MemberJob::Other, false);
    }
}
