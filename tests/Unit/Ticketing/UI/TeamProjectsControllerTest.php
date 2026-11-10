<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\TeamProjectsController;
use App\Web\UI\Controller\TeamController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TeamProjectsController keeps copies of the team page's route name and slug requirement, as Ticketing_UI may not
 * import Web_UI; these keep each copy the same as the original.
 */
final class TeamProjectsControllerTest extends TestCase
{
    /**
     * Read from the team page's route attribute, so it also fails when TeamController stops using its ROUTE.
     */
    #[Test]
    public function theTeamPageRouteItRedirectsToIsTheTeamControllersRoute(): void
    {
        $routes = new \ReflectionMethod(TeamController::class, 'show')->getAttributes(Route::class);
        self::assertCount(1, $routes);

        self::assertSame($routes[0]->newInstance()->name, TeamProjectsController::TEAM_PAGE_ROUTE);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'unlink' => ['unlink'];
        yield 'link' => ['link'];
        yield 'create' => ['create'];
    }

    /**
     * Read from the route attribute, so it also fails when a route stops using TeamProjectsController::SLUG_REQUIREMENT.
     */
    #[Test]
    #[DataProvider('actions')]
    public function eachRoutesSlugRequirementIsTheTeamPagesOne(string $action): void
    {
        $routes = new \ReflectionMethod(TeamProjectsController::class, $action)->getAttributes(Route::class);
        self::assertCount(1, $routes);
        $route = $routes[0]->newInstance();

        self::assertSame(TeamController::SLUG_REQUIREMENT, $route->requirements['slug'] ?? null);
        self::assertSame(['POST'], $route->methods);
    }
}
