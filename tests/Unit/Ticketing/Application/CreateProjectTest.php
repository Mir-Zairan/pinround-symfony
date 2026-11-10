<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\CreateProject\CreateProject;
use App\Ticketing\Application\CreateProject\ProjectKeyNotValid;
use App\Ticketing\Application\CreateProject\ProjectKeyTaken;
use App\Ticketing\Application\CreateProject\ProjectNameMissing;
use App\Ticketing\Application\OrganizationTeams;
use App\Ticketing\Application\ProjectTeamNotFound;
use App\Ticketing\Domain\BlankProjectName;
use App\Ticketing\Domain\InvalidProjectKey;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectKeyInUse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateProjectTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000005b1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000006a1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-0000000006a2';
    private const string CHECKOUT_TEAM = '0197a3c0-0000-7000-8000-0000000006b1';
    private const string EXISTING_PROJECT = '0197a3c0-0000-7000-8000-0000000007a0';

    #[Test]
    public function theProjectIsSavedForTheOwningTeamWithItsTrimmedName(): void
    {
        $projects = new InMemoryProjects();

        $projectId = self::createProject($projects)(self::acmeFoods(), new TeamId(self::MOBILE_TEAM), 'SHOP', '  Online shop  ');

        self::assertSame(InMemoryProjects::NEXT_ID, $projectId->toString());
        $project = $projects->byId($projectId);
        self::assertNotNull($project);
        self::assertTrue($project->organizationId()->equals(self::acmeFoods()));
        self::assertSame(self::MOBILE_TEAM, $project->owningTeam()?->toString());
        self::assertSame('SHOP', $project->key()->toString());
        self::assertSame('Online shop', $project->name());
    }

    #[Test]
    public function aKeyAnotherProjectUsesIsRefusedWithThatKey(): void
    {
        $existing = new Project(new ProjectId(self::EXISTING_PROJECT), self::acmeFoods(), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Online shop');
        $projects = new InMemoryProjects(false, $existing);

        try {
            self::createProject($projects)(self::acmeFoods(), new TeamId(self::MOBILE_TEAM), 'SHOP', 'Shop app');
            self::fail('A second project got the key SHOP.');
        } catch (ProjectKeyTaken $keyTaken) {
            self::assertSame('Another project already uses the key SHOP.', $keyTaken->getMessage());
        }

        self::assertSame([self::EXISTING_PROJECT], array_keys($projects->saved));
    }

    #[Test]
    public function aKeyAnotherTransactionSavedAfterTheCheckIsRefusedAsTaken(): void
    {
        $projects = new InMemoryProjects(true);

        try {
            self::createProject($projects)(self::acmeFoods(), new TeamId(self::MOBILE_TEAM), 'APP', 'Shop app');
            self::fail('The key the unique index refused was not reported as taken.');
        } catch (ProjectKeyTaken $keyTaken) {
            self::assertSame('Another project already uses the key APP.', $keyTaken->getMessage());
            self::assertInstanceOf(ProjectKeyInUse::class, $keyTaken->getPrevious());
        }

        self::assertSame([], $projects->saved);
    }

    #[Test]
    public function aTeamOfAnotherOrganizationCannotOwnTheProject(): void
    {
        $projects = new InMemoryProjects();

        try {
            self::createProject($projects)(self::acmeFoods(), new TeamId(self::CHECKOUT_TEAM), 'SHOP', 'Online shop');
            self::fail('A team of another organization got a project.');
        } catch (ProjectTeamNotFound $teamNotFound) {
            self::assertSame('That team is not in this organization.', $teamNotFound->getMessage());
        }

        self::assertSame([], $projects->saved);
    }

    #[Test]
    public function aTeamIsLookedUpInTheOrganizationOfTheProject(): void
    {
        $projects = new InMemoryProjects();

        $this->expectException(ProjectTeamNotFound::class);

        self::createProject($projects)(new OrganizationId(self::BETA_BAKERY), new TeamId(self::SHOP_TEAM), 'SHOP', 'Online shop');
    }

    #[Test]
    public function aKeyOutsideTheFormatIsRefusedAndNothingIsSaved(): void
    {
        $projects = new InMemoryProjects();

        try {
            self::createProject($projects)(self::acmeFoods(), new TeamId(self::SHOP_TEAM), 'shop', 'Online shop');
            self::fail('A project was created with a lowercase key.');
        } catch (ProjectKeyNotValid $notValid) {
            self::assertSame('A project key is 2 to 10 capital letters or digits and starts with a letter.', $notValid->getMessage());
            self::assertInstanceOf(InvalidProjectKey::class, $notValid->getPrevious());
        }

        self::assertSame([], $projects->saved);
    }

    #[Test]
    public function aBlankNameIsRefusedAndNothingIsSaved(): void
    {
        $projects = new InMemoryProjects();

        try {
            self::createProject($projects)(self::acmeFoods(), new TeamId(self::SHOP_TEAM), 'SHOP', '   ');
            self::fail('A project was created without a name.');
        } catch (ProjectNameMissing $missing) {
            self::assertSame('A project needs a name.', $missing->getMessage());
            self::assertInstanceOf(BlankProjectName::class, $missing->getPrevious());
        }

        self::assertSame([], $projects->saved);
    }

    private static function createProject(InMemoryProjects $projects): CreateProject
    {
        return new CreateProject($projects, new OrganizationTeams(new FixedTeamQueries(self::acmeFoods(), [new TeamId(self::SHOP_TEAM), new TeamId(self::MOBILE_TEAM)])));
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }
}
