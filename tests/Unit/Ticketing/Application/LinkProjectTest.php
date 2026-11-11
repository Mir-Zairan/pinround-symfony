<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\LinkProject\LinkProject;
use App\Ticketing\Application\LinkProject\ProjectStillLinked;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\OrganizationTeams;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\ProjectTeamNotFound;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectAlreadyLinked;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkProjectTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000024a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000024b1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000024c1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-0000000024c2';
    private const string CHECKOUT_TEAM = '0197a3c0-0000-7000-8000-0000000024c3';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000024d1';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000024d2';
    private const string SECOND_PROJECT = '0197a3c0-0000-7000-8000-0000000024d3';
    private const string UNKNOWN_PROJECT = '0197a3c0-0000-7000-8000-0000000024d9';

    #[Test]
    public function anUnlinkedProjectIsLinkedToATeamOfTheOrganizationAndSaved(): void
    {
        $shop = self::unlinkedShopProject();
        $projects = new InMemoryProjects(false, $shop);

        $key = self::linkProject($projects)(self::acmeFoods(), new TeamId(self::MOBILE_TEAM), self::SHOP_PROJECT);

        self::assertSame('SHOP', $key);
        self::assertSame(self::MOBILE_TEAM, $shop->owningTeam()?->toString());
        self::assertSame([self::SHOP_PROJECT], $projects->lockCalls, 'The project was checked without its row lock.');
        self::assertSame([self::SHOP_PROJECT], $projects->saveCalls);
    }

    #[Test]
    public function aTeamOfAnotherOrganizationGetsNoProject(): void
    {
        $shop = self::unlinkedShopProject();
        $projects = new InMemoryProjects(false, $shop);

        try {
            self::linkProject($projects)(self::acmeFoods(), new TeamId(self::CHECKOUT_TEAM), self::SHOP_PROJECT);
            self::fail('A team of another organization got the project.');
        } catch (ProjectTeamNotFound $teamNotFound) {
            self::assertSame('That team is not in this organization.', $teamNotFound->getMessage());
        }

        self::assertNull($shop->owningTeam());
        self::assertSame([], $projects->saveCalls);
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
        $beta = new Project(new ProjectId(self::BETA_PROJECT), new OrganizationId(self::BETA_BAKERY), new TeamId(self::CHECKOUT_TEAM), new ProjectKey('BETA'), 'Beta');
        $beta->unlink();
        $projects = new InMemoryProjects(false, self::unlinkedShopProject(), $beta);

        try {
            self::linkProject($projects)(self::acmeFoods(), new TeamId(self::SHOP_TEAM), $projectId);
            self::fail('A project the organization does not have was linked.');
        } catch (ProjectNotFound $notFound) {
            self::assertSame('That project is not in this organization.', $notFound->getMessage());
        }

        self::assertNull($beta->owningTeam());
        self::assertSame([], $projects->saveCalls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function teamsTheProjectIsNotLinkedToYet(): iterable
    {
        yield 'another team' => [self::MOBILE_TEAM];
        yield 'the team that owns it' => [self::SHOP_TEAM];
    }

    #[Test]
    #[DataProvider('teamsTheProjectIsNotLinkedToYet')]
    public function aLinkedProjectIsRefusedAndKeepsItsTeam(string $teamId): void
    {
        $shop = new Project(new ProjectId(self::SHOP_PROJECT), self::acmeFoods(), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');
        $projects = new InMemoryProjects(false, $shop);

        try {
            self::linkProject($projects)(self::acmeFoods(), new TeamId($teamId), self::SHOP_PROJECT);
            self::fail('A linked project was linked again.');
        } catch (ProjectStillLinked $stillLinked) {
            self::assertSame('That project is already linked to a team.', $stillLinked->getMessage());
            self::assertInstanceOf(ProjectAlreadyLinked::class, $stillLinked->getPrevious());
        }

        self::assertSame(self::SHOP_TEAM, $shop->owningTeam()?->toString());
        self::assertSame([], $projects->saveCalls);
    }

    #[Test]
    public function severalProjectsAreLockedInAscendingIdOrderWhateverOrderIsGivenAndTheTeamIsCheckedOnce(): void
    {
        $shop = self::unlinkedShopProject();
        $second = new Project(new ProjectId(self::SECOND_PROJECT), self::acmeFoods(), new TeamId(self::SHOP_TEAM), new ProjectKey('CART'), 'Cart');
        $second->unlink();
        $projects = new InMemoryProjects(false, $shop, $second);
        $teamQueries = self::teamQueries();

        $keysById = self::linkProject($projects, $teamQueries)->linkAll(self::acmeFoods(), new TeamId(self::MOBILE_TEAM), [self::SECOND_PROJECT, self::SHOP_PROJECT]);

        self::assertSame([self::SHOP_PROJECT => 'SHOP', self::SECOND_PROJECT => 'CART'], $keysById);
        self::assertSame([self::SHOP_PROJECT, self::SECOND_PROJECT], $projects->lockCalls);
        self::assertSame([self::SHOP_PROJECT, self::SECOND_PROJECT], $projects->saveCalls);
        self::assertSame(1, $teamQueries->teamsOfCalls);
    }

    #[Test]
    public function severalProjectsGoToNoTeamOfAnotherOrganization(): void
    {
        $projects = new InMemoryProjects(false, self::unlinkedShopProject());

        try {
            self::linkProject($projects)->linkAll(self::acmeFoods(), new TeamId(self::CHECKOUT_TEAM), [self::SHOP_PROJECT]);
            self::fail('A team of another organization got the projects.');
        } catch (ProjectTeamNotFound) {
            self::assertSame([], $projects->lockCalls);
            self::assertSame([], $projects->saveCalls);
        }
    }

    private static function teamQueries(): FixedTeamQueries
    {
        return new FixedTeamQueries(self::acmeFoods(), [new TeamId(self::SHOP_TEAM), new TeamId(self::MOBILE_TEAM)]);
    }

    private static function linkProject(InMemoryProjects $projects, ?FixedTeamQueries $teamQueries = null): LinkProject
    {
        return new LinkProject($projects, new OrganizationProjects($projects), new OrganizationTeams($teamQueries ?? self::teamQueries()));
    }

    private static function unlinkedShopProject(): Project
    {
        $shop = new Project(new ProjectId(self::SHOP_PROJECT), self::acmeFoods(), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');
        $shop->unlink();

        return $shop;
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }
}
