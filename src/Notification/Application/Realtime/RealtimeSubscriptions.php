<?php

declare(strict_types=1);

namespace App\Notification\Application\Realtime;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Lets a browser subscribe to live updates (realtime-and-notifications.md, Mercure topics): the user's own topic and the
 * topics of the tickets the caller has already checked with TicketPermission::VIEW. It checks nothing itself.
 */
interface RealtimeSubscriptions
{
    /** How long a subscriber cookie lasts; the browser renews it before then through POST /realtime/token. */
    public const int LIFETIME_SECONDS = 600;

    /**
     * Sets the subscriber cookie on the response to the current main request: the user's topic and one topic per
     * ticket, issued now and expiring LIFETIME_SECONDS later by the app clock. It replaces any cookie the browser held,
     * so a ticket left out here is no longer readable once the hub sees the new cookie.
     *
     * @throws \LogicException when there is no main request
     */
    public function grant(OrganizationId $organizationId, UserId $userId, TicketId ...$ticketIds): void;

    /**
     * The hub URL a browser subscribes to for the user's topic and, when given, the ticket's topic. It grants nothing:
     * the hub reads the topics a subscriber may read from the cookie grant() set.
     */
    public function subscribeUrlOf(OrganizationId $organizationId, UserId $userId, ?TicketId $ticketId = null): string;
}
