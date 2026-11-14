<?php

declare(strict_types=1);

namespace App\Conversation\UI;

/**
 * One reply under a thread message as the Thread component shows it, worded for the viewer: a plain reply or the
 * answer that closed a question. Replies are one level only, so a reply has no actions.
 */
final readonly class ThreadReply
{
    /**
     * @param ?string $body       null when the reply is removed, whose text is never shown
     * @param string  $postedLine how long ago it was posted, as RelativeTime::sinceOf words it
     * @param string  $postedAt   when it was posted, ISO 8601 in UTC, for the time element's datetime
     * @param bool    $isAnswer   true for the answer that closed a question
     */
    public function __construct(
        public string $id,
        public string $authorName,
        public ?string $body,
        public string $postedLine,
        public string $postedAt,
        public bool $isAnswer,
        public bool $isRemoved,
    ) {
    }
}
