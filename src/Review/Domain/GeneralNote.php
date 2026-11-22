<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A general note on a shared round (ADR-0048, note of 2026-11-22): what someone who may see the round wants to say of
 * the whole round, something its screenshots do not show or a change for all of it, with an optional verdict. A note
 * is never changed once written; a round holds at most MAX_PER_ROUND of them.
 */
final readonly class GeneralNote
{
    /** In characters, after trimming; rvw_round_note_note_check repeats it. */
    public const int NOTE_MAX_LENGTH = 2000;
    /** The notes one round holds, so its page and get_round_notes read a bounded list. */
    public const int MAX_PER_ROUND = 200;

    public const string NOTE_MISSING = 'Write the note first.';
    public const string NOTE_TOO_LONG = 'A note holds at most 2,000 characters.';
    public const string NOTE_NOT_TEXT = 'The note holds characters it cannot keep. Write it again.';
    public const string UNKNOWN_VERDICT = 'Pick Pass, Needs fix or Question, or none.';
    public const string ROUND_FULL = 'This round holds 200 general notes, the most it can hold.';

    private function __construct(
        public GeneralNoteId $id,
        public RoundId $roundId,
        public UserId $authorId,
        public ?AreaVerdict $verdict,
        public string $note,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param string|null $verdict AreaVerdict's value; null or '' for none
     * @param string      $note    trimmed before it is checked
     *
     * @throws \DomainException NOTE_MISSING, NOTE_TOO_LONG, NOTE_NOT_TEXT or UNKNOWN_VERDICT; nothing is made
     */
    public static function write(GeneralNoteId $id, RoundId $roundId, UserId $authorId, ?string $verdict, string $note, \DateTimeImmutable $createdAt): self
    {
        if (!mb_check_encoding($note, 'UTF-8') || str_contains($note, "\0")) {
            throw new \DomainException(self::NOTE_NOT_TEXT);
        }
        // The characters btrim() in rvw_round_note_note_check takes off; not NUL, which is refused above.
        $trimmed = trim($note, " \t\n\r\x0B");
        if ('' === $trimmed) {
            throw new \DomainException(self::NOTE_MISSING);
        }
        if (mb_strlen($trimmed) > self::NOTE_MAX_LENGTH) {
            throw new \DomainException(self::NOTE_TOO_LONG);
        }
        $chosen = null;
        if (null !== $verdict && '' !== $verdict) {
            $chosen = AreaVerdict::tryFrom($verdict) ?? throw new \DomainException(self::UNKNOWN_VERDICT);
        }

        return new self($id, $roundId, $authorId, $chosen, $trimmed, $createdAt);
    }
}
