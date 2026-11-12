<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Identity\Api\Event\UserRenamed;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs inside the transaction of the new name (UserRenamed has no outbox route), so the cached teams that show the old
 * name are cleared when it commits, and kept when it rolls back.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class ForgetRenamedPersonHandler
{
    public function __construct(private TeamPeopleCache $teamPeopleCache)
    {
    }

    public function __invoke(UserRenamed $event): void
    {
        $this->teamPeopleCache->invalidatePersonAfterCommit(new UserId($event->userId));
    }
}
