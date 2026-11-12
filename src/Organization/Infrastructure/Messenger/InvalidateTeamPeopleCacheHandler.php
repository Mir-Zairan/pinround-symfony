<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Domain\TeamId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The second invalidation of a change of a team's people, in worker-notify once the relayed TeamRoleChanged arrives: it
 * drops an entry a reader loaded from the old rows just before the change committed and saved just after the first
 * invalidation (TeamPeopleCache::transactionCommitted()), and one the first invalidation failed to drop. Invalidating
 * is safe to repeat, so it does not go through OnceOnlyHandling.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class InvalidateTeamPeopleCacheHandler
{
    public function __construct(private TeamPeopleCache $teamPeopleCache)
    {
    }

    /**
     * @throws \RuntimeException when the pool could not invalidate, so the event is retried
     */
    public function __invoke(TeamRoleChanged $event): void
    {
        $this->teamPeopleCache->invalidateTeamNow(new TeamId($event->teamId));
    }
}
