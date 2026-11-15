<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * A comment was posted on a ticket. A question publishes QuestionAsked instead.
 */
final readonly class CommentPosted implements ConversationEvent
{
    /**
     * @param string $messageId the cnv_message id of the comment
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
