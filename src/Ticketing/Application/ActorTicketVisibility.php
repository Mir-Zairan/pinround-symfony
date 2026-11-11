<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\SettingsQueries;
use App\Ticketing\Api\TicketVisibility;

/**
 * The ticket visibility predicate of the acting user (ADR-0013): their organization, their teams of any team role,
 * whether they are an admin, and the organization's triager.
 */
final readonly class ActorTicketVisibility
{
    public function __construct(private SettingsQueries $settingsQueries)
    {
    }

    /**
     * Reads the triager through SettingsQueries, so it needs the open transaction under the actor's organization that
     * SettingsQueries needs; under another tenant the organization has no triager.
     */
    public function for(ActorContext $actor): TicketVisibility
    {
        return TicketVisibility::for(
            $actor->organizationId(),
            $actor->userId(),
            $actor->roles()->teamIds(),
            $actor->roles()->isAdmin(),
            $this->settingsQueries->triagerOf($actor->organizationId()),
        );
    }
}
