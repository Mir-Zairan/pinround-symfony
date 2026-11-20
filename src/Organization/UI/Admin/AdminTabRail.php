<?php

declare(strict_types=1);

namespace App\Organization\UI\Admin;

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The tabs of the admin area (admin.md, Layout) in the prototype's order, each only for a person its page lets in: the
 * same attributes the tab's page asks, ADMIN_AREA first. P14.T3 built the tabs that exist today; P11 adds the others
 * here. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final readonly class AdminTabRail
{
    /** Label, route of the page, and the attributes it asks on the organization besides ADMIN_AREA. */
    private const array TABS = [
        ['People and roles', PeopleController::ROUTE, [OrganizationPermission::INVITE, OrganizationPermission::ADMIN]],
        ['AI', AiSettingsController::ROUTE, []],
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
            $isCurrent = \is_string($currentRoute) && ($currentRoute === $routeName || str_starts_with($currentRoute, $routeName.'_'));
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
}
