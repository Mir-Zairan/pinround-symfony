<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\UnlinkProject\ProjectHasOpenTickets;
use App\Ticketing\Application\UnlinkProject\ProjectNotOfTeam;
use App\Ticketing\Application\UnlinkProject\UnlinkProject;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnlinkProjectTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000023a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000023b1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000023c1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-0000000023c2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000023d1';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000023d2';
    private const string UNKNOWN_PROJECT = '0197a3c0-0000-7000-8000-0000000023d9';

    #[Test]
    public function theOwningTeamUnlinksItsProjectAndItIsSaved(): void
    {
        $shop = self::shopProject();
        $projects = new InMemoryProjects(false, $shop);

        $key = new UnlinkProject($projects, new OrganizationProjects($projects), new FixedOpenTicketCounts())(self::acmeFoods(), new TeamId(self::SHOP_TEAM), self::SHOP_PROJECT);

        self::assertSame('SHOP', $key);
        self::assertNull($shop->owningTeam());
        self::assertSame([self::SHOP_PROJECT], $projects->lockCalls, 'The project was checked without its row lock.');
        self::assertSame([self::SHOP_PROJECT], $projects->saveCalls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function projectIdsTheOrganizationDoesNotHave(): iterable
    {
        yield 'an unknown project' => [self::UNKNOWN_PROJECT];
        yield 'a project of another organization' => [self::BETA_PROJECT];
        yield 'not a project id' => ['SHOP'];
    }

    #[Test]
    #[DataProvider('projectIdsTheOrganizationDoesNotHave')]
    public function aProjectTheOrganizationDoesNotHaveIsNotFound(string $projectId): void
    {
        $beta = new Project(new ProjectId(self::BETA_PROJECT), new OrganizationId(self::BETA_BAKERY), new TeamId(self::SHOP_TEAM), new ProjectKey('BETA'), 'Beta');
        $projects = new InMemoryProjects(false, self::shopProject(), $beta);

        try {
            new UnlinkProject($projects, new OrganizationProjects($projects), new FixedOpenTicketCounts())(self::acmeFoods(), new TeamId(self::SHOP_TEAM), $projectId);
            self::fail('A project the organization does not have was unlinked.');
        } catch (ProjectNotFound $notFound) {
            self::assertSame('That project is not in this organization.', $notFound->getMessage());
        }

        self::assertSame(self::SHOP_TEAM, $beta->owningTeam()?->toString());
        self::assertSame([], $projects->saveCalls);
    }

    #[Test]
    public function anotherTeamCannotUnlinkTheProject(): void
    {
        $shop = self::shopProject();
        $projects = new InMemoryProjects(false, $shop);

        try {
            new UnlinkProject($projects, new OrganizationProjects($projects), new FixedOpenTicketCounts())(self::acmeFoods(), new TeamId(self::MOBILE_TEAM), self::SHOP_PROJECT);
            self::fail('Another team unlinked the project.');
        } catch (ProjectNotOfTeam $notOfTeam) {
            self::assertSame('That project is not linked to this team.', $notOfTeam->getMessage());
        }

        self::assertSame(self::SHOP_TEAM, $shop->owningTeam()?->toString());
        self::assertSame([], $projects->saveCalls);
    }

    #[Test]
    public function anUnlinkedProjectIsNotOfTheTeamAnyMore(): void
    {
        $shop = self::shopProject();
        $shop->unlink();
        $projects = new InMemoryProjects(false, $shop);

        $this->expectException(ProjectNotOfTeam::class);

        new UnlinkProject($projects, new OrganizationProjects($projects), new FixedOpenTicketCounts())(self::acmeFoods(), new TeamId(self::SHOP_TEAM), self::SHOP_PROJECT);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function openTicketCounts(): iterable
    {
        yield 'one open ticket' => [1, '1 open ticket is still in this project. Close it first.'];
        yield 'two open tickets' => [2, '2 open tickets are still in this project. Close them first.'];
    }

    #[Test]
    #[DataProvider('openTicketCounts')]
    public function aProjectWithOpenTicketsStaysLinkedWithTheCountInTheRefusal(int $openTickets, string $message): void
    {
        $shop = self::shopProject();
        $projects = new InMemoryProjects(false, $shop);

        try {
            new UnlinkProject($projects, new OrganizationProjects($projects), new FixedOpenTicketCounts([self::SHOP_PROJECT => $openTickets]))(self::acmeFoods(), new TeamId(self::SHOP_TEAM), self::SHOP_PROJECT);
            self::fail('A project with open tickets was unlinked.');
        } catch (ProjectHasOpenTickets $hasOpenTickets) {
            self::assertSame([$openTickets, $message], [$hasOpenTickets->openTickets, $hasOpenTickets->getMessage()]);
        }

        self::assertSame(self::SHOP_TEAM, $shop->owningTeam()?->toString());
        self::assertSame([], $projects->saveCalls);
    }

    private static function shopProject(): Project
    {
        return new Project(new ProjectId(self::SHOP_PROJECT), self::acmeFoods(), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }
}
