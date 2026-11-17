<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application;

use App\Assist\Application\AiDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiDraftTest extends TestCase
{
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c7f41';

    #[Test]
    public function aDraftAtEveryLimitIsKeptWhole(): void
    {
        // Multibyte characters, so a limit counted in bytes would refuse these.
        $title = str_repeat('é', AiDraft::MAX_TITLE_CHARACTERS);
        $detail = str_repeat('ü', AiDraft::MAX_DETAIL_CHARACTERS);
        $pinNotes = array_fill(0, AiDraft::MAX_PIN_NOTES, str_repeat('ß', AiDraft::MAX_PIN_NOTE_CHARACTERS));

        $draft = new AiDraft(self::SHOP_PROJECT, $title, $detail, $pinNotes);

        self::assertSame([self::SHOP_PROJECT, $title, $detail, $pinNotes], [$draft->projectId, $draft->title, $draft->detail, $draft->pinNotes]);
    }

    /**
     * @return iterable<string, array{\Closure(): AiDraft, string}>
     */
    public static function draftsPastALimit(): iterable
    {
        yield 'a title one character too long' => [
            static fn (): AiDraft => new AiDraft(self::SHOP_PROJECT, str_repeat('é', AiDraft::MAX_TITLE_CHARACTERS + 1), ''),
            'A draft report\'s title has at most 200 characters.',
        ];
        yield 'a detail one character too long' => [
            static fn (): AiDraft => new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', str_repeat('ü', AiDraft::MAX_DETAIL_CHARACTERS + 1)),
            'A draft report\'s detail has at most 50000 characters.',
        ];
        yield 'one pin note too many' => [
            static fn (): AiDraft => new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', '', array_fill(0, AiDraft::MAX_PIN_NOTES + 1, 'The button.')),
            'A draft report has at most 100 pin notes.',
        ];
        yield 'the last pin note one character too long' => [
            static fn (): AiDraft => new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', '', ['The button.', str_repeat('ß', AiDraft::MAX_PIN_NOTE_CHARACTERS + 1)]),
            'A draft report\'s pin note has at most 1000 characters.',
        ];
        yield 'a project id in capitals' => [
            static fn (): AiDraft => new AiDraft(strtoupper(self::SHOP_PROJECT), 'Pay does nothing', ''),
            'A draft report\'s project id must be a canonical lowercase uuid.',
        ];
        yield 'a project id with a trailing newline' => [
            static fn (): AiDraft => new AiDraft(self::SHOP_PROJECT."\n", 'Pay does nothing', ''),
            'A draft report\'s project id must be a canonical lowercase uuid.',
        ];
    }

    /**
     * @param \Closure(): AiDraft $draft
     */
    #[Test]
    #[DataProvider('draftsPastALimit')]
    public function aDraftPastALimitIsRefused(\Closure $draft, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $draft();
    }

    #[Test]
    public function thePromptTextIsTheDetailThenEachNoteUnderItsPinNumberWithoutBlankOnes(): void
    {
        $draft = new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', "Pressing Pay does nothing.\nTwice.", ['The button.', '  ', 'Still on this page.']);

        self::assertSame("Pressing Pay does nothing.\nTwice.\nPin 1: The button.\nPin 3: Still on this page.", $draft->promptText());
    }

    #[Test]
    public function aDraftWithOnlyATitleHasNoPromptText(): void
    {
        self::assertNull(new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', " \n", ['', ' '])->promptText());
    }
}
