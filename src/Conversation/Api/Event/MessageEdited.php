<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * The author changed the text of their comment. It notifies nobody; open pages use it to update the thread.
 */
final readonly class MessageEdited implements ConversationEvent
{
    /**
     * @param string $messageId the cnv_message id of the comment that was edited
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
