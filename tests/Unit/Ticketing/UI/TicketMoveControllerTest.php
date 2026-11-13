<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\UI\MoveToasts;
use App\Ticketing\UI\TicketMoveController;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TicketMoveController keeps its verbs as a route requirement, a copy of MoveToasts' verbs, which are Workflow's verb
 * codes that Ticketing_UI may not import from Workflow's Domain; these keep each copy the same as the original.
 */
final class TicketMoveControllerTest extends TestCase
{
    #[Test]
    public function theVerbsAreEveryWorkflowVerbButGiveItTo(): void
    {
        $verbsButGiveItTo = array_values(array_filter(Verb::cases(), static fn (Verb $verb): bool => Verb::GiveItTo !== $verb));

        self::assertEqualsCanonicalizing(
            array_map(static fn (Verb $verb): string => $verb->value, $verbsButGiveItTo),
            array_keys(MoveToasts::VERB_TOASTS),
        );
    }

    /**
     * Read from the route attribute, so it also fails when the move route stops using VERB_REQUIREMENT.
     */
    #[Test]
    public function theMoveRouteTakesTheVerbsOfTheToasts(): void
    {
        $routes = new \ReflectionMethod(TicketMoveController::class, 'move')->getAttributes(Route::class);
        self::assertCount(1, $routes);

        self::assertSame(array_keys(MoveToasts::VERB_TOASTS), explode('|', (string) ($routes[0]->newInstance()->requirements['verb'] ?? '')));
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, string>}>
     */
    public static function actions(): iterable
    {
        yield 'move' => ['move', TicketMoveController::MOVE_ROUTE, '/t/{key}/move/{verb}', ['verb' => TicketMoveController::VERB_REQUIREMENT]];
        yield 'move to' => ['moveTo', TicketMoveController::MOVE_TO_ROUTE, '/t/{key}/move-to/{stepId}', ['stepId' => TicketMoveController::STEP_ID_REQUIREMENT]];
    }

    /**
     * Read from the route attribute, so it also fails when a route stops using its requirement or starts using the
     * request transaction, inside which a refused move could not be rolled back alone.
     *
     * @param array<string, string> $expectedRequirements
     */
    #[Test]
    #[DataProvider('actions')]
    public function eachRouteTakesOnlyPostsWithItsRequirementAndNoRequestTransaction(string $action, string $routeName, string $path, array $expectedRequirements): void
    {
        $routes = new \ReflectionMethod(TicketMoveController::class, $action)->getAttributes(Route::class);
        self::assertCount(1, $routes);
        $route = $routes[0]->newInstance();

        self::assertSame([$routeName, $path, ['POST']], [$route->name, $route->path, $route->methods]);
        self::assertSame($expectedRequirements, $route->requirements);
        self::assertSame([RequestTransaction::ROUTE_DEFAULT => false], $route->defaults);
    }

    #[Test]
    public function eachTicketHasItsOwnCsrfTokenId(): void
    {
        self::assertNotSame(TicketMoveController::csrfIdOf('SHOP-1'), TicketMoveController::csrfIdOf('SHOP-12'));
        self::assertSame(TicketMoveController::csrfIdOf('SHOP-1'), TicketMoveController::csrfIdOf('SHOP-1'));
    }
}
