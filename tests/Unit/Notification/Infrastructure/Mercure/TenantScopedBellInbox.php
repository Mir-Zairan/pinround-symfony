<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mercure;

use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\BellInbox;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;

/**
 * Counts like DbalBellInbox under row-level security: only the counts of the organization set, and it throws
 * TenantNotSet with none set. It records each count it reads.
 */
final class TenantScopedBellInbox implements BellInbox
{
    /** @var list<array{string, string}> the user id and the instant of each count read */
    public array $countedFor = [];

    /**
     * @param array<string, array<string, int>> $openCounts by organization id, then user id
     */
    public function __construct(private readonly TenantContext $tenantContext, private readonly array $openCounts)
    {
    }

    public function openCountOf(UserId $userId, \DateTimeImmutable $now): int
    {
        $organizationId = $this->tenantContext->require()->toString();
        $this->countedFor[] = [$userId->toString(), $now->format('Y-m-d H:i:s T')];

        return $this->openCounts[$organizationId][$userId->toString()] ?? 0;
    }

    public function markGroupDone(BellGroupKey $group, \DateTimeImmutable $now): int
    {
        throw new \LogicException('A bell push never changes the bell.');
    }

    public function markGroupRead(BellGroupKey $group, \DateTimeImmutable $now): ?string
    {
        throw new \LogicException('A bell push never changes the bell.');
    }
}
