<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * A reply was posted under a comment or question, one level deep. An answer that resolves a question publishes
 * QuestionAnswered instead.
 */
final readonly class ReplyPosted implements ConversationEvent
{
    /**
     * @param string $messageId       the cnv_message id of the comment or question replied to
     * @param string $replyId         the cnv_reply id of the reply
     * @param string $messageAuthorId the author of the comment or question replied to, who is notified of the reply
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $messageId,
        public string $replyId,
        public string $messageAuthorId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
