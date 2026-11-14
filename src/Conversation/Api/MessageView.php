<?php

declare(strict_types=1);

namespace App\Conversation\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One message of a thread as the ticket page shows it.
 */
final readonly class MessageView
{
    public const string KIND_COMMENT = 'comment';
    public const string KIND_QUESTION = 'question';

    /**
     * @param self::KIND_*       $kind
     * @param ?string            $body           null when the message is removed: a removed message's text never leaves the database
     * @param \DateTimeImmutable $editableUntil  the last instant its author may edit it (inclusive); removing has no time
     *                                           limit (FR-CNV-06)
     * @param ?UserId            $toUserId       the person a question asks; null for a comment
     * @param bool               $isOpenQuestion true for a question neither answered nor withdrawn (removed): the ticket
     *                                           waits on it
     * @param bool               $isAnswered     true for a question its answer closed, also when the answer was removed
     * @param ?ReplyView         $answer         the answer of an answered question, null when it is removed or the
     *                                           message is no answered question
     */
    public function __construct(
        public string $id,
        public UserId $authorId,
        public string $kind,
        public ?string $body,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $editedAt,
        public bool $isRemoved,
        public \DateTimeImmutable $editableUntil,
        public ?UserId $toUserId,
        public bool $isOpenQuestion,
        public bool $isAnswered,
        public ?ReplyView $answer,
    ) {
    }
}
