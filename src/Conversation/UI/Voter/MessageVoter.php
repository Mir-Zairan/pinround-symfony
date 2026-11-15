<?php

declare(strict_types=1);

namespace App\Conversation\UI\Voter;

use App\Conversation\Api\ConversationPermission;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketVisibilities;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides ConversationPermission RESOLVE on one message (personas-and-roles.md, "Resolve a comment"): the token's user
 * must see the ticket by the ticket visibility predicate, as for COMMENT, and be the message's author or the lead of
 * the ticket's team; an admin or manager resolves only their own. Denied with nobody logged in, with no tenant (the
 * request's user is no member), and for a ticket of another organization. ConversationVoter decides the ticket-level
 * attributes; a voter supports one subject class, so the message-level one is here.
 *
 * Reads the viewer's roles under row-level security, so it needs the request's open transaction under the tenant.
 *
 * @extends DenyByDefaultVoter<MessageOnTicket>
 */
final class MessageVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly TenantContext $tenantContext,
        private readonly TicketVisibilities $ticketVisibilities,
    ) {
    }

    protected function attributes(): array
    {
        return [ConversationPermission::RESOLVE];
    }

    protected function subjectClass(): string
    {
        return MessageOnTicket::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $userId = $this->currentUser->idOf($token->getUser());
        $organizationId = $this->tenantContext->get();
        if (null === $userId || null === $organizationId || !$subject->ticket->organizationId->equals($organizationId)) {
            return false;
        }
        // Thread::resolvableCommentsAmong picks its candidates by this same author-or-lead rule; change both together.
        if (!$userId->equals($subject->authorId) && !(null !== $subject->teamLeadId && $userId->equals($subject->teamLeadId))) {
            return false;
        }

        return $this->ticketVisibilities->of($organizationId, $userId)->isVisible($subject->ticket);
    }
}
