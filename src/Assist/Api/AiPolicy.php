<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The organization's AI policy for one person, for pages that show AI help and for the server's AI door. A request
 * about one ticket is decided again in the gateway, with the ticket's visibility and the laptop's state.
 */
interface AiPolicy
{
    /**
     * Reads the organization's AI settings and the viewer's roles under row-level security, uncached, so it needs an
     * open transaction whose tenant is $organizationId; under any other tenant, or none, AI help reads as off. A
     * stored setting the Organization module does not know reads as off too.
     */
    public function for(UserId $viewerId, OrganizationId $organizationId): PolicyView;
}
