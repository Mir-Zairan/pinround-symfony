<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The voter attributes for a ticket's thread (ADR-0013), decided by Conversation's UI\Voter\ConversationVoter.
 */
final class ConversationPermission
{
    /**
     * Write in a ticket's thread: post a comment, edit or remove one's own message there, and answer a question; the
     * subject is the ticket's TicketVisibilityFacts. Whoever may see the ticket, an admin with no team role included.
     * That only the author edits (within 15 minutes) or removes a message, and that only the person asked answers a
     * question, are the use cases' rules (Answer's for the answer), checked on the locked message row.
     */
    public const string COMMENT = 'CONVERSATION_COMMENT';

    /**
     * Ask a question on a ticket, which pauses it; the subject is the ticket's TicketVisibilityFacts. Whoever may see
     * the ticket and is an Admin (with no team role too), a Manager, a Lead or Member of its team, or its reporter; a
     * Viewer of the team is denied, also as reporter. That the asked person is on the ticket and no question is open
     * yet is the use case's rule, checked on the locked ticket.
     */
    public const string ASK = 'CONVERSATION_ASK';

    /**
     * Resolve a comment, which folds it for everyone; the subject is Conversation's UI\Voter\MessageOnTicket, decided
     * by UI\Voter\MessageVoter. Whoever may see the ticket and wrote the comment, or leads the ticket's team. That the
     * message is a comment, not removed and not resolved yet is the use case's rule, checked on the locked row.
     */
    public const string RESOLVE = 'CONVERSATION_RESOLVE';
}
