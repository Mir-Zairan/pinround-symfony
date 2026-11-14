<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The newest messages of a ticket's thread, oldest first so the newest shows last.
 */
final readonly class ThreadView
{
    public const int MESSAGE_LIMIT = 200;

    /**
     * @param list<MessageView> $messages           at most MESSAGE_LIMIT, by created at then id
     * @param bool              $hasEarlierMessages true when older messages were left out to keep MESSAGE_LIMIT
     */
    public function __construct(
        public array $messages,
        public bool $hasEarlierMessages,
    ) {
    }
}
