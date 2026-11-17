<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\Area;
use App\Review\Domain\AreaId;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\RoundId;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AreaTest extends TestCase
{
    private const string AREA = '0197a3c0-0000-7000-8000-000000007a31';
    private const string ROUND = '0197a3c0-0000-7000-8000-000000007a12';
    private const string BEFORE = '0197a3c0-0000-7000-8000-000000007a21';
    private const string AFTER = '0197a3c0-0000-7000-8000-000000007a22';
    private const string LENA = '0197a3c0-0000-7000-8000-000000007a04';
    private const string MIA = '0197a3c0-0000-7000-8000-000000007a05';
    private const string EARLIER = '2027-03-15 09:00:00+00:00';
    private const string NOW = '2027-03-16 10:30:00+00:00';
    private const string NAME_REFUSED = 'An area name holds 1 to 80 characters.';
    private const string DECIDED_REFUSED = 'An area has who decided and when exactly when it has a verdict.';

    /**
     * @return iterable<string, array{array{name?: string, position?: int, before?: string, verdict?: AreaVerdict, note?: string, decidedBy?: string, decidedAt?: string}, string}>
     */
    public static function storedAreasTheDomainCouldNotMake(): iterable
    {
        yield 'an empty name' => [['name' => ''], self::NAME_REFUSED];
        yield 'a name of only spaces and a line break' => [['name' => "  \t\r\n"], self::NAME_REFUSED];
        yield 'a name of 81 characters' => [['name' => str_repeat('é', 81)], self::NAME_REFUSED];
        yield 'a position below 0' => [['position' => -1], 'An area position starts at 0.'];
        yield 'one picture as before and after' => [['before' => self::AFTER], 'An area compares two different pictures.'];
        yield 'one picture as before and after in another case' => [['before' => strtoupper(self::AFTER)], 'An area compares two different pictures.'];
        yield 'a verdict with nobody who decided' => [['verdict' => AreaVerdict::Pass, 'decidedAt' => self::EARLIER], self::DECIDED_REFUSED];
        yield 'a verdict with no time' => [['verdict' => AreaVerdict::Pass, 'decidedBy' => self::LENA], self::DECIDED_REFUSED];
        yield 'who decided and when with no verdict' => [['decidedBy' => self::LENA, 'decidedAt' => self::EARLIER], self::DECIDED_REFUSED];
        yield 'a time with no verdict' => [['decidedAt' => self::EARLIER], self::DECIDED_REFUSED];
        yield 'a note of 1001 characters' => [['verdict' => AreaVerdict::Pass, 'decidedBy' => self::LENA, 'decidedAt' => self::EARLIER, 'note' => str_repeat('é', 1001)], 'A note holds at most 1,000 characters.'];
    }

    /**
     * @param array{name?: string, position?: int, before?: string, verdict?: AreaVerdict, note?: string, decidedBy?: string, decidedAt?: string} $change
     */
    #[Test]
    #[DataProvider('storedAreasTheDomainCouldNotMake')]
    public function aStoredAreaTheDomainCouldNotMakeIsRefused(array $change, string $reason): void
    {
        try {
            self::restored(...$change);
            self::fail('The stored area was not refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }
    }

    #[Test]
    public function aNameOfExactly80CharactersIsKept(): void
    {
        self::assertSame(str_repeat('é', Area::NAME_MAX_LENGTH), self::restored(name: str_repeat('é', 80))->name());
    }

    #[Test]
    public function theNameIsTrimmedBeforeItIsCounted(): void
    {
        $paddedName = " \t".str_repeat('é', 80)."\r\n";

        self::assertSame(str_repeat('é', 80), self::restored(name: $paddedName)->name());
        self::assertSame('Per product', self::restored(name: "  Per product\n")->name());
    }

    #[Test]
    public function anAreaWithNoBeforePictureIsKeptAtPosition0(): void
    {
        $area = self::restored(before: null, position: 0);

        self::assertSame(
            [self::AREA, self::ROUND, 0, null, self::AFTER, null, null, null, null],
            [$area->id()->toString(), $area->roundId()->toString(), $area->position(), $area->beforeScreenshotId(), $area->afterScreenshotId()->toString(), $area->verdict(), $area->note(), $area->decidedBy(), $area->decidedAt()],
        );
    }

    #[Test]
    public function aDecidedAreaKeepsItsVerdictWhoAndWhenAndATrimmedNote(): void
    {
        $area = self::restored(verdict: AreaVerdict::Question, decidedBy: self::LENA, decidedAt: self::EARLIER, note: "  Is this the new layout?\n");

        self::assertSame(
            [AreaVerdict::Question, 'Is this the new layout?', self::LENA, self::EARLIER],
            [$area->verdict(), $area->note(), $area->decidedBy()?->toString(), $area->decidedAt()?->format('Y-m-d H:i:sP')],
        );
    }

    #[Test]
    public function aBlankStoredNoteIsNone(): void
    {
        self::assertNull(self::restored(verdict: AreaVerdict::Pass, decidedBy: self::LENA, decidedAt: self::EARLIER, note: " \r\n")->note());
    }

    #[Test]
    public function decidingReplacesTheEarlierVerdictAndLeavesTheAreaAsItWas(): void
    {
        $earlier = self::restored(verdict: AreaVerdict::Pass, decidedBy: self::LENA, decidedAt: self::EARLIER, note: 'Looks right');

        $decided = $earlier->decide(new UserId(self::MIA), AreaVerdict::NeedsFix, '  The total is off ', new \DateTimeImmutable(self::NOW));

        self::assertSame(
            [AreaVerdict::NeedsFix, 'The total is off', self::MIA, self::NOW, 'Per product', self::BEFORE],
            [$decided->verdict(), $decided->note(), $decided->decidedBy()?->toString(), $decided->decidedAt()?->format('Y-m-d H:i:sP'), $decided->name(), $decided->beforeScreenshotId()?->toString()],
        );
        self::assertSame([AreaVerdict::Pass, 'Looks right', self::LENA], [$earlier->verdict(), $earlier->note(), $earlier->decidedBy()?->toString()]);
    }

    /**
     * @return iterable<string, array{?AreaVerdict, string, class-string<\Throwable>, string}>
     */
    public static function decisionsTheAreaRefuses(): iterable
    {
        yield 'no verdict' => [null, 'Fine', \DomainException::class, Area::VERDICT_MISSING];
        yield 'a note of 1001 characters' => [AreaVerdict::NeedsFix, str_repeat('é', 1001), \InvalidArgumentException::class, 'A note holds at most 1,000 characters.'];
    }

    /**
     * @param class-string<\Throwable> $refusalClass
     */
    #[Test]
    #[DataProvider('decisionsTheAreaRefuses')]
    public function aRefusedDecisionLeavesTheEarlierVerdict(?AreaVerdict $verdict, string $note, string $refusalClass, string $reason): void
    {
        $earlier = self::restored(verdict: AreaVerdict::Pass, decidedBy: self::LENA, decidedAt: self::EARLIER, note: 'Looks right');

        try {
            $earlier->decide(new UserId(self::MIA), $verdict, $note, new \DateTimeImmutable(self::NOW));
            self::fail('The decision was not refused.');
        } catch (\DomainException|\InvalidArgumentException $refusal) {
            self::assertSame([$refusalClass, $reason], [$refusal::class, $refusal->getMessage()]);
        }

        self::assertSame([AreaVerdict::Pass, 'Looks right', self::LENA], [$earlier->verdict(), $earlier->note(), $earlier->decidedBy()?->toString()]);
    }

    #[Test]
    public function aNoteOfExactly1000CharactersIsKept(): void
    {
        $note = str_repeat('é', Area::NOTE_MAX_LENGTH);

        self::assertSame($note, self::restored()->decide(new UserId(self::MIA), AreaVerdict::Pass, $note, new \DateTimeImmutable(self::NOW))->note());
    }

    /**
     * An undecided area "Per product" at position 0 comparing BEFORE with AFTER, but for the arguments given.
     */
    private static function restored(
        string $name = 'Per product',
        int $position = 0,
        ?string $before = self::BEFORE,
        ?AreaVerdict $verdict = null,
        ?string $note = null,
        ?string $decidedBy = null,
        ?string $decidedAt = null,
    ): Area {
        return Area::restored(
            new AreaId(self::AREA),
            new RoundId(self::ROUND),
            $name,
            $position,
            null === $before ? null : new ScreenshotId($before),
            new ScreenshotId(self::AFTER),
            $verdict,
            $note,
            null === $decidedBy ? null : new UserId($decidedBy),
            null === $decidedAt ? null : new \DateTimeImmutable($decidedAt),
        );
    }
}
