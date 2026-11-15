<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\Deliveries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;

/**
 * Like DbalDeliveries for one person: the latest digest's time, and a window key recorded once. A key another
 * transaction holds uncommitted is not seen by lastDigestSentAt but makes recordDigest answer false. Every method
 * throws TenantNotSet without a tenant and DbalDeliveries' \LogicException when built as outside a transaction.
 */
final class InMemoryDeliveries implements Deliveries
{
    /** @var list<string> each call in order, as its method name and user id, such as "lockFor <id>" */
    public array $calls = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $sentAtByWindowKey = [];

    /**
     * @param array<string, string> $sentAtByWindowKey     committed digests
     * @param list<string>          $uncommittedWindowKeys
     */
    public function __construct(
        private readonly TenantContext $tenantContext,
        array $sentAtByWindowKey = [],
        private readonly array $uncommittedWindowKeys = [],
        private readonly bool $isInTransaction = true,
    ) {
        foreach ($sentAtByWindowKey as $windowKey => $sentAt) {
            $this->sentAtByWindowKey[$windowKey] = new \DateTimeImmutable($sentAt);
        }
    }

    public function lockFor(UserId $userId): void
    {
        $this->requireTenantAndTransaction();
        $this->calls[] = 'lockFor '.$userId->toString();
    }

    public function lastDigestSentAt(UserId $userId): ?\DateTimeImmutable
    {
        $this->requireTenantAndTransaction();
        $this->calls[] = 'lastDigestSentAt '.$userId->toString();

        return [] === $this->sentAtByWindowKey ? null : max($this->sentAtByWindowKey);
    }

    public function recordDigest(UserId $userId, string $windowKey, \DateTimeImmutable $sentAt): bool
    {
        if (!str_starts_with($windowKey, self::DIGEST_WINDOW_PREFIX) || self::DIGEST_WINDOW_PREFIX === $windowKey) {
            throw new \InvalidArgumentException(\sprintf('A digest window key starts with "%s" and names the window.', self::DIGEST_WINDOW_PREFIX));
        }
        $this->requireTenantAndTransaction();
        $this->calls[] = 'recordDigest '.$userId->toString();
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

    private function requireTenantAndTransaction(): void
    {
        $this->tenantContext->require();
        if (!$this->isInTransaction) {
            throw new \LogicException('A digest delivery is read and recorded in a transaction; none is open.');
        }
    }
}
