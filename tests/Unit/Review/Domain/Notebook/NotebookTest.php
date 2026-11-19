<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain\Notebook;

use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookRefused;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every time is a fixed one in March 2027; nothing reads the clock.
 */
final class NotebookTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-00000000a601';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a602';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a603';
    private const string CREATED = '2027-03-15 11:20:30.250000+01:00';
    private const string LATER = '2027-03-16 09:00:00+01:00';

    #[Test]
    public function aNewNotebookIsEmptyAtVersionOneWithItsTitleTrimmed(): void
    {
        $notebook = self::started("  Order page\t");

        self::assertSame(['Order page', [], [], 1], [$notebook->title(), $notebook->screenshots(), $notebook->scenarios(), $notebook->version()]);
        self::assertTrue($notebook->ownerId()->equals(new UserId(self::LENA)));
        self::assertTrue($notebook->organizationId()->equals(new OrganizationId(self::ACME_FOODS)));
        self::assertEquals([new \DateTimeImmutable(self::CREATED), new \DateTimeImmutable(self::CREATED), new \DateTimeImmutable(self::CREATED)], [$notebook->createdAt(), $notebook->updatedAt(), $notebook->lastUsedAt()]);
    }

    /**
     * 120 characters pass and 121 are refused, counted as characters: each "é" is two bytes.
     */
    #[Test]
    public function aTitleOf121CharactersIsRefusedAndOneOf120Passes(): void
    {
        self::assertSame(str_repeat('é', 120), self::started(str_repeat('é', 120))->title());
        self::assertRefused(NotebookRefused::TITLE_TOO_LONG, static fn (): Notebook => self::started(str_repeat('é', 121)));
        self::assertRefused(NotebookRefused::TITLE_BLANK, static fn (): Notebook => self::started(" \n "));

        $notebook = self::started('Order page');
        self::assertRefused(NotebookRefused::TITLE_TOO_LONG, static fn (): Notebook => $notebook->renamed(str_repeat('a', 121), new \DateTimeImmutable(self::LATER)));
        self::assertSame('Order page', $notebook->title(), 'A refused rename leaves the notebook as it was.');
        self::assertSame(str_repeat('a', 120), $notebook->renamed(str_repeat('a', 120), new \DateTimeImmutable(self::LATER))->title());
    }

    #[Test]
    public function anEleventhScreenshotIsRefusedAndTheTenthPasses(): void
    {
        $notebook = self::started('Order page');
        for ($picture = 1; $picture <= 9; ++$picture) {
            $notebook = $notebook->withScreenshotAdded(self::screenshot($picture), new \DateTimeImmutable(self::LATER));
        }

        $full = $notebook->withScreenshotAdded(self::screenshot(10), new \DateTimeImmutable(self::LATER));

        self::assertCount(10, $full->screenshots());
        self::assertSame(self::mediaId(10), $full->screenshots()[9]->mediaId(), 'The tenth comes last, after the others in order.');
        self::assertRefused('A notebook holds at most 10 screenshots.', static fn (): Notebook => $full->withScreenshotAdded(self::screenshot(11), new \DateTimeImmutable(self::LATER)));
    }

    #[Test]
    public function theSamePictureTwiceIsRefusedWhateverItsCase(): void
    {
        $notebook = self::started('Order page')->withScreenshotAdded(self::screenshot(1), new \DateTimeImmutable(self::LATER));

        self::assertRefused(
            NotebookRefused::SAME_PICTURE_TWICE,
            static fn (): Notebook => $notebook->withScreenshotAdded(NotebookScreenshot::of(strtoupper(self::mediaId(1)), null, null, []), new \DateTimeImmutable(self::LATER)),
        );
        self::assertCount(2, $notebook->withScreenshotAdded(self::screenshot(2), new \DateTimeImmutable(self::LATER))->screenshots());
    }

    #[Test]
    public function aScreenshotKeepsTheCapsOfARoundScreenshotEachWithAValidTwin(): void
    {
        $passing = NotebookScreenshot::of(strtoupper(self::mediaId(1)), ' '.str_repeat('t', 120).' ', str_repeat('s', 600), [NotebookPin::placed(0.0, 100.0, str_repeat('n', 1000))]);
        self::assertSame([self::mediaId(1), str_repeat('t', 120), str_repeat('s', 600)], [$passing->mediaId(), $passing->title(), $passing->summary()]);
        self::assertNull(NotebookScreenshot::of(self::mediaId(1), '  ', "\n", [])->title(), 'A blank title is none.');
        self::assertCount(100, NotebookScreenshot::of(self::mediaId(1), null, null, array_fill(0, 100, NotebookPin::placed(1.0, 1.0, '')))->pins());

        self::assertRefused(NotebookRefused::PICTURE_TITLE_TOO_LONG, static fn (): NotebookScreenshot => NotebookScreenshot::of(self::mediaId(1), str_repeat('t', 121), null, []));
        self::assertRefused(NotebookRefused::PICTURE_SUMMARY_TOO_LONG, static fn (): NotebookScreenshot => NotebookScreenshot::of(self::mediaId(1), null, str_repeat('s', 601), []));
        self::assertRefused(NotebookRefused::TOO_MANY_PINS, static fn (): NotebookScreenshot => NotebookScreenshot::of(self::mediaId(1), null, null, array_fill(0, 101, NotebookPin::placed(1.0, 1.0, ''))));
        self::assertRefused(NotebookRefused::PICTURE_NOT_UPLOADED, static fn (): NotebookScreenshot => NotebookScreenshot::of(self::mediaId(1)."\n", null, null, []));
    }

    #[Test]
    public function aPinSitsInsideThePictureWithANoteOfAtMost1000CharactersEachWithAValidTwin(): void
    {
        $pin = NotebookPin::placed(12.345, 99.999, '  Why is this grey?  ');
        self::assertSame([12.35, 100.0, 'Why is this grey?'], [$pin->xPercent(), $pin->yPercent(), $pin->note()]);
        self::assertSame(str_repeat('é', 1000), NotebookPin::placed(0.0, 0.0, str_repeat('é', 1000))->note());

        self::assertRefused(NotebookRefused::PIN_NOTE_TOO_LONG, static fn (): NotebookPin => NotebookPin::placed(0.0, 0.0, str_repeat('é', 1001)));
        foreach ([[100.01, 50.0], [50.0, -0.01], [\NAN, 50.0], [50.0, \INF]] as [$xPercent, $yPercent]) {
            self::assertRefused(NotebookRefused::PIN_OUTSIDE, static fn (): NotebookPin => NotebookPin::placed($xPercent, $yPercent, ''));
        }
    }

    #[Test]
    public function aTwentyFirstScenarioIsRefusedAndTwentyPass(): void
    {
        $notebook = self::started('Order page');

        self::assertCount(20, $notebook->withScenarios(array_fill(0, 20, ['title' => 'A warning shows']), new \DateTimeImmutable(self::LATER))->scenarios());
        self::assertRefused(NotebookRefused::TOO_MANY_SCENARIOS, static fn (): Notebook => $notebook->withScenarios(array_fill(0, 21, ['title' => 'A warning shows']), new \DateTimeImmutable(self::LATER)));
        self::assertSame([], $notebook->scenarios());
    }

    #[Test]
    public function aChangeMovesTheChangeAndUseTimesButKeepsTheVersionAndCreationTime(): void
    {
        $renamed = self::started('Order page')->renamed('Cart page', new \DateTimeImmutable(self::LATER));

        self::assertSame(['Cart page', 1], [$renamed->title(), $renamed->version()]);
        self::assertEquals([new \DateTimeImmutable(self::CREATED), new \DateTimeImmutable(self::LATER), new \DateTimeImmutable(self::LATER)], [$renamed->createdAt(), $renamed->updatedAt(), $renamed->lastUsedAt()]);
    }

    #[Test]
    public function aClockBehindTheCreationTimeIsHeldAtIt(): void
    {
        $earlier = new \DateTimeImmutable('2027-03-14 08:00:00+01:00');

        $renamed = self::started('Order page')->renamed('Cart page', $earlier);
        $used = self::started('Order page')->markedUsed($earlier);

        self::assertEquals([new \DateTimeImmutable(self::CREATED), new \DateTimeImmutable(self::CREATED)], [$renamed->updatedAt(), $renamed->lastUsedAt()]);
        self::assertEquals(new \DateTimeImmutable(self::CREATED), $used->lastUsedAt());
    }

    #[Test]
    public function markingANotebookUsedChangesOnlyItsLastUseTime(): void
    {
        $used = self::started('Order page')->markedUsed(new \DateTimeImmutable(self::LATER));

        self::assertSame(['Order page', 1], [$used->title(), $used->version()]);
        self::assertEquals([new \DateTimeImmutable(self::CREATED), new \DateTimeImmutable(self::LATER)], [$used->updatedAt(), $used->lastUsedAt()]);
    }

    #[Test]
    public function aStoredNotebookIsRestoredUnderTheSameRules(): void
    {
        $later = new \DateTimeImmutable(self::LATER);

        $restored = self::restored('Order page', [self::screenshot(1)], 4, $later);
        self::assertSame(['Order page', 4, 1], [$restored->title(), $restored->version(), \count($restored->screenshots())]);

        self::assertRefused(NotebookRefused::TITLE_TOO_LONG, static fn (): Notebook => self::restored(str_repeat('a', 121), [], 1, $later));
        self::assertRefused(NotebookRefused::SAME_PICTURE_TWICE, static fn (): Notebook => self::restored('Order page', [self::screenshot(1), self::screenshot(1)], 1, $later));
        $beforeCreation = new \DateTimeImmutable('2027-03-14 08:00:00+01:00');
        $invalidReasons = [];
        foreach ([[0, $later, $later], [1, $beforeCreation, $later], [1, $later, $beforeCreation]] as [$version, $updatedAt, $lastUsedAt]) {
            try {
                self::restored('Order page', [], $version, $updatedAt, $lastUsedAt);
                self::fail('A notebook at version 0, or changed or used before it was created, was restored.');
            } catch (\InvalidArgumentException $invalid) {
                $invalidReasons[] = $invalid->getMessage();
            }
        }
        self::assertSame(
            ['A notebook version starts at 1.', 'A notebook is changed and used no earlier than it was created.', 'A notebook is changed and used no earlier than it was created.'],
            $invalidReasons,
        );
    }

    #[Test]
    public function aNotebookIdIsACanonicalUuidV7KeptInLowercase(): void
    {
        self::assertSame(self::ID, new NotebookId(strtoupper(self::ID))->toString());
        self::assertTrue(new NotebookId(self::ID)->equals(new NotebookId(strtoupper(self::ID))));

        foreach (['', '0197a3c0-0000-4000-8000-00000000a601', self::ID."\n"] as $value) {
            try {
                new NotebookId($value);
                self::fail(\sprintf('NotebookId accepted %s.', var_export($value, true)));
            } catch (\InvalidArgumentException $invalid) {
                self::assertSame('A notebook id must be a canonical UUIDv7.', $invalid->getMessage());
            }
        }
    }

    private static function started(string $title): Notebook
    {
        return Notebook::start(new NotebookId(self::ID), new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $title, new \DateTimeImmutable(self::CREATED));
    }

    /**
     * @param list<NotebookScreenshot> $screenshots
     */
    private static function restored(string $title, array $screenshots, int $version, \DateTimeImmutable $updatedAt, \DateTimeImmutable $lastUsedAt = new \DateTimeImmutable(self::LATER)): Notebook
    {
        return Notebook::restored(
            new NotebookId(self::ID),
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::LENA),
            $title,
            $screenshots,
            [],
            $version,
            new \DateTimeImmutable(self::CREATED),
            $updatedAt,
            $lastUsedAt,
        );
    }

    private static function screenshot(int $picture): NotebookScreenshot
    {
        return NotebookScreenshot::of(self::mediaId($picture), 'Picture '.$picture, null, [NotebookPin::placed(10.0, 20.0, 'Look here')]);
    }

    private static function mediaId(int $picture): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-%012d', 100 + $picture);
    }

    private static function assertRefused(string $reason, \Closure $change): void
    {
        try {
            $change();
            self::fail(\sprintf('Not refused: %s', $reason));
        } catch (NotebookRefused $refused) {
            self::assertSame($reason, $refused->getMessage());
        }
    }
}
