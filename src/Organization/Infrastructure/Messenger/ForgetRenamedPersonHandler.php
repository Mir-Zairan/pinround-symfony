<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Identity\Api\Event\UserRenamed;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Clears every cached team that shows the person, in worker-notify once the relayed UserRenamed arrives, so after the
 * new name has committed. Until then a team's toolbar can show the old name. Invalidating is safe to repeat, so it does
 * not go through OnceOnlyHandling.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class ForgetRenamedPersonHandler
{
    public function __construct(private TeamPeopleCache $teamPeopleCache)
    {
    }

    /**
     * @throws \RuntimeException when the pool could not invalidate, so the event is retried
     */
    public function __invoke(UserRenamed $event): void
    {
        $this->teamPeopleCache->invalidatePersonNow(new UserId($event->userId));
    }
}
