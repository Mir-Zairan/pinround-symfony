<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Controller;

use App\Organization\Domain\SlugFormat;
use App\Ticketing\UI\TeamProjectsController;
use App\Web\UI\Controller\TeamController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class TeamControllerTest extends TestCase
{
    /**
     * The route keeps its own copy of the slug groups, as Web_UI may not see Organization_Domain; wrapped in
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

    #[Test]
    public function theProjectFormsNameTheRoutesTokensAndFieldsOfTheTicketingController(): void
    {
        $constantOfTicketingController = [
            'unlinkRoute' => 'UNLINK_ROUTE',
            'linkRoute' => 'LINK_ROUTE',
            'createRoute' => 'CREATE_ROUTE',
            'unlinkCsrfId' => 'UNLINK_CSRF_ID',
            'linkCsrfId' => 'LINK_CSRF_ID',
            'createCsrfId' => 'CREATE_CSRF_ID',
            'csrfField' => 'CSRF_FIELD',
            'projectIdField' => 'PROJECT_ID_FIELD',
            'projectIdsField' => 'PROJECT_IDS_FIELD',
            'keyField' => 'KEY_FIELD',
            'nameField' => 'NAME_FIELD',
        ];

        foreach ($constantOfTicketingController as $formKey => $constantName) {
            self::assertSame(
                new \ReflectionClassConstant(TeamProjectsController::class, $constantName)->getValue(),
                TeamController::PROJECT_FORMS[$formKey],
                $formKey,
            );
        }
        $projectForms = new \ReflectionClassConstant(TeamController::class, 'PROJECT_FORMS')->getValue();
        self::assertIsArray($projectForms);
        self::assertSame(array_keys($constantOfTicketingController), array_keys($projectForms));
    }
}
