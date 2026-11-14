<?php

declare(strict_types=1);

namespace App\Conversation\Application\Answer;

use App\Conversation\Api\Event\QuestionAnswered;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\QuestionRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\Messages;
use App\Conversation\Domain\Replies;
use App\Conversation\Domain\Reply;
use App\Insight\Api\TurnProjector;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketPauses;

/**
 * The person asked answers the open question (FR-CNV-02): the answer is a reply, the question closes, the ticket
 * resumes on its step and the turn goes back to the step's owner. The asker gets one notification from QuestionAnswered;
 * an answer posts no ReplyPosted.
 */
final readonly class Answer
{
    public function __construct(
        private ThreadMessages $threadMessages,
        private Messages $messages,
        private Replies $replies,
        private TicketPauses $ticketPauses,
        private TurnProjector $turnProjector,
        private ConversationEvents $conversationEvents,
    ) {
    }

    /**
     * Decides on the question's locked row, then saves the answer and the closed question, resumes the ticket,
     * refreshes its turn and writes QuestionAnswered to the outbox, all in the open transaction, whose tenant must be
     * $organizationId. That the answerer may write in the thread (ConversationPermission COMMENT) is the caller's check.
     *
     * @return string the answer reply's id
     *
     * @throws MessageNotFound        when the id is malformed or names no message of that ticket; nothing changes
     * @throws QuestionRefused        when the message is a comment, the question is answered or withdrawn, or the
     *                                answerer is not the person asked; nothing changes
     * @throws MessageBodyRefused     when the trimmed body is empty or longer than 10,000 characters; nothing changes
     * @throws TicketChangedMeanwhile when another request saved the ticket after this request read it; the caller rolls
     *                                back
     * @throws \LogicException        when no transaction is open, or the ticket waits for no answer; the caller rolls
     *                                back
     */
    public function answer(OrganizationId $organizationId, TicketId $ticketId, string $questionId, UserId $answererId, string $body, \DateTimeImmutable $answeredAt): string
    {
        $question = $this->threadMessages->lockedOf($organizationId, $ticketId, $questionId);
        try {
            $answerReply = Reply::post($this->replies->nextIdentity(), $organizationId, $question->id(), $answererId, trim($body), true, $answeredAt);
        } catch (\InvalidArgumentException $invalidBody) {
            throw new MessageBodyRefused($invalidBody->getMessage(), 0, $invalidBody);
        }
        try {
            $question->answer($answererId, $answeredAt);
        } catch (\DomainException $refusal) {
            throw new QuestionRefused($refusal->getMessage(), 0, $refusal);
        }

        // Published before the saves, as PostComment does: publish refuses when no transaction is open.
        $this->conversationEvents->publish(new QuestionAnswered(
            $organizationId->toString(),
            $ticketId->toString(),
            $question->id()->toString(),
            $question->authorId()->toString(),
            $answererId->toString(),
            $answeredAt,
        ));
        $this->replies->add($answerReply);
        $this->messages->save($question);
        $this->ticketPauses->resumeAfterAnswer($ticketId);
        $this->turnProjector->refresh($ticketId);

        return $answerReply->id()->toString();
    }
}
