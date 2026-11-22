<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\AreaVerdict;
use App\Review\Domain\GeneralNote;
use App\Review\Domain\GeneralNoteId;
use App\Review\Domain\RoundId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules of a general note on a shared round (ADR-0048, note of 2026-11-22): the note trimmed, 1 to 2,000
 * characters, text PostgreSQL can keep; the verdict none or one of the area verdicts.
 */
final class GeneralNoteTest extends TestCase
{
    private const string NOTE_ID = '0197a3c0-0000-7000-8000-00000000a501';
    private const string ROUND = '0197a3c0-0000-7000-8000-00000000a502';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a503';
    private const string AT = '2026-11-06 12:00:00+00:00';

    /**
     * @return iterable<string, array{string, string}> what is typed, what is kept
     */
    public static function notesKeptTrimmed(): iterable
    {
        yield 'spaces and a CRLF around it' => ["  The footer is gone\r\n", 'The footer is gone'];
        yield 'tabs and a vertical tab' => ["\t\x0BLogo is blurry\t", 'Logo is blurry'];
        yield 'line breaks inside are kept' => ["Line one\r\nline two\u{2028}three", "Line one\r\nline two\u{2028}three"];
        yield 'multibyte text, no final newline' => ['Prix affiché en € 価格', 'Prix affiché en € 価格'];
        yield 'a byte order mark is text, not space' => ["\u{FEFF}Hello", "\u{FEFF}Hello"];
    }

    #[Test]
    #[DataProvider('notesKeptTrimmed')]
    public function theNoteIsKeptTrimmed(string $typed, string $kept): void
    {
        self::assertSame($kept, self::write(null, $typed)->note);
    }

    #[Test]
    public function aNoteOfExactlyTheCapIsKeptAndOneMoreCharacterIsRefused(): void
    {
        self::assertSame(GeneralNote::NOTE_MAX_LENGTH, mb_strlen(self::write(null, ' '.str_repeat('é', GeneralNote::NOTE_MAX_LENGTH).' ')->note));

        $this->expectExceptionObject(new \DomainException('A note holds at most 2,000 characters.'));
        self::write(null, str_repeat('é', GeneralNote::NOTE_MAX_LENGTH + 1));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNotes(): iterable
    {
        yield 'empty' => ['', GeneralNote::NOTE_MISSING];
        yield 'only spaces and line breaks' => [" \r\n\t ", GeneralNote::NOTE_MISSING];
        yield 'a NUL byte' => ["Total\0wrong", GeneralNote::NOTE_NOT_TEXT];
        yield 'invalid UTF-8' => ["Total \xC3\x28 wrong", GeneralNote::NOTE_NOT_TEXT];
    }

    #[Test]
    #[DataProvider('refusedNotes')]
    public function aNoteWithNothingToKeepIsRefused(string $note, string $reason): void
    {
        $this->expectExceptionObject(new \DomainException($reason));
        self::write(null, $note);
    }

    /**
     * @return iterable<string, array{string|null, AreaVerdict|null}>
     */
    public static function verdicts(): iterable
    {
        yield 'none' => [null, null];
        yield 'none, as an empty radio posts it' => ['', null];
        yield 'pass' => ['pass', AreaVerdict::Pass];
        yield 'needs fix' => ['needs_fix', AreaVerdict::NeedsFix];
        yield 'question' => ['question', AreaVerdict::Question];
    }

    #[Test]
    #[DataProvider('verdicts')]
    public function theVerdictIsNoneOrAnAreaVerdict(?string $verdict, ?AreaVerdict $kept): void
    {
        self::assertSame($kept, self::write($verdict, 'A note')->verdict);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownVerdicts(): iterable
    {
        yield 'a pin verdict' => ['fixed'];
        yield 'another case' => ['Pass'];
        yield 'a trailing newline' => ["pass\n"];
    }

    #[Test]
    #[DataProvider('unknownVerdicts')]
    public function anUnknownVerdictIsRefused(string $verdict): void
    {
        $this->expectExceptionObject(new \DomainException(GeneralNote::UNKNOWN_VERDICT));
        self::write($verdict, 'A note');
    }

    #[Test]
    public function itKeepsWhoWroteItOnWhichRoundAndWhen(): void
    {
        $note = self::write('pass', 'Fine');

        self::assertSame(
            [self::NOTE_ID, self::ROUND, self::MIA, self::AT],
            [$note->id->toString(), $note->roundId->toString(), $note->authorId->toString(), $note->createdAt->format('Y-m-d H:i:sP')],
        );
    }

    private static function write(?string $verdict, string $note): GeneralNote
    {
        return GeneralNote::write(new GeneralNoteId(self::NOTE_ID), new RoundId(self::ROUND), new UserId(self::MIA), $verdict, $note, new \DateTimeImmutable(self::AT));
    }
}
