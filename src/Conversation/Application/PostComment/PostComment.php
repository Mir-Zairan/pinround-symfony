<?php

declare(strict_types=1);

namespace App\Conversation\Application\PostComment;

use App\Conversation\Api\Event\CommentPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\PostedMessage;
use App\Conversation\Application\VisibleTickets;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * Posts a comment in a ticket's thread (FR-CNV-01). A comment changes no status and notifies nobody, and it clears the
 * author's pings on the ticket (FR-PNG-05).
 */
final readonly class PostComment
{
    public function __construct(
        private VisibleTickets $visibleTickets,
        private Messages $messages,
        private ConversationEvents $conversationEvents,
        private PingClearing $pingClearing,
    ) {
    }

    /**
     * Saves the comment, writes CommentPosted to the outbox and clears the author's open pings on the ticket, all in the
     * open transaction, whose tenant must be the ticket's organization.
     *
     * @param TicketVisibilityFacts $ticket the visibility facts of the ticket $ticketId, as the ticket page read them
     *
     * @return PostedMessage the new message's id and how many pings it cleared
     *
     * @throws TicketNotFound     when the ticket is of another organization than the tenant, or the author may not see
     *                            it; nothing is saved
     * @throws MessageBodyRefused when the trimmed body is empty or longer than 10,000 characters; nothing is saved
     * @throws \LogicException    when no transaction is open; nothing is saved
     */
    public function post(TicketId $ticketId, TicketVisibilityFacts $ticket, UserId $authorId, string $body, \DateTimeImmutable $postedAt): PostedMessage
    {
        $this->visibleTickets->refuseUnlessVisibleTo($ticket, $authorId);
        try {
            $comment = Message::comment($this->messages->nextIdentity(), $ticket->organizationId, $ticketId, $authorId, trim($body), $postedAt);
        } catch (\InvalidArgumentException $invalidBody) {
            throw new MessageBodyRefused($invalidBody->getMessage(), 0, $invalidBody);
        }
        // Published before the save: publish refuses when no transaction is open, and add would then commit on its own.
        $this->conversationEvents->publish(new CommentPosted(
            $ticket->organizationId->toString(),
            $ticketId->toString(),
            $comment->id()->toString(),
            $authorId->toString(),
            $postedAt,
        ));
        $this->messages->add($comment);

        return new PostedMessage($comment->id()->toString(), $this->pingClearing->clearForActor($ticket->organizationId, $ticketId, $authorId, $postedAt));
    }
}
