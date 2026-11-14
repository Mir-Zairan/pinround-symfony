<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\Deliveries;
use App\SharedKernel\Domain\UserId;

/**
 * Like DbalDeliveries for one person: the latest digest's time, and a window key recorded once. A key another
 * transaction holds uncommitted is not seen by lastDigestSentAt but makes recordDigest answer false.
 */
final class InMemoryDeliveries implements Deliveries
{
    public int $lastReadCount = 0;

    /** @var array<string, \DateTimeImmutable> */
    private array $sentAtByWindowKey = [];

    /**
     * @param array<string, string> $sentAtByWindowKey     committed digests
     * @param list<string>          $uncommittedWindowKeys
     */
    public function __construct(array $sentAtByWindowKey = [], private readonly array $uncommittedWindowKeys = [])
    {
        foreach ($sentAtByWindowKey as $windowKey => $sentAt) {
            $this->sentAtByWindowKey[$windowKey] = new \DateTimeImmutable($sentAt);
        }
    }

    public function lastDigestSentAt(UserId $userId): ?\DateTimeImmutable
    {
        ++$this->lastReadCount;

        return [] === $this->sentAtByWindowKey ? null : max($this->sentAtByWindowKey);
    }

    public function recordDigest(UserId $userId, string $windowKey, \DateTimeImmutable $sentAt): bool
    {
        if (!str_starts_with($windowKey, self::DIGEST_WINDOW_PREFIX) || self::DIGEST_WINDOW_PREFIX === $windowKey) {
            throw new \InvalidArgumentException('A digest window key starts with the prefix and names the window.');
        }
        if (isset($this->sentAtByWindowKey[$windowKey]) || \in_array($windowKey, $this->uncommittedWindowKeys, true)) {
            return false;
        }
        $this->sentAtByWindowKey[$windowKey] = $sentAt;

        return true;
    }

    /**
     * @return array<string, string> each recorded window key's sent time, in UTC
     */
    public function recorded(): array
    {
        return array_map(
            static fn (\DateTimeImmutable $sentAt): string => $sentAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $this->sentAtByWindowKey,
        );
    }
}
