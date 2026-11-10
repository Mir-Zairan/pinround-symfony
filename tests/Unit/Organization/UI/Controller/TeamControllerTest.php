<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Controller;

use App\Organization\Domain\SlugFormat;
use App\Organization\UI\Controller\TeamController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class TeamControllerTest extends TestCase
{
    /**
     * The route keeps its own copy of the slug groups, as Organization_UI may not see Organization_Domain; wrapped in
     * the anchors and the length, the route's requirement must be SlugFormat::PATTERN itself. Read from the route
     * attribute, so it also fails when the route stops using TeamController::SLUG_REQUIREMENT.
     */
    #[Test]
    public function theRoutesSlugRequirementIsTheSlugFormatWithoutItsAnchorsAndLength(): void
    {
        $routes = new \ReflectionMethod(TeamController::class, 'show')->getAttributes(Route::class);
        self::assertCount(1, $routes);
        $slugRequirement = (string) ($routes[0]->newInstance()->requirements['slug'] ?? '');

        self::assertSame(SlugFormat::PATTERN, '/^(?=.{1,63}\z)'.$slugRequirement.'\z/');
    }
}
