<?php

declare(strict_types=1);

namespace App\Notification\Application\Digest;

use App\Notification\Application\Notify\NotificationKind;

/**
 * One unread bell item as the email digest lists it: who, what kind, which ticket and when. No ping text, comment or
 * description is ever here (FR-PNG-09).
 */
final readonly class DigestItem
{
    /**
     * @param NotificationKind $kind        ping or question, the kinds a digest lists
     * @param string|null      $ticketTitle null for a ticket restricted to its team
     * @param string|null      $teamName    the restricted ticket's team; null for a ticket with a title or no team
     */
    public function __construct(
        public string $actorName,
        public NotificationKind $kind,
        public string $ticketKey,
        public ?string $ticketTitle,
        public ?string $teamName,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
