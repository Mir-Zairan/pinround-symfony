<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketSummaryCommands;
use App\Ticketing\Domain\Summary;
use App\Ticketing\Domain\SummaryRefused;
use App\Ticketing\Domain\SummarySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SummaryTest extends TestCase
{
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f7a01';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f7a12';
    private const string TEXT = 'The checkout button is hidden on small screens.';
    private const string MODEL = 'claude-sonnet';
    private const string PROMPT_VERSION = 'summary.v1';

    /**
     * The text caps are the Api's, which Assist refuses a laptop result over: a different Domain cap would let it offer
     * one it cannot store, or refuse one it can.
     */
    #[Test]
    public function anAiSummaryKeepsItsTrimmedTextAndLabels(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-03 10:05:00 UTC');
        $text = str_repeat('é', TicketSummaryCommands::TEXT_MAX_LENGTH - 4)."\r\n\té";

        $summary = Summary::generatedByAi(new TicketId(self::SHOP_7), "\u{20}\n".$text." \n", new UserId(self::MIA), ' '.self::MODEL.' ', "\t".self::PROMPT_VERSION, $createdAt);

        self::assertSame(
            [self::SHOP_7, $text, SummarySource::Ai, self::MIA, self::MODEL, self::PROMPT_VERSION, $createdAt],
            [
                $summary->ticketId()->toString(),
                $summary->text(),
                $summary->source(),
                $summary->authorId()?->toString(),
                $summary->model(),
                $summary->promptVersion(),
                $summary->createdAt(),
            ],
        );
    }

    #[Test]
    public function aLaptopSummaryKeepsItsTrimmedTextAndLabelsItsPosterAndWhoChoseIt(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-03 10:05:00 UTC');

        $summary = Summary::chosenFromLaptop(new TicketId(self::SHOP_7), "\u{20}\n".self::TEXT." \n", new UserId(self::OMAR), ' qwen3:4b ', "\t".self::PROMPT_VERSION, new UserId(self::MIA), $createdAt);

        self::assertSame(
            [self::SHOP_7, self::TEXT, SummarySource::Laptop, self::OMAR, 'qwen3:4b', self::PROMPT_VERSION, $createdAt, self::MIA],
            [
                $summary->ticketId()->toString(),
                $summary->text(),
                $summary->source(),
                $summary->authorId()?->toString(),
                $summary->model(),
                $summary->promptVersion(),
                $summary->createdAt(),
                $summary->chosenById()?->toString(),
            ],
        );
    }

    #[Test]
    public function anAiSummaryWasChosenByNobody(): void
    {
        self::assertNull(self::summaryOf(self::TEXT, self::MODEL, self::PROMPT_VERSION)->chosenById());
    }

    #[Test]
    public function labelsAtTheirCapAreKept(): void
    {
        $model = str_repeat('m', Summary::MODEL_MAX_LENGTH);
        $promptVersion = str_repeat('v', Summary::PROMPT_VERSION_MAX_LENGTH);

        $summary = self::summaryOf(self::TEXT, $model, $promptVersion);

        self::assertSame([$model, $promptVersion], [$summary->model(), $summary->promptVersion()]);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function refusedSummaries(): iterable
    {
        yield 'empty text' => ['', self::MODEL, self::PROMPT_VERSION, 'A summary has text; this one is blank.'];
        yield 'text of spaces and a CRLF' => [" \r\n\t ", self::MODEL, self::PROMPT_VERSION, 'A summary has text; this one is blank.'];
        yield 'text over the cap' => [str_repeat('é', TicketSummaryCommands::TEXT_MAX_LENGTH + 1), self::MODEL, self::PROMPT_VERSION, 'A summary has at most 2000 characters.'];
        yield 'text with an ESC' => ["Hidden \e[31mbutton", self::MODEL, self::PROMPT_VERSION, 'A summary is plain UTF-8 text; this one holds a control character or is not UTF-8.'];
        yield 'text with a NUL' => ["Hidden\0button", self::MODEL, self::PROMPT_VERSION, 'A summary is plain UTF-8 text; this one holds a control character or is not UTF-8.'];
        yield 'text not UTF-8' => ["Hidden \xC3button", self::MODEL, self::PROMPT_VERSION, 'A summary is plain UTF-8 text; this one holds a control character or is not UTF-8.'];
        yield 'blank model' => [self::TEXT, " \t", self::PROMPT_VERSION, 'An AI summary names its model; it is blank.'];
        yield 'model over the cap' => [self::TEXT, str_repeat('m', Summary::MODEL_MAX_LENGTH + 1), self::PROMPT_VERSION, 'The model of an AI summary has at most 200 characters.'];
        yield 'model with a line break' => [self::TEXT, "claude\nsonnet", self::PROMPT_VERSION, 'The model of an AI summary is one line of UTF-8 text.'];
        yield 'blank prompt version' => [self::TEXT, self::MODEL, '', 'An AI summary names its prompt version; it is blank.'];
        yield 'prompt version over the cap' => [self::TEXT, self::MODEL, str_repeat('v', Summary::PROMPT_VERSION_MAX_LENGTH + 1), 'The prompt version of an AI summary has at most 100 characters.'];
        yield 'prompt version with a line separator' => [self::TEXT, self::MODEL, "summary\u{2028}v1", 'The prompt version of an AI summary is one line of UTF-8 text.'];
    }

    #[Test]
    #[DataProvider('refusedSummaries')]
    public function aSummaryThatCannotBeStoredIsRefused(string $text, string $model, string $promptVersion, string $expectedReason): void
    {
        $this->expectException(SummaryRefused::class);
        $this->expectExceptionMessageIsOrContains($expectedReason);
        self::summaryOf($text, $model, $promptVersion);
    }

    #[Test]
    #[DataProvider('refusedSummaries')]
    public function aLaptopSummaryThatCannotBeStoredIsRefused(string $text, string $model, string $promptVersion, string $expectedReason): void
    {
        $this->expectException(SummaryRefused::class);
        $this->expectExceptionMessageIsOrContains($expectedReason);
        Summary::chosenFromLaptop(
            new TicketId(self::SHOP_7),
            $text,
            new UserId(self::OMAR),
            $model,
            $promptVersion,
            new UserId(self::MIA),
            new \DateTimeImmutable('2027-03-03 10:05:00 UTC'),
        );
    }

    #[Test]
    public function noRefusalQuotesTheText(): void
    {
        try {
            self::summaryOf("MARKER-f7a \e secret", self::MODEL, self::PROMPT_VERSION);
            self::fail('The summary was not refused.');
        } catch (SummaryRefused $refusal) {
            self::assertStringNotContainsString('MARKER-f7a', $refusal->getMessage());
        }
    }

    private static function summaryOf(string $text, string $model, string $promptVersion): Summary
    {
        return Summary::generatedByAi(
            new TicketId(self::SHOP_7),
            $text,
            new UserId(self::MIA),
            $model,
            $promptVersion,
            new \DateTimeImmutable('2027-03-03 10:05:00 UTC'),
        );
    }
}
