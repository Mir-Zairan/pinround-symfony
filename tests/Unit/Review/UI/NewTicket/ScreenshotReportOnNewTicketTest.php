<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\NewTicket;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\Pin;
use App\Review\Domain\Round;
use App\Review\UI\NewTicket\ScreenshotReportOnNewTicket;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\Application\InMemoryRounds;
use App\Tests\Unit\Review\Application\InMemoryUploadedMedia;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\NewTicketReportRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScreenshotReportOnNewTicket on the real UseCaseRoundCommands, with rounds, tickets and uploads in memory. Lena reported
 * the ticket; Omar's picture is ready in her organization and her own one is ready in Beta Bakery.
 */
final class ScreenshotReportOnNewTicketTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000f02';
    private const string SHOP = '0197a3c0-0000-7000-8000-000000000f11';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000001098';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string READY_MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string PENDING_MEDIA = '0197a3c0-0000-7000-8000-000000000d03';
    private const string REJECTED_MEDIA = '0197a3c0-0000-7000-8000-000000000d04';
    private const string OMARS_MEDIA = '0197a3c0-0000-7000-8000-000000000d05';
    private const string LENAS_MEDIA_AT_BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000d06';

    private InMemoryRounds $rounds;
    private InMemoryUploadedMedia $uploadedMedia;
    private ScreenshotReportOnNewTicket $report;
    private \DateTimeImmutable $reportedAt;

    protected function setUp(): void
    {
        $this->rounds = new InMemoryRounds();
        $this->uploadedMedia = new InMemoryUploadedMedia([
            [self::ACME_FOODS, self::READY_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::PENDING_MEDIA, self::LENA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::REJECTED_MEDIA, self::LENA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_MEDIA, self::OMAR, MediaImageStatus::Ready],
            [self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, self::LENA, MediaImageStatus::Ready],
        ]);
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $roundCommands = new UseCaseRoundCommands(
            $this->rounds,
            new InMemoryTicketTurnQueries([self::snapshot()], new TicketId(self::BROKEN_TICKET)),
            $tenantContext,
            $this->uploadedMedia,
        );
        $this->report = new ScreenshotReportOnNewTicket($roundCommands, $this->uploadedMedia);
        $this->reportedAt = new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
    }

    #[Test]
    public function noScreenshotPostedIsFineAndSavesNothing(): void
    {
        $posted = self::posted('', '  ', "\r\n", []);

        self::assertNull($this->refusalOf($posted));
        $this->attach($posted);

        self::assertSame([], $this->rounds->stored);
        self::assertSame([], $this->uploadedMedia->askedMediaIds);
    }

    /**
     * Pins come by their posted number, whatever order the fields were sent in, and are numbered from 1 with no gap.
     */
    #[Test]
    public function aReadyPictureOfTheReporterIsSavedAsRoundZeroWithItsPinsInTheOrderOfTheirPostedNumber(): void
    {
        $posted = self::posted(strtoupper(self::READY_MEDIA), ' Checkout page ', '', [
            5 => ['x' => '100', 'y' => '0.00', 'note' => 'Logo is cut'],
            2 => ['x' => '50.25', 'y' => '75.50', 'note' => ''],
            1 => ['x' => '12.50', 'y' => '80.00', 'note' => ' Total is off '],
        ]);

        self::assertNull($this->refusalOf($posted));
        $this->attach($posted);

        self::assertCount(1, $this->rounds->stored);
        $round = $this->rounds->stored[0];
        self::assertSame(Round::REPORT_NUMBER, $round->number());
        self::assertSame(self::TICKET, $round->ticketId()->toString());
        self::assertSame(self::LENA, $round->ownerId()->toString());
        self::assertSame($this->reportedAt, $round->sharedAt());
        [$screenshot] = $round->screenshots();
        self::assertSame(self::READY_MEDIA, $screenshot->mediaId());
        self::assertSame('Checkout page', $screenshot->title());
        self::assertNull($screenshot->summary());
        self::assertSame(
            [[1, 12.5, 80.0, 'Total is off'], [2, 50.25, 75.5, ''], [3, 100.0, 0.0, 'Logo is cut']],
            array_map(static fn (Pin $pin): array => [$pin->number(), $pin->xPercent(), $pin->yPercent(), $pin->note()], $screenshot->pins()),
        );
    }

    #[Test]
    public function aMissingNoteIsAnEmptyOne(): void
    {
        $this->attach(self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '10', 'y' => '20']]));

        self::assertSame([''], array_map(static fn (Pin $pin): string => $pin->note(), $this->rounds->stored[0]->screenshots()[0]->pins()));
    }

    /**
     * A textarea posts its line breaks as CRLF but its maxlength counts each as one character: 998 characters, a line
     * break and one more are 1,000 characters, at the note's cap, and 1,001 as sent.
     */
    #[Test]
    public function lineBreaksPostedAsCrlfAreKeptAsOneCharacterEach(): void
    {
        $note = str_repeat('é', 998)."\r\n".'é';

        $this->attach(self::posted(self::READY_MEDIA, '', "Totals\r\nare wrong\rtwice", [1 => ['x' => '10', 'y' => '20', 'note' => $note]]));

        $screenshot = $this->rounds->stored[0]->screenshots()[0];
        self::assertSame("Totals\nare wrong\ntwice", $screenshot->summary());
        self::assertSame(str_repeat('é', 998)."\n".'é', $screenshot->pins()[0]->note());
    }

    #[Test]
    public function exactlyTheMaximumNumberOfPinsIsSaved(): void
    {
        $this->attach(self::posted(self::READY_MEDIA, '', '', self::pins(RoundCommands::MAX_PINS_PER_SCREENSHOT)));

        self::assertCount(RoundCommands::MAX_PINS_PER_SCREENSHOT, $this->rounds->stored[0]->screenshots()[0]->pins());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function postsThatCannotBeRead(): iterable
    {
        yield 'no media id field' => [['title' => '', 'summary' => '', 'pins' => []], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a media id that is a list' => [['media_id' => [self::READY_MEDIA], 'title' => '', 'summary' => '', 'pins' => []], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'no title field' => [['media_id' => self::READY_MEDIA, 'summary' => '', 'pins' => []], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'pins that are not a list' => [self::posted(self::READY_MEDIA, '', '', 'pin'), ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'no pins field' => [['media_id' => self::READY_MEDIA, 'title' => '', 'summary' => ''], ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a pin that is not a list' => [self::posted(self::READY_MEDIA, '', '', [1 => '10,20']), ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a pin number that is no number' => [self::posted(self::READY_MEDIA, '', '', ['first' => ['x' => '10', 'y' => '20', 'note' => '']]), ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a title that is not UTF-8' => [self::posted(self::READY_MEDIA, "Caf\xE9", '', []), ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a note with a NUL byte' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '10', 'y' => '20', 'note' => "Total\0is off"]]), ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'a note that is a list' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '10', 'y' => '20', 'note' => ['Total']]]), ScreenshotReportOnNewTicket::REPORT_UNREADABLE];
        yield 'an x that is no number' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => 'left', 'y' => '20', 'note' => '']]), ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'an x that is a list' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => ['10'], 'y' => '20', 'note' => '']]), ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'an x in exponent notation' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '1e1', 'y' => '20', 'note' => '']]), ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'a negative y' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '10', 'y' => '-1', 'note' => '']]), ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'a y with a trailing newline' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '10', 'y' => "20\n", 'note' => '']]), ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'no y' => [self::posted(self::READY_MEDIA, '', '', [1 => ['x' => '10', 'note' => '']]), ScreenshotReportOnNewTicket::PIN_SPOT_UNREADABLE];
        yield 'one pin more than the cap' => [self::posted(self::READY_MEDIA, '', '', self::pins(RoundCommands::MAX_PINS_PER_SCREENSHOT + 1)), ScreenshotReportOnNewTicket::TOO_MANY_PINS];
        yield 'a title with no screenshot' => [self::posted('', 'Checkout page', '', []), ScreenshotReportOnNewTicket::CONTENT_WITHOUT_SCREENSHOT];
        yield 'a summary with no screenshot' => [self::posted('', '', 'Totals are wrong', []), ScreenshotReportOnNewTicket::CONTENT_WITHOUT_SCREENSHOT];
        yield 'a pin with no screenshot' => [self::posted('', '', '', [1 => ['x' => '10', 'y' => '20', 'note' => '']]), ScreenshotReportOnNewTicket::CONTENT_WITHOUT_SCREENSHOT];
        yield 'too many pins with no screenshot' => [self::posted('', '', '', self::pins(RoundCommands::MAX_PINS_PER_SCREENSHOT + 1)), ScreenshotReportOnNewTicket::TOO_MANY_PINS];
    }

    /**
     * @param array<mixed> $posted
     */
    #[Test]
    #[DataProvider('postsThatCannotBeRead')]
    public function aPostThatCannotBeReadGetsAPlainMessageAndSavesNothing(array $posted, string $message): void
    {
        self::assertSame($message, $this->refusalOf($posted));
        self::assertSame($message, $this->attachRefusal($posted)->getMessage());

        self::assertSame([], $this->rounds->stored);
        self::assertSame([], $this->uploadedMedia->askedMediaIds);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function picturesAndTheirAnswers(): iterable
    {
        yield 'the reporter\'s ready picture' => [self::READY_MEDIA, null];
        yield 'the reporter\'s picture still processing' => [self::PENDING_MEDIA, RoundRefused::MEDIA_STILL_PROCESSING];
        yield 'the reporter\'s rejected picture' => [self::REJECTED_MEDIA, RoundRefused::MEDIA_REJECTED];
        yield 'another person\'s ready picture' => [self::OMARS_MEDIA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'the reporter\'s picture in another organization' => [self::LENAS_MEDIA_AT_BETA_BAKERY, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a malformed media id' => ['not-a-media-id', RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    #[Test]
    #[DataProvider('picturesAndTheirAnswers')]
    public function theRefusalTellsWhetherThePictureIsTheReportersAndProcessed(string $mediaId, ?string $refusal): void
    {
        self::assertSame($refusal, $this->refusalOf(self::posted($mediaId, '', '', [])));
        self::assertSame([[$mediaId]], $this->uploadedMedia->askedMediaIds);
    }

    /**
     * Attach goes through createReport's own check, so a caller that skips refusalOf still cannot save the picture.
     */
    #[Test]
    public function attachingAnotherPersonsPictureIsRefusedWithTheRoundsReasonAndSavesNothing(): void
    {
        $refused = $this->attachRefusal(self::posted(self::OMARS_MEDIA, '', '', [1 => ['x' => '10', 'y' => '20', 'note' => 'Total is off']]));

        self::assertSame(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU, $refused->getMessage());
        self::assertInstanceOf(RoundRefused::class, $refused->getPrevious());
        self::assertSame([], $this->rounds->stored);
        self::assertSame(0, $this->rounds->issuedPinIds);
    }

    /**
     * The title rules need no ticket but are left to createReport, so refusalOf lets the title through.
     */
    #[Test]
    public function aTitleOverTheCapIsRefusedByAttachWithTheRoundsReason(): void
    {
        $posted = self::posted(self::READY_MEDIA, str_repeat('é', 121), '', []);

        self::assertNull($this->refusalOf($posted));
        self::assertSame('A picture title holds at most 120 characters.', $this->attachRefusal($posted)->getMessage());
        self::assertSame([], $this->rounds->stored);
    }

    #[Test]
    public function aTitleAtTheCapInMultibyteTextIsSaved(): void
    {
        $this->attach(self::posted(self::READY_MEDIA, str_repeat('é', 120), '', []));

        self::assertSame(str_repeat('é', 120), $this->rounds->stored[0]->screenshots()[0]->title());
    }

    #[Test]
    public function aSecondReportOnTheTicketIsRefusedAndTheFirstIsKept(): void
    {
        $this->attach(self::posted(self::READY_MEDIA, 'First', '', []));

        self::assertSame('This ticket has a report already.', $this->attachRefusal(self::posted(self::READY_MEDIA, 'Second', '', []))->getMessage());
        self::assertCount(1, $this->rounds->stored);
        self::assertSame('First', $this->rounds->stored[0]->screenshots()[0]->title());
    }

    /**
     * @param array<mixed> $posted
     */
    private function refusalOf(array $posted): ?string
    {
        return $this->report->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $posted);
    }

    /**
     * @param array<mixed> $posted
     */
    private function attach(array $posted): void
    {
        $this->report->attach(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET), $posted, $this->reportedAt);
    }

    /**
     * @param array<mixed> $posted
     */
    private function attachRefusal(array $posted): NewTicketReportRefused
    {
        try {
            $this->attach($posted);
        } catch (NewTicketReportRefused $refused) {
            return $refused;
        }

        self::fail('The report was attached.');
    }

    /**
     * @return array{media_id: string, title: string, summary: string, pins: mixed}
     */
    private static function posted(string $mediaId, string $title, string $summary, mixed $pins): array
    {
        return ['media_id' => $mediaId, 'title' => $title, 'summary' => $summary, 'pins' => $pins];
    }

    /**
     * @return array<int, array{x: string, y: string, note: string}>
     */
    private static function pins(int $count): array
    {
        $pins = [];
        for ($number = 1; $number <= $count; ++$number) {
            $pins[$number] = ['x' => '10.00', 'y' => '20.00', 'note' => ''];
        }

        return $pins;
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
