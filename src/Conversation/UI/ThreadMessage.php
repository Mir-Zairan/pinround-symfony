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
     * @param string               $postedLine  when it was posted, in the viewer's time zone
     * @param bool                 $isEditable  true when the viewer wrote it, it is not removed and its edit window
     *                                          is still open
     * @param bool                 $isRemovable true when the viewer wrote it and it is not removed: removal has no time
     *                                          limit (FR-CNV-06)
     */
    public function __construct(
        public string $id,
        public string $authorName,
        public string $kind,
        public ?string $body,
        public string $postedLine,
        public bool $isEdited,
        public bool $isRemoved,
        public bool $isEditable,
        public bool $isRemovable,
    ) {
    }
}
