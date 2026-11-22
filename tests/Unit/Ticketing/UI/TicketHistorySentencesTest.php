<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Review\Api\ChecklistCommands;
use App\Review\Api\VerdictCommands;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\ReviewChange;
use App\Ticketing\Application\TicketHistoryEntry;
use App\Ticketing\UI\TicketHistorySentences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketHistorySentencesTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000a0001';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000a0002';
    /** A person whose user row is gone: the page holds no name for them. */
    private const string GONE = '0197a3c0-0000-7000-8000-0000000a0009';
    private const string TO_DO = '0197a3c0-0000-7000-8000-0000000b0001';
    private const string IN_PROGRESS = '0197a3c0-0000-7000-8000-0000000b0002';
    private const string CODE_REVIEW = '0197a3c0-0000-7000-8000-0000000b0003';
    /** A step id the organization's listed steps no longer have. */
    private const string DELETED_STEP = '0197a3c0-0000-7000-8000-0000000b0009';

    /**
     * @return iterable<string, array{TicketHistoryEntry, string}>
     */
    public static function entriesAndSentences(): iterable
    {
        yield 'a verb move' => [self::entry(self::OMAR, self::IN_PROGRESS, self::CODE_REVIEW, 'send_to_code_review'), 'Omar moved it from In progress to Code review'];
        yield 'a lead\'s move to another step' => [self::entry(self::LENA, self::TO_DO, self::IN_PROGRESS, null), 'Lena moved it from To do to In progress'];
        yield 'a move with no step left' => [self::entry(self::LENA, null, self::TO_DO, null), 'Lena moved it to To do'];
        yield 'a give' => [self::entry(self::LENA, self::TO_DO, self::TO_DO, 'give_it_to', self::OMAR), 'Lena gave it to Omar'];
        yield 'a give out of Backlog' => [self::entry(self::LENA, self::IN_PROGRESS, self::TO_DO, 'give_it_to', self::OMAR), 'Lena gave it to Omar'];
        yield 'a give to oneself' => [self::entry(self::OMAR, self::TO_DO, self::TO_DO, 'give_it_to', self::OMAR), 'Omar took it'];
        yield 'a give to someone gone' => [self::entry(self::LENA, self::TO_DO, self::TO_DO, 'give_it_to', self::GONE), 'Lena gave it to an unknown person'];
        yield 'a give whose payload named nobody' => [self::entry(self::LENA, self::TO_DO, self::TO_DO, 'give_it_to'), 'Lena gave it to an unknown person'];
        yield 'a move by someone gone' => [self::entry(self::GONE, self::TO_DO, self::IN_PROGRESS, 'start_work'), 'An unknown person moved it from To do to In progress'];
        yield 'a self-give by someone gone' => [self::entry(self::GONE, self::TO_DO, self::TO_DO, 'give_it_to', self::GONE), 'An unknown person took it'];
        yield 'a move from a deleted step' => [self::entry(self::LENA, self::DELETED_STEP, self::TO_DO, null), 'Lena moved it from a step that is gone to To do'];
        yield 'a move to a deleted step' => [self::entry(self::LENA, self::TO_DO, self::DELETED_STEP, null), 'Lena moved it from To do to a step that is gone'];
        yield 'an undo' => [self::entry(self::OMAR, self::CODE_REVIEW, self::IN_PROGRESS, 'undo'), 'Omar undid the move to Code review, back to In progress'];
        yield 'an undo by someone gone' => [self::entry(self::GONE, self::CODE_REVIEW, self::IN_PROGRESS, 'undo'), 'An unknown person undid the move to Code review, back to In progress'];
        yield 'a step id in upper case' => [self::entry(self::OMAR, strtoupper(self::TO_DO), self::IN_PROGRESS, 'start_work'), 'Omar moved it from To do to In progress'];
    }

    #[Test]
    #[DataProvider('entriesAndSentences')]
    public function eachMoveReadsAsAPlainSentenceWithFirstNamesAndStepNames(TicketHistoryEntry $entry, string $expectedSentence): void
    {
        self::assertSame($expectedSentence, self::sentences()->sentenceOf($entry));
    }

    /**
     * A re-give in To do enters the step it left; it never reads "moved it from To do to To do".
     */
    #[Test]
    public function aMoveIntoTheStepItLeftNamesTheStepOnce(): void
    {
        self::assertSame('Lena moved it to To do', self::sentences()->sentenceOf(self::entry(self::LENA, self::TO_DO, self::TO_DO, null)));
    }

    #[Test]
    public function stepNamesKeyedByUpperCaseIdsAreFoundForAnEntryInAnyCase(): void
    {
        $sentences = new TicketHistorySentences(
            [self::OMAR => 'Omar Haddad'],
            [strtoupper(self::TO_DO) => 'To do', strtoupper(self::IN_PROGRESS) => 'In progress'],
        );

        self::assertSame('Omar moved it from To do to In progress', $sentences->sentenceOf(self::entry(self::OMAR, self::TO_DO, strtoupper(self::IN_PROGRESS), 'start_work')));
    }

    #[Test]
    public function aMoveIntoTheStepItLeftWrittenInAnotherCaseNamesTheStepOnce(): void
    {
        self::assertSame('Lena moved it to To do', self::sentences()->sentenceOf(self::entry(self::LENA, strtoupper(self::TO_DO), self::TO_DO, null)));
    }

    /**
     * @return iterable<string, array{ReviewChange, string}>
     */
    public static function reviewChangesAndSentences(): iterable
    {
        yield 'a checklist item marked fixed' => [new ReviewChange(ReviewChange::CHECK_ITEM_CHANGED, 'fixed', 2, null), 'Omar set pin 2 on the checklist to Fixed'];
        yield 'a checklist item checked OK' => [new ReviewChange(ReviewChange::CHECK_ITEM_CHANGED, 'ok', 2, null), 'Omar set pin 2 on the checklist to Checked OK'];
        yield 'a checklist item sent back' => [new ReviewChange(ReviewChange::CHECK_ITEM_CHANGED, 'not_fixed', 12, null), 'Omar set pin 12 on the checklist to Not fixed'];
        yield 'a checklist item with no pin number' => [new ReviewChange(ReviewChange::CHECK_ITEM_CHANGED, 'fixed', null, null), 'Omar set a pin on the checklist to Fixed'];
        yield 'a checklist item in a state with no words' => [new ReviewChange(ReviewChange::CHECK_ITEM_CHANGED, 'open', 2, null), 'Omar changed pin 2 on the checklist'];
        yield 'a pin verdict Fixed' => [new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'fixed', 2, 'Per product'), 'Omar set pin 2 in area Per product to Fixed'];
        yield 'a pin verdict Still wrong' => [new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'still_wrong', 3, 'Totals'), 'Omar set pin 3 in area Totals to Still wrong'];
        yield 'a pin verdict on a pin with no labels' => [new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'still_wrong', null, null), 'Omar set a pin in an area to Still wrong'];
        yield 'a pin verdict on a pin with no area' => [new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'fixed', 2, null), 'Omar set pin 2 in an area to Fixed'];
        yield 'a pin verdict with no words' => [new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'maybe', 2, 'Totals'), 'Omar changed the verdict on pin 2 in area Totals'];
        yield 'a pin verdict undone' => [new ReviewChange(ReviewChange::PIN_VERDICT_UNDONE, 'fixed', 2, 'Per product'), 'Omar undid the verdict on pin 2 in area Per product'];
        yield 'a pin verdict undone on a pin with no labels' => [new ReviewChange(ReviewChange::PIN_VERDICT_UNDONE, 'still_wrong', null, null), 'Omar undid the verdict on a pin in an area'];
        yield 'an area verdict Pass' => [new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, 'pass', null, 'Totals'), 'Omar gave area Totals: Pass'];
        yield 'an area verdict Needs fix' => [new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, 'needs_fix', null, 'Totals'), 'Omar gave area Totals: Needs fix'];
        yield 'an area verdict Question' => [new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, 'question', null, 'Totals'), 'Omar gave area Totals: Question'];
        yield 'an area verdict on an area with no name' => [new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, 'needs_fix', null, null), 'Omar gave an area: Needs fix'];
        yield 'an area verdict with no words' => [new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, 'later', null, 'Totals'), 'Omar changed the verdict on area Totals'];
    }

    #[Test]
    #[DataProvider('reviewChangesAndSentences')]
    public function eachReviewChangeReadsAsAPlainSentenceWithThePinAndAreaNeverACode(ReviewChange $change, string $expectedSentence): void
    {
        self::assertSame($expectedSentence, self::sentences()->sentenceOf(self::reviewEntry(self::OMAR, $change)));
    }

    /**
     * Every code a Review event can carry, read from Review's Api, so a code Review adds without words here fails.
     * STATE_OPEN is left out: no ChecklistItemChanged carries it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function reviewCodesOfEachKind(): iterable
    {
        $kindsAndCodes = [
            ReviewChange::CHECK_ITEM_CHANGED => array_diff_key(self::constantsOf(ChecklistCommands::class, 'STATE_'), ['STATE_OPEN' => true]),
            ReviewChange::PIN_VERDICT_SET => self::constantsOf(VerdictCommands::class, 'PIN_'),
            ReviewChange::AREA_VERDICT_SAVED => self::constantsOf(VerdictCommands::class, 'AREA_'),
        ];
        foreach ($kindsAndCodes as $kind => $codes) {
            self::assertNotSame([], $codes);
            foreach ($codes as $name => $code) {
                self::assertIsString($code);

                yield $kind.' '.$name => [$kind, $code];
            }
        }
    }

    #[Test]
    #[DataProvider('reviewCodesOfEachKind')]
    public function everyCodeOfReviewsApiHasItsOwnWordsNeverTheChangedFallback(string $kind, string $code): void
    {
        $sentence = self::sentences()->sentenceOf(self::reviewEntry(self::OMAR, new ReviewChange($kind, $code, 2, 'Totals')));

        self::assertStringStartsNotWith('Omar changed ', $sentence);
    }

    #[Test]
    public function aReviewChangeBySomeoneGoneStartsCapitalized(): void
    {
        $change = new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, 'pass', null, 'Totals');

        self::assertSame('An unknown person gave area Totals: Pass', self::sentences()->sentenceOf(self::reviewEntry(self::GONE, $change)));
    }

    #[Test]
    public function anEntryIsEitherAMoveIntoAStepOrAReviewChange(): void
    {
        $change = new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'fixed', 2, 'Totals');

        try {
            new TicketHistoryEntry(new UserId(self::OMAR), null, null, null, null, new \DateTimeImmutable('2027-03-16 09:00:00 UTC'));
            self::fail('An entry with neither a step nor a review change was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('A ticket history entry is either a move into a step or a review change.', $exception->getMessage());
        }

        $this->expectExceptionMessageIsOrContains('A ticket history entry is either a move into a step or a review change.');
        new TicketHistoryEntry(new UserId(self::OMAR), null, self::TO_DO, null, null, new \DateTimeImmutable('2027-03-16 09:00:00 UTC'), $change);
    }

    #[Test]
    public function thePeopleOfReviewChangesAreTheirActors(): void
    {
        $people = TicketHistorySentences::peopleOf([self::reviewEntry(self::GONE, new ReviewChange(ReviewChange::CHECK_ITEM_CHANGED, 'ok', 1, null))]);

        self::assertSame([self::GONE], array_map(static fn (UserId $userId): string => $userId->toString(), $people));
    }

    #[Test]
    public function theCreatedLineNamesTheReporterByFirstName(): void
    {
        self::assertSame('Created by Lena', self::sentences()->createdSentenceOf(new UserId(self::LENA)));
        self::assertSame('Created by an unknown person', self::sentences()->createdSentenceOf(new UserId(self::GONE)));
    }

    #[Test]
    public function aNameWrittenInLowerCaseKeepsItsCase(): void
    {
        $sentences = new TicketHistorySentences([self::OMAR => 'omar Haddad'], [self::TO_DO => 'To do', self::IN_PROGRESS => 'In progress']);

        self::assertSame('omar moved it from To do to In progress', $sentences->sentenceOf(self::entry(self::OMAR, self::TO_DO, self::IN_PROGRESS, 'start_work')));
    }

    #[Test]
    public function thePeopleOfTheEntriesAreTheActorsAndThoseGivenToOnceEach(): void
    {
        $people = TicketHistorySentences::peopleOf([
            self::entry(self::LENA, self::TO_DO, self::TO_DO, 'give_it_to', self::OMAR),
            self::entry(self::OMAR, self::TO_DO, self::IN_PROGRESS, 'start_work'),
            self::entry(self::GONE, self::TO_DO, self::TO_DO, 'give_it_to'),
        ]);

        self::assertSame([self::LENA, self::OMAR, self::GONE], array_map(static fn (UserId $userId): string => $userId->toString(), $people));
        self::assertSame([], TicketHistorySentences::peopleOf([]));
    }

    /**
     * @param class-string $apiClass
     *
     * @return array<string, mixed> the values by constant name
     */
    private static function constantsOf(string $apiClass, string $namePrefix): array
    {
        return array_filter(
            new \ReflectionClass($apiClass)->getConstants(),
            static fn (string $name): bool => str_starts_with($name, $namePrefix),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    private static function sentences(): TicketHistorySentences
    {
        return new TicketHistorySentences(
            [self::LENA => 'Lena Berg', self::OMAR => 'Omar Haddad'],
            [self::TO_DO => 'To do', self::IN_PROGRESS => 'In progress', self::CODE_REVIEW => 'Code review'],
        );
    }

    private static function reviewEntry(string $actorId, ReviewChange $change): TicketHistoryEntry
    {
        return new TicketHistoryEntry(new UserId($actorId), null, null, null, null, new \DateTimeImmutable('2027-03-16 09:00:00 UTC'), $change);
    }

    private static function entry(string $actorId, ?string $fromStepId, string $toStepId, ?string $verb, ?string $givenToId = null): TicketHistoryEntry
    {
        return new TicketHistoryEntry(
            new UserId($actorId),
            $fromStepId,
            $toStepId,
            $verb,
            null === $givenToId ? null : new UserId($givenToId),
            new \DateTimeImmutable('2027-03-16 09:00:00 UTC'),
        );
    }
}
