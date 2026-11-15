<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * A comment was marked resolved. No module consumes it yet; open pages use it to update the thread.
 */
final readonly class CommentResolved implements ConversationEvent
{
    /**
     * @param string $messageId the cnv_message id of the comment that was resolved
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
