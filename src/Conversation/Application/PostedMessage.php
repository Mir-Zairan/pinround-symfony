<?php

declare(strict_types=1);

namespace App\Conversation\Application;

/**
 * What posting in a thread made: the new comment, question or reply, and how many of the author's pings on the ticket
 * it cleared (FR-PNG-05), so the toast can say so.
 */
final readonly class PostedMessage
{
    /**
     * @param string $id               the new message's id, or the new reply's for a reply or an answer
     * @param int    $clearedPingCount what PingClearing::clearForActor returned for the post; 0 or more
     */
    public function __construct(
        public string $id,
        public int $clearedPingCount,
    ) {
    }
}
