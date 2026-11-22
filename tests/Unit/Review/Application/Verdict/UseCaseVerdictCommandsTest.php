<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\Verdict;

use App\Review\Api\Event\AreaVerdictSaved;
use App\Review\Api\Event\PinVerdictSet;
use App\Review\Api\Event\PinVerdictUndone;
use App\Review\Api\Event\ScreenshotVerdictSaved;
use App\Review\Api\VerdictCommands;
use App\Review\Api\VerdictRefused;
use App\Review\Application\Verdict\UseCaseVerdictCommands;
use App\Review\Domain\AiPinState;
use App\Review\Domain\Area;
use App\Review\Domain\AreaId;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\Application\RecordingReviewEvents;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UseCaseVerdictCommands over InMemoryAreas, which keeps what DbalAreas keeps: the tenant's rows only, an area in its
 * own round only, a pin only in the rounds that compare its picture, and the conditional pin write. Mia gives the
 * verdicts in Acme Foods' open review round; a closed round, a report, an unshared and a deleted draft, another round's
 * area and pin, and Beta Bakery's round with the same ids are the rows each refusal must leave alone. Every refusal is
 * proven with its reason, the stored areas and pins exactly as they were, and no event.
 */
final class UseCaseVerdictCommandsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007702';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000007711';
    private const string LENA = '0197a3c0-0000-7000-8000-000000007721';
    private const string MIA = '0197a3c0-0000-7000-8000-000000007722';

    private const string REVIEW = '0197a3c0-0000-7000-8000-000000007731';
    private const string OTHER_REVIEW = '0197a3c0-0000-7000-8000-000000007732';
    private const string CLOSED_REVIEW = '0197a3c0-0000-7000-8000-000000007733';
    private const string REPORT = '0197a3c0-0000-7000-8000-000000007734';
    private const string UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-000000007735';
    private const string DELETED_DRAFT = '0197a3c0-0000-7000-8000-000000007736';
    private const string SHARED_DRAFT = '0197a3c0-0000-7000-8000-000000007737';
    private const string BETA_REVIEW = '0197a3c0-0000-7000-8000-000000007738';
    private const string UNKNOWN_ROUND = '0197a3c0-0000-7000-8000-0000000077ff';

    private const string AREA = '0197a3c0-0000-7000-8000-000000007741';
    private const string DECIDED_AREA = '0197a3c0-0000-7000-8000-000000007742';
    private const string OTHER_REVIEW_AREA = '0197a3c0-0000-7000-8000-000000007743';
    private const string BETA_AREA = '0197a3c0-0000-7000-8000-000000007744';
    private const string UNKNOWN_AREA = '0197a3c0-0000-7000-8000-0000000077fe';

    private const string HUMAN_PIN = '0197a3c0-0000-7000-8000-000000007751';
    private const string JUDGED_PIN = '0197a3c0-0000-7000-8000-000000007752';
    private const string KEPT_AI_PIN = '0197a3c0-0000-7000-8000-000000007753';
    private const string SUGGESTED_AI_PIN = '0197a3c0-0000-7000-8000-000000007754';
    private const string DISMISSED_AI_PIN = '0197a3c0-0000-7000-8000-000000007755';
    private const string OTHER_REVIEW_PIN = '0197a3c0-0000-7000-8000-000000007756';
    private const string BETA_PIN = '0197a3c0-0000-7000-8000-000000007757';
    private const string UNKNOWN_PIN = '0197a3c0-0000-7000-8000-0000000077fd';

    private const string BEFORE = '0197a3c0-0000-7000-8000-000000007761';
    private const string AFTER = '0197a3c0-0000-7000-8000-000000007762';
    private const string SECOND_AFTER = '0197a3c0-0000-7000-8000-000000007763';
    private const string OTHER_AFTER = '0197a3c0-0000-7000-8000-000000007764';

    private const string DRAFT_SHOT = '0197a3c0-0000-7000-8000-000000007771';
    private const string DRAFT_EARLIER_TRY = '0197a3c0-0000-7000-8000-000000007772';
    private const string OTHER_DRAFT_SHOT = '0197a3c0-0000-7000-8000-000000007773';
    private const string OTHER_DRAFT = '0197a3c0-0000-7000-8000-000000007739';

    private const string DECIDED_AT = '2027-03-15 16:00:00+00:00';
    private const string NOW = '2027-03-16 09:30:00+00:00';

    private InMemoryAreas $areas;
    private RecordingReviewEvents $events;
    private TenantContext $tenantContext;
    private UseCaseVerdictCommands $commands;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->areas = new InMemoryAreas($this->tenantContext);
        $this->events = new RecordingReviewEvents();
        $this->commands = new UseCaseVerdictCommands($this->areas, $this->events, $this->tenantContext);

        $this->areas->addRound(self::ACME_FOODS, self::round(self::REVIEW, RoundKind::Review));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::OTHER_REVIEW, RoundKind::Review));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::CLOSED_REVIEW, RoundKind::Review, state: RoundState::Closed));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::REPORT, RoundKind::Report));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::UNSHARED_DRAFT, RoundKind::Draft, isShared: false));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::DELETED_DRAFT, RoundKind::Draft, isShared: false, state: RoundState::Closed, isDeleted: true));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::SHARED_DRAFT, RoundKind::Draft));
        $this->areas->addRound(self::BETA_BAKERY, self::round(self::BETA_REVIEW, RoundKind::Review));
        $this->areas->addRound(self::ACME_FOODS, self::round(self::OTHER_DRAFT, RoundKind::Draft));
        // DRAFT_EARLIER_TRY is a try of the shared draft that a retake replaced, so its page does not show it.
        $this->areas->addShownScreenshot(self::ACME_FOODS, self::SHARED_DRAFT, self::DRAFT_SHOT);
        $this->areas->addShownScreenshot(self::ACME_FOODS, self::OTHER_DRAFT, self::OTHER_DRAFT_SHOT);

        $this->areas->addArea(self::ACME_FOODS, self::area(self::AREA, self::REVIEW, self::AFTER));
        $this->areas->addArea(self::ACME_FOODS, self::area(self::DECIDED_AREA, self::REVIEW, self::SECOND_AFTER)->decide(new UserId(self::LENA), AreaVerdict::Pass, 'Looks right now', new \DateTimeImmutable(self::DECIDED_AT)));
        $this->areas->addArea(self::ACME_FOODS, self::area(self::OTHER_REVIEW_AREA, self::OTHER_REVIEW, self::OTHER_AFTER));
        $this->areas->addArea(self::BETA_BAKERY, self::area(self::BETA_AREA, self::BETA_REVIEW, self::AFTER));

        $checkedInAllAcmeRounds = [self::REVIEW, self::CLOSED_REVIEW, self::REPORT, self::UNSHARED_DRAFT, self::DELETED_DRAFT, self::SHARED_DRAFT];
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::HUMAN_PIN, PinSource::Human, null, null), $checkedInAllAcmeRounds);
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::JUDGED_PIN, PinSource::Human, null, PinVerdict::Fixed), $checkedInAllAcmeRounds);
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::KEPT_AI_PIN, PinSource::Ai, AiPinState::Kept, null), [self::REVIEW]);
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::SUGGESTED_AI_PIN, PinSource::Ai, AiPinState::Suggested, null), [self::REVIEW]);
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::DISMISSED_AI_PIN, PinSource::Ai, AiPinState::Dismissed, null), [self::REVIEW]);
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::OTHER_REVIEW_PIN, PinSource::Human, null, null), [self::OTHER_REVIEW]);
        $this->areas->addPin(self::BETA_BAKERY, self::pin(self::BETA_PIN, PinSource::Human, null, null), [self::BETA_REVIEW]);
    }

    #[Test]
    public function aPinGetsFixedAfterTheRoundIsLockedAndOneEventSaysSo(): void
    {
        $this->commands->setPinVerdict(self::REVIEW, self::HUMAN_PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW));

        self::assertSame(PinVerdict::Fixed, $this->areas->storedPin(self::ACME_FOODS, self::HUMAN_PIN)?->verdict());
        self::assertSame(['lockRound', 'pinToCheckOf', 'savePinVerdict'], $this->areas->calls);
        self::assertEquals(
            [new PinVerdictSet(self::ACME_FOODS, self::TICKET, self::REVIEW, self::HUMAN_PIN, 'fixed', self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
    }

    #[Test]
    public function aKeptAiPinIsStillWrongInASharedDraftAndTheOtherPinsStayAsTheyWere(): void
    {
        $this->areas->addPin(self::ACME_FOODS, self::pin(self::KEPT_AI_PIN, PinSource::Ai, AiPinState::Kept, null), [self::SHARED_DRAFT]);
        $before = $this->areas->snapshot();

        $this->commands->setPinVerdict(self::SHARED_DRAFT, self::KEPT_AI_PIN, new UserId(self::MIA), VerdictCommands::PIN_STILL_WRONG, new \DateTimeImmutable(self::NOW));

        $expected = $before;
        $expected[1][self::ACME_FOODS][self::KEPT_AI_PIN] = self::pin(self::KEPT_AI_PIN, PinSource::Ai, AiPinState::Kept, PinVerdict::StillWrong);
        self::assertEquals($expected, $this->areas->snapshot());
        self::assertEquals(
            [new PinVerdictSet(self::ACME_FOODS, self::TICKET, self::SHARED_DRAFT, self::KEPT_AI_PIN, 'still_wrong', self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
    }

    #[Test]
    public function anUppercasePinIdFindsThePinAndTheEventCarriesItsCanonicalForm(): void
    {
        $this->commands->setPinVerdict(strtoupper(self::REVIEW), strtoupper(self::HUMAN_PIN), new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW));

        self::assertSame(PinVerdict::Fixed, $this->areas->storedPin(self::ACME_FOODS, self::HUMAN_PIN)?->verdict());
        $event = $this->events->published()[0] ?? null;
        self::assertInstanceOf(PinVerdictSet::class, $event);
        self::assertSame([self::REVIEW, self::HUMAN_PIN], [$event->roundId, $event->pinId]);
    }

    #[Test]
    public function undoingClearsThePinsVerdictAndTheEventNamesTheVerdictUndone(): void
    {
        $this->commands->undoPinVerdict(self::REVIEW, self::JUDGED_PIN, new UserId(self::MIA), new \DateTimeImmutable(self::NOW));

        self::assertNull($this->areas->storedPin(self::ACME_FOODS, self::JUDGED_PIN)?->verdict());
        self::assertNotNull($this->areas->storedPin(self::ACME_FOODS, self::JUDGED_PIN));
        self::assertSame(['lockRound', 'pinToCheckOf', 'savePinVerdict'], $this->areas->calls);
        self::assertEquals(
            [new PinVerdictUndone(self::ACME_FOODS, self::TICKET, self::REVIEW, self::JUDGED_PIN, 'fixed', self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
    }

    #[Test]
    public function anAreaVerdictIsSavedWithItsTrimmedNoteAndTheEventSaysThereIsANoteButNotWhatItSays(): void
    {
        $this->commands->saveAreaVerdict(self::REVIEW, self::AREA, new UserId(self::MIA), VerdictCommands::AREA_NEEDS_FIX, "  The total is still off\r\n", new \DateTimeImmutable(self::NOW));

        $stored = $this->areas->storedArea(self::ACME_FOODS, self::AREA);
        self::assertNotNull($stored);
        self::assertSame(
            [AreaVerdict::NeedsFix, 'The total is still off', self::MIA, self::NOW],
            [$stored->verdict(), $stored->note(), $stored->decidedBy()?->toString(), $stored->decidedAt()?->format('Y-m-d H:i:sP')],
        );
        self::assertSame(['lockRound', 'areaOf', 'saveVerdict'], $this->areas->calls);
        self::assertEquals(
            [new AreaVerdictSaved(self::ACME_FOODS, self::TICKET, self::REVIEW, self::AREA, 'needs_fix', true, self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
        self::assertStringNotContainsString('total', serialize($this->events->published()));
    }

    #[Test]
    public function aNewAreaVerdictReplacesTheEarlierOneAndABlankNoteIsNone(): void
    {
        $this->commands->saveAreaVerdict(self::REVIEW, self::DECIDED_AREA, new UserId(self::MIA), VerdictCommands::AREA_QUESTION, " \n ", new \DateTimeImmutable(self::NOW));

        $stored = $this->areas->storedArea(self::ACME_FOODS, self::DECIDED_AREA);
        self::assertNotNull($stored);
        self::assertSame(
            [AreaVerdict::Question, null, self::MIA, self::NOW],
            [$stored->verdict(), $stored->note(), $stored->decidedBy()?->toString(), $stored->decidedAt()?->format('Y-m-d H:i:sP')],
        );
        self::assertEquals(
            [new AreaVerdictSaved(self::ACME_FOODS, self::TICKET, self::REVIEW, self::DECIDED_AREA, 'question', false, self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
    }

    #[Test]
    public function aNoteOfExactlyTheCapIsSaved(): void
    {
        $note = str_repeat('é', Area::NOTE_MAX_LENGTH);

        $this->commands->saveAreaVerdict(self::REVIEW, self::AREA, new UserId(self::MIA), VerdictCommands::AREA_PASS, $note, new \DateTimeImmutable(self::NOW));

        self::assertSame($note, $this->areas->storedArea(self::ACME_FOODS, self::AREA)?->note());
    }

    /**
     * @return iterable<string, array{\Closure(VerdictCommands, string): void}>
     */
    public static function everyCommand(): iterable
    {
        yield 'a pin verdict' => [static fn (VerdictCommands $commands, string $roundId) => $commands->setPinVerdict($roundId, self::HUMAN_PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW))];
        yield 'an undo' => [static fn (VerdictCommands $commands, string $roundId) => $commands->undoPinVerdict($roundId, self::JUDGED_PIN, new UserId(self::MIA), new \DateTimeImmutable(self::NOW))];
        yield 'an area verdict' => [static fn (VerdictCommands $commands, string $roundId) => $commands->saveAreaVerdict($roundId, self::AREA, new UserId(self::MIA), VerdictCommands::AREA_PASS, 'Fine', new \DateTimeImmutable(self::NOW))];
        yield 'a screenshot verdict' => [static fn (VerdictCommands $commands, string $roundId) => $commands->saveScreenshotVerdict($roundId, self::DRAFT_SHOT, new UserId(self::MIA), VerdictCommands::AREA_PASS, 'Fine', new \DateTimeImmutable(self::NOW))];
    }

    /**
     * @return iterable<string, array{\Closure(VerdictCommands, string): void, string, string}>
     */
    public static function everyCommandInARoundThatTakesNoVerdicts(): iterable
    {
        $rounds = [
            'a malformed round id' => ['round-1', VerdictRefused::NOT_FOUND],
            'a round id with a trailing newline' => [self::REVIEW."\n", VerdictRefused::NOT_FOUND],
            'an unknown round' => [self::UNKNOWN_ROUND, VerdictRefused::NOT_FOUND],
            'another organization\'s round' => [self::BETA_REVIEW, VerdictRefused::NOT_FOUND],
            'a closed round' => [self::CLOSED_REVIEW, ReviewedRound::CLOSED],
            'a report' => [self::REPORT, ReviewedRound::TAKES_NO_VERDICTS],
            'an unshared draft' => [self::UNSHARED_DRAFT, ReviewedRound::TAKES_NO_VERDICTS],
            'a deleted draft' => [self::DELETED_DRAFT, ReviewedRound::TAKES_NO_VERDICTS],
        ];
        foreach (self::everyCommand() as $commandName => [$command]) {
            foreach ($rounds as $roundName => [$roundId, $reason]) {
                yield $commandName.' in '.$roundName => [$command, $roundId, $reason];
            }
        }
    }

    /**
     * @param \Closure(VerdictCommands, string): void $command
     */
    #[Test]
    #[DataProvider('everyCommandInARoundThatTakesNoVerdicts')]
    public function aRoundThatTakesNoVerdictsIsRefusedWithItsReasonAndNothingChanges(\Closure $command, string $roundId, string $reason): void
    {
        $this->assertRefusedAndUnchanged($reason, fn () => $command($this->commands, $roundId));
    }

    /**
     * The round is locked before the pin is looked at, so the round's own refusal wins over a pin the round does not
     * take; nothing else is read.
     */
    #[Test]
    public function aClosedRoundIsRefusedBeforeThePinIsRead(): void
    {
        $this->assertRefusedAndUnchanged(ReviewedRound::CLOSED, fn () => $this->commands->setPinVerdict(self::CLOSED_REVIEW, self::UNKNOWN_PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW)));

        self::assertSame(['lockRound'], $this->areas->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pinsTheRoundDoesNotCheck(): iterable
    {
        yield 'a malformed pin id' => ['pin-1'];
        yield 'a pin id with a trailing newline' => [self::HUMAN_PIN."\n"];
        yield 'an unknown pin' => [self::UNKNOWN_PIN];
        yield 'a pin another round checks' => [self::OTHER_REVIEW_PIN];
        yield 'another organization\'s pin' => [self::BETA_PIN];
    }

    #[Test]
    #[DataProvider('pinsTheRoundDoesNotCheck')]
    public function aPinVerdictOnAPinTheRoundDoesNotCheckIsNotFound(string $pinId): void
    {
        $this->assertRefusedAndUnchanged(VerdictRefused::PIN_NOT_FOUND, fn () => $this->commands->setPinVerdict(self::REVIEW, $pinId, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    #[DataProvider('pinsTheRoundDoesNotCheck')]
    public function anUndoOnAPinTheRoundDoesNotCheckIsNotFound(string $pinId): void
    {
        $this->assertRefusedAndUnchanged(VerdictRefused::PIN_NOT_FOUND, fn () => $this->commands->undoPinVerdict(self::REVIEW, $pinId, new UserId(self::MIA), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPinVerdicts(): iterable
    {
        yield 'an empty one' => [''];
        yield 'another word' => ['maybe'];
        yield 'an area verdict' => [VerdictCommands::AREA_PASS];
        yield 'a known one in capitals' => ['FIXED'];
    }

    #[Test]
    #[DataProvider('unknownPinVerdicts')]
    public function anUnknownPinVerdictIsRefused(string $verdict): void
    {
        $this->assertRefusedAndUnchanged(VerdictRefused::UNKNOWN_PIN_VERDICT, fn () => $this->commands->setPinVerdict(self::REVIEW, self::HUMAN_PIN, new UserId(self::MIA), $verdict, new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aiPinsNobodyKept(): iterable
    {
        yield 'a suggested AI pin' => [self::SUGGESTED_AI_PIN];
        yield 'a dismissed AI pin' => [self::DISMISSED_AI_PIN];
    }

    #[Test]
    #[DataProvider('aiPinsNobodyKept')]
    public function anAiPinNobodyKeptGetsNoVerdict(string $pinId): void
    {
        $this->assertRefusedAndUnchanged(PinToCheck::NOT_COUNTED, fn () => $this->commands->setPinVerdict(self::REVIEW, $pinId, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aPinWithAVerdictIsNotJudgedAgainUntilItIsUndone(): void
    {
        $this->assertRefusedAndUnchanged(PinToCheck::VERDICT_GIVEN, fn () => $this->commands->setPinVerdict(self::REVIEW, self::JUDGED_PIN, new UserId(self::MIA), VerdictCommands::PIN_STILL_WRONG, new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aPinWithNoVerdictHasNothingToUndo(): void
    {
        $this->assertRefusedAndUnchanged(PinToCheck::NO_VERDICT, fn () => $this->commands->undoPinVerdict(self::REVIEW, self::HUMAN_PIN, new UserId(self::MIA), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * Another tab gave the pin its verdict between this request's read and its save: the conditional write finds the
     * verdict moved, so the other tab's verdict stays and nothing is published.
     */
    #[Test]
    public function aPinVerdictSavedMeanwhileByAnotherTabIsRefusedAndKept(): void
    {
        $this->areas->beforePinSave = static function (InMemoryAreas $areas): void {
            $areas->replaceStoredPin(self::ACME_FOODS, self::pin(self::HUMAN_PIN, PinSource::Human, null, PinVerdict::StillWrong));
        };

        $this->assertPinChangedRefusal(fn () => $this->commands->setPinVerdict(self::REVIEW, self::HUMAN_PIN, new UserId(self::MIA), VerdictCommands::PIN_FIXED, new \DateTimeImmutable(self::NOW)));

        self::assertSame(PinVerdict::StillWrong, $this->areas->storedPin(self::ACME_FOODS, self::HUMAN_PIN)?->verdict());
    }

    #[Test]
    public function anUndoAfterAnotherTabChangedTheVerdictIsRefusedAndKeepsTheirs(): void
    {
        $this->areas->beforePinSave = static function (InMemoryAreas $areas): void {
            $areas->replaceStoredPin(self::ACME_FOODS, self::pin(self::JUDGED_PIN, PinSource::Human, null, PinVerdict::StillWrong));
        };

        $this->assertPinChangedRefusal(fn () => $this->commands->undoPinVerdict(self::REVIEW, self::JUDGED_PIN, new UserId(self::MIA), new \DateTimeImmutable(self::NOW)));

        self::assertSame(PinVerdict::StillWrong, $this->areas->storedPin(self::ACME_FOODS, self::JUDGED_PIN)?->verdict());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function areasTheRoundDoesNotHave(): iterable
    {
        yield 'a malformed area id' => ['area-1'];
        yield 'an area id with a trailing newline' => [self::AREA."\n"];
        yield 'an unknown area' => [self::UNKNOWN_AREA];
        yield 'an area of another round' => [self::OTHER_REVIEW_AREA];
        yield 'another organization\'s area' => [self::BETA_AREA];
    }

    #[Test]
    #[DataProvider('areasTheRoundDoesNotHave')]
    public function anAreaTheRoundDoesNotHaveIsNotFound(string $areaId): void
    {
        $this->assertRefusedAndUnchanged(VerdictRefused::AREA_NOT_FOUND, fn () => $this->commands->saveAreaVerdict(self::REVIEW, $areaId, new UserId(self::MIA), VerdictCommands::AREA_PASS, null, new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingAreaVerdicts(): iterable
    {
        yield 'no chip chosen' => [null];
        yield 'an empty one' => [''];
        yield 'a pin verdict' => [VerdictCommands::PIN_FIXED];
        yield 'a known one in capitals' => ['PASS'];
    }

    #[Test]
    #[DataProvider('missingAreaVerdicts')]
    public function anAreaVerdictWithoutAKnownChipAsksToPickOne(?string $verdict): void
    {
        $this->assertRefusedAndUnchanged('Pick Pass, Needs fix or Question first.', fn () => $this->commands->saveAreaVerdict(self::REVIEW, self::DECIDED_AREA, new UserId(self::MIA), $verdict, 'A note', new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aNoteOverTheCapIsRefusedAndTheEarlierVerdictStays(): void
    {
        $this->assertRefusedAndUnchanged('A note holds at most 1,000 characters.', fn () => $this->commands->saveAreaVerdict(self::REVIEW, self::DECIDED_AREA, new UserId(self::MIA), VerdictCommands::AREA_NEEDS_FIX, str_repeat('é', Area::NOTE_MAX_LENGTH + 1), new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aScreenshotOfASharedDraftGetsItsVerdictWithItsTrimmedNoteAfterTheRoundIsLocked(): void
    {
        $this->commands->saveScreenshotVerdict(strtoupper(self::SHARED_DRAFT), strtoupper(self::DRAFT_SHOT), new UserId(self::MIA), VerdictCommands::AREA_NEEDS_FIX, "  The total is still off\r\n", new \DateTimeImmutable(self::NOW));

        $stored = $this->areas->storedScreenshotVerdict(self::ACME_FOODS, self::DRAFT_SHOT);
        self::assertNotNull($stored);
        self::assertSame(
            [self::SHARED_DRAFT, AreaVerdict::NeedsFix, 'The total is still off', self::MIA, self::NOW],
            [$stored->roundId->toString(), $stored->verdict, $stored->note, $stored->decidedBy->toString(), $stored->decidedAt->format('Y-m-d H:i:sP')],
        );
        self::assertSame(['lockRound', 'isShownScreenshotOf', 'saveScreenshotVerdict'], $this->areas->calls);
        self::assertEquals(
            [new ScreenshotVerdictSaved(self::ACME_FOODS, self::TICKET, self::SHARED_DRAFT, self::DRAFT_SHOT, 'needs_fix', true, self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
        self::assertStringNotContainsString('total', serialize($this->events->published()));
        self::assertNull($this->areas->storedScreenshotVerdict(self::ACME_FOODS, self::OTHER_DRAFT_SHOT), 'Another draft\'s screenshot keeps none.');
    }

    #[Test]
    public function aNewScreenshotVerdictTakesThePlaceOfTheEarlierOneAndABlankNoteIsNone(): void
    {
        $this->commands->saveScreenshotVerdict(self::SHARED_DRAFT, self::DRAFT_SHOT, new UserId(self::LENA), VerdictCommands::AREA_PASS, 'Fine', new \DateTimeImmutable(self::DECIDED_AT));

        $this->commands->saveScreenshotVerdict(self::SHARED_DRAFT, self::DRAFT_SHOT, new UserId(self::MIA), VerdictCommands::AREA_QUESTION, " \n ", new \DateTimeImmutable(self::NOW));

        $stored = $this->areas->storedScreenshotVerdict(self::ACME_FOODS, self::DRAFT_SHOT);
        self::assertNotNull($stored);
        self::assertSame([AreaVerdict::Question, null, self::MIA, self::NOW], [$stored->verdict, $stored->note, $stored->decidedBy->toString(), $stored->decidedAt->format('Y-m-d H:i:sP')]);
        self::assertSame([true, false], array_map(static fn (object $event): bool => $event instanceof ScreenshotVerdictSaved && $event->hasNote, $this->events->published()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function screenshotsTheRoundPageDoesNotShow(): iterable
    {
        yield 'a malformed id' => [self::SHARED_DRAFT, 'shot-1'];
        yield 'an unknown id' => [self::SHARED_DRAFT, self::UNKNOWN_AREA];
        yield 'an earlier try a retake replaced' => [self::SHARED_DRAFT, self::DRAFT_EARLIER_TRY];
        yield 'another draft\'s screenshot' => [self::SHARED_DRAFT, self::OTHER_DRAFT_SHOT];
        yield 'a review round\'s picture, which is an area\'s' => [self::REVIEW, self::AFTER];
    }

    #[Test]
    #[DataProvider('screenshotsTheRoundPageDoesNotShow')]
    public function aScreenshotTheRoundPageDoesNotShowIsNotFoundAndNothingIsSaved(string $roundId, string $screenshotId): void
    {
        $this->assertRefusedAndUnchanged(VerdictRefused::SCREENSHOT_NOT_FOUND, fn () => $this->commands->saveScreenshotVerdict($roundId, $screenshotId, new UserId(self::MIA), VerdictCommands::AREA_PASS, null, new \DateTimeImmutable(self::NOW)));
        self::assertTrue(new VerdictRefused(VerdictRefused::SCREENSHOT_NOT_FOUND)->isNotFound());
    }

    #[Test]
    #[DataProvider('missingAreaVerdicts')]
    public function aScreenshotVerdictWithoutAKnownChipAsksToPickOne(?string $verdict): void
    {
        $this->assertRefusedAndUnchanged('Pick Pass, Needs fix or Question first.', fn () => $this->commands->saveScreenshotVerdict(self::SHARED_DRAFT, self::DRAFT_SHOT, new UserId(self::MIA), $verdict, 'A note', new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aScreenshotVerdictNoteOverTheCapIsRefused(): void
    {
        $this->assertRefusedAndUnchanged('A note holds at most 1,000 characters.', fn () => $this->commands->saveScreenshotVerdict(self::SHARED_DRAFT, self::DRAFT_SHOT, new UserId(self::MIA), VerdictCommands::AREA_NEEDS_FIX, str_repeat('é', Area::NOTE_MAX_LENGTH + 1), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @param \Closure(VerdictCommands, string): void $command
     */
    #[Test]
    #[DataProvider('everyCommand')]
    public function noCommandRunsWithoutATenant(\Closure $command): void
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $commands = new UseCaseVerdictCommands($this->areas, $this->events, $tenantContext);
        $before = $this->areas->snapshot();

        try {
            $command($commands, self::REVIEW);
            self::fail('A verdict was given with no tenant set.');
        } catch (TenantNotSet) {
        }

        self::assertEquals($before, $this->areas->snapshot());
        self::assertSame([], $this->areas->calls);
        self::assertSame([], $this->events->published());
    }

    /**
     * @param \Closure(): void $command
     */
    private function assertRefusedAndUnchanged(string $reason, \Closure $command): void
    {
        $before = $this->areas->snapshot();

        try {
            $command();
            self::fail(\sprintf('The verdict was saved, not refused with "%s".', $reason));
        } catch (VerdictRefused $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }

        self::assertEquals($before, $this->areas->snapshot());
        self::assertNotContains('saveVerdict', $this->areas->calls);
        self::assertNotContains('saveScreenshotVerdict', $this->areas->calls);
        self::assertSame([], $this->events->published());
    }

    /**
     * @param \Closure(): void $command
     */
    private function assertPinChangedRefusal(\Closure $command): void
    {
        try {
            $command();
            self::fail('The pin verdict was saved over the other tab\'s.');
        } catch (VerdictRefused $refusal) {
            self::assertSame(VerdictRefused::PIN_CHANGED, $refusal->getMessage());
        }

        self::assertSame([], $this->events->published());
    }

    private static function round(string $roundId, RoundKind $kind, bool $isShared = true, RoundState $state = RoundState::Open, bool $isDeleted = false): ReviewedRound
    {
        return new ReviewedRound(new RoundId($roundId), new TicketId(self::TICKET), $kind, $isShared, $state, $isDeleted);
    }

    private static function area(string $areaId, string $roundId, string $afterScreenshotId): Area
    {
        return Area::restored(new AreaId($areaId), new RoundId($roundId), 'Per product', 0, new ScreenshotId(self::BEFORE), new ScreenshotId($afterScreenshotId), null, null, null, null);
    }

    private static function pin(string $pinId, PinSource $source, ?AiPinState $aiState, ?PinVerdict $verdict): PinToCheck
    {
        return new PinToCheck(new PinId($pinId), $source, $aiState, $verdict);
    }
}
