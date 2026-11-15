<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * The author removed their comment or withdrew their open question; the thread keeps a "Message removed" line where
 * it was. It notifies nobody; open pages use it to update the thread, and Notification marks done the bell rows about
 * the message.
 */
final readonly class MessageRemoved implements ConversationEvent
{
    /**
     * @param string $messageId the cnv_message id of the comment or question that was removed
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $messageId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
