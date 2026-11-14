<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Ping;

use App\Notification\Api\PingsPaused;
use App\Notification\Api\PingTooSoon;
use App\Notification\Application\Ping\PingWindow;
use App\Notification\Domain\PingId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Behaves like ValkeyPingWindow: a held slot refuses every take for its key with the same againAt until it ends, and
 * only the ping that holds a slot releases it.
 */
final class InMemoryPingWindow implements PingWindow
{
    /** @var array<string, array{againAt: \DateTimeImmutable, pingId: string}> */
    private array $slots = [];

    private bool $isReleasePaused = false;

    public function take(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId, int $hours, \DateTimeImmutable $at): void
    {
        $key = self::keyOf($organizationId, $fromUserId, $toUserId, $ticketId);
        $slot = $this->slots[$key] ?? null;
        if (null !== $slot && $slot['againAt'] > $at) {
            throw new PingTooSoon($slot['againAt']);
        }

        $this->slots[$key] = ['againAt' => $at->modify(\sprintf('+%d hours', $hours)), 'pingId' => $pingId->toString()];
    }

    public function release(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId): void
    {
        if ($this->isReleasePaused) {
            throw new PingsPaused();
        }

        $key = self::keyOf($organizationId, $fromUserId, $toUserId, $ticketId);
        if (($this->slots[$key]['pingId'] ?? null) === $pingId->toString()) {
            unset($this->slots[$key]);
        }
    }

    public function pauseReleases(): void
    {
        $this->isReleasePaused = true;
    }

    public function heldSlotCount(): int
    {
        return \count($this->slots);
    }

    private static function keyOf(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId): string
    {
        return \sprintf('ping:%s:%s:%s:%s', $organizationId->toString(), $fromUserId->toString(), $toUserId->toString(), $ticketId->toString());
    }
}
