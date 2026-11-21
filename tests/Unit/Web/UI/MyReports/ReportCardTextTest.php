<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Api\ReportGroup;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\PinProgress;
use App\Web\UI\MyReports\ReportCardText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The page's time is 6 Nov 2026 12:00 UTC, a fixed day that is not today.
 */
final class ReportCardTextTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f0a53';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f0a52';

    /**
     * @return iterable<string, array{string, ReportGroup, string}>
     */
    public static function sideTexts(): iterable
    {
        yield 'stuck for 17 whole days' => ['2026-10-20 12:00:00 UTC', ReportGroup::Stuck, 'no change for 17 days'];
        yield 'stuck a second short of 17 days rounds down' => ['2026-10-20 12:00:01 UTC', ReportGroup::Stuck, 'no change for 16 days'];
        yield 'changed exactly two days ago' => ['2026-11-04 12:00:00 UTC', ReportGroup::Moving, 'changed recently'];
        yield 'changed just now' => ['2026-11-06 12:00:00 UTC', ReportGroup::NeedsYou, 'changed recently'];
        yield 'changed two days and a second ago' => ['2026-11-04 11:59:59 UTC', ReportGroup::Moving, 'updated 4 Nov'];
        yield 'done long ago in the same year' => ['2026-03-05 09:00:00 UTC', ReportGroup::Done, 'updated 5 Mar'];
        yield 'changed in another year' => ['2025-12-30 09:00:00 UTC', ReportGroup::Moving, 'updated 30 Dec 2025'];
        yield 'the date is the page time zone\'s, not the stored one' => ['2026-11-01 02:00:00 +05:30', ReportGroup::Moving, 'updated 31 Oct'];
    }

    #[Test]
    #[DataProvider('sideTexts')]
    public function theSideTextSaysHowLongAgoTheReportChanged(string $updatedAt, ReportGroup $group, string $expected): void
    {
        $report = self::report(updatedAt: $updatedAt);

        self::assertSame($expected, ReportCardText::sideTextOf($report, $group, new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function turnKinds(): iterable
    {
        yield 'build' => ['build', 'Omar builds it'];
        yield 'test' => ['test', 'Omar tests it'];
        yield 'check' => ['check', 'Omar checks the new ticket'];
        yield 'review' => ['review', 'Omar reviews the code'];
        yield 'assign' => ['assign', 'Omar picks a developer'];
        yield 'input' => ['input', 'Omar gives input'];
        yield 'decide' => ['decide', 'Omar decides: go on or stop'];
        yield 'answer, of a question to someone else' => ['answer', 'Omar answers a question'];
    }

    #[Test]
    #[DataProvider('turnKinds')]
    public function theNowLineNamesTheTurnPersonAndTheirTask(string $kind, string $expected): void
    {
        $report = self::report(turnUserId: self::OMAR, turnKind: $kind);

        self::assertSame($expected, ReportCardText::nowTextOf($report, ReportGroup::Moving, new UserId(self::MIA), 'Omar'));
    }

    #[Test]
    public function onTheViewersOwnTurnTheNowLineSaysYou(): void
    {
        $report = self::report(turnUserId: self::MIA, turnKind: 'test');

        self::assertSame('You test it', ReportCardText::nowTextOf($report, ReportGroup::Stuck, new UserId(self::MIA), 'Mia'));
    }

    #[Test]
    public function aTurnPersonWithNoNameHereIsSomeone(): void
    {
        $report = self::report(turnUserId: self::OMAR, turnKind: 'build');

        self::assertSame('Someone builds it', ReportCardText::nowTextOf($report, ReportGroup::Moving, new UserId(self::MIA), null));
    }

    #[Test]
    public function aLabelsTurnWaitsOnTheLabel(): void
    {
        $report = self::report(turnLabel: 'Customer', turnKind: 'input');

        self::assertSame('Waiting on Customer', ReportCardText::nowTextOf($report, ReportGroup::Moving, new UserId(self::MIA), null));
    }

    #[Test]
    public function withNoTurnNoOneNeedsToAct(): void
    {
        self::assertSame('No one needs to act now', ReportCardText::nowTextOf(self::report(), ReportGroup::Moving, new UserId(self::MIA), null));
        self::assertSame('No one needs to act now', ReportCardText::nowTextOf(self::report(turnUserId: self::OMAR), ReportGroup::Moving, new UserId(self::MIA), 'Omar'));
    }

    #[Test]
    public function aDoneReportSaysDoneWhateverItsTurnRow(): void
    {
        $report = self::report(turnUserId: self::OMAR, turnKind: 'build');

        self::assertSame('Done', ReportCardText::nowTextOf($report, ReportGroup::Done, new UserId(self::MIA), 'Omar'));
    }

    #[Test]
    public function aQuestionToTheViewerSaysItWaitsForTheirAnswer(): void
    {
        $report = self::report(turnUserId: self::MIA, turnKind: 'answer', isQuestionToReporter: true);

        self::assertSame('A question waits for your answer', ReportCardText::nowTextOf($report, ReportGroup::NeedsYou, new UserId(self::MIA), 'Mia'));
        self::assertSame('A question waits for your answer', ReportCardText::nowTextOf($report, ReportGroup::NeedsYou, new UserId(self::MIA), 'Mia', null));
    }

    #[Test]
    public function aKnownQuestionToTheViewerIsQuotedOnTheNowLine(): void
    {
        $report = self::report(turnUserId: self::MIA, turnKind: 'answer', isQuestionToReporter: true);

        self::assertSame('Lena asks: “Which order?”', ReportCardText::nowTextOf($report, ReportGroup::NeedsYou, new UserId(self::MIA), 'Mia', 'Lena asks: “Which order?”'));
    }

    #[Test]
    public function aQuestionLineIsQuotedOnlyForAReportThatNeedsTheViewer(): void
    {
        $building = self::report(turnUserId: self::OMAR, turnKind: 'build');

        self::assertSame('Omar builds it', ReportCardText::nowTextOf($building, ReportGroup::Moving, new UserId(self::MIA), 'Omar', 'Lena asks: “Which order?”'));
        self::assertSame('Done', ReportCardText::nowTextOf($building, ReportGroup::Done, new UserId(self::MIA), 'Omar', 'Lena asks: “Which order?”'));
    }

    /**
     * @return iterable<string, array{string|null, string, string|null}>
     */
    public static function questionLines(): iterable
    {
        yield 'a short question' => ['Lena', 'Which order was it?', 'Lena asks: “Which order was it?”'];
        yield 'an asker with no name here' => [null, 'Which order was it?', 'Someone asks: “Which order was it?”'];
        yield 'CRLF, LF and CR line breaks join with one space' => ['Lena', "Which order?\r\nThe one from May.\nOr June?\rThanks", 'Lena asks: “Which order? The one from May. Or June? Thanks”'];
        yield 'U+2028, U+2029, tabs and no-break spaces join with one space' => ['Lena', "Which\u{2028}order\u{2029}was\tit\u{00A0}?", 'Lena asks: “Which order was it ?”'];
        yield 'a BOM, escape codes and outer blanks go' => ['Lena', "\u{FEFF}  Which \e[31morder\e[0m?\n\n", 'Lena asks: “Which [31morder [0m?”'];
        yield 'multibyte text keeps its characters' => ['Lena', 'Für welche Bestellung? 注文はどれ？', 'Lena asks: “Für welche Bestellung? 注文はどれ？”'];
        yield 'exactly the most characters is not cut' => ['Lena', str_repeat('é', ReportCardText::QUESTION_MAX_CHARACTERS), 'Lena asks: “'.str_repeat('é', ReportCardText::QUESTION_MAX_CHARACTERS).'”'];
        yield 'one character more is cut with an ellipsis' => ['Lena', str_repeat('é', ReportCardText::QUESTION_MAX_CHARACTERS + 1), 'Lena asks: “'.str_repeat('é', ReportCardText::QUESTION_MAX_CHARACTERS).'…”'];
        yield 'a cut after a space drops the space' => ['Lena', str_repeat('a', ReportCardText::QUESTION_MAX_CHARACTERS - 1).' and more', 'Lena asks: “'.str_repeat('a', ReportCardText::QUESTION_MAX_CHARACTERS - 1).'…”'];
        yield 'only blanks and line breaks have no line' => ['Lena', " \r\n\t\u{2028} ", null];
    }

    #[Test]
    #[DataProvider('questionLines')]
    public function aQuestionIsQuotedOnOneLineWithItsAsker(?string $askerFirstName, string $question, ?string $expected): void
    {
        self::assertSame($expected, ReportCardText::questionLineOf($askerFirstName, $question));
    }

    /**
     * @return iterable<string, array{int<0, max>, int<1, max>, string}>
     */
    public static function pinProgressTexts(): iterable
    {
        yield 'some of two' => [1, 2, '1 of 2 pins OK'];
        yield 'none of three' => [0, 3, '0 of 3 pins OK'];
        yield 'all of two' => [2, 2, '2 of 2 pins OK'];
        yield 'none of one' => [0, 1, '0 of 1 pin OK'];
        yield 'all of one' => [1, 1, '1 of 1 pin OK'];
    }

    /**
     * @param int<0, max> $okCount
     * @param int<1, max> $pinCount
     */
    #[Test]
    #[DataProvider('pinProgressTexts')]
    public function thePinProgressCountsThePinsCheckedOk(int $okCount, int $pinCount, string $expected): void
    {
        self::assertSame($expected, ReportCardText::pinProgressTextOf(new PinProgress($okCount, $pinCount)));
    }

    #[Test]
    public function aTurnKindThatIsNoNeedsYouKindIsRefused(): void
    {
        $report = self::report(turnUserId: self::OMAR, turnKind: 'nap');

        $this->expectException(\ValueError::class);

        ReportCardText::nowTextOf($report, ReportGroup::Moving, new UserId(self::MIA), 'Omar');
    }

    private static function report(
        string $updatedAt = '2026-11-05 09:00:00 UTC',
        ?string $turnUserId = null,
        ?string $turnLabel = null,
        ?string $turnKind = null,
        bool $isQuestionToReporter = false,
    ): MyReport {
        return new MyReport(
            new TicketId('0197a3c0-0000-7000-8000-0000000f0b01'),
            'SHOP-1',
            'Cart total is wrong',
            '0197a3c0-0000-7000-8000-0000000f0c01',
            new \DateTimeImmutable('2026-09-01 09:00:00 UTC'),
            new \DateTimeImmutable($updatedAt),
            null,
            null === $turnKind ? null : 'doing',
            null === $turnUserId ? null : new UserId($turnUserId),
            $turnLabel,
            $turnKind,
            $isQuestionToReporter,
        );
    }
}
