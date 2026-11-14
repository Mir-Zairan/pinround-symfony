<?php

declare(strict_types=1);

namespace App\Conversation\Application\PostReply;

use App\Conversation\Api\Event\ReplyPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ReplyRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\PostedMessage;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Application\VisibleTickets;
use App\Conversation\Domain\Replies;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * Replies under a comment or question of a ticket's thread, one level deep (FR-CNV-04). The message's author gets one
 * notification from ReplyPosted. A reply by the person asked is not an answer: only Answer closes a question. A reply
 * clears its author's pings on the ticket (FR-PNG-05).
 */
final readonly class PostReply
{
    public function __construct(
        private VisibleTickets $visibleTickets,
        private ThreadMessages $threadMessages,
        private Replies $replies,
        private ConversationEvents $conversationEvents,
        private PingClearing $pingClearing,
    ) {
    }

    /**
     * Decides on the message's locked row, so a removal cannot slip in between the check and the reply, then saves the
     * reply, writes ReplyPosted to the outbox and clears the author's open pings on the ticket, all in the open
     * transaction, whose tenant must be the ticket's organization.
     *
     * @param TicketVisibilityFacts $ticket    the visibility facts of the ticket $ticketId, as the ticket page read them
     * @param string                $messageId the id of a comment or question; a reply's id names no message, so a
     *                                         reply to a reply is not found
     *
     * @return PostedMessage the new reply's id and how many pings it cleared
     *
     * @throws TicketNotFound     when the ticket is of another organization than the tenant, or the author may not see
     *                            it; nothing is saved
     * @throws MessageNotFound    when the id is malformed or names no comment or question of that ticket; nothing is
     *                            saved
     * @throws ReplyRefused       when the message was removed or its question withdrawn; nothing is saved
     * @throws MessageBodyRefused when the trimmed body is empty or longer than 10,000 characters; nothing is saved
     * @throws \LogicException    when no transaction is open; nothing is saved
     */
    public function post(TicketId $ticketId, TicketVisibilityFacts $ticket, string $messageId, UserId $authorId, string $body, \DateTimeImmutable $postedAt): PostedMessage
    {
        $this->visibleTickets->refuseUnlessVisibleTo($ticket, $authorId);
        $message = $this->threadMessages->lockedOf($ticket->organizationId, $ticketId, $messageId);
        try {
            $reply = $message->reply($this->replies->nextIdentity(), $authorId, trim($body), $postedAt);
        } catch (\DomainException $refusal) {
            throw new ReplyRefused($refusal->getMessage(), 0, $refusal);
        } catch (\InvalidArgumentException $invalidBody) {
            throw new MessageBodyRefused($invalidBody->getMessage(), 0, $invalidBody);
        }
        // Published before the save, as PostComment does: publish refuses when no transaction is open.
        $this->conversationEvents->publish(new ReplyPosted(
            $ticket->organizationId->toString(),
            $ticketId->toString(),
            $message->id()->toString(),
            $reply->id()->toString(),
            $message->authorId()->toString(),
            $authorId->toString(),
            $postedAt,
        ));
        $this->replies->add($reply);

        return new PostedMessage($reply->id()->toString(), $this->pingClearing->clearForActor($ticket->organizationId, $ticketId, $authorId, $postedAt));
    }
}
