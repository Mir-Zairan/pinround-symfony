<?php

declare(strict_types=1);

namespace App\Conversation\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One reply to a thread message as the ticket page shows it: a plain reply or the answer that closed a question.
 */
final readonly class ReplyView
{
    /**
     * @param ?string $body null when the reply is removed: a removed reply's text never leaves the database
     */
    public function __construct(
        public string $id,
        public UserId $authorId,
        public ?string $body,
        public \DateTimeImmutable $createdAt,
        public bool $isAnswer,
        public bool $isRemoved,
    ) {
    }
}
