<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\Pin;
use App\Review\Domain\Round;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\Screenshot;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketNotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UseCaseRoundCommandsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string SHOP = '0197a3c0-0000-7000-8000-000000000f11';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-000000001099';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000001098';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000f02';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string OTHER_MEDIA = '0197a3c0-0000-7000-8000-000000000d02';
    private const string PENDING_MEDIA = '0197a3c0-0000-7000-8000-000000000d03';
    private const string REJECTED_MEDIA = '0197a3c0-0000-7000-8000-000000000d04';
    private const string OMARS_MEDIA = '0197a3c0-0000-7000-8000-000000000d05';
    private const string LENAS_MEDIA_AT_BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000d06';
    private const string UNKNOWN_MEDIA = '0197a3c0-0000-7000-8000-000000000d09';

    private InMemoryRounds $rounds;
    private InMemoryTicketTurnQueries $tickets;
    private InMemoryUploadedMedia $uploadedMedia;
    private UseCaseRoundCommands $commands;
    private \DateTimeImmutable $reportedAt;

    protected function setUp(): void
    {
        $this->rounds = new InMemoryRounds();
        $this->tickets = new InMemoryTicketTurnQueries([self::snapshot()], new TicketId(self::BROKEN_TICKET));
        $this->uploadedMedia = new InMemoryUploadedMedia([
            [self::ACME_FOODS, self::MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::OTHER_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::PENDING_MEDIA, self::LENA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::REJECTED_MEDIA, self::LENA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_MEDIA, self::OMAR, MediaImageStatus::Ready],
            [self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, self::LENA, MediaImageStatus::Ready],
        ]);
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->commands = new UseCaseRoundCommands($this->rounds, $this->tickets, $tenantContext, $this->uploadedMedia);
        $this->reportedAt = new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
    }

    #[Test]
    public function itStoresOneReportRoundWithTheScreenshotsAndPinsAsGiven(): void
    {
        $roundId = $this->commands->createReport(
            new TicketId(self::TICKET),
            [
                new ScreenshotWithPins(self::MEDIA, '  Checkout page ', 'Totals are wrong', [
                    new PlacedPin(12.5, 80.0, 'Total is off'),
                    new PlacedPin(100.0, 0.0, ' Logo is cut '),
                ]),
                new ScreenshotWithPins(strtoupper(self::OTHER_MEDIA)),
            ],
            new UserId(self::LENA),
            $this->reportedAt,
        );

        self::assertCount(1, $this->rounds->stored);
        $round = $this->rounds->stored[0];
        self::assertSame($roundId, $round->id()->toString());
        self::assertSame(RoundKind::Report, $round->kind());
        self::assertSame(Round::REPORT_NUMBER, $round->number());
        self::assertSame(self::ACME_FOODS, $round->organizationId()->toString());
        self::assertSame(self::TICKET, $round->ticketId()->toString());
        self::assertSame(self::LENA, $round->ownerId()->toString());
        self::assertSame($this->reportedAt, $round->sharedAt());
        self::assertSame($this->reportedAt, $round->createdAt());

        self::assertSame([self::MEDIA, self::OTHER_MEDIA], array_map(static fn (Screenshot $screenshot): string => $screenshot->mediaId(), $round->screenshots()));
        self::assertSame([0, 1], array_map(static fn (Screenshot $screenshot): int => $screenshot->position(), $round->screenshots()));
        [$checkout, $plain] = $round->screenshots();
        self::assertSame('Checkout page', $checkout->title());
        self::assertSame('Totals are wrong', $checkout->summary());
        self::assertNull($plain->title());
        self::assertSame([], $plain->pins());

        $pins = $checkout->pins();
        self::assertSame([1, 2], array_map(static fn (Pin $pin): int => $pin->number(), $pins));
        self::assertSame([12.5, 100.0], array_map(static fn (Pin $pin): float => $pin->xPercent(), $pins));
        self::assertSame([80.0, 0.0], array_map(static fn (Pin $pin): float => $pin->yPercent(), $pins));
        self::assertSame(['Total is off', 'Logo is cut'], array_map(static fn (Pin $pin): string => $pin->note(), $pins));
        self::assertSame([self::LENA, self::LENA], array_map(static fn (Pin $pin): string => $pin->authorId()->toString(), $pins));
        self::assertSame([self::TICKET], $this->tickets->askedTicketIds);
        self::assertSame([[self::MEDIA, strtoupper(self::OTHER_MEDIA)]], $this->uploadedMedia->askedMediaIds);
    }

    /**
     * Omar's picture is ready in Lena's organization and Lena's own one is ready in Beta Bakery, so only the uploader
     * and organization check refuses them.
     *
     * @return iterable<string, array{string}>
     */
    public static function picturesTheReporterDidNotUpload(): iterable
    {
        yield 'another person\'s ready picture' => [self::OMARS_MEDIA];
        yield 'the reporter\'s picture in another organization' => [self::LENAS_MEDIA_AT_BETA_BAKERY];
        yield 'an unknown picture' => [self::UNKNOWN_MEDIA];
        yield 'a malformed id' => ['not-a-media-id'];
    }

    #[Test]
    #[DataProvider('picturesTheReporterDidNotUpload')]
    public function itRefusesAPictureTheReporterDidNotUploadBeforeBuildingAnything(string $mediaId): void
    {
        $this->assertRefusedBeforeBuilding(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU, [self::screenshotOf($mediaId)]);

        self::assertSame([[$mediaId]], $this->uploadedMedia->askedMediaIds);
    }

    #[Test]
    public function itRefusesAPictureThatIsStillProcessingBeforeBuildingAnything(): void
    {
        $this->assertRefusedBeforeBuilding(RoundRefused::MEDIA_STILL_PROCESSING, [self::screenshotOf(self::PENDING_MEDIA)]);
    }

    #[Test]
    public function itRefusesARejectedPictureBeforeBuildingAnything(): void
    {
        $this->assertRefusedBeforeBuilding(RoundRefused::MEDIA_REJECTED, [self::screenshotOf(self::REJECTED_MEDIA)]);
    }

    /**
     * A ready picture first does not let the others through, and the first refused one in the list names the reason.
     */
    #[Test]
    public function oneRefusedPictureRefusesTheWholeReportWithTheReasonOfTheFirst(): void
    {
        $this->assertRefusedBeforeBuilding(RoundRefused::MEDIA_STILL_PROCESSING, [
            self::screenshotOf(self::MEDIA),
            self::screenshotOf(self::PENDING_MEDIA),
            self::screenshotOf(self::OMARS_MEDIA),
        ]);

        self::assertSame([[self::MEDIA, self::PENDING_MEDIA, self::OMARS_MEDIA]], $this->uploadedMedia->askedMediaIds);
    }

    #[Test]
    public function aReportFromSomeoneWhoDidNotReportTheTicketIsRefusedBeforeAskingForThePictures(): void
    {
        $this->assertRefused('Only the person who reported the ticket can add its report.', [self::screenshotOf(self::OMARS_MEDIA)], self::OMAR);

        self::assertSame([], $this->uploadedMedia->askedMediaIds);
    }

    #[Test]
    public function itAcceptsExactlyTheMaximumNumberOfScreenshots(): void
    {
        $this->commands->createReport(new TicketId(self::TICKET), self::screenshots(Round::MAX_SCREENSHOTS), new UserId(self::LENA), $this->reportedAt);

        self::assertCount(Round::MAX_SCREENSHOTS, $this->rounds->stored[0]->screenshots());
    }

    #[Test]
    public function itRefusesMoreThanTheMaximumNumberOfScreenshotsBeforeAskingForTheTicket(): void
    {
        $this->assertRefused('A round holds at most 50 screenshots.', self::screenshots(Round::MAX_SCREENSHOTS + 1));

        self::assertSame([], $this->tickets->askedTicketIds);
    }

    #[Test]
    public function itRefusesAReportWithNoScreenshot(): void
    {
        $this->assertRefused('A report round needs at least one screenshot.', []);
    }

    #[Test]
    public function itRefusesAReportFromSomeoneWhoDidNotReportTheTicket(): void
    {
        $this->assertRefused('Only the person who reported the ticket can add its report.', self::screenshots(1), self::OMAR);
    }

    #[Test]
    public function itRefusesATitleOverTheCapAndStoresNothing(): void
    {
        $this->assertRefused('A picture title holds at most 120 characters.', [new ScreenshotWithPins(self::MEDIA, str_repeat('é', 121))]);
    }

    #[Test]
    public function itRefusesASummaryOverTheCapAndStoresNothing(): void
    {
        $this->assertRefused('A picture summary holds at most 600 characters.', [new ScreenshotWithPins(self::MEDIA, null, str_repeat('é', 601))]);
    }

    #[Test]
    public function itRefusesANoteOverTheCapAndStoresNothing(): void
    {
        $this->assertRefused('A pin note holds at most 1000 characters.', [new ScreenshotWithPins(self::MEDIA, null, null, [new PlacedPin(10.0, 10.0, str_repeat('é', 1001))])]);
    }

    #[Test]
    public function itRefusesAPinOutsideThePictureAndStoresNothing(): void
    {
        $this->assertRefused('A pin must sit inside the picture: x and y go from 0 to 100.', [new ScreenshotWithPins(self::MEDIA, null, null, [new PlacedPin(10.0, 100.5, '')])]);
    }

    #[Test]
    public function itAcceptsExactlyTheMaximumNumberOfPinsOnAScreenshot(): void
    {
        $this->commands->createReport(new TicketId(self::TICKET), [self::screenshotWithPins(Screenshot::MAX_PINS)], new UserId(self::LENA), $this->reportedAt);

        self::assertCount(Screenshot::MAX_PINS, $this->rounds->stored[0]->screenshots()[0]->pins());
    }

    #[Test]
    public function itRefusesMoreThanTheMaximumNumberOfPinsOnAScreenshotAndStoresNothing(): void
    {
        $this->assertRefused('A picture holds at most 100 pins.', [self::screenshotWithPins(Screenshot::MAX_PINS + 1)]);

        self::assertSame(0, $this->rounds->issuedPinIds);
    }

    #[Test]
    public function itTellsATicketThatHasAReportAlreadyAndKeepsTheFirstReport(): void
    {
        $firstRoundId = $this->commands->createReport(new TicketId(self::TICKET), [new ScreenshotWithPins(self::MEDIA)], new UserId(self::LENA), $this->reportedAt);

        try {
            $this->commands->createReport(new TicketId(self::TICKET), [new ScreenshotWithPins(self::OTHER_MEDIA)], new UserId(self::LENA), new \DateTimeImmutable('2026-03-05 10:00:00+00:00'));
            self::fail('A second report was stored.');
        } catch (RoundRefused $refused) {
            self::assertSame('This ticket has a report already.', $refused->getMessage());
            self::assertInstanceOf(RoundSlotTaken::class, $refused->getPrevious());
        }

        self::assertCount(1, $this->rounds->stored);
        self::assertSame($firstRoundId, $this->rounds->stored[0]->id()->toString());
        self::assertSame($this->reportedAt, $this->rounds->stored[0]->createdAt());
    }

    #[Test]
    public function itDoesNotAnswerForATicketItCannotFind(): void
    {
        try {
            $this->commands->createReport(new TicketId(self::UNKNOWN_TICKET), self::screenshots(1), new UserId(self::LENA), $this->reportedAt);
            self::fail('A report was added to a missing ticket.');
        } catch (TicketNotFound $notFound) {
            self::assertSame('That ticket does not exist.', $notFound->getMessage());
        }

        self::assertSame([], $this->rounds->stored);
    }

    #[Test]
    public function itFailsWhenNoOrganizationIsSetAndAsksNothing(): void
    {
        $commands = new UseCaseRoundCommands($this->rounds, $this->tickets, new TenantContext(new RecordingTenantSwitchGuard()), $this->uploadedMedia);

        try {
            $commands->createReport(new TicketId(self::TICKET), self::screenshots(1), new UserId(self::LENA), $this->reportedAt);
            self::fail('A report was added with no tenant.');
        } catch (TenantNotSet) {
            self::assertSame([], $this->tickets->askedTicketIds);
            self::assertSame([], $this->rounds->stored);
        }
    }

    /**
     * @param list<ScreenshotWithPins> $screenshots
     */
    private function assertRefused(string $reason, array $screenshots, string $reporterId = self::LENA): void
    {
        try {
            $this->commands->createReport(new TicketId(self::TICKET), $screenshots, new UserId($reporterId), $this->reportedAt);
            self::fail('The report was accepted; expected: '.$reason);
        } catch (RoundRefused $refused) {
            self::assertSame($reason, $refused->getMessage());
        }

        self::assertSame([], $this->rounds->stored);
    }

    /**
     * @param list<ScreenshotWithPins> $screenshots
     */
    private function assertRefusedBeforeBuilding(string $reason, array $screenshots): void
    {
        $this->assertRefused($reason, $screenshots);

        self::assertSame(0, $this->rounds->issuedRoundIds);
        self::assertSame(0, $this->rounds->issuedPinIds);
    }

    /**
     * A screenshot with a pin, so a refusal that came after building would have issued a round and a pin id.
     */
    private static function screenshotOf(string $mediaId): ScreenshotWithPins
    {
        return new ScreenshotWithPins($mediaId, null, null, [new PlacedPin(10.0, 10.0, 'Total is off')]);
    }

    /**
     * @return list<ScreenshotWithPins>
     */
    private static function screenshots(int $count): array
    {
        return array_fill(0, $count, new ScreenshotWithPins(self::MEDIA));
    }

    private static function screenshotWithPins(int $pinCount): ScreenshotWithPins
    {
        return new ScreenshotWithPins(self::MEDIA, null, null, array_fill(0, $pinCount, new PlacedPin(10.0, 10.0, '')));
    }

    private static function snapshot(): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-0000000017c1',
            '0197a3c0-0000-7000-8000-0000000017c2',
            'backlog',
            'todo',
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [],
            [],
            'all',
            'major',
            null,
            new UserId(self::LENA),
        );
    }
}
