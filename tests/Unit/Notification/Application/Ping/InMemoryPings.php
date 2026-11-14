<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Ping;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingId;
use App\Notification\Domain\Pings;

/**
 * Like DoctrinePings with ntf_ping's primary key: a second ping with an id already stored is refused.
 */
final class InMemoryPings implements Pings
{
    /** @var array<string, Ping> */
    private array $stored = [];

    private int $issuedIdCount = 0;

    private ?\Throwable $nextAddFailure = null;

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
