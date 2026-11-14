<?php

declare(strict_types=1);

namespace App\Conversation\UI;

/**
 * One reply under a thread message as the Thread component shows it, worded for the viewer: for now only the answer
 * that closed a question.
 */
final readonly class ThreadReply
{
    /**
     * @param ?string $body       null when the reply is removed
     * @param string  $postedLine how long ago it was posted, as RelativeTime::sinceOf words it
     * @param string  $postedAt   when it was posted, ISO 8601 in UTC, for the time element's datetime
     */
    public function __construct(
        public string $id,
        public string $authorName,
        public ?string $body,
        public string $postedLine,
        public string $postedAt,
    ) {
    }
}
