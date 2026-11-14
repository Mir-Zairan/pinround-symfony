<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Web\UI\TicketViewers;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketViewersTest extends TestCase
{
    private const string ORGANIZATION = '0195f3a0-0000-7000-8000-000000000001';
    private const string LENA = '0195f3a0-0000-7000-8000-000000000011';
    private const string OMAR = '0195f3a0-0000-7000-8000-000000000012';
    private const string SHOP_TEAM = '0195f3a0-0000-7000-8000-000000000021';
    private const string APP_TEAM = '0195f3a0-0000-7000-8000-000000000022';

    #[Test]
    public function anAdminWhoIsNotTheTriagerKeepsTheirTeamsAndAdminRole(): void
    {
        $viewer = TicketViewers::of(TicketVisibility::for(
            new OrganizationId(self::ORGANIZATION),
            new UserId(self::LENA),
            [new TeamId(self::SHOP_TEAM), new TeamId(self::APP_TEAM)],
            true,
            new UserId(self::OMAR),
        ));

        self::assertSame(self::ORGANIZATION, $viewer->organizationId->toString());
        self::assertSame(self::LENA, $viewer->viewerId->toString());
        self::assertSame([self::SHOP_TEAM, self::APP_TEAM], array_map(static fn (TeamId $teamId): string => $teamId->toString(), $viewer->teamIds));
        self::assertTrue($viewer->isAdmin);
        self::assertFalse($viewer->isTriager);
    }

    #[Test]
    public function theTriagerWhoIsNoAdminIsTheTriagerOnly(): void
    {
        $viewer = TicketViewers::of(TicketVisibility::for(new OrganizationId(self::ORGANIZATION), new UserId(self::OMAR), [], false, new UserId(self::OMAR)));

        self::assertSame(self::OMAR, $viewer->viewerId->toString());
        self::assertSame([], $viewer->teamIds);
        self::assertFalse($viewer->isAdmin);
        self::assertTrue($viewer->isTriager);
    }
}
