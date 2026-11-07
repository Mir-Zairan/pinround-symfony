<?php

declare(strict_types=1);

namespace App\Organization\UI;

use App\Organization\Application\OrganizationNames;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * What the avatar menu shows about organizations: only a user in two or more of them gets the current one's name and
 * "Switch organization". The count comes from EnterChosenOrganization's resolver call, so it costs no second query.
 * Holds no state, so it is safe in worker mode.
 */
final readonly class OrganizationSwitch
{
    public function __construct(private RequestStack $requestStack, private OrganizationNames $organizationNames)
    {
    }

    #[AsTwigFunction('can_switch_organization')]
    public function canSwitch(): bool
    {
        $organizationCount = $this->requestStack->getMainRequest()?->attributes->get(EnterChosenOrganization::ORGANIZATION_COUNT_ATTRIBUTE);

        return \is_int($organizationCount) && $organizationCount >= 2;
    }

    /**
     * @return string|null null on a page without the request transaction, such as the picker itself
     */
    #[AsTwigFunction('current_organization_name')]
    public function currentOrganizationName(): ?string
    {
        return $this->organizationNames->nameOfTenant();
    }
}
