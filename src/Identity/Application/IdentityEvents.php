<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\Event\UserRenamed;

/**
 * Tells other modules what changed about a person, in the transaction of the change.
 */
interface IdentityEvents
{
    public function publish(UserRenamed $event): void;
}
