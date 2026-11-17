<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\DraftDecision;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundTest extends TestCase
{
    private const string ROUND = '0197a3c0-0000-7000-8000-000000000e01';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string NEW_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000c99';
    private const string UNKNOWN_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000c77';

    #[Test]
    public function aReportIsRoundZeroSharedAtTheMomentItWasReportedAndOpen(): void
    {
        $reportedAt = new \DateTimeImmutable('2026-03-04 09:30:00+00:00');

        $round = Round::report(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [self::screenshot(0)], $reportedAt);

        self::assertSame(RoundKind::Report, $round->kind());
        self::assertSame(0, $round->number());
        self::assertSame(Round::REPORT_NUMBER, $round->number());
        self::assertSame($reportedAt, $round->sharedAt());
        self::assertSame($reportedAt, $round->createdAt());
        self::assertSame(RoundState::Open, $round->state());
        self::assertSame(1, $round->version());
        self::assertFalse($round->isUnsharedDraft());
        self::assertSame(self::LENA, $round->ownerId()->toString());
        self::assertSame(self::ROUND, $round->id()->toString());
        self::assertSame(self::ACME_FOODS, $round->organizationId()->toString());
        self::assertSame(self::TICKET, $round->ticketId()->toString());
    }

    #[Test]
    public function aReportNeedsAtLeastOneScreenshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A report round needs at least one screenshot.');

        self::report([]);
    }

    #[Test]
    public function aReportHoldsExactlyTheMaximumNumberOfScreenshotsAndRefusesOneMore(): void
    {
        $screenshots = [];
        for ($position = 0; $position < Round::MAX_SCREENSHOTS; ++$position) {
            $screenshots[] = self::screenshot($position);
        }
        self::assertCount(50, self::report($screenshots)->screenshots());

        $screenshots[] = self::screenshot(Round::MAX_SCREENSHOTS);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A round holds at most 50 screenshots.');

        self::report($screenshots);
    }

    #[Test]
    public function aReportRefusesTwoScreenshotsAtOnePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Two screenshots of one round cannot share a position.');

        self::report([self::screenshot(0), self::screenshot(1), self::screenshot(1)]);
    }

    #[Test]
    public function aReportKeepsItsScreenshotsInPositionOrder(): void
    {
        $round = self::report([self::screenshot(2), self::screenshot(0), self::screenshot(1)]);

        self::assertSame([0, 1, 2], self::positionsOf($round));
    }

    #[Test]
    public function aDraftIsAnEmptyUnsharedRoundWithNoNumber(): void
    {
        $startedAt = new \DateTimeImmutable('2026-03-04 10:00:00+00:00');

        $draft = Round::draft(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), $startedAt);

        self::assertSame(RoundKind::Draft, $draft->kind());
        self::assertNull($draft->number());
        self::assertNull($draft->sharedAt());
        self::assertTrue($draft->isUnsharedDraft());
        self::assertSame([], $draft->screenshots());
        self::assertSame(RoundState::Open, $draft->state());
        self::assertSame(1, $draft->version());
        self::assertSame($startedAt, $draft->createdAt());
        self::assertSame(self::OMAR, $draft->ownerId()->toString());
        self::assertNull($draft->deletedAt());
        self::assertNull($draft->decision());
        self::assertNull($draft->decidedAt());
    }

    #[Test]
    public function theOwnerSharesADraftAsTheGivenNumberAndTheVersionGoesUp(): void
    {
        $draft = self::draft();
        $sharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');

        $draft->share(new UserId(self::OMAR), 1, $sharedAt);

        self::assertSame(1, $draft->number());
        self::assertSame($sharedAt, $draft->sharedAt());
        self::assertFalse($draft->isUnsharedDraft());
        self::assertSame(RoundKind::Draft, $draft->kind());
        self::assertSame(2, $draft->version());
        self::assertSame(1, $draft->savedVersion());
    }

    #[Test]
    public function aDraftCannotBeSharedAsRoundZeroOrBelow(): void
    {
        foreach ([0, -1] as $number) {
            $draft = self::draft();
            $before = self::snapshot($draft);

            try {
                $draft->share(new UserId(self::OMAR), $number, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
                self::fail('Sharing as number '.$number.' was allowed.');
            } catch (\DomainException $refused) {
                self::assertSame('A shared draft round gets a number from 1; Round 0 is the report.', $refused->getMessage());
            }
            self::assertSame($before, self::snapshot($draft));
            self::assertTrue($draft->isUnsharedDraft());
        }
    }

    #[Test]
    public function aDraftCannotBeSharedTwiceAndKeepsItsFirstNumber(): void
    {
        $draft = self::draft();
        $firstSharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $draft->share(new UserId(self::OMAR), 1, $firstSharedAt);

        try {
            $draft->share(new UserId(self::OMAR), 2, new \DateTimeImmutable('2026-03-06 08:00:00+00:00'));
            self::fail('A shared draft was shared again.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round is shared already.', $refused->getMessage());
        }

        self::assertSame(1, $draft->number());
        self::assertSame($firstSharedAt, $draft->sharedAt());
        self::assertSame(2, $draft->version());
    }

    #[Test]
    public function onlyTheOwnerCanShareADraft(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('Another person shared the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can share it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertTrue($draft->isUnsharedDraft());
    }

    #[Test]
    public function aDeletedDraftCannotBeSharedAndStaysDeleted(): void
    {
        $draft = self::draft();
        $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-04 12:00:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('A deleted draft was shared.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aReportCannotBeSharedAndKeepsItsNumberAndTime(): void
    {
        $reportedAt = new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
        $report = Round::report(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [self::screenshot(0)], $reportedAt);

        try {
            $report->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('A report round was shared.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a draft round can be shared.', $refused->getMessage());
        }

        self::assertSame(0, $report->number());
        self::assertSame($reportedAt, $report->sharedAt());
        self::assertSame(1, $report->version());
    }

    #[Test]
    public function aScreenshotIsAddedWithNoTextPinsOrVerdictAndTheVersionGoesUp(): void
    {
        $draft = self::draft();

        $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA);

        self::assertCount(1, $draft->screenshots());
        $added = $draft->screenshots()[0];
        self::assertSame(self::NEW_SCREENSHOT, $added->id()->toString());
        self::assertSame(self::MEDIA, $added->mediaId());
        self::assertSame(0, $added->position());
        self::assertNull($added->title());
        self::assertNull($added->summary());
        self::assertSame([], $added->pins());
        self::assertNull($added->verdict());
        self::assertSame(2, $draft->version());
        self::assertSame(1, $draft->savedVersion());
    }

    #[Test]
    public function aNewScreenshotTakesTheNextPositionAfterTheHighestNeverAGapARemovedOneLeft(): void
    {
        $draft = self::draftWithScreenshots(3);
        $owner = new UserId(self::OMAR);
        $draft->removeScreenshot($owner, self::screenshotId(1));
        $draft->removeScreenshot($owner, self::screenshotId(2));

        $draft->addScreenshot($owner, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA);

        self::assertSame([0, 1], self::positionsOf($draft));

        $draft->removeScreenshot($owner, self::screenshotId(0));
        $draft->addScreenshot($owner, new ScreenshotId('0197a3c0-0000-7000-8000-000000000c98'), self::MEDIA);

        self::assertSame([1, 2], self::positionsOf($draft));
    }

    #[Test]
    public function aDraftHoldsTheMaximumNumberOfScreenshotsAndRefusesOneMore(): void
    {
        $draft = self::draftWithScreenshots(Round::MAX_SCREENSHOTS);
        self::assertCount(50, $draft->screenshots());
        $before = self::snapshot($draft);

        try {
            $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA);
            self::fail('A 51st screenshot was added.');
        } catch (\DomainException $refused) {
            self::assertSame('A round holds at most 50 screenshots.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aMediaIdThatIsNotAUuidV7IsRefusedAndNothingChanges(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), 'screenshot.png');
            self::fail('A bad media id was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A screenshot needs the id of an uploaded picture.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function removingAScreenshotTakesItsPinsAndKeepsThePositionsOfTheOthers(): void
    {
        $draft = self::draftWithScreenshots(3);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(1), [self::pin(1)]);

        $draft->removeScreenshot($owner, self::screenshotId(1));

        self::assertSame([self::screenshotId(0)->toString(), self::screenshotId(2)->toString()], array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $draft->screenshots()));
        self::assertSame([0, 2], self::positionsOf($draft));
        self::assertSame(3, $draft->version());
        self::assertSame(1, $draft->savedVersion());
    }

    #[Test]
    public function aTextEditSetsTrimmedTitleAndSummaryAndKeepsPinsAndVerdict(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $draft->setVerdict($owner, self::screenshotId(0), ScreenshotVerdict::NotRight);

        $draft->editScreenshotText($owner, self::screenshotId(0), '  Cart page ', "Total is wrong\n");

        $edited = $draft->screenshots()[0];
        self::assertSame('Cart page', $edited->title());
        self::assertSame('Total is wrong', $edited->summary());
        self::assertCount(1, $edited->pins());
        self::assertSame(ScreenshotVerdict::NotRight, $edited->verdict());
        self::assertNull($draft->screenshots()[1]->title());
        self::assertSame(4, $draft->version());
    }

    #[Test]
    public function aBlankTextClearsTheTitleAndSummary(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->editScreenshotText($owner, self::screenshotId(0), 'Cart page', 'Total');

        $draft->editScreenshotText($owner, self::screenshotId(0), ' ', null);

        self::assertNull($draft->screenshots()[0]->title());
        self::assertNull($draft->screenshots()[0]->summary());
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function textsOverTheCaps(): iterable
    {
        yield 'title over 120 characters' => [str_repeat('é', 121), null, 'A picture title holds at most 120 characters.'];
        yield 'summary over 600 characters' => [null, str_repeat('é', 601), 'A picture summary holds at most 600 characters.'];
    }

    #[Test]
    #[DataProvider('textsOverTheCaps')]
    public function aTextOverTheCapIsRefusedAndTheOldTextAndVersionStay(?string $title, ?string $summary, string $reason): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->editScreenshotText($owner, self::screenshotId(0), 'Cart page', 'Total');
        $before = self::snapshot($draft);

        try {
            $draft->editScreenshotText($owner, self::screenshotId(0), $title, $summary);
            self::fail('A text over the cap was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame($reason, $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aTitleAndSummaryOfExactlyTheCapsAreAccepted(): void
    {
        $draft = self::draftWithScreenshots(1);

        $draft->editScreenshotText(new UserId(self::OMAR), self::screenshotId(0), str_repeat('é', 120), str_repeat('é', 600));

        self::assertSame(120, mb_strlen((string) $draft->screenshots()[0]->title()));
        self::assertSame(600, mb_strlen((string) $draft->screenshots()[0]->summary()));
    }

    #[Test]
    public function replacingPinsPutsTheNewOnesInNumberOrderInPlaceOfTheOldAndOnlyOnThatPicture(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $draft->replacePins($owner, self::screenshotId(1), [self::pin(5)]);

        $draft->replacePins($owner, self::screenshotId(0), [self::pin(3), self::pin(2)]);

        self::assertSame([2, 3], self::pinNumbersOf($draft, 0));
        self::assertSame([5], self::pinNumbersOf($draft, 1));

        $draft->replacePins($owner, self::screenshotId(0), []);

        self::assertSame([], self::pinNumbersOf($draft, 0));
        self::assertSame(5, $draft->version());
    }

    #[Test]
    public function morePinsThanTheCapAreRefusedAndTheOldPinsStay(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $before = self::snapshot($draft);
        $tooMany = [];
        for ($number = 1; $number <= Screenshot::MAX_PINS + 1; ++$number) {
            $tooMany[] = self::pin($number);
        }

        try {
            $draft->replacePins($owner, self::screenshotId(0), $tooMany);
            self::fail('More pins than the cap were accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A picture holds at most 100 pins.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertSame([1], self::pinNumbersOf($draft, 0));
    }

    #[Test]
    public function twoPinsWithOneNumberAreRefusedAndTheOldPinsStay(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $before = self::snapshot($draft);

        try {
            $draft->replacePins($owner, self::screenshotId(0), [self::pin(2), self::pin(2)]);
            self::fail('Two pins with one number were accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('Pin 2 appears twice on one screenshot.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function theVerdictIsSetChangedAndClearedAndTheDraftCountsThoseThatLookRight(): void
    {
        $draft = self::draftWithScreenshots(3);
        $owner = new UserId(self::OMAR);

        $draft->setVerdict($owner, self::screenshotId(0), ScreenshotVerdict::LooksRight);
        $draft->setVerdict($owner, self::screenshotId(1), ScreenshotVerdict::NotRight);

        self::assertSame(ScreenshotVerdict::LooksRight, $draft->screenshots()[0]->verdict());
        self::assertSame(ScreenshotVerdict::NotRight, $draft->screenshots()[1]->verdict());
        self::assertNull($draft->screenshots()[2]->verdict());
        self::assertSame(1, $draft->screenshotsThatLookRight());

        $draft->setVerdict($owner, self::screenshotId(1), ScreenshotVerdict::LooksRight);
        self::assertSame(2, $draft->screenshotsThatLookRight());

        $draft->setVerdict($owner, self::screenshotId(0), null);
        self::assertNull($draft->screenshots()[0]->verdict());
        self::assertSame(1, $draft->screenshotsThatLookRight());
        self::assertSame(5, $draft->version());
    }

    /**
     * @return iterable<string, array{\Closure(Round, UserId): void}>
     */
    public static function draftMutations(): iterable
    {
        yield 'add a screenshot' => [static fn (Round $round, UserId $actor) => $round->addScreenshot($actor, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA)];
        yield 'remove a screenshot' => [static fn (Round $round, UserId $actor) => $round->removeScreenshot($actor, self::screenshotId(0))];
        yield 'edit the text' => [static fn (Round $round, UserId $actor) => $round->editScreenshotText($actor, self::screenshotId(0), 'Title', 'Summary')];
        yield 'replace the pins' => [static fn (Round $round, UserId $actor) => $round->replacePins($actor, self::screenshotId(0), [self::pin(1)])];
        yield 'set the verdict' => [static fn (Round $round, UserId $actor) => $round->setVerdict($actor, self::screenshotId(0), ScreenshotVerdict::LooksRight)];
        yield 'delete' => [static fn (Round $round, UserId $actor) => $round->delete($actor, new \DateTimeImmutable('2026-03-06 08:00:00+00:00'))];
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function onlyTheOwnerChangesADraft(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(1);
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::LENA));
            self::fail('Another person changed the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can change it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aSharedDraftNoLongerChanges(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::OMAR));
            self::fail('A shared draft was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round is shared, so it no longer changes.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aDeletedDraftNoLongerChanges(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::OMAR));
            self::fail('A deleted draft was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aClosedDraftThatWasNotDeletedNoLongerChanges(\Closure $mutation): void
    {
        $closed = self::restoredDraft(RoundState::Closed);
        $before = self::snapshot($closed);

        try {
            $mutation($closed, new UserId(self::OMAR));
            self::fail('A closed draft was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round is closed.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($closed));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aReportRoundIsNeverChangedThisWay(\Closure $mutation): void
    {
        $report = self::report([self::screenshot(0)]);
        $before = self::snapshot($report);

        try {
            $mutation($report, new UserId(self::LENA));
            self::fail('A report round was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a draft round can be changed this way.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($report));
    }

    /**
     * @return iterable<string, array{\Closure(Round, UserId): void}>
     */
    public static function mutationsOfAPictureTheDraftDoesNotHave(): iterable
    {
        $unknown = new ScreenshotId(self::UNKNOWN_SCREENSHOT);
        yield 'remove' => [static fn (Round $round, UserId $actor) => $round->removeScreenshot($actor, $unknown)];
        yield 'edit the text' => [static fn (Round $round, UserId $actor) => $round->editScreenshotText($actor, $unknown, 'Title', null)];
        yield 'replace the pins' => [static fn (Round $round, UserId $actor) => $round->replacePins($actor, $unknown, [self::pin(1)])];
        yield 'set the verdict' => [static fn (Round $round, UserId $actor) => $round->setVerdict($actor, $unknown, ScreenshotVerdict::NotRight)];
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('mutationsOfAPictureTheDraftDoesNotHave')]
    public function anUnknownScreenshotIsRefusedAndTheDraftStaysAsItWas(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(2);
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::OMAR));
            self::fail('An unknown screenshot was accepted.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round has no such screenshot.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function deletingADraftClosesItAndKeepsItsPicturesForRetention(): void
    {
        $draft = self::draftWithScreenshots(2);
        $deletedAt = new \DateTimeImmutable('2026-03-06 08:00:00+00:00');

        $draft->delete(new UserId(self::OMAR), $deletedAt);

        self::assertSame(RoundState::Closed, $draft->state());
        self::assertSame($deletedAt, $draft->deletedAt());
        self::assertCount(2, $draft->screenshots());
        self::assertSame(2, $draft->version());
        self::assertSame(1, $draft->savedVersion());
        self::assertNull($draft->number());
    }

    #[Test]
    public function aDraftCannotBeDeletedTwiceAndKeepsItsFirstDeletionTime(): void
    {
        $draft = self::draft();
        $firstDeletedAt = new \DateTimeImmutable('2026-03-06 08:00:00+00:00');
        $draft->delete(new UserId(self::OMAR), $firstDeletedAt);

        try {
            $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-07 08:00:00+00:00'));
            self::fail('A draft was deleted twice.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($firstDeletedAt, $draft->deletedAt());
        self::assertSame(2, $draft->version());
    }

    #[Test]
    public function theOwnerRecordsThatTheySharedAfterSharing(): void
    {
        $draft = self::draft();
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $decidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');

        $draft->decide(new UserId(self::OMAR), DraftDecision::Shared, $decidedAt);

        self::assertSame(DraftDecision::Shared, $draft->decision());
        self::assertSame($decidedAt, $draft->decidedAt());
        self::assertSame(3, $draft->version());
    }

    #[Test]
    public function theOwnerKeepsAnUnsharedDraftPrivate(): void
    {
        $draft = self::draft();
        $decidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');

        $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, $decidedAt);

        self::assertSame(DraftDecision::KeptPrivate, $draft->decision());
        self::assertSame($decidedAt, $draft->decidedAt());
        self::assertSame(2, $draft->version());
        self::assertTrue($draft->isUnsharedDraft());
    }

    #[Test]
    public function aReportRoundCannotBeDecidedAbout(): void
    {
        $report = self::report([self::screenshot(0)]);
        $before = self::snapshot($report);

        try {
            $report->decide(new UserId(self::LENA), DraftDecision::Shared, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('A report round was decided about.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a draft round is shared or kept private.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($report));
    }

    #[Test]
    public function onlyTheOwnerDecidesAboutADraft(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::LENA), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('Another person decided about the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can decide about it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aDeletedDraftCannotBeDecidedAbout(): void
    {
        $draft = self::draft();
        $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-05 11:00:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('A deleted draft was decided about.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aDraftIsDecidedAboutOnceAndKeepsItsFirstChoice(): void
    {
        $draft = self::draft();
        $firstDecidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');
        $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, $firstDecidedAt);
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-06 09:00:00+00:00'));
            self::fail('A second decision was recorded.');
        } catch (\DomainException $refused) {
            self::assertSame('You decided about this draft round already.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertSame($firstDecidedAt, $draft->decidedAt());
    }

    #[Test]
    public function sharedCannotBeRecordedBeforeTheDraftIsShared(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::Shared, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('Shared was recorded on an unshared draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Share the draft round before recording that you shared it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertNull($draft->decision());
    }

    #[Test]
    public function aSharedDraftCannotBeKeptPrivate(): void
    {
        $draft = self::draft();
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('A shared draft was kept private.');
        } catch (\DomainException $refused) {
            self::assertSame('A shared draft round cannot be kept private.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertNull($draft->decision());
    }

    #[Test]
    public function aRestoredRoundKeepsItsVersionAsTheOneToSaveOverAndCountsFromThere(): void
    {
        $draft = self::restoredDraft(RoundState::Open, 7);

        self::assertSame(7, $draft->version());
        self::assertSame(7, $draft->savedVersion());

        $draft->setVerdict(new UserId(self::OMAR), self::screenshotId(0), ScreenshotVerdict::LooksRight);

        self::assertSame(8, $draft->version());
        self::assertSame(7, $draft->savedVersion());
    }

    #[Test]
    public function aRestoredRoundKeepsItsScreenshotsInPositionOrder(): void
    {
        $draft = Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, RoundState::Open, 1, [self::screenshot(4), self::screenshot(0), self::screenshot(2)], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);

        self::assertSame([0, 2, 4], self::positionsOf($draft));
    }

    /**
     * @return iterable<string, array{string, int, ?int, ?\DateTimeImmutable, ?DraftDecision, ?\DateTimeImmutable}>
     */
    public static function storedRoundsThatBreakARule(): iterable
    {
        $time = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $versionMessage = 'A round version starts at 1.';
        $pairMessage = 'A round has a number exactly when it is shared, and a decision exactly when it has its time.';
        yield 'version 0' => [$versionMessage, 0, null, null, null, null];
        yield 'negative version' => [$versionMessage, -3, null, null, null, null];
        yield 'number without share time' => [$pairMessage, 1, 1, null, null, null];
        yield 'share time without number' => [$pairMessage, 1, null, $time, null, null];
        yield 'decision without time' => [$pairMessage, 1, null, null, DraftDecision::KeptPrivate, null];
        yield 'decision time without decision' => [$pairMessage, 1, null, null, null, $time];
    }

    #[Test]
    #[DataProvider('storedRoundsThatBreakARule')]
    public function aStoredRoundThatBreaksARuleIsRefused(string $message, int $version, ?int $number, ?\DateTimeImmutable $sharedAt, ?DraftDecision $decision, ?\DateTimeImmutable $decidedAt): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), $number, $sharedAt, RoundState::Open, $version, [], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, $decision, $decidedAt);
    }

    #[Test]
    public function aStoredSharedAndDecidedRoundIsRestoredWithEverythingItHad(): void
    {
        $sharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $decidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');

        $round = Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), 2, $sharedAt, RoundState::Open, 4, [], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, DraftDecision::Shared, $decidedAt);

        self::assertSame(2, $round->number());
        self::assertSame($sharedAt, $round->sharedAt());
        self::assertSame(DraftDecision::Shared, $round->decision());
        self::assertSame($decidedAt, $round->decidedAt());
        self::assertFalse($round->isUnsharedDraft());
    }

    /**
     * @param list<Screenshot> $screenshots
     */
    private static function report(array $screenshots): Round
    {
        return Round::report(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), $screenshots, new \DateTimeImmutable('2026-03-04 09:30:00+00:00'));
    }

    private static function draft(): Round
    {
        return Round::draft(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), new \DateTimeImmutable('2026-03-04 10:00:00+00:00'));
    }

    /**
     * Omar's draft with $count screenshots at positions 0 up, as a read gives it at version 1.
     */
    private static function draftWithScreenshots(int $count): Round
    {
        $screenshots = [];
        for ($position = 0; $position < $count; ++$position) {
            $screenshots[] = self::screenshot($position);
        }

        return Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, RoundState::Open, 1, $screenshots, new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);
    }

    private static function restoredDraft(RoundState $state, int $version = 1): Round
    {
        return Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, $state, $version, [self::screenshot(0)], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);
    }

    private static function screenshotId(int $position): ScreenshotId
    {
        return new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xC00 + $position));
    }

    private static function screenshot(int $position): Screenshot
    {
        return Screenshot::of(self::screenshotId($position), self::MEDIA, $position, null, null, []);
    }

    private static function pin(int $number): Pin
    {
        return Pin::placedBy(new PinId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xA00 + $number)), $number, 10.0, 20.0, 'Header is cut off', new UserId(self::OMAR));
    }

    /**
     * @return list<int>
     */
    private static function positionsOf(Round $round): array
    {
        return array_map(static fn (Screenshot $screenshot): int => $screenshot->position(), $round->screenshots());
    }

    /**
     * @return list<int>
     */
    private static function pinNumbersOf(Round $round, int $index): array
    {
        return array_map(static fn (Pin $pin): int => $pin->number(), $round->screenshots()[$index]->pins());
    }

    /**
     * Everything a refused change must leave as it was.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Round $round): array
    {
        return [
            'number' => $round->number(),
            'sharedAt' => $round->sharedAt()?->format('c'),
            'state' => $round->state(),
            'version' => $round->version(),
            'savedVersion' => $round->savedVersion(),
            'deletedAt' => $round->deletedAt()?->format('c'),
            'decision' => $round->decision(),
            'decidedAt' => $round->decidedAt()?->format('c'),
            'screenshots' => array_map(static fn (Screenshot $screenshot): array => [
                $screenshot->id()->toString(),
                $screenshot->mediaId(),
                $screenshot->position(),
                $screenshot->title(),
                $screenshot->summary(),
                $screenshot->verdict(),
                array_map(static fn (Pin $pin): array => [$pin->id()->toString(), $pin->number()], $screenshot->pins()),
            ], $round->screenshots()),
        ];
    }
}
