<?php

declare(strict_types=1);

namespace App\Conversation\Application\ResolveComment;

use App\Conversation\Api\Event\CommentResolved;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ResolveRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The comment's author or the lead of the ticket's team marks a comment resolved, which folds it for everyone
 * (FR-CNV-05). A question is never resolved here: only its answer closes it. CommentResolved refreshes the pages open
 * on the ticket; it notifies nobody.
 */
final readonly class ResolveComment
{
    public function __construct(
        private ThreadMessages $threadMessages,
        private Messages $messages,
        private ConversationEvents $conversationEvents,
    ) {
    }

    /**
     * Decides on the message's locked row, so two resolves, or a resolve and a removal, cannot both pass the check,
     * then writes CommentResolved to the outbox and saves, both in the open transaction, whose tenant must be
     * $organizationId.
     *
     * @param UserId|null            $teamLeadId             the lead of the ticket's team, null when the ticket has no team or the team no lead
     * @param \Closure(UserId): void $refuseUnlessMayResolve the caller's permission check, given the locked message's
     *                                                       author; whatever it throws leaves everything unchanged
     *
     * @throws MessageNotFound when the id is malformed or names no message of that ticket; nothing changes
     * @throws ResolveRefused  when the message is a question, the comment is removed or already resolved, or the
     *                         resolver is neither its author nor $teamLeadId; nothing changes
     * @throws \LogicException when no transaction is open; nothing is saved
     */
    public function resolve(OrganizationId $organizationId, TicketId $ticketId, string $messageId, UserId $resolverId, ?UserId $teamLeadId, \DateTimeImmutable $resolvedAt, \Closure $refuseUnlessMayResolve): void
    {
        $message = $this->threadMessages->lockedOf($organizationId, $ticketId, $messageId);
        $refuseUnlessMayResolve($message->authorId());
        try {
            $message->resolve($resolverId, null !== $teamLeadId && $teamLeadId->equals($resolverId), $resolvedAt);
        } catch (\DomainException $refusal) {
            throw new ResolveRefused($refusal->getMessage(), 0, $refusal);
        }
        // Published before the save, as PostComment does: publish refuses when no transaction is open.
        $this->conversationEvents->publish(new CommentResolved(
            $organizationId->toString(),
            $ticketId->toString(),
            $message->id()->toString(),
            $resolverId->toString(),
            $resolvedAt,
        ));
        $this->messages->save($message);
    }
}
