<?php

declare(strict_types=1);

namespace App\Notification\Application\AutoClear;

use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason as ClearedReasonOfEvent;
use App\Notification\Application\Notify\Notifications;
use App\Notification\Application\Ping\PingEvents;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\Pings;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

final readonly class ClearPingsOnAction implements PingClearing
{
    public function __construct(
        private PingSettingsQueries $pingSettingsQueries,
        private Pings $pings,
        private Notifications $notifications,
        private PingEvents $pingEvents,
    ) {
    }

    public function clearForActor(OrganizationId $organizationId, TicketId $ticketId, UserId $actorId, \DateTimeImmutable $at): int
    {
        if (!$this->pingSettingsQueries->isAutoClearOn($organizationId)) {
            return 0;
        }

        $openPings = $this->pings->lockedOpenPingsTo($actorId, $ticketId);
        foreach ($openPings as $ping) {
            $ping->clear(PingClearedReason::Acted, $at);
            $this->pings->save($ping);
            $this->pingEvents->publish(new PingCleared(
                $organizationId->toString(),
                $ticketId->toString(),
                $ping->id()->toString(),
                $ping->fromId()->toString(),
                $actorId->toString(),
                ClearedReasonOfEvent::Acted,
                $actorId->toString(),
                $at,
            ));
        }
        if ([] !== $openPings) {
            $this->notifications->markPingsDone($organizationId, $actorId, $ticketId, $at);
        }

        return \count($openPings);
    }
}
