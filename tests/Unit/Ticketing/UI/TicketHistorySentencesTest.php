<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
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
    public function theCreatedLineNamesTheReporterByFirstName(): void
    {
        self::assertSame('Lena created it', self::sentences()->createdSentenceOf(new UserId(self::LENA)));
        self::assertSame('An unknown person created it', self::sentences()->createdSentenceOf(new UserId(self::GONE)));
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

    private static function sentences(): TicketHistorySentences
    {
        return new TicketHistorySentences(
            [self::LENA => 'Lena Berg', self::OMAR => 'Omar Haddad'],
            [self::TO_DO => 'To do', self::IN_PROGRESS => 'In progress', self::CODE_REVIEW => 'Code review'],
        );
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
