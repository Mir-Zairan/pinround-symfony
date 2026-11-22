<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\GeneralNote;

use App\Review\Api\GeneralNoteRefused;
use App\Review\Application\GeneralNote\UseCaseGeneralNoteCommands;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\GeneralNote;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Adding a general note to a round of Acme Foods' SHOP ticket: open shared rounds of every kind but the checklist take
 * them; a closed round, an unshared or deleted draft, the checklist, an unknown round and Beta Bakery's round do not.
 * The clock is a fixed day in 2027.
 */
final class UseCaseGeneralNoteCommandsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007702';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000007711';
    private const string MIA = '0197a3c0-0000-7000-8000-000000007721';
    private const string SHARED_DRAFT = '0197a3c0-0000-7000-8000-000000007731';
    private const string REPORT = '0197a3c0-0000-7000-8000-000000007732';
    private const string REVIEW = '0197a3c0-0000-7000-8000-000000007733';
    private const string CLOSED_DRAFT = '0197a3c0-0000-7000-8000-000000007734';
    private const string UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-000000007735';
    private const string DELETED_DRAFT = '0197a3c0-0000-7000-8000-000000007736';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-000000007737';
    private const string BETA_DRAFT = '0197a3c0-0000-7000-8000-000000007738';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-000000007739';
    private const string NOW = '2027-03-16 09:30:00+00:00';

    private TenantContext $tenantContext;
    private InMemoryGeneralNotes $notes;
    private UseCaseGeneralNoteCommands $commands;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->notes = new InMemoryGeneralNotes($this->tenantContext);
        $this->commands = new UseCaseGeneralNoteCommands($this->notes, $this->tenantContext);

        $this->notes->addRound(self::ACME_FOODS, self::round(self::SHARED_DRAFT, RoundKind::Draft));
        $this->notes->addRound(self::ACME_FOODS, self::round(self::REPORT, RoundKind::Report));
        $this->notes->addRound(self::ACME_FOODS, self::round(self::REVIEW, RoundKind::Review));
        $this->notes->addRound(self::ACME_FOODS, self::round(self::CLOSED_DRAFT, RoundKind::Draft, state: RoundState::Closed));
        $this->notes->addRound(self::ACME_FOODS, self::round(self::UNSHARED_DRAFT, RoundKind::Draft, isShared: false));
        $this->notes->addRound(self::ACME_FOODS, self::round(self::DELETED_DRAFT, RoundKind::Draft, isDeleted: true));
        $this->notes->addRound(self::ACME_FOODS, self::round(self::CHECKLIST, RoundKind::Checklist));
        $this->notes->addRound(self::BETA_BAKERY, self::round(self::BETA_DRAFT, RoundKind::Draft));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundsThatTakeNotes(): iterable
    {
        yield 'a shared draft' => [self::SHARED_DRAFT];
        yield 'the report' => [self::REPORT];
        yield 'a review round' => [self::REVIEW];
    }

    #[Test]
    #[DataProvider('roundsThatTakeNotes')]
    public function anOpenSharedRoundTakesATrimmedNoteWithItsVerdictAfterItIsLocked(string $roundId): void
    {
        $this->commands->add(strtoupper($roundId), new UserId(self::MIA), 'needs_fix', "  The footer is gone on every page\r\n", new \DateTimeImmutable(self::NOW));

        $stored = $this->notes->notesIn(self::ACME_FOODS);
        self::assertCount(1, $stored);
        self::assertSame(
            [$roundId, self::MIA, AreaVerdict::NeedsFix, 'The footer is gone on every page', self::NOW],
            [$stored[0]->roundId->toString(), $stored[0]->authorId->toString(), $stored[0]->verdict, $stored[0]->note, $stored[0]->createdAt->format('Y-m-d H:i:sP')],
        );
        self::assertSame(['lockRound', 'countOn', 'add'], $this->notes->calls);
    }

    #[Test]
    public function aNoteWithoutAVerdictIsKeptAndANewNoteNeverReplacesAnEarlierOne(): void
    {
        $this->commands->add(self::SHARED_DRAFT, new UserId(self::MIA), '', 'First', new \DateTimeImmutable(self::NOW));
        $this->commands->add(self::SHARED_DRAFT, new UserId(self::MIA), null, 'Second', new \DateTimeImmutable(self::NOW));

        self::assertSame(
            [['First', null], ['Second', null]],
            array_map(static fn (GeneralNote $note): array => [$note->note, $note->verdict], $this->notes->notesIn(self::ACME_FOODS)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundsNobodyCouldBeShown(): iterable
    {
        yield 'a malformed id' => ['round-1'];
        yield 'an id with a trailing newline' => [self::SHARED_DRAFT."\n"];
        yield 'an unknown round' => [self::UNKNOWN];
        yield 'another organization\'s round' => [self::BETA_DRAFT];
        yield 'an unshared draft' => [self::UNSHARED_DRAFT];
        yield 'a deleted draft' => [self::DELETED_DRAFT];
        yield 'the checklist' => [self::CHECKLIST];
    }

    #[Test]
    #[DataProvider('roundsNobodyCouldBeShown')]
    public function aRoundThatTakesNoNotesAtAllIsNotFoundAndNothingIsSaved(string $roundId): void
    {
        $refused = $this->assertRefusedAndUnchanged(GeneralNoteRefused::NOT_FOUND, fn () => $this->commands->add($roundId, new UserId(self::MIA), 'pass', 'A note', new \DateTimeImmutable(self::NOW)));
        self::assertTrue($refused->isNotFound());
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function refusedNotes(): iterable
    {
        yield 'a closed round' => [self::CLOSED_DRAFT, 'pass', ReviewedRound::CLOSED_FOR_GENERAL_NOTES];
        yield 'an empty note' => [self::SHARED_DRAFT, 'pass', GeneralNote::NOTE_MISSING];
        yield 'an unknown verdict' => [self::SHARED_DRAFT, 'fixed', GeneralNote::UNKNOWN_VERDICT];
    }

    #[Test]
    #[DataProvider('refusedNotes')]
    public function aRefusedNoteSavesNothingAndSaysWhy(string $roundId, ?string $verdict, string $reason): void
    {
        $note = GeneralNote::NOTE_MISSING === $reason ? " \n " : 'A note';
        $refused = $this->assertRefusedAndUnchanged($reason, fn () => $this->commands->add($roundId, new UserId(self::MIA), $verdict, $note, new \DateTimeImmutable(self::NOW)));
        self::assertFalse($refused->isNotFound());
    }

    #[Test]
    public function aNoteOverTheCapIsRefused(): void
    {
        $this->assertRefusedAndUnchanged('A note holds at most 2,000 characters.', fn () => $this->commands->add(self::SHARED_DRAFT, new UserId(self::MIA), null, str_repeat('é', GeneralNote::NOTE_MAX_LENGTH + 1), new \DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function aFullRoundTakesNoMoreNotesAndAnotherRoundStillDoes(): void
    {
        for ($i = 1; $i <= GeneralNote::MAX_PER_ROUND; ++$i) {
            $this->commands->add(self::SHARED_DRAFT, new UserId(self::MIA), null, 'Note '.$i, new \DateTimeImmutable(self::NOW));
        }

        $this->assertRefusedAndUnchanged(GeneralNote::ROUND_FULL, fn () => $this->commands->add(self::SHARED_DRAFT, new UserId(self::MIA), null, 'One more', new \DateTimeImmutable(self::NOW)));

        $this->commands->add(self::REPORT, new UserId(self::MIA), null, 'Elsewhere', new \DateTimeImmutable(self::NOW));
        self::assertCount(GeneralNote::MAX_PER_ROUND + 1, $this->notes->notesIn(self::ACME_FOODS));
    }

    #[Test]
    public function withoutATenantNothingIsRead(): void
    {
        $this->tenantContext->clear();

        $this->expectException(TenantNotSet::class);
        try {
            $this->commands->add(self::SHARED_DRAFT, new UserId(self::MIA), null, 'A note', new \DateTimeImmutable(self::NOW));
        } finally {
            self::assertSame([], $this->notes->calls);
        }
    }

    /**
     * @param \Closure(): void $add
     */
    private function assertRefusedAndUnchanged(string $reason, \Closure $add): GeneralNoteRefused
    {
        $before = $this->notes->snapshot();
        try {
            $add();
        } catch (GeneralNoteRefused $refused) {
            self::assertSame($reason, $refused->getMessage());
            self::assertSame($before, $this->notes->snapshot());

            return $refused;
        }
        self::fail('The note was not refused.');
    }

    private static function round(string $roundId, RoundKind $kind, bool $isShared = true, RoundState $state = RoundState::Open, bool $isDeleted = false): ReviewedRound
    {
        return new ReviewedRound(new RoundId($roundId), new TicketId(self::TICKET), $kind, $isShared, $state, $isDeleted);
    }
}
