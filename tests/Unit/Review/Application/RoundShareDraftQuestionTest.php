<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Api\RoundRefused;
use App\Review\Application\ReportPictures;
use App\Review\Application\RoundShareDraftQuestion;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\UndecidedDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Review's answer to "Share your draft round?" over InMemoryRounds, which keeps DbalRounds' version check and number
 * slots. Omar owns the draft with two screenshots that the question is about; Lena's draft on the same ticket, Mia's
 * shared round 1 and Omar's draft on another ticket are the rows each answer must leave alone.
 */
final class RoundShareDraftQuestionTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000002101';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000002102';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000002198';
    private const string LENA = '0197a3c0-0000-7000-8000-000000002b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002b02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002b03';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000002b04';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000002d01';

    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-000000002e01';
    private const string LENAS_DRAFT = '0197a3c0-0000-7000-8000-000000002e02';
    private const string MIAS_SHARED_ROUND = '0197a3c0-0000-7000-8000-000000002e03';
    private const string OMARS_OTHER_TICKET_DRAFT = '0197a3c0-0000-7000-8000-000000002e04';
    private const string RAJS_EMPTY_DRAFT = '0197a3c0-0000-7000-8000-000000002e05';

    private TenantContext $tenantContext;
    private InMemoryRounds $rounds;
    private RoundShareDraftQuestion $question;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->rounds = new InMemoryRounds($this->tenantContext);
        $commands = new UseCaseRoundCommands(
            $this->rounds,
            new InMemoryTicketTurnQueries([], new TicketId(self::BROKEN_TICKET)),
            $this->tenantContext,
            new ReportPictures(new InMemoryUploadedMedia($this->tenantContext, [])),
        );
        $this->question = new RoundShareDraftQuestion($this->rounds, $commands);
        $this->seedRounds();
    }

    #[Test]
    public function theQuestionIsAboutTheOwnersDraftWithItsScreenshotCountAndTheNextRoundNumber(): void
    {
        $draft = $this->question->undecidedDraftOf(new TicketId(self::TICKET), new UserId(self::OMAR));

        self::assertEquals(new UndecidedDraft(self::OMARS_DRAFT, 2, 2), $draft, 'Mia\'s round 1 makes Omar\'s the second.');
        self::assertSame([self::TICKET], $this->rounds->numberedTicketIds, 'The number is read under the lock sharing takes.');
        self::assertSame(0, $this->rounds->loadedRounds, 'Only the draft\'s facts are read, not its screenshots and pins.');
    }

    /**
     * @return iterable<string, array{string, string}> who asks, on which ticket
     */
    public static function ownersWithNoQuestion(): iterable
    {
        yield 'a draft with no screenshot' => [self::RAJ, self::TICKET];
        yield 'only a shared round' => [self::MIA, self::TICKET];
        yield 'no draft on this ticket' => [self::LENA, self::OTHER_TICKET];
    }

    #[Test]
    #[DataProvider('ownersWithNoQuestion')]
    public function thereIsNoQuestionWithoutAnUndecidedDraftWithAScreenshot(string $owner, string $ticketId): void
    {
        self::assertNull($this->question->undecidedDraftOf(new TicketId($ticketId), new UserId($owner)));
        self::assertNull($this->question->undecidedDraftIdOf(new TicketId($ticketId), new UserId($owner)));
        self::assertSame([], $this->rounds->numberedTicketIds, 'No number is locked when nothing is asked.');
        self::assertSame(0, $this->rounds->loadedRounds, 'Only the draft\'s facts are read, not its screenshots and pins.');
    }

    #[Test]
    public function aMoveWithItsAnswerGetsTheDraftIdWithoutReadingOrLockingANumber(): void
    {
        self::assertSame(self::OMARS_DRAFT, $this->question->undecidedDraftIdOf(new TicketId(self::TICKET), new UserId(self::OMAR)));
        self::assertSame([], $this->rounds->numberedTicketIds);
        self::assertSame(0, $this->rounds->loadedRounds, 'Only the draft\'s facts are read, not its screenshots and pins.');
    }

    #[Test]
    public function aDecidedDraftIsNotAskedAboutAgain(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->question->undecidedDraftOf(new TicketId(self::TICKET), $omar);
        self::assertNotNull($draft);
        $this->question->keepPrivate($draft->roundId, $omar, self::at());
        $this->rounds->numberedTicketIds = [];

        self::assertNull($this->question->undecidedDraftOf(new TicketId(self::TICKET), $omar));
        self::assertNull($this->question->undecidedDraftIdOf(new TicketId(self::TICKET), $omar));
        self::assertSame([], $this->rounds->numberedTicketIds, 'No number is locked for a decided draft.');
    }

    #[Test]
    public function sharingGivesTheDraftTheNextNumberAndRecordsSharedAndLeavesTheOtherRoundsAlone(): void
    {
        $before = $this->snapshot();
        $draft = $this->undecidedDraftOfOmar();
        $this->rounds->loadedRounds = 0;
        $this->rounds->savedRounds = 0;

        $number = $this->question->share($draft->roundId, new UserId(self::OMAR), self::at());

        self::assertSame(2, $number);
        self::assertSame([1, 1], [$this->rounds->loadedRounds, $this->rounds->savedRounds], 'The draft is read and saved once, shared and decided together.');
        $after = $this->snapshot();
        self::assertSame(
            [...$before[self::OMARS_DRAFT], 'number' => 2, 'sharedAt' => self::at()->format('c'), 'version' => $before[self::OMARS_DRAFT]['version'] + 2, 'decision' => 'shared', 'decidedAt' => self::at()->format('c')],
            $after[self::OMARS_DRAFT],
        );
        self::assertSame(array_diff_key($before, [self::OMARS_DRAFT => true]), array_diff_key($after, [self::OMARS_DRAFT => true]));
    }

    #[Test]
    public function keepingPrivateRecordsKeptPrivateWithNoNumberAndLeavesTheOtherRoundsAlone(): void
    {
        $before = $this->snapshot();
        $draft = $this->undecidedDraftOfOmar();

        $this->question->keepPrivate($draft->roundId, new UserId(self::OMAR), self::at());

        $after = $this->snapshot();
        self::assertSame(
            [...$before[self::OMARS_DRAFT], 'version' => $before[self::OMARS_DRAFT]['version'] + 1, 'decision' => 'kept_private', 'decidedAt' => self::at()->format('c')],
            $after[self::OMARS_DRAFT],
        );
        self::assertNull($after[self::OMARS_DRAFT]['sharedAt']);
        self::assertSame(array_diff_key($before, [self::OMARS_DRAFT => true]), array_diff_key($after, [self::OMARS_DRAFT => true]));
    }

    #[Test]
    public function anotherPersonCannotAnswerForTheOwnersDraft(): void
    {
        $draft = $this->undecidedDraftOfOmar();
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share($draft->roundId, new UserId(self::LENA), self::at()), RoundRefused::class);
        $this->assertChangedMeanwhile(fn () => $this->question->keepPrivate($draft->roundId, new UserId(self::LENA), self::at()), \DomainException::class);

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function keepingPrivateADraftDecidedSinceItWasReadIsRefusedAndChangesNothing(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $this->question->keepPrivate($draft->roundId, $omar, self::at());
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->keepPrivate($draft->roundId, $omar, self::at()), \DomainException::class);

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function sharingADraftSharedSinceItWasReadIsRefusedAndChangesNothing(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $this->question->share($draft->roundId, $omar, self::at());
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share($draft->roundId, $omar, self::at()), RoundRefused::class);

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function sharingADraftKeptPrivateSinceItWasReadIsRefusedAndNeitherSharesNorNumbersIt(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $this->question->keepPrivate($draft->roundId, $omar, self::at());
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share($draft->roundId, $omar, self::at()), RoundRefused::class);

        self::assertSame($before, $this->snapshot());
        self::assertNull($this->snapshot()[self::OMARS_DRAFT]['sharedAt']);
    }

    #[Test]
    public function sharingADraftSavedByAnotherRequestBetweenItsReadAndItsSaveIsRefused(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $interfering = new InterferingRounds($this->rounds);
        $interfering->afterNextGet = self::editOmarsDraftMeanwhile(...);
        $question = $this->questionOver($interfering);
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(static fn () => $question->share($draft->roundId, $omar, self::at()), RoundRefused::class);

        $after = $this->snapshot()[self::OMARS_DRAFT];
        self::assertSame([null, null, null], [$after['number'], $after['sharedAt'], $after['decision']]);
        self::assertSame($before[self::OMARS_DRAFT]['version'] + 1, $after['version'], 'Only the other request\'s edit is saved.');
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>|null}> the round id, and the refusal's cause
     */
    public static function draftIdsThatAreGone(): iterable
    {
        yield 'a malformed id' => ['not-a-round', \InvalidArgumentException::class];
        yield 'an id no round has' => ['0197a3c0-0000-7000-8000-000000002e99', null];
    }

    /**
     * @param class-string<\Throwable>|null $expectedCause
     */
    #[Test]
    #[DataProvider('draftIdsThatAreGone')]
    public function keepingPrivateADraftThatIsGoneIsRefusedAndChangesNothing(string $roundId, ?string $expectedCause): void
    {
        $before = $this->snapshot();

        try {
            $this->question->keepPrivate($roundId, new UserId(self::OMAR), self::at());
            self::fail('Keeping a gone draft private was not refused.');
        } catch (TicketChangedMeanwhile $refused) {
            self::assertSame('The draft round is gone.', $refused->getMessage());
            self::assertSame($expectedCause, null === $refused->getPrevious() ? null : $refused->getPrevious()::class);
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->rounds->savedRounds);
    }

    #[Test]
    public function sharingADraftThatIsGoneIsRefusedAndChangesNothing(): void
    {
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share('not-a-round', new UserId(self::OMAR), self::at()), RoundRefused::class);

        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->rounds->savedRounds);
    }

    #[Test]
    public function aDraftSavedByAnotherRequestBetweenItsReadAndTheDecisionIsRefused(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $interfering = new InterferingRounds($this->rounds);
        $interfering->afterNextGet = self::editOmarsDraftMeanwhile(...);
        $question = $this->questionOver($interfering);
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(static fn () => $question->keepPrivate($draft->roundId, $omar, self::at()), RoundChanged::class);

        self::assertNull($this->snapshot()[self::OMARS_DRAFT]['decision']);
        self::assertSame($before[self::OMARS_DRAFT]['version'] + 1, $this->snapshot()[self::OMARS_DRAFT]['version'], 'Only the other request\'s edit is saved.');
    }

    private static function editOmarsDraftMeanwhile(Rounds $rounds): void
    {
        $stored = $rounds->get(new RoundId(self::OMARS_DRAFT)) ?? throw new \LogicException('Omar\'s draft is seeded.');
        $stored->editScreenshotText(new UserId(self::OMAR), new ScreenshotId('0197a3c0-0000-7000-8000-000000002a01'), 'Edited meanwhile', null);
        $rounds->save($stored, self::at());
    }

    private function questionOver(Rounds $rounds): RoundShareDraftQuestion
    {
        return new RoundShareDraftQuestion($rounds, new UseCaseRoundCommands(
            $rounds,
            new InMemoryTicketTurnQueries([], new TicketId(self::BROKEN_TICKET)),
            $this->tenantContext,
            new ReportPictures(new InMemoryUploadedMedia($this->tenantContext, [])),
        ));
    }

    private function undecidedDraftOfOmar(): UndecidedDraft
    {
        return $this->question->undecidedDraftOf(new TicketId(self::TICKET), new UserId(self::OMAR)) ?? throw new \LogicException('Omar has an undecided draft.');
    }

    /**
     * @param \Closure(): mixed        $answer
     * @param class-string<\Throwable> $expectedCause
     */
    private function assertChangedMeanwhile(\Closure $answer, string $expectedCause): void
    {
        try {
            $answer();
            self::fail('The answer was not refused.');
        } catch (TicketChangedMeanwhile $refused) {
            self::assertInstanceOf($expectedCause, $refused->getPrevious());
        }
    }

    private function seedRounds(): void
    {
        $omar = new UserId(self::OMAR);
        $omarsDraft = self::draftOn(self::OMARS_DRAFT, self::TICKET, self::OMAR);
        $omarsDraft->addScreenshot($omar, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a01'), self::MEDIA);
        $omarsDraft->addScreenshot($omar, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a02'), self::MEDIA);
        $this->rounds->add($omarsDraft);

        $lena = new UserId(self::LENA);
        $lenasDraft = self::draftOn(self::LENAS_DRAFT, self::TICKET, self::LENA);
        $lenasDraft->addScreenshot($lena, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a03'), self::MEDIA);
        $this->rounds->add($lenasDraft);

        $mia = new UserId(self::MIA);
        $miasShared = self::draftOn(self::MIAS_SHARED_ROUND, self::TICKET, self::MIA);
        $miasShared->addScreenshot($mia, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a04'), self::MEDIA);
        $miasShared->share($mia, 1, self::at());
        $this->rounds->add($miasShared);

        $otherTicketDraft = self::draftOn(self::OMARS_OTHER_TICKET_DRAFT, self::OTHER_TICKET, self::OMAR);
        $otherTicketDraft->addScreenshot($omar, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a05'), self::MEDIA);
        $this->rounds->add($otherTicketDraft);

        $this->rounds->add(self::draftOn(self::RAJS_EMPTY_DRAFT, self::TICKET, self::RAJ));
    }

    /**
     * @return array<string, array{number: int|null, sharedAt: string|null, version: int, decision: string|null, decidedAt: string|null, screenshots: int}>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->rounds->stored as $round) {
            $snapshot[$round->id()->toString()] = [
                'number' => $round->number(),
                'sharedAt' => $round->sharedAt()?->format('c'),
                'version' => $round->version(),
                'decision' => $round->decision()?->value,
                'decidedAt' => $round->decidedAt()?->format('c'),
                'screenshots' => \count($round->screenshots()),
            ];
        }

        return $snapshot;
    }

    private static function draftOn(string $roundId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId($ownerId), new \DateTimeImmutable('2026-03-03 08:00:00+00:00'));
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
    }
}
