<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The Mercure topic IRIs (realtime-and-notifications.md, Mercure topics). Every topic starts with its organization, and
 * is built from ids only, so no ticket key, title or text ever reaches the hub, its subscribe URLs or its logs. The
 * IRIs name topics; nothing is served at them.
 */
final class RealtimeTopics
{
    public const string BASE = 'https://pinround.app';

    /**
     * The ticket topic: content-free "refresh" signals for those TicketPermission::VIEW lets see the ticket.
     */
    public static function ofTicket(OrganizationId $organizationId, TicketId $ticketId): string
    {
        return self::BASE.'/o/'.$organizationId->toString().'/t/'.$ticketId->toString();
    }

    /**
     * The per-user topic: Turbo Streams with content for that user only.
     */
    public static function ofUser(OrganizationId $organizationId, UserId $userId): string
    {
        return self::BASE.'/o/'.$organizationId->toString().'/u/'.$userId->toString();
    }
}
