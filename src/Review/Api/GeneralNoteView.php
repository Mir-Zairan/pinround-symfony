<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One general note on a round, as GeneralNoteQueries reads it.
 */
final readonly class GeneralNoteView
{
    /**
     * @param string|null $verdict GeneralNoteCommands::VERDICT_PASS, VERDICT_NEEDS_FIX or VERDICT_QUESTION; null for none
     * @param string      $note    trimmed, never empty; text a person wrote, untrusted
     */
    public function __construct(
        public string $id,
        public UserId $authorId,
        public ?string $verdict,
        public string $note,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
