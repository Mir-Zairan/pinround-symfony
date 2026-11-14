<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * A question was asked to one person on a ticket. The ticket keeps its step; the asked person gets the turn, which the
 * asking transaction already wrote to the turn row (TurnProjector::refresh).
 */
final readonly class QuestionAsked implements ConversationEvent
{
    /**
     * @param string $messageId the cnv_message id of the question
     * @param string $toUserId  the person the question is to
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $messageId,
        public string $toUserId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
