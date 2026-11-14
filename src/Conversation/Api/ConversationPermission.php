<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The voter attributes for a ticket's thread (ADR-0013), decided by Conversation's UI\Voter\ConversationVoter.
 */
final class ConversationPermission
{
    /**
     * Write in a ticket's thread: post a comment, and edit or remove one's own message there; the subject is the
     * ticket's TicketVisibilityFacts. Whoever may see the ticket, an admin with no team role included. That only the
     * author edits (within 15 minutes) or removes a message is the use case's rule, checked on the locked row.
     */
    public const string COMMENT = 'CONVERSATION_COMMENT';
}
