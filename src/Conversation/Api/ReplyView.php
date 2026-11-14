<?php

declare(strict_types=1);

namespace App\Conversation\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One reply to a thread message as the ticket page shows it: for now only the answer that closed a question.
 */
final readonly class ReplyView
{
    public function __construct(
        public string $id,
        public UserId $authorId,
        public string $body,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
