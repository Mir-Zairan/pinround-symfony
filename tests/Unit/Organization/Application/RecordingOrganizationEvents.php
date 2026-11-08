<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\OrganizationEvent;
use App\Organization\Application\OrganizationEvents;

final class RecordingOrganizationEvents implements OrganizationEvents
{
    /** @var list<OrganizationEvent> */
    public array $published = [];

    public function publish(OrganizationEvent $event): void
    {
        $this->published[] = $event;
    }
}
