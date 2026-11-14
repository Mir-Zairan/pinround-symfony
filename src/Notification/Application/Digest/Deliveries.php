<?php

declare(strict_types=1);

namespace App\Notification\Application\Digest;

use App\SharedKernel\Domain\UserId;

/**
 * The digest emails sent to people of the tenant's organization, one per person and window, read and recorded under
 * row-level security. Every method needs a tenant and throws TenantNotSet before any query without one, and an open
 * transaction, throwing \LogicException without one, since outside one row-level security would hide every delivery
 * and a digest would look never sent.
 */
interface Deliveries
{
    public const string DIGEST_WINDOW_PREFIX = 'digest:';

    /**
     * Holds the person's digest lock until the transaction ends, waiting while another transaction holds it. Taken
     * before lastDigestSentAt, it makes a second run for the person read only after the first committed or rolled
     * back, so two runs on both sides of a window boundary cannot both see no recent digest.
     */
    public function lockFor(UserId $userId): void;

    /**
     * @return \DateTimeImmutable|null when the person's latest digest was sent; null when none was
     */
    public function lastDigestSentAt(UserId $userId): ?\DateTimeImmutable;

    /**
     * Records the digest before it is sent, so a second run in the same window finds the row and sends nothing.
     *
     * @param string $windowKey starts with DIGEST_WINDOW_PREFIX and names the window, such as its UTC start
     *
     * @return bool true only when this call recorded it; false when the person already has a delivery for $windowKey
     *
     * @throws \InvalidArgumentException when $windowKey does not start with DIGEST_WINDOW_PREFIX or names no window, before any query
     */
    public function recordDigest(UserId $userId, string $windowKey, \DateTimeImmutable $sentAt): bool;
}
