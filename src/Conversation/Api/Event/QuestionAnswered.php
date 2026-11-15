<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * A question was answered: a reply that resolves it. The turn goes back to the ticket's step, which the answering
 * transaction already wrote to the turn row (TurnProjector::refresh).
 */
final readonly class QuestionAnswered implements ConversationEvent
{
    /**
     * @param string $messageId the cnv_message id of the question that was answered
     * @param string $askerId   the person who asked the question, who is notified of the answer
     */
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $messageId,
        public string $askerId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
