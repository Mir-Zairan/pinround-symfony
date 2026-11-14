<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Bell;

use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\BellInbox;
use App\SharedKernel\Domain\UserId;

/**
 * A BellInbox that holds each group's open notifications as a count: Done finishes them, so a second Done finds none,
 * and Open marks them read, which leaves them open. Like DbalBellInbox it does not check the ticket's visibility.
 */
final class InMemoryBellInbox implements BellInbox
{
    /** @var array<string, int> open notifications by group */
    private array $openByGroup = [];

    /** @var array<string, int> read notifications by group */
    private array $readByGroup = [];

    /** @var array<string, string> the ticket key of each group's snapshot */
    private array $ticketKeyByGroup = [];

    public function add(BellGroupKey $group, string $ticketKey, int $openCount): void
    {
        $this->openByGroup[self::keyOf($group)] = $openCount;
        $this->ticketKeyByGroup[self::keyOf($group)] = $ticketKey;
    }

    public function openCountOf(UserId $userId, \DateTimeImmutable $now): int
    {
        throw new \LogicException('The bell commands never read the count.');
    }

    public function markGroupDone(BellGroupKey $group, \DateTimeImmutable $now): int
    {
        $marked = $this->openByGroup[self::keyOf($group)] ?? 0;
        $this->openByGroup[self::keyOf($group)] = 0;

        return $marked;
    }

    public function markGroupRead(BellGroupKey $group, \DateTimeImmutable $now): ?string
    {
        $open = $this->openByGroup[self::keyOf($group)] ?? 0;
        if (0 === $open) {
            return null;
        }
        $this->readByGroup[self::keyOf($group)] = $open;

        return $this->ticketKeyByGroup[self::keyOf($group)];
    }

    public function openCountOfGroup(BellGroupKey $group): int
    {
        return $this->openByGroup[self::keyOf($group)] ?? 0;
    }

    public function readCountOfGroup(BellGroupKey $group): int
    {
        return $this->readByGroup[self::keyOf($group)] ?? 0;
    }

    private static function keyOf(BellGroupKey $group): string
    {
        return $group->recipientId->toString().'/'.$group->senderId->toString().'/'.$group->ticketId->toString();
    }
}
