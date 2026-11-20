<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Admin;

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\UI\Admin\AiSettingsController;
use App\Organization\UI\Admin\PeopleController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\Web\UI\Admin\AdminTab;
use App\Web\UI\Admin\AdminTabRail;
use App\Web\UI\Controller\Admin\AdminShellController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The rail asks the voters on the organization of the request; the fake grants only the attributes a test lists, and
 * only on Acme Foods.
 */
final class AdminTabRailTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const array EVERY_ATTRIBUTE = [AdminPermission::AREA, OrganizationPermission::INVITE, OrganizationPermission::ADMIN];

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
    }

    #[Test]
    public function anAdminGetsEveryTabInRailOrderWithTheCurrentOneMarked(): void
    {
        $this->requestStack->push(new Request(attributes: ['_route' => AiSettingsController::ROUTE]));

        $tabs = $this->rail(self::EVERY_ATTRIBUTE)->tabs();

        self::assertSame(['People and roles → /admin/people', 'AI → /admin/ai (current)'], self::describe($tabs));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function currentRoutes(): iterable
    {
        yield 'the tab page' => [PeopleController::ROUTE, ['People and roles → /admin/people (current)', 'AI → /admin/ai']];
        yield 'a form of the tab' => [PeopleController::INVITE_ROUTE, ['People and roles → /admin/people (current)', 'AI → /admin/ai']];
        yield 'a route that only starts with the same letters' => [PeopleController::ROUTE.'s', ['People and roles → /admin/people', 'AI → /admin/ai']];
        yield 'the admin area address' => [AdminShellController::ROUTE, ['People and roles → /admin/people', 'AI → /admin/ai']];
    }

    /**
     * @param list<string> $expectedTabs
     */
    #[Test]
    #[DataProvider('currentRoutes')]
    public function aTabIsCurrentOnItsPageAndTheRoutesUnderIt(string $currentRoute, array $expectedTabs): void
    {
        $this->requestStack->push(new Request(attributes: ['_route' => $currentRoute]));

        self::assertSame($expectedTabs, self::describe($this->rail(self::EVERY_ATTRIBUTE)->tabs()));
    }

    #[Test]
    public function aTabWhosePageRefusesAnAttributeIsLeftOut(): void
    {
        $rail = $this->rail([AdminPermission::AREA, OrganizationPermission::ADMIN]);

        self::assertSame(['AI → /admin/ai'], self::describe($rail->tabs()));
        self::assertSame('/admin/ai', $rail->firstTab()?->url);
    }

    #[Test]
    public function withoutTheAdminAreaThereIsNoTab(): void
    {
        $rail = $this->rail([OrganizationPermission::INVITE, OrganizationPermission::ADMIN]);

        self::assertSame([], $rail->tabs());
        self::assertNull($rail->firstTab());
    }

    #[Test]
    public function outsideAnOrganizationThereIsNoTab(): void
    {
        $rail = $this->rail(self::EVERY_ATTRIBUTE, organizationId: null);

        self::assertSame([], $rail->tabs());
        self::assertNull($rail->firstTab());
    }

    /**
     * @param list<AdminTab> $tabs
     *
     * @return list<string>
     */
    private static function describe(array $tabs): array
    {
        return array_map(static fn (AdminTab $tab): string => $tab->label.' → '.$tab->url.($tab->isCurrent ? ' (current)' : ''), $tabs);
    }

    /**
     * @param list<string> $grantedAttributes
     */
    private function rail(array $grantedAttributes, ?string $organizationId = self::ACME_FOODS): AdminTabRail
    {
        $tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
        if (null !== $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
        }
        $authorizationChecker = new readonly class($grantedAttributes, new OrganizationId(self::ACME_FOODS)) implements AuthorizationCheckerInterface {
            /**
             * @param list<string> $grantedAttributes
             */
            public function __construct(private array $grantedAttributes, private OrganizationId $acmeFoods)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return \in_array($attribute, $this->grantedAttributes, true) && $subject instanceof OrganizationId && $subject->equals($this->acmeFoods);
            }
        };
        $routes = new RouteCollection();
        $routes->add(PeopleController::ROUTE, new Route('/admin/people'));
        $routes->add(AiSettingsController::ROUTE, new Route('/admin/ai'));

        return new AdminTabRail($authorizationChecker, $tenantContext, new UrlGenerator($routes, new RequestContext()), $this->requestStack);
    }
}
