<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Application\ReportPictures;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
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

/**
 * The draft commands of UseCaseRoundCommands over InMemoryRounds, which keeps what DbalRounds keeps: a copy per saved
 * round, the version check and the number and draft slots. Every refusal is proven with the reason and with the stored
 * rounds exactly as they were. Lena owns the draft the commands change; Omar's draft, Mia's shared and deleted drafts,
 * another organization's draft, a report and a deleted draft of Lena's are the rows each one must leave alone.
 */
final class UseCaseDraftRoundCommandsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000f02';
    private const string SHOP = '0197a3c0-0000-7000-8000-000000000f11';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000001002';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-000000001099';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000001098';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';

    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string OTHER_MEDIA = '0197a3c0-0000-7000-8000-000000000d02';
    private const string PENDING_MEDIA = '0197a3c0-0000-7000-8000-000000000d03';
    private const string REJECTED_MEDIA = '0197a3c0-0000-7000-8000-000000000d04';
    private const string OMARS_MEDIA = '0197a3c0-0000-7000-8000-000000000d05';
    private const string UNKNOWN_MEDIA = '0197a3c0-0000-7000-8000-000000000d09';

    private const string DRAFT = '0197a3c0-0000-7000-8000-000000000e01';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-000000000e02';
    private const string MIAS_SHARED_DRAFT = '0197a3c0-0000-7000-8000-000000000e03';
    private const string MIAS_DELETED_DRAFT = '0197a3c0-0000-7000-8000-000000000e04';
    private const string LENAS_DELETED_DRAFT = '0197a3c0-0000-7000-8000-000000000e05';
    private const string BETA_BAKERY_DRAFT = '0197a3c0-0000-7000-8000-000000000e06';
    private const string REPORT = '0197a3c0-0000-7000-8000-000000000e07';
    private const string FULL_DRAFT = '0197a3c0-0000-7000-8000-000000000e08';
    private const string OMARS_SHARED_ROUND = '0197a3c0-0000-7000-8000-000000000e09';
    private const string BETA_BAKERY_SHARED_ROUND = '0197a3c0-0000-7000-8000-000000000e0a';
    private const string UNKNOWN_ROUND = '0197a3c0-0000-7000-8000-000000000e99';

    private const string FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000a01';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000a02';
    private const string OMARS_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000a03';
    private const string MIAS_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000a04';
    private const string CONCURRENT_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000a05';
    private const string UNKNOWN_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000a99';
    private const string OLD_PIN = '0197a3c0-0000-7000-8000-000000000c01';

    private InMemoryRounds $rounds;
    private InterferingRounds $interfering;
    private InMemoryTicketTurnQueries $tickets;
    private InMemoryUploadedMedia $uploadedMedia;
    private UseCaseRoundCommands $commands;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->rounds = new InMemoryRounds($this->tenantContext);
        $this->interfering = new InterferingRounds($this->rounds);
        $this->tickets = new InMemoryTicketTurnQueries([self::ticketFacts(self::TICKET), self::ticketFacts(self::OTHER_TICKET)], new TicketId(self::BROKEN_TICKET));
        $this->uploadedMedia = new InMemoryUploadedMedia($this->tenantContext, [
            [self::ACME_FOODS, self::MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::MEDIA, self::MIA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::OTHER_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::PENDING_MEDIA, self::LENA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::REJECTED_MEDIA, self::LENA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_MEDIA, self::OMAR, MediaImageStatus::Ready],
        ]);
        $this->commands = $this->commandsOver($this->interfering);
        $this->seedRounds();
    }

    #[Test]
    public function startingADraftStoresAnEmptyPrivateDraftOfTheOwnerAndLeavesTheOtherRoundsAlone(): void
    {
        $before = $this->snapshot();

        $roundId = $this->commands->startDraft(new TicketId(self::TICKET), new UserId(self::MIA), self::at());

        $started = $this->storedRound($roundId);
        self::assertSame(RoundKind::Draft, $started->kind());
        self::assertSame(self::MIA, $started->ownerId()->toString());
        self::assertSame(self::TICKET, $started->ticketId()->toString());
        self::assertSame(self::ACME_FOODS, $started->organizationId()->toString());
        self::assertNull($started->number());
        self::assertNull($started->sharedAt());
        self::assertSame(RoundState::Open, $started->state());
        self::assertEquals(self::at(), $started->createdAt());
        self::assertSame([], $started->screenshots());
        self::assertCount(\count($before) + 1, $this->rounds->stored);
        self::assertSame($before, array_diff_key($this->snapshot(), [$roundId => true]), 'Mia\'s shared and deleted drafts must not have blocked or changed.');
    }

    #[Test]
    public function startingASecondOpenDraftOnTheTicketIsRefusedAndStoresNothing(): void
    {
        $this->assertRefusedAndNothingSaved(RoundRefused::DRAFT_EXISTS, fn () => $this->commands->startDraft(new TicketId(self::TICKET), new UserId(self::LENA), self::at()));
    }

    #[Test]
    public function startingADraftOnAnotherTicketIsNotBlockedByTheOpenDraftOfThisOne(): void
    {
        $roundId = $this->commands->startDraft(new TicketId(self::OTHER_TICKET), new UserId(self::OMAR), self::at());

        self::assertSame(self::OTHER_TICKET, $this->storedRound($roundId)->ticketId()->toString());
    }

    #[Test]
    public function aDraftStartedByAnotherRequestBetweenTheCheckAndTheAddIsRefusedAsAnExistingDraft(): void
    {
        $this->interfering->hidesOpenDrafts = true;

        $refused = $this->assertRefusedAndNothingSaved(RoundRefused::DRAFT_EXISTS, fn () => $this->commands->startDraft(new TicketId(self::TICKET), new UserId(self::LENA), self::at()));

        self::assertInstanceOf(RoundSlotTaken::class, $refused->getPrevious());
    }

    #[Test]
    public function startingADraftOnATicketItCannotFindIsRefusedAndStoresNothing(): void
    {
        $before = $this->snapshot();

        try {
            $this->commands->startDraft(new TicketId(self::UNKNOWN_TICKET), new UserId(self::LENA), self::at());
            self::fail('A draft was started on a missing ticket.');
        } catch (TicketNotFound $notFound) {
            self::assertSame('That ticket does not exist.', $notFound->getMessage());
        }

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function startingADraftWithNoOrganizationSetFailsAndStoresNothing(): void
    {
        $commands = new UseCaseRoundCommands($this->rounds, $this->tickets, new TenantContext(new RecordingTenantSwitchGuard()), new ReportPictures($this->uploadedMedia));
        $before = $this->snapshot();

        try {
            $commands->startDraft(new TicketId(self::TICKET), new UserId(self::MIA), self::at());
            self::fail('A draft was started with no tenant.');
        } catch (TenantNotSet) {
            self::assertSame([], $this->tickets->askedTicketIds);
        }

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function addingAPictureAppendsItAfterTheLastOneWithNothingElseOnIt(): void
    {
        $before = $this->snapshot();
        $versionBefore = $this->storedRound(self::DRAFT)->version();

        $screenshotId = $this->commands->addDraftScreenshot(self::DRAFT, new UserId(self::LENA), strtoupper(self::OTHER_MEDIA), self::at());

        $draft = $this->storedRound(self::DRAFT);
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT, $screenshotId], self::screenshotIdsOf($draft));
        $added = $draft->screenshots()[2];
        self::assertSame(self::OTHER_MEDIA, $added->mediaId());
        self::assertSame(2, $added->position());
        self::assertNull($added->title());
        self::assertNull($added->summary());
        self::assertNull($added->verdict());
        self::assertSame([], $added->pins());
        self::assertSame($versionBefore + 1, $draft->version());
        self::assertSame([[strtoupper(self::OTHER_MEDIA)]], $this->uploadedMedia->askedMediaIds);
        self::assertSame(array_diff_key($before, [self::DRAFT => true]), array_diff_key($this->snapshot(), [self::DRAFT => true]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function picturesTheOwnerCannotAdd(): iterable
    {
        yield 'another person\'s ready picture' => [self::OMARS_MEDIA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'an unknown picture' => [self::UNKNOWN_MEDIA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a malformed id' => ['not-a-media-id', RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a picture still processing' => [self::PENDING_MEDIA, RoundRefused::MEDIA_STILL_PROCESSING];
        yield 'a rejected picture' => [self::REJECTED_MEDIA, RoundRefused::MEDIA_REJECTED];
    }

    #[Test]
    #[DataProvider('picturesTheOwnerCannotAdd')]
    public function addingAPictureTheOwnerCannotUseIsRefusedAndStoresNothing(string $mediaId, string $reason): void
    {
        $this->assertRefusedAndNothingSaved($reason, fn () => $this->commands->addDraftScreenshot(self::DRAFT, new UserId(self::LENA), $mediaId, self::at()));
    }

    #[Test]
    public function addingAPictureToADraftThatHoldsTheMaximumIsRefusedAndStoresNothing(): void
    {
        self::assertCount(Round::MAX_SCREENSHOTS, $this->storedRound(self::FULL_DRAFT)->screenshots());

        $this->assertRefusedAndNothingSaved('A round holds at most 50 screenshots.', fn () => $this->commands->addDraftScreenshot(self::FULL_DRAFT, new UserId(self::LENA), self::MEDIA, self::at()));
    }

    #[Test]
    public function removingAPictureTakesItWithItsPinsAndTheOthersKeepTheirPlaces(): void
    {
        $before = $this->snapshot();
        $versionBefore = $this->storedRound(self::DRAFT)->version();

        $this->commands->removeDraftScreenshot(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, self::at());

        $draft = $this->storedRound(self::DRAFT);
        self::assertSame([self::SECOND_SCREENSHOT], self::screenshotIdsOf($draft));
        self::assertSame(1, $draft->screenshots()[0]->position());
        self::assertSame($versionBefore + 1, $draft->version());
        self::assertSame(array_diff_key($before, [self::DRAFT => true]), array_diff_key($this->snapshot(), [self::DRAFT => true]));
    }

    #[Test]
    public function editingAPictureTrimsItsTextAndTurnsABlankOneIntoNone(): void
    {
        $this->commands->editDraftScreenshot(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, '  New title ', '   ', self::at());

        [$edited, $untouched] = $this->storedRound(self::DRAFT)->screenshots();
        self::assertSame('New title', $edited->title());
        self::assertNull($edited->summary());
        self::assertCount(1, $edited->pins(), 'Editing the text must keep the pins.');
        self::assertNull($untouched->title());
    }

    #[Test]
    public function editingAPictureAcceptsTheLongestTitleAndSummary(): void
    {
        $this->commands->editDraftScreenshot(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, str_repeat('é', 120), str_repeat('é', 600), self::at());

        $edited = $this->storedRound(self::DRAFT)->screenshots()[0];
        self::assertSame(120, mb_strlen((string) $edited->title()));
        self::assertSame(600, mb_strlen((string) $edited->summary()));
    }

    /**
     * @return iterable<string, array{string|null, string|null, string}>
     */
    public static function textsOverTheirCap(): iterable
    {
        yield 'a title over 120 characters' => [str_repeat('é', 121), null, 'A picture title holds at most 120 characters.'];
        yield 'a summary over 600 characters' => ['Fine', str_repeat('é', 601), 'A picture summary holds at most 600 characters.'];
    }

    #[Test]
    #[DataProvider('textsOverTheirCap')]
    public function editingAPictureWithATextOverItsCapIsRefusedAndStoresNothing(?string $title, ?string $summary, string $reason): void
    {
        $this->assertRefusedAndNothingSaved($reason, fn () => $this->commands->editDraftScreenshot(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, $title, $summary, self::at()));
    }

    #[Test]
    public function settingPinsReplacesTheOldOnesAndNumbersTheNewOnesFromOneInOrder(): void
    {
        $before = $this->snapshot();

        $this->commands->setDraftPins(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, [new PlacedPin(70.0, 5.5, ' Second spot '), new PlacedPin(0.0, 100.0, '')], self::at());

        [$pinned, $untouched] = $this->storedRound(self::DRAFT)->screenshots();
        $pins = $pinned->pins();
        self::assertSame([1, 2], array_map(static fn (Pin $pin): int => $pin->number(), $pins));
        self::assertSame([70.0, 0.0], array_map(static fn (Pin $pin): float => $pin->xPercent(), $pins));
        self::assertSame([5.5, 100.0], array_map(static fn (Pin $pin): float => $pin->yPercent(), $pins));
        self::assertSame(['Second spot', ''], array_map(static fn (Pin $pin): string => $pin->note(), $pins));
        self::assertSame([self::LENA, self::LENA], array_map(static fn (Pin $pin): string => $pin->authorId()->toString(), $pins));
        self::assertNotContains(self::OLD_PIN, array_map(static fn (Pin $pin): string => $pin->id()->toString(), $pins));
        self::assertSame([], $untouched->pins());
        self::assertSame(array_diff_key($before, [self::DRAFT => true]), array_diff_key($this->snapshot(), [self::DRAFT => true]));
    }

    #[Test]
    public function settingNoPinsClearsThePicturesPins(): void
    {
        self::assertNotSame([], $this->storedRound(self::DRAFT)->screenshots()[0]->pins());

        $this->commands->setDraftPins(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, [], self::at());

        self::assertSame([], $this->storedRound(self::DRAFT)->screenshots()[0]->pins());
    }

    #[Test]
    public function settingExactlyTheMaximumNumberOfPinsIsAccepted(): void
    {
        $this->commands->setDraftPins(self::DRAFT, new UserId(self::LENA), self::SECOND_SCREENSHOT, array_fill(0, Screenshot::MAX_PINS, new PlacedPin(10.0, 10.0, '')), self::at());

        self::assertCount(Screenshot::MAX_PINS, $this->storedRound(self::DRAFT)->screenshots()[1]->pins());
    }

    /**
     * It answers for a round that does not exist too, which shows the cap is checked before the round is read.
     */
    #[Test]
    public function settingMoreThanTheMaximumNumberOfPinsIsRefusedBeforeTheRoundIsRead(): void
    {
        $pins = array_fill(0, Screenshot::MAX_PINS + 1, new PlacedPin(10.0, 10.0, ''));

        $this->assertRefusedAndNothingSaved('A picture holds at most 100 pins.', fn () => $this->commands->setDraftPins(self::UNKNOWN_ROUND, new UserId(self::LENA), self::FIRST_SCREENSHOT, $pins, self::at()));
        $this->assertRefusedAndNothingSaved('A picture holds at most 100 pins.', fn () => $this->commands->setDraftPins(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, $pins, self::at()));
    }

    /**
     * @return iterable<string, array{PlacedPin, string}>
     */
    public static function pinsTheDraftRefuses(): iterable
    {
        yield 'a pin below the picture' => [new PlacedPin(10.0, 100.5, ''), 'A pin must sit inside the picture: x and y go from 0 to 100.'];
        yield 'a pin left of the picture' => [new PlacedPin(-0.5, 10.0, ''), 'A pin must sit inside the picture: x and y go from 0 to 100.'];
        yield 'a note over 1000 characters' => [new PlacedPin(10.0, 10.0, str_repeat('é', 1001)), 'A pin note holds at most 1000 characters.'];
    }

    #[Test]
    #[DataProvider('pinsTheDraftRefuses')]
    public function settingAPinTheDomainRefusesLeavesTheOldPinsInPlace(PlacedPin $pin, string $reason): void
    {
        $this->assertRefusedAndNothingSaved($reason, fn () => $this->commands->setDraftPins(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, [new PlacedPin(1.0, 1.0, 'fine'), $pin], self::at()));
        self::assertSame([self::OLD_PIN], array_map(static fn (Pin $pin): string => $pin->id()->toString(), $this->storedRound(self::DRAFT)->screenshots()[0]->pins()));
    }

    /**
     * The first picture starts at "Looks right" and the second at none, so each answer changes or clears something.
     *
     * @return iterable<string, array{string|null, ScreenshotVerdict|null, int}>
     */
    public static function verdictsTheOwnerCanSet(): iterable
    {
        yield '"Looks right" on a picture with none' => [RoundCommands::VERDICT_LOOKS_RIGHT, ScreenshotVerdict::LooksRight, 1];
        yield '"Not right yet" over "Looks right"' => [RoundCommands::VERDICT_NOT_RIGHT_YET, ScreenshotVerdict::NotRight, 0];
        yield 'none, which clears "Looks right"' => [null, null, 0];
    }

    #[Test]
    #[DataProvider('verdictsTheOwnerCanSet')]
    public function settingAVerdictStoresItOnThatPictureOnly(?string $verdict, ?ScreenshotVerdict $expected, int $index): void
    {
        $screenshots = $this->storedRound(self::DRAFT)->screenshots();
        self::assertSame(ScreenshotVerdict::LooksRight, $screenshots[0]->verdict());
        self::assertNull($screenshots[1]->verdict());
        $versionBefore = $this->storedRound(self::DRAFT)->version();

        $this->commands->setDraftVerdict(self::DRAFT, new UserId(self::LENA), $screenshots[$index]->id()->toString(), $verdict, self::at());

        $changed = $this->storedRound(self::DRAFT)->screenshots();
        self::assertSame($expected, $changed[$index]->verdict());
        self::assertSame($screenshots[1 - $index]->verdict(), $changed[1 - $index]->verdict());
        self::assertSame($versionBefore + 1, $this->storedRound(self::DRAFT)->version());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function verdictsThatAreNone(): iterable
    {
        yield 'a made-up verdict' => ['maybe'];
        yield 'an empty text' => [''];
        yield 'the right words in another case' => ['LOOKS_RIGHT'];
    }

    #[Test]
    #[DataProvider('verdictsThatAreNone')]
    public function settingAVerdictThatIsNeitherOfTheTwoIsRefusedAndStoresNothing(string $verdict): void
    {
        $this->assertRefusedAndNothingSaved(RoundRefused::UNKNOWN_VERDICT, fn () => $this->commands->setDraftVerdict(self::DRAFT, new UserId(self::LENA), self::FIRST_SCREENSHOT, $verdict, self::at()));
    }

    #[Test]
    public function sharingGivesTheDraftTheTicketsNextRoundNumberUnderTheNumberLock(): void
    {
        $before = $this->snapshot();

        $number = $this->commands->shareDraft(self::DRAFT, new UserId(self::LENA), self::at());

        // The report is 0 and Mia's shared draft 1; Omar's round 7 is on another ticket and Beta Bakery's 9 on another organization's.
        self::assertSame(2, $number);
        $shared = $this->storedRound(self::DRAFT);
        self::assertSame(2, $shared->number());
        self::assertEquals(self::at(), $shared->sharedAt());
        self::assertSame(RoundState::Open, $shared->state());
        self::assertSame([self::TICKET], $this->rounds->numberedTicketIds);
        self::assertSame(array_diff_key($before, [self::DRAFT => true]), array_diff_key($this->snapshot(), [self::DRAFT => true]));
    }

    #[Test]
    public function twoSharesOnOneTicketGetTwoNumbers(): void
    {
        $first = $this->commands->shareDraft(self::DRAFT, new UserId(self::LENA), self::at());
        $second = $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at());

        self::assertSame([2, 3], [$first, $second]);
    }

    #[Test]
    public function sharingOnAnotherTicketCountsThatTicketsRoundsOnly(): void
    {
        self::assertSame(8, $this->commands->shareDraft(self::FULL_DRAFT, new UserId(self::LENA), self::at()));
    }

    #[Test]
    public function aSharedDraftFreesTheOwnersDraftSlotOnTheTicket(): void
    {
        $this->commands->shareDraft(self::DRAFT, new UserId(self::LENA), self::at());

        $newDraftId = $this->commands->startDraft(new TicketId(self::TICKET), new UserId(self::LENA), self::at());

        self::assertSame(RoundKind::Draft, $this->storedRound($newDraftId)->kind());
    }

    #[Test]
    public function aNumberAnotherRoundTookBetweenTheReadAndTheSaveIsRefusedAsNumberTaken(): void
    {
        // Mia's shared draft holds number 1, the number a read made before it committed would have given.
        $this->interfering->staleNextNumber = 1;

        $refused = $this->assertRefusedAndNothingSaved(RoundRefused::NUMBER_TAKEN, fn () => $this->commands->shareDraft(self::DRAFT, new UserId(self::LENA), self::at()));

        self::assertInstanceOf(RoundSlotTaken::class, $refused->getPrevious());
        self::assertNull($this->storedRound(self::DRAFT)->number());
    }

    #[Test]
    public function deletingClosesTheDraftKeepsItsPicturesAndFreesTheOwnersSlot(): void
    {
        $before = $this->snapshot();
        $versionBefore = $this->storedRound(self::DRAFT)->version();

        $this->commands->deleteDraft(self::DRAFT, new UserId(self::LENA), self::at());

        $deleted = $this->storedRound(self::DRAFT);
        self::assertSame(RoundState::Closed, $deleted->state());
        self::assertEquals(self::at(), $deleted->deletedAt());
        self::assertNull($deleted->sharedAt());
        self::assertNull($deleted->number());
        self::assertSame($versionBefore + 1, $deleted->version());
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT], self::screenshotIdsOf($deleted));
        self::assertSame(array_diff_key($before, [self::DRAFT => true]), array_diff_key($this->snapshot(), [self::DRAFT => true]));
        $newDraftId = $this->commands->startDraft(new TicketId(self::TICKET), new UserId(self::LENA), self::at());
        self::assertSame(RoundKind::Draft, $this->storedRound($newDraftId)->kind());
    }

    #[Test]
    public function deletingADraftTwiceRefusesTheSecondAsNotFoundAndChangesNothing(): void
    {
        $this->commands->deleteDraft(self::DRAFT, new UserId(self::LENA), self::at());

        $this->assertRefusedAndNothingSaved(RoundRefused::NOT_FOUND, fn () => $this->commands->deleteDraft(self::DRAFT, new UserId(self::LENA), new \DateTimeImmutable('2026-03-05 10:00:00+00:00')));
    }

    /**
     * @return iterable<string, array{\Closure(RoundCommands, string, string, string): mixed}>
     */
    public static function draftChanges(): iterable
    {
        yield 'addDraftScreenshot' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId): mixed => $commands->addDraftScreenshot($roundId, new UserId($ownerId), self::MEDIA, self::at())];
        yield 'shareDraft' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId): mixed => $commands->shareDraft($roundId, new UserId($ownerId), self::at())];
        yield 'deleteDraft' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId) => $commands->deleteDraft($roundId, new UserId($ownerId), self::at())];
        yield from self::screenshotChanges();
    }

    /**
     * @return iterable<string, array{\Closure(RoundCommands, string, string, string): mixed}>
     */
    public static function screenshotChanges(): iterable
    {
        yield 'removeDraftScreenshot' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId) => $commands->removeDraftScreenshot($roundId, new UserId($ownerId), $screenshotId, self::at())];
        yield 'editDraftScreenshot' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId) => $commands->editDraftScreenshot($roundId, new UserId($ownerId), $screenshotId, 'Title', null, self::at())];
        yield 'setDraftPins' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId) => $commands->setDraftPins($roundId, new UserId($ownerId), $screenshotId, [new PlacedPin(1.0, 1.0, 'Note')], self::at())];
        yield 'setDraftVerdict' => [static fn (RoundCommands $commands, string $roundId, string $ownerId, string $screenshotId) => $commands->setDraftVerdict($roundId, new UserId($ownerId), $screenshotId, RoundCommands::VERDICT_NOT_RIGHT_YET, self::at())];
    }

    /**
     * @return iterable<string, array{\Closure(RoundCommands, string, string, string): mixed, string}>
     */
    public static function roundsThatAreNotTheOwnersOpenDraft(): iterable
    {
        $notFound = [
            'a malformed round id' => 'not-a-round-id',
            'an unknown round' => self::UNKNOWN_ROUND,
            'another person\'s draft' => self::OMARS_DRAFT,
            'another organization\'s draft' => self::BETA_BAKERY_DRAFT,
            'a report round' => self::REPORT,
            'the owner\'s deleted draft' => self::LENAS_DELETED_DRAFT,
        ];
        foreach (self::draftChanges() as $command => [$change]) {
            foreach ($notFound as $case => $roundId) {
                yield $command.' on '.$case => [$change, $roundId];
            }
        }
    }

    /**
     * One reason for every kind of round that is not Lena's open draft, and the media, the numbers and the picture are
     * not looked at before it.
     *
     * @param \Closure(RoundCommands, string, string, string): mixed $change
     */
    #[Test]
    #[DataProvider('roundsThatAreNotTheOwnersOpenDraft')]
    public function aCommandOnARoundThatIsNotTheOwnersOpenDraftIsRefusedAsNotFoundAndChangesNothing(\Closure $change, string $roundId): void
    {
        $this->assertRefusedAndNothingSaved(RoundRefused::NOT_FOUND, fn () => $change($this->commands, $roundId, self::LENA, self::FIRST_SCREENSHOT));

        self::assertSame([], $this->uploadedMedia->askedMediaIds);
        self::assertSame([], $this->rounds->numberedTicketIds);
    }

    /**
     * @return iterable<string, array{\Closure(RoundCommands, string, string, string): mixed, string}>
     */
    public static function changesOfASharedDraft(): iterable
    {
        foreach (self::draftChanges() as $command => [$change]) {
            yield $command => [$change, 'shareDraft' === $command ? 'This draft round is shared already.' : 'This draft round is shared, so it no longer changes.'];
        }
    }

    /**
     * @param \Closure(RoundCommands, string, string, string): mixed $change
     */
    #[Test]
    #[DataProvider('changesOfASharedDraft')]
    public function aCommandOnADraftThatIsSharedIsRefusedAndChangesNothing(\Closure $change, string $reason): void
    {
        self::assertNotNull($this->storedRound(self::MIAS_SHARED_DRAFT)->sharedAt());

        $this->assertRefusedAndNothingSaved($reason, fn () => $change($this->commands, self::MIAS_SHARED_DRAFT, self::MIA, self::MIAS_SCREENSHOT));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screenshotsOfNoSuchDraft(): iterable
    {
        yield 'a malformed screenshot id' => ['not-a-screenshot-id'];
        yield 'an unknown screenshot' => [self::UNKNOWN_SCREENSHOT];
        yield 'a screenshot of another round' => [self::OMARS_SCREENSHOT];
    }

    #[Test]
    #[DataProvider('screenshotsOfNoSuchDraft')]
    public function aPictureCommandOnAScreenshotThatIsNotInTheDraftIsRefusedAndChangesNothing(string $screenshotId): void
    {
        foreach (self::screenshotChanges() as $command => [$change]) {
            $before = $this->snapshot();
            try {
                $change($this->commands, self::DRAFT, self::LENA, $screenshotId);
                self::fail($command.' changed a screenshot that is not in the draft.');
            } catch (RoundRefused $refused) {
                self::assertSame(RoundRefused::SCREENSHOT_NOT_FOUND, $refused->getMessage(), $command);
            }
            self::assertSame($before, $this->snapshot(), $command);
        }
    }

    /**
     * @param \Closure(RoundCommands, string, string, string): mixed $change
     */
    #[Test]
    #[DataProvider('draftChanges')]
    public function aCommandOnADraftAnotherRequestSavedInTheMeantimeIsRefusedAsChangedAndSavesNothingOfItsOwn(\Closure $change): void
    {
        $this->interfering->afterNextGet = static function (Rounds $rounds): void {
            $draft = $rounds->get(new RoundId(self::DRAFT)) ?? throw new \LogicException('The seeded draft is missing.');
            $draft->addScreenshot(new UserId(self::LENA), new ScreenshotId(self::CONCURRENT_SCREENSHOT), self::MEDIA);
            $rounds->save($draft, self::at());
        };
        $versionBefore = $this->storedRound(self::DRAFT)->version();

        try {
            $change($this->commands, self::DRAFT, self::LENA, self::FIRST_SCREENSHOT);
            self::fail('The command saved over a draft that changed after it was read.');
        } catch (RoundRefused $refused) {
            self::assertSame(RoundRefused::DRAFT_CHANGED, $refused->getMessage());
            self::assertInstanceOf(RoundChanged::class, $refused->getPrevious());
        }

        $draft = $this->storedRound(self::DRAFT);
        self::assertSame($versionBefore + 1, $draft->version(), 'Only the other request\'s save may have landed.');
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT, self::CONCURRENT_SCREENSHOT], self::screenshotIdsOf($draft));
        self::assertNull($draft->deletedAt());
        self::assertNull($draft->sharedAt());
        self::assertSame([self::OLD_PIN], array_map(static fn (Pin $pin): string => $pin->id()->toString(), $draft->screenshots()[0]->pins()));
        self::assertSame(ScreenshotVerdict::LooksRight, $draft->screenshots()[0]->verdict());
        self::assertSame('Cart', $draft->screenshots()[0]->title());
    }

    private function commandsOver(Rounds $rounds): UseCaseRoundCommands
    {
        return new UseCaseRoundCommands($rounds, $this->tickets, $this->tenantContext, new ReportPictures($this->uploadedMedia));
    }

    private function seedRounds(): void
    {
        $lena = new UserId(self::LENA);
        $draft = self::draftOn(self::DRAFT, self::ACME_FOODS, self::TICKET, self::LENA);
        $draft->addScreenshot($lena, new ScreenshotId(self::FIRST_SCREENSHOT), self::MEDIA);
        $draft->editScreenshotText($lena, new ScreenshotId(self::FIRST_SCREENSHOT), 'Cart', null);
        $draft->replacePins($lena, new ScreenshotId(self::FIRST_SCREENSHOT), [Pin::placedBy(new PinId(self::OLD_PIN), 1, 10.0, 20.0, 'old note', $lena)]);
        $draft->setVerdict($lena, new ScreenshotId(self::FIRST_SCREENSHOT), ScreenshotVerdict::LooksRight);
        $draft->addScreenshot($lena, new ScreenshotId(self::SECOND_SCREENSHOT), self::OTHER_MEDIA);
        $this->rounds->add($draft);

        $omarsDraft = self::draftOn(self::OMARS_DRAFT, self::ACME_FOODS, self::TICKET, self::OMAR);
        $omarsDraft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::OMARS_SCREENSHOT), self::OMARS_MEDIA);
        $this->rounds->add($omarsDraft);

        $miasShared = self::draftOn(self::MIAS_SHARED_DRAFT, self::ACME_FOODS, self::TICKET, self::MIA);
        $miasShared->addScreenshot(new UserId(self::MIA), new ScreenshotId(self::MIAS_SCREENSHOT), self::MEDIA);
        $miasShared->share(new UserId(self::MIA), 1, self::at());
        $this->rounds->add($miasShared);

        $miasDeleted = self::draftOn(self::MIAS_DELETED_DRAFT, self::ACME_FOODS, self::TICKET, self::MIA);
        $miasDeleted->delete(new UserId(self::MIA), self::at());
        $this->rounds->add($miasDeleted);

        $lenasDeleted = self::draftOn(self::LENAS_DELETED_DRAFT, self::ACME_FOODS, self::TICKET, self::LENA);
        $lenasDeleted->delete($lena, self::at());
        $this->rounds->add($lenasDeleted);

        $this->rounds->add(self::draftOn(self::BETA_BAKERY_DRAFT, self::BETA_BAKERY, self::TICKET, self::LENA));
        $betaShared = self::draftOn(self::BETA_BAKERY_SHARED_ROUND, self::BETA_BAKERY, self::TICKET, self::OMAR);
        $betaShared->share(new UserId(self::OMAR), 9, self::at());
        $this->rounds->add($betaShared);

        $this->rounds->add(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $lena, [
            Screenshot::of(new ScreenshotId('0197a3c0-0000-7000-8000-000000000a06'), self::MEDIA, 0, null, null, []),
        ], self::at()));

        $full = self::draftOn(self::FULL_DRAFT, self::ACME_FOODS, self::OTHER_TICKET, self::LENA);
        for ($index = 0; $index < Round::MAX_SCREENSHOTS; ++$index) {
            $full->addScreenshot($lena, new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xB00 + $index)), self::MEDIA);
        }
        $this->rounds->add($full);

        $omarsSharedElsewhere = self::draftOn(self::OMARS_SHARED_ROUND, self::ACME_FOODS, self::OTHER_TICKET, self::OMAR);
        $omarsSharedElsewhere->share(new UserId(self::OMAR), 7, self::at());
        $this->rounds->add($omarsSharedElsewhere);
    }

    /**
     * Everything a command may change in a stored round, by round id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->rounds->stored as $round) {
            $snapshot[$round->id()->toString()] = [
                'organization' => $round->organizationId()->toString(),
                'kind' => $round->kind()->value,
                'number' => $round->number(),
                'sharedAt' => $round->sharedAt()?->format('c'),
                'state' => $round->state()->value,
                'version' => $round->version(),
                'deletedAt' => $round->deletedAt()?->format('c'),
                'screenshots' => array_map(static fn (Screenshot $screenshot): array => [
                    $screenshot->id()->toString(),
                    $screenshot->mediaId(),
                    $screenshot->position(),
                    $screenshot->title(),
                    $screenshot->summary(),
                    $screenshot->verdict()?->value,
                    array_map(static fn (Pin $pin): array => [$pin->id()->toString(), $pin->number(), $pin->xPercent(), $pin->yPercent(), $pin->note(), $pin->authorId()->toString()], $screenshot->pins()),
                ], $round->screenshots()),
            ];
        }

        return $snapshot;
    }

    /**
     * @param \Closure(): mixed $action
     */
    private function assertRefusedAndNothingSaved(string $reason, \Closure $action): RoundRefused
    {
        $before = $this->snapshot();
        self::assertNotSame([], $before);
        $refusal = null;
        try {
            $action();
        } catch (RoundRefused $refused) {
            $refusal = $refused;
        }

        self::assertNotNull($refusal, 'The command was accepted; expected: '.$reason);
        self::assertSame($reason, $refusal->getMessage());
        self::assertSame($before, $this->snapshot());

        return $refusal;
    }

    private function storedRound(string $roundId): Round
    {
        foreach ($this->rounds->stored as $round) {
            if ($round->id()->toString() === $roundId) {
                return $round;
            }
        }

        throw new \LogicException('No stored round '.$roundId);
    }

    /**
     * @return list<string>
     */
    private static function screenshotIdsOf(Round $round): array
    {
        return array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $round->screenshots());
    }

    private static function draftOn(string $roundId, string $organizationId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId($organizationId), new TicketId($ticketId), new UserId($ownerId), new \DateTimeImmutable('2026-03-03 08:00:00+00:00'));
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
    }

    private static function ticketFacts(string $ticketId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
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
