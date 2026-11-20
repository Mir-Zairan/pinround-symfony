<?php

declare(strict_types=1);

namespace App\Web\UI\Admin;

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Web\UI\Controller\Admin\AdminShellController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The tabs of the admin area (admin.md, Layout) in the prototype's order, each only for a person its page lets in: the
 * same attributes the tab's page asks, ADMIN_AREA first. Each tab's page lives in the module that owns it (P11 design
 * notes), so the rail names its route as a string rather than reaching into that module's UI; generating a renamed
 * route throws, so AdminShellTest finds it. P14.T3 built the tabs that exist today; P11 adds the others here. Holds no
 * state, so it is safe in worker mode (ADR-0008).
 */
final readonly class AdminTabRail
{
    /** The People and roles tab's page, which Home's People tile also opens. */
    public const string PEOPLE_ROUTE = 'organization_admin_people';
    /** The AI tab's page, which Home's AI help tile also opens. */
    public const string AI_ROUTE = 'organization_admin_ai';

    /** Label, route of the page, and the attributes it asks on the organization besides ADMIN_AREA. */
    private const array TABS = [
        ['People and roles', self::PEOPLE_ROUTE, [OrganizationPermission::INVITE, OrganizationPermission::ADMIN]],
        ['AI', self::AI_ROUTE, []],
    ];

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private TenantContext $tenantContext,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Asks the voters, so call it inside the request transaction, as CurrentActor needs.
     *
     * @return list<AdminTab> the tabs the person may open, in rail order; none outside an organization. A tab is current
     *                        on its page and on the pages its forms answer with (the page's route plus "_…").
     */
    #[AsTwigFunction('admin_tabs')]
    public function tabs(): array
    {
        $organizationId = $this->tenantContext->get();
        if (null === $organizationId || !$this->authorizationChecker->isGranted(AdminPermission::AREA, $organizationId)) {
            return [];
        }

        $currentRoute = $this->requestStack->getMainRequest()?->attributes->get('_route');
        $tabs = [];
        foreach (self::TABS as [$label, $routeName, $attributes]) {
            if (!array_all($attributes, fn (string $attribute): bool => $this->authorizationChecker->isGranted($attribute, $organizationId))) {
                continue;
            }
            $isCurrent = \is_string($currentRoute) && self::isRouteOrUnder($currentRoute, $routeName);
            $tabs[] = new AdminTab($label, $this->urlGenerator->generate($routeName), $isCurrent);
        }

        return $tabs;
    }

    /**
     * @return AdminTab|null the first tab the person may open; null when there is none
     */
    public function firstTab(): ?AdminTab
    {
        return $this->tabs()[0] ?? null;
    }

    /**
     * Whether a route belongs to the admin area: the shell's address, a tab's page or a route under it ("_…"), whatever
     * the person may open. Asks no voter.
     */
    public static function isAdminAreaRoute(string $routeName): bool
    {
        if (AdminShellController::ROUTE === $routeName) {
            return true;
        }

        return array_any(self::TABS, static fn (array $tab): bool => self::isRouteOrUnder($routeName, $tab[1]));
    }

    private static function isRouteOrUnder(string $routeName, string $tabRoute): bool
    {
        return $routeName === $tabRoute || str_starts_with($routeName, $tabRoute.'_');
    }
}
