<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The key a project's next ticket gets, with the team that owns the project, so Report a problem's "When you send:" line
 * can tell from one read whether the project is one of the picked team's.
 */
final readonly class NextTicketKey
{
    /**
     * @param string      $key    for example SHOP-12
     * @param TeamId|null $teamId null when no team owns the project, so it takes no report
     */
    public function __construct(
        public string $key,
        public ?TeamId $teamId,
    ) {
    }
}
