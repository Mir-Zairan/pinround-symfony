<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\TeamCreated;
use App\Organization\Application\CreateTeam;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\NotAnOrganizationMember;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamMemberView;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\TeamSlug;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateTeamTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000005b1';
    private const string SHOP_WEB_TEAM = '0197a3c0-0000-7000-8000-0000000005c1';
    private const string BAKERY_TEAM = '0197a3c0-0000-7000-8000-0000000005c2';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000552';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000556';

    #[Test]
    public function theTeamIsSavedWithItsLeadAndTheCreationIsRecorded(): void
    {
        $teams = new InMemoryTeams();
        $events = new RecordingOrganizationEvents();
        $memberships = new InMemoryMemberships(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(), self::now()));

        $teamId = new CreateTeam($teams, $memberships, $events)(new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, new UserId(self::SARA), self::now());

        self::assertSame(InMemoryTeams::NEXT_ID, $teamId->toString());
        $team = $teams->byId(new TeamId(InMemoryTeams::NEXT_ID));
        self::assertNotNull($team);
        self::assertSame('shop', $team->slug()->toString());
        self::assertEquals([new TeamMemberView(new UserId(self::LENA), TeamRole::Lead, Job::Developer, self::now())], $team->members());
        self::assertEquals([new TeamCreated(self::ACME_FOODS, InMemoryTeams::NEXT_ID, self::LENA, self::SARA, self::now())], $events->published);
    }

    /** Beta Bakery's "shop-web-2" does not count, so a slug taken only in Acme Foods decides the suffix. */
    #[Test]
    public function aSlugTakenInTheOrganizationGetsTheFirstFreeSuffix(): void
    {
        $teams = new InMemoryTeams(
            new Team(new TeamId(self::SHOP_WEB_TEAM), new OrganizationId(self::ACME_FOODS), 'Shop Web', new TeamSlug('shop-web'), new UserId(self::LENA), Job::Developer, self::now()),
            new Team(new TeamId(self::BAKERY_TEAM), new OrganizationId(self::BETA_BAKERY), 'Shop-Web', new TeamSlug('shop-web-2'), new UserId(self::LENA), Job::Developer, self::now()),
        );
        $memberships = new InMemoryMemberships(new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(), self::now()));

        $teamId = new CreateTeam($teams, $memberships, new RecordingOrganizationEvents())(new OrganizationId(self::ACME_FOODS), 'Shop-Web', new UserId(self::LENA), Job::Developer, null, self::now());

        self::assertSame('shop-web-2', $teams->byId($teamId)?->slug()->toString());
    }

    #[Test]
    public function aLeadWhoIsNoMemberOfTheOrganizationIsRefused(): void
    {
        $teams = new InMemoryTeams();
        $events = new RecordingOrganizationEvents();

        try {
            new CreateTeam($teams, new InMemoryMemberships(), $events)(new OrganizationId(self::ACME_FOODS), 'Shop', new UserId(self::LENA), Job::Developer, null, self::now());
            self::fail('A team was created with a lead outside the organization.');
        } catch (NotAnOrganizationMember) {
        }

        self::assertSame([], $teams->calls);
        self::assertSame([], $events->published);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }
}
