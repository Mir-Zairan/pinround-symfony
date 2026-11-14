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
     * @param string|null          $body              null for a removed message, whose text is never shown
     * @param string               $postedLine        how long ago it was posted, as RelativeTime::sinceOf words it ("now",
     *                                                "3 h ago", "2 Nov")
     * @param string               $postedAt          when it was posted, ISO 8601 in UTC, for the time element's datetime
     * @param bool                 $isEditable        true when it is a comment the viewer wrote, it is not removed and its
     *                                                edit window is still open
     * @param bool                 $isRemovable       true when it is a comment the viewer wrote and it is not removed:
     *                                                removal has no time limit (FR-CNV-06)
     * @param bool                 $isAnswerable      true when it is an open question that asks the viewer
     * @param string|null          $askedName         the display name of the person a question asks; null for a comment
     * @param string|null          $askedFirstName    the first name of the person a question asks; null for a comment
     * @param bool                 $isOpenQuestion    true for a question neither answered nor withdrawn: the ticket waits on
     *                                                it
     * @param bool                 $isAnswered        true for a question its answer closed, also when the answer was
     *                                                removed
     * @param list<ThreadReply>    $replies           oldest first, removed ones and a question's answer included
     * @param bool                 $hasEarlierReplies true when replies older than the listed ones are left out
     * @param bool                 $isReplyable       true when it is not removed and the viewer is not the one to answer
     *                                                it: the asked person gets Answer instead, one button per message
     * @param string               $replyNote         the note under the reply box
     * @param bool                 $isResolvable      true for a comment neither removed nor resolved that
     *                                                ConversationPermission RESOLVE grants the viewer
     * @param string|null          $resolvedSummary   the one line a resolved comment folds into, without its check mark
     *                                                and "show"; null for a message not resolved
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
        public bool $isAnswerable,
        public ?string $askedName,
        public ?string $askedFirstName,
        public bool $isOpenQuestion,
        public bool $isAnswered,
        public array $replies,
        public bool $hasEarlierReplies,
        public bool $isReplyable,
        public string $replyNote,
        public bool $isResolvable,
        public ?string $resolvedSummary,
    ) {
    }
}
