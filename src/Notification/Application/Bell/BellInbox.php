<?php

declare(strict_types=1);

namespace App\Notification\Application\Bell;

use App\SharedKernel\Domain\UserId;

/**
 * The open notifications of a person's bell, read and changed in the tenant's organization under row-level security.
 * Open means delivered (no deliver_after, or one at or before $now), not done, with a sender and a ticket, and of a
 * BellKind. The count also needs a ticket the person may still see (ADR-0013); as in the bell, only the 200 tickets
 * with the newest open notifications are checked and the rest count as hidden. The changes do not check the ticket:
 * MarkBellGroupDone and OpenBellGroup do before they call them. Every method needs a tenant and throws TenantNotSet
 * before any query without one, and an open transaction, throwing \LogicException without one.
 */
interface BellInbox
{
    /**
     * The count on the person's bell; it may be up to a minute old, unless a change of the person's notifications in
     * this transaction or one committed since dropped it. A change of the person's teams does not drop it.
     */
    public function openCountOf(UserId $userId, \DateTimeImmutable $now): int;

    /**
     * Marks the group's open notifications done in one conditional update, so a second click or a second tab finds none;
     * the bell's count is dropped after the commit.
     *
     * @return int how many it marked done; 0 when the group has none open, which includes another person's group
     */
    public function markGroupDone(BellGroupKey $group, \DateTimeImmutable $now): int;

    /**
     * Marks the group's open notifications read and keeps the time of those read before.
     *
     * @return string|null the ticket's key from the group's snapshot, or null when the group has none open, which
     *                     includes another person's group
     */
    public function markGroupRead(BellGroupKey $group, \DateTimeImmutable $now): ?string;
}
