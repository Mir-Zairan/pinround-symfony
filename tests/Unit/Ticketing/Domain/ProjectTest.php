<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectAlreadyLinked;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectNotLinked;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000021a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000021c1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-0000000021c2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000021d1';

    #[Test]
    public function aNewProjectIsOwnedByTheTeamItWasCreatedFor(): void
    {
        self::assertSame(self::SHOP_TEAM, self::shopProject()->owningTeam()?->toString());
    }

    #[Test]
    public function anUnlinkedProjectHasNoOwningTeamAndCanBeLinkedToAnotherTeam(): void
    {
        $project = self::shopProject();

        $project->unlink();
        self::assertNull($project->owningTeam());

        $project->linkTo(new TeamId(self::MOBILE_TEAM));
        self::assertSame(self::MOBILE_TEAM, $project->owningTeam()?->toString());
    }

    #[Test]
    public function aProjectWithoutATeamCannotBeUnlinkedAgain(): void
    {
        $project = self::shopProject();
        $project->unlink();

        $this->expectException(ProjectNotLinked::class);
        $project->unlink();
    }

    #[Test]
    public function aLinkedProjectIsNotLinkedToASecondTeamAndKeepsItsTeam(): void
    {
        $project = self::shopProject();

        try {
            $project->linkTo(new TeamId(self::MOBILE_TEAM));
            self::fail('A linked project got a second team.');
        } catch (ProjectAlreadyLinked) {
        }

        self::assertSame(self::SHOP_TEAM, $project->owningTeam()?->toString());
    }

    private static function shopProject(): Project
    {
        return new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');
    }
}
