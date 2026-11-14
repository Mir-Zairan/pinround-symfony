<?php

declare(strict_types=1);

namespace App\Notification\Application\Ping;

use App\SharedKernel\Domain\UserId;

/**
 * The people one sender pinged about one ticket within the organization's ping limit, and when each may be pinged again.
 */
final readonly class RecentPings
{
    /**
     * @param int                               $limitHours  the organization's ping limit: 1, 4, 8 or 24
     * @param array<string, \DateTimeImmutable> $againAtById the first instant the sender may ping each person
     *                                                       again, keyed by user id string
     */
    public function __construct(
        public int $limitHours,
        private array $againAtById,
    ) {
    }

    /**
     * @return \DateTimeImmutable|null null when the sender did not ping $toUserId about the ticket within the limit
     */
    public function againAtOf(UserId $toUserId): ?\DateTimeImmutable
    {
        return $this->againAtById[$toUserId->toString()] ?? null;
    }
}
