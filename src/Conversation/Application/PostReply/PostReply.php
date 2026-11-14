<?php

declare(strict_types=1);

namespace App\Conversation\Application\PostReply;

use App\Conversation\Api\Event\ReplyPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ReplyRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\Replies;
use App\Conversation\Domain\Reply;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * Replies under a comment or question of a ticket's thread, one level deep (FR-CNV-04). The message's author gets one
 * notification from ReplyPosted. A reply by the person asked is not an answer: only Answer closes a question.
 */
final readonly class PostReply
{
    public function __construct(
        private TicketVisibilities $ticketVisibilities,
        private ThreadMessages $threadMessages,
        private Replies $replies,
        private ConversationEvents $conversationEvents,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Decides on the message's locked row, so a removal cannot slip in between the check and the reply, then saves the
     * reply and writes ReplyPosted to the outbox, both in the open transaction, whose tenant must be the ticket's
     * organization.
     *
     * @param TicketVisibilityFacts $ticket    the visibility facts of the ticket $ticketId, as the ticket page read them
     * @param string                $messageId the id of a comment or question; a reply's id names no message, so a
     *                                         reply to a reply is not found
     *
     * @return string the new reply's id
     *
     * @throws TicketNotFound     when the ticket is of another organization than the tenant, or the author may not see
     *                            it; nothing is saved
     * @throws MessageNotFound    when the id is malformed or names no comment or question of that ticket; nothing is
     *                            saved
     * @throws ReplyRefused       when the message was removed or its question withdrawn; nothing is saved
     * @throws MessageBodyRefused when the trimmed body is empty or longer than 10,000 characters; nothing is saved
     * @throws \LogicException    when no transaction is open; nothing is saved
     */
    public function post(TicketId $ticketId, TicketVisibilityFacts $ticket, string $messageId, UserId $authorId, string $body, \DateTimeImmutable $postedAt): string
    {
        // The visibility predicate trusts the organization it is given: a ticket for everyone of any organization passes it.
        if (true !== $this->tenantContext->get()?->equals($ticket->organizationId)
            || !$this->ticketVisibilities->of($ticket->organizationId, $authorId)->isVisible($ticket)) {
            throw new TicketNotFound('That ticket does not exist.');
        }

        $message = $this->threadMessages->lockedOf($ticket->organizationId, $ticketId, $messageId);
        if (null !== $message->removedAt()) {
            throw new ReplyRefused('This message was removed, so you cannot reply to it.');
        }
        try {
            $reply = Reply::post($this->replies->nextIdentity(), $ticket->organizationId, $message->id(), $authorId, trim($body), false, $postedAt);
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

        return $reply->id()->toString();
    }
}
