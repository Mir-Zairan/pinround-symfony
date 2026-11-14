<?php

declare(strict_types=1);

namespace App\Notification\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Sending a ping, for the ticket page.
 */
interface PingCommands
{
    /**
     * Pings $toUserId about the ticket with that key: stores one ping and publishes PingSent to the outbox. It reads and
     * writes under row-level security, so it needs an open transaction whose tenant is $organizationId; the ping is
     * sent only if that transaction commits. A refused ping stores and publishes nothing.
     *
     * @param string $body 1 to 500 characters after trimming
     *
     * @throws PingToYourself          when $fromUserId and $toUserId are the same person, before any read
     * @throws PingMessageRefused      when the trimmed body is empty or longer than 500 characters, before any read
     * @throws PingRecipientCannotView when $toUserId is not a member of the organization or may not see the ticket
     * @throws PingTooSoon             when the sender pinged this person about this ticket within the ping limit
     * @throws PingsPaused             when the ping limit cannot be checked
     * @throws \DomainException        Ticketing's TicketNotFound when the sender may not see a ticket with that key,
     *                                 exactly as when there is none
     * @throws \LogicException         when no transaction is open or its tenant is another organization
     */
    public function ping(OrganizationId $organizationId, string $ticketKey, UserId $fromUserId, UserId $toUserId, string $body, \DateTimeImmutable $at): void;
}
