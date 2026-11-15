<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItems;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;

/**
 * Answers every read with one digest and keeps the window each read asked for. Like DbalDigestItems, it throws
 * TenantNotSet without a tenant, answers an empty digest when $since is not before the window's end, and otherwise
 * throws its \LogicException when built as outside a transaction.
 */
final class RecordingDigestItems implements DigestItems
{
    /** @var list<array{string, string}> since and now of each read, in UTC */
    public array $calls = [];

    public function __construct(
        private readonly Digest $digest,
        private readonly TenantContext $tenantContext,
        private readonly bool $isInTransaction = true,
    ) {
    }

    public function digestOf(UserId $recipientId, \DateTimeImmutable $since, \DateTimeImmutable $now): Digest
    {
        $this->tenantContext->require();
        $utc = new \DateTimeZone('UTC');
        $this->calls[] = [$since->setTimezone($utc)->format(\DATE_ATOM), $now->setTimezone($utc)->format(\DATE_ATOM)];
        $coveredUntil = Digest::coveredUntilAt($now);
        if ($since >= $coveredUntil) {
            return new Digest([], 0, $coveredUntil);
        }
        if (!$this->isInTransaction) {
            throw new \LogicException('A digest is read in a transaction under the organization as tenant; none is open.');
        }

        return $this->digest;
    }
}
