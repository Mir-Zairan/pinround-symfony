<?php

declare(strict_types=1);

namespace App\Notification\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One group of the bell: a person's open notifications from one sender about one ticket. The names and the key are
 * the snapshot the newest notification was written with; newestPingText is that ping's text, null when the newest one
 * is not a ping.
 */
final readonly class BellGroup
{
    /**
     * @param positive-int $size       the open notifications in the group
     * @param bool         $isAllPings true when every one of them is a ping
     * @param string       $newestKind the kind code of the newest one (realtime-and-notifications.md, "Notification kinds")
     * @param bool         $hasUnread  true when the person has not opened one of them yet
     */
    public function __construct(
        public UserId $senderId,
        public string $senderName,
        public TicketId $ticketId,
        public string $ticketKey,
        public int $size,
        public bool $isAllPings,
        public string $newestKind,
        public ?string $newestPingText,
        public \DateTimeImmutable $latestAt,
        public bool $hasUnread,
    ) {
    }
}
