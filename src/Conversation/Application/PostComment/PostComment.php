<?php

declare(strict_types=1);

namespace App\Conversation\Application\PostComment;

use App\Conversation\Api\Event\CommentPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * Posts a comment in a ticket's thread (FR-CNV-01). A comment changes no status and notifies nobody.
 */
final readonly class PostComment
{
    public function __construct(
        private TicketVisibilities $ticketVisibilities,
        private Messages $messages,
        private ConversationEvents $conversationEvents,
    ) {
    }

    /**
     * Saves the comment and writes CommentPosted to the outbox, both in the open transaction, whose tenant must be the
     * ticket's organization.
     *
     * @param TicketVisibilityFacts $ticket the visibility facts of the ticket $ticketId, as the ticket page read them
     *
     * @return string the new message's id
     *
     * @throws TicketNotFound     when the author may not see the ticket; nothing is saved
     * @throws MessageBodyRefused when the trimmed body is empty or longer than 10,000 characters; nothing is saved
     * @throws \LogicException    when no transaction is open
     */
    public function post(TicketId $ticketId, TicketVisibilityFacts $ticket, UserId $authorId, string $body, \DateTimeImmutable $postedAt): string
    {
        if (!$this->ticketVisibilities->of($ticket->organizationId, $authorId)->isVisible($ticket)) {
            throw new TicketNotFound('That ticket does not exist.');
        }

        try {
            $comment = Message::comment($this->messages->nextIdentity(), $ticket->organizationId, $ticketId, $authorId, trim($body), $postedAt);
        } catch (\InvalidArgumentException $invalidBody) {
            throw new MessageBodyRefused($invalidBody->getMessage(), 0, $invalidBody);
        }
        $this->messages->add($comment);
        $this->conversationEvents->publish(new CommentPosted(
            $ticket->organizationId->toString(),
            $ticketId->toString(),
            $comment->id()->toString(),
            $authorId->toString(),
            $postedAt,
        ));

        return $comment->id()->toString();
    }
}
