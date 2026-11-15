<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
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
        $this->expectExceptionMessage('A report round needs at least one screenshot.');

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
        $this->expectExceptionMessage('A round holds at most 50 screenshots.');

        self::report($screenshots);
    }

    #[Test]
    public function aReportRefusesTwoScreenshotsAtOnePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Two screenshots of one round cannot share a position.');

        self::report([self::screenshot(0), self::screenshot(1), self::screenshot(1)]);
    }

    #[Test]
    public function aReportKeepsItsScreenshotsInPositionOrder(): void
    {
        $round = self::report([self::screenshot(2), self::screenshot(0), self::screenshot(1)]);

        self::assertSame([0, 1, 2], array_map(static fn (Screenshot $screenshot): int => $screenshot->position(), $round->screenshots()));
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
    }

    #[Test]
    public function theOwnerSharesADraftAsTheGivenNumber(): void
    {
        $draft = self::draft();
        $sharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');

        $draft->share(new UserId(self::OMAR), 1, $sharedAt);

        self::assertSame(1, $draft->number());
        self::assertSame($sharedAt, $draft->sharedAt());
        self::assertFalse($draft->isUnsharedDraft());
        self::assertSame(RoundKind::Draft, $draft->kind());
    }

    #[Test]
    public function aDraftCannotBeSharedAsRoundZeroOrBelow(): void
    {
        foreach ([0, -1] as $number) {
            $draft = self::draft();

            try {
                $draft->share(new UserId(self::OMAR), $number, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
                self::fail('Sharing as number '.$number.' was allowed.');
            } catch (\DomainException $refused) {
                self::assertSame('A shared draft round gets a number from 1; Round 0 is the report.', $refused->getMessage());
            }
            self::assertNull($draft->number());
            self::assertNull($draft->sharedAt());
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
    }

    #[Test]
    public function onlyTheOwnerCanShareADraft(): void
    {
        $draft = self::draft();

        try {
            $draft->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('Another person shared the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can share it.', $refused->getMessage());
        }

        self::assertNull($draft->number());
        self::assertNull($draft->sharedAt());
        self::assertTrue($draft->isUnsharedDraft());
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

    private static function screenshot(int $position): Screenshot
    {
        return Screenshot::of(new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xC00 + $position)), self::MEDIA, $position, null, null, []);
    }
}
