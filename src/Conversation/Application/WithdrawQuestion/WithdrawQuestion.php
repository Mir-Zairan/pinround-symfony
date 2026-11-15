<?php

declare(strict_types=1);

namespace App\Conversation\Application\WithdrawQuestion;

use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\QuestionRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\Messages;
use App\Insight\Api\TurnProjector;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketPauses;

/**
 * The asker takes back their open question (workflow-engine.md, "or the asker withdraws it"): the thread shows
 * "Message removed" where it was, the ticket resumes on its step and the turn goes back to the step's owner. It
 * notifies nobody; its MessageRemoved refreshes the pages open on the ticket.
 */
final readonly class WithdrawQuestion
{
    public function __construct(
        private ThreadMessages $threadMessages,
        private Messages $messages,
        private TicketPauses $ticketPauses,
        private TurnProjector $turnProjector,
        private ConversationEvents $conversationEvents,
    ) {
    }

    /**
     * Decides on the question's locked row, then saves the withdrawal, resumes the ticket, refreshes its turn and writes
     * MessageRemoved to the outbox, all in the open transaction, whose tenant must be $organizationId. That the asker
     * may write in the thread (ConversationPermission COMMENT) is the caller's check.
     *
     * @throws MessageNotFound        when the id is malformed or names no message of that ticket; nothing changes
     * @throws QuestionRefused        when the message is a comment, the question is answered or withdrawn, or the
     *                                withdrawer is not its asker; nothing changes
     * @throws TicketChangedMeanwhile when another request saved the ticket after this request read it; the caller rolls
     *                                back
     * @throws \LogicException        when no transaction is open, or the ticket waits for no answer; the caller rolls
     *                                back
     */
    public function withdraw(OrganizationId $organizationId, TicketId $ticketId, string $questionId, UserId $withdrawerId, \DateTimeImmutable $withdrawnAt): void
    {
        $question = $this->threadMessages->lockedOf($organizationId, $ticketId, $questionId);
        try {
            $question->withdraw($withdrawerId, $withdrawnAt);
        } catch (\DomainException $refusal) {
            throw new QuestionRefused($refusal->getMessage(), 0, $refusal);
        }

        // Published before the saves, as PostComment does: publish refuses when no transaction is open.
        $this->conversationEvents->publish(new MessageRemoved(
            $organizationId->toString(),
            $ticketId->toString(),
            $question->id()->toString(),
            $withdrawerId->toString(),
            $withdrawnAt,
        ));
        $this->messages->save($question);
        $this->ticketPauses->endQuestionPause($ticketId);
        $this->turnProjector->refresh($ticketId);
    }
}
