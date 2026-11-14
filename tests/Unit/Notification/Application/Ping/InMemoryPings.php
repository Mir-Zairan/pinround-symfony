<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Ping;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingId;
use App\Notification\Domain\Pings;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Like DoctrinePings with ntf_ping's primary key: a second ping with an id already stored is refused. The locking read
 * needs a tenant, as the real one needs a transaction under one, and finds only that organization's pings.
 */
final class InMemoryPings implements Pings
{
    /** @var array<string, Ping> */
    private array $stored = [];

    private int $issuedIdCount = 0;

    private ?\Throwable $nextAddFailure = null;

    private ?OrganizationId $tenantId = null;

    public function nextIdentity(): PingId
    {
        ++$this->issuedIdCount;

        return new PingId(\sprintf('0197a3c0-0000-7000-8000-%012d', 9000 + $this->issuedIdCount));
    }

    public function add(Ping $ping): void
    {
        if (null !== $this->nextAddFailure) {
            $failure = $this->nextAddFailure;
            $this->nextAddFailure = null;

            throw $failure;
        }
        if (isset($this->stored[$ping->id()->toString()])) {
            throw new \LogicException('A ping with this id is already stored.');
        }

        $this->stored[$ping->id()->toString()] = $ping;
    }

    public function save(Ping $ping): void
    {
        $this->stored[$ping->id()->toString()] = $ping;
    }

    public function lockedOpenPingsTo(UserId $toId, TicketId $ticketId): array
    {
        $tenantId = $this->tenantId ?? throw new \LogicException('Locking pings needs an open transaction.');
        $openPings = array_values(array_filter($this->stored, static fn (Ping $ping): bool => $ping->organizationId()->equals($tenantId)
            && $ping->toId()->equals($toId)
            && $ping->ticketId()->equals($ticketId)
            && null === $ping->clearedAt()));
        usort($openPings, static fn (Ping $left, Ping $right): int => [$left->createdAt(), $left->id()->toString()] <=> [$right->createdAt(), $right->id()->toString()]);

        return $openPings;
    }

    public function actAsTenant(OrganizationId $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function failNextAdd(\Throwable $failure): void
    {
        $this->nextAddFailure = $failure;
    }

    /**
     * @return list<Ping>
     */
    public function stored(): array
    {
        return array_values($this->stored);
    }
}
