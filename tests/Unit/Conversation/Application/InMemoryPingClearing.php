<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Open pings counted per organization, ticket and recipient. As the real port, a call clears all of the actor's open
 * pings on that ticket of that organization, so a second call clears none, and pings to others or on other tickets stay.
 */
final class InMemoryPingClearing implements PingClearing
{
    /** @var array<string, int> */
    private array $openPingCounts = [];

    /** @var list<array{string, string, string, \DateTimeImmutable}> organization, ticket and actor ids, and when */
    private array $calls = [];

    public function addOpenPings(OrganizationId $organizationId, TicketId $ticketId, UserId $recipientId, int $count): void
    {
        $key = self::keyOf($organizationId, $ticketId, $recipientId);
        $this->openPingCounts[$key] = ($this->openPingCounts[$key] ?? 0) + $count;
    }

    public function clearForActor(OrganizationId $organizationId, TicketId $ticketId, UserId $actorId, \DateTimeImmutable $at): int
    {
        $this->calls[] = [$organizationId->toString(), $ticketId->toString(), $actorId->toString(), $at];
        $key = self::keyOf($organizationId, $ticketId, $actorId);
        $clearedCount = $this->openPingCounts[$key] ?? 0;
        unset($this->openPingCounts[$key]);

        return $clearedCount;
    }

    /**
     * @return list<array{string, string, string, \DateTimeImmutable}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    private static function keyOf(OrganizationId $organizationId, TicketId $ticketId, UserId $userId): string
    {
        return $organizationId->toString().'|'.$ticketId->toString().'|'.$userId->toString();
    }
}
