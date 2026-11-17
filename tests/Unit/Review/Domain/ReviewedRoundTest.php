<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\SharedKernel\Domain\TicketId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewedRoundTest extends TestCase
{
    private const string ROUND = '0197a3c0-0000-7000-8000-000000007d12';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000007d03';

    /**
     * @return iterable<string, array{RoundKind}>
     */
    public static function kindsThatTakeVerdicts(): iterable
    {
        yield 'a review round' => [RoundKind::Review];
        yield 'a draft' => [RoundKind::Draft];
    }

    #[Test]
    #[DataProvider('kindsThatTakeVerdicts')]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function anOpenSharedRoundOfAKindThatTakesVerdictsTakesThem(RoundKind $kind): void
    {
        self::round($kind, isShared: true, state: RoundState::Open, isDeleted: false)->requireOpenForVerdicts();
    }

    /**
     * @return iterable<string, array{RoundKind, bool, RoundState, bool, string}>
     */
    public static function roundsThatTakeNoVerdicts(): iterable
    {
        yield 'a report' => [RoundKind::Report, true, RoundState::Open, false, ReviewedRound::TAKES_NO_VERDICTS];
        yield 'a checklist round' => [RoundKind::Checklist, true, RoundState::Open, false, ReviewedRound::TAKES_NO_VERDICTS];
        yield 'an unshared draft' => [RoundKind::Draft, false, RoundState::Open, false, ReviewedRound::TAKES_NO_VERDICTS];
        yield 'a deleted draft' => [RoundKind::Draft, true, RoundState::Open, true, ReviewedRound::TAKES_NO_VERDICTS];
        yield 'a closed review round' => [RoundKind::Review, true, RoundState::Closed, false, ReviewedRound::CLOSED];
        yield 'a closed shared draft' => [RoundKind::Draft, true, RoundState::Closed, false, ReviewedRound::CLOSED];
        yield 'a closed report, which never takes them' => [RoundKind::Report, true, RoundState::Closed, false, ReviewedRound::TAKES_NO_VERDICTS];
    }

    #[Test]
    #[DataProvider('roundsThatTakeNoVerdicts')]
    public function aRoundThatTakesNoVerdictsSaysWhy(RoundKind $kind, bool $isShared, RoundState $state, bool $isDeleted, string $reason): void
    {
        try {
            self::round($kind, $isShared, $state, $isDeleted)->requireOpenForVerdicts();
            self::fail('The round took verdicts.');
        } catch (\DomainException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }
    }

    private static function round(RoundKind $kind, bool $isShared, RoundState $state, bool $isDeleted): ReviewedRound
    {
        return new ReviewedRound(new RoundId(self::ROUND), new TicketId(self::TICKET), $kind, $isShared, $state, $isDeleted);
    }
}
