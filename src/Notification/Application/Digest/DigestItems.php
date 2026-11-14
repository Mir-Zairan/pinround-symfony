<?php

declare(strict_types=1);

namespace App\Notification\Application\Digest;

use App\SharedKernel\Domain\UserId;

/**
 * What a person's email digest lists (FR-PNG-09). It reads under the tenant's row-level security, so it needs an open
 * transaction whose tenant is the person's organization.
 */
interface DigestItems
{
    /**
     * The person's ping and question rows that are unread, not done, delivered (not held for quiet hours), about a
     * ticket they may still see, and created after $since and at most Digest::MIN_ITEM_AGE_SECONDS before $now. A row
     * still held when a digest is made falls before the next digest's $since, so email never lists it; the bell does.
     *
     * @param \DateTimeImmutable $since the previous digest's coveredUntil; an item created at that instant is left out
     *
     * @return Digest empty, with no query, when $since is not before Digest::coveredUntilAt($now)
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     * @throws \UnexpectedValueException                  when a listed row has no actorName in its data
     */
    public function digestOf(UserId $recipientId, \DateTimeImmutable $since, \DateTimeImmutable $now): Digest;
}
