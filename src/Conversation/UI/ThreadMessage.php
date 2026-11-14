<?php

declare(strict_types=1);

namespace App\Conversation\UI;

/**
 * One message as the Thread component shows it, worded for the viewer.
 */
final readonly class ThreadMessage
{
    /**
     * @param 'comment'|'question' $kind
     * @param string|null          $body        null for a removed message, whose text is never shown
     * @param string               $postedLine  how long ago it was posted, as RelativeTime::sinceOf words it ("now",
     *                                          "3 h ago", "2 Nov")
     * @param string               $postedAt    when it was posted, ISO 8601 in UTC, for the time element's datetime
     * @param bool                 $isEditable  true when it is a comment the viewer wrote, it is not removed and its
     *                                          edit window is still open
     * @param bool                 $isRemovable true when it is a comment the viewer wrote and it is not removed: removal
     *                                          has no time limit (FR-CNV-06)
     */
    public function __construct(
        public string $id,
        public string $authorName,
        public string $kind,
        public ?string $body,
        public string $postedLine,
        public string $postedAt,
        public bool $isEdited,
        public bool $isRemoved,
        public bool $isEditable,
        public bool $isRemovable,
    ) {
    }
}
