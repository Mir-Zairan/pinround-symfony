<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\UI;

use App\Web\UI\Controller\TeamController;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\RuleSet;
use App\Workflow\UI\TeamWorkflowController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TeamWorkflowController keeps copies of the team page's route name and slug requirement, which Workflow_UI may not
 * import from Web_UI, and of the Domain's group labels and rule codes, which it may not import from Workflow's Domain;
 * these keep each copy the same as the original.
 */
final class TeamWorkflowControllerTest extends TestCase
{
    /**
     * Read from the team page's route attribute, so it also fails when TeamController stops using its ROUTE.
     */
    #[Test]
    public function theTeamPageRouteItRedirectsToIsTheTeamControllersRoute(): void
    {
        $routes = new \ReflectionMethod(TeamController::class, 'show')->getAttributes(Route::class);
        self::assertCount(1, $routes);

        self::assertSame($routes[0]->newInstance()->name, TeamWorkflowController::TEAM_PAGE_ROUTE);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function actions(): iterable
    {
        yield 'add step' => ['addStep', TeamWorkflowController::ADD_STEP_ROUTE];
        yield 'remove step' => ['removeStep', TeamWorkflowController::REMOVE_STEP_ROUTE];
        yield 'set rule' => ['setRule', TeamWorkflowController::SET_RULE_ROUTE];
    }

    /**
     * Read from the route attribute, so it also fails when a route stops using TeamWorkflowController::SLUG_REQUIREMENT.
     */
    #[Test]
    #[DataProvider('actions')]
    public function eachRouteTakesOnlyPostsAndTheTeamPagesSlugRequirement(string $action, string $routeName): void
    {
        $routes = new \ReflectionMethod(TeamWorkflowController::class, $action)->getAttributes(Route::class);
        self::assertCount(1, $routes);
        $route = $routes[0]->newInstance();

        self::assertSame($routeName, $route->name);
        self::assertSame(TeamController::SLUG_REQUIREMENT, $route->requirements['slug'] ?? null);
        self::assertSame(['POST'], $route->methods);
    }

    #[Test]
    public function theStepGroupsAreEveryGroupButDoneWithTheDomainsLabels(): void
    {
        $groupsButDone = array_filter(Group::cases(), static fn (Group $group): bool => Group::Done !== $group);

        self::assertEqualsCanonicalizing(
            array_map(static fn (Group $group): string => $group->value, $groupsButDone),
            array_keys(TeamWorkflowController::STEP_GROUP_LABELS),
        );
        foreach (TeamWorkflowController::STEP_GROUP_LABELS as $groupCode => $label) {
            self::assertSame(Group::from($groupCode)->label(), $label, $groupCode);
        }
    }

    #[Test]
    public function theRuleLabelsAreThoseOfEveryRuleCode(): void
    {
        self::assertEqualsCanonicalizing(RuleSet::RULE_CODES, array_keys(TeamWorkflowController::RULE_LABELS));
    }
}
