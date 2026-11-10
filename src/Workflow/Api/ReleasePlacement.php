<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Where a ticket's release stands against the org's current release; the caller knows the current one, the ticket state
 * only carries the release name.
 */
enum ReleasePlacement
{
    case InCurrentRelease;
    case InOtherRelease;
    case NoRelease;
}
