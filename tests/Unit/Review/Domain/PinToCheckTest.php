<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\AiPinState;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\PinVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PinToCheckTest extends TestCase
{
    private const string PIN = '0197a3c0-0000-7000-8000-000000007c41';

    /**
     * @return iterable<string, array{PinSource, ?AiPinState}>
     */
    public static function pinsThatCount(): iterable
    {
        yield 'a person\'s pin' => [PinSource::Human, null];
        yield 'a kept AI pin' => [PinSource::Ai, AiPinState::Kept];
    }

    #[Test]
    #[DataProvider('pinsThatCount')]
    public function aPinThatCountsIsJudgedAndUndoneAndEachStepLeavesTheOneBefore(PinSource $source, ?AiPinState $aiState): void
    {
        $unjudged = new PinToCheck(new PinId(self::PIN), $source, $aiState, null);

        $judged = $unjudged->judge(PinVerdict::StillWrong);
        $undone = $judged->undo();

        self::assertSame([null, PinVerdict::StillWrong, null], [$unjudged->verdict(), $judged->verdict(), $undone->verdict()]);
        self::assertSame([true, self::PIN], [$undone->counts(), $undone->id()->toString()]);
    }

    /**
     * @return iterable<string, array{AiPinState, ?PinVerdict, string}>
     */
    public static function aiPinsNobodyKept(): iterable
    {
        yield 'a suggested AI pin judged' => [AiPinState::Suggested, null, 'judge'];
        yield 'a dismissed AI pin judged' => [AiPinState::Dismissed, null, 'judge'];
        yield 'a suggested AI pin with a verdict undone' => [AiPinState::Suggested, PinVerdict::Fixed, 'undo'];
        yield 'a dismissed AI pin with a verdict undone' => [AiPinState::Dismissed, PinVerdict::Fixed, 'undo'];
    }

    #[Test]
    #[DataProvider('aiPinsNobodyKept')]
    public function anAiPinNobodyKeptIsNeitherJudgedNorUndone(AiPinState $aiState, ?PinVerdict $verdict, string $step): void
    {
        $pin = new PinToCheck(new PinId(self::PIN), PinSource::Ai, $aiState, $verdict);

        self::assertFalse($pin->counts());
        self::assertSame(PinToCheck::NOT_COUNTED, self::refusalOf(static fn () => 'judge' === $step ? $pin->judge(PinVerdict::Fixed) : $pin->undo()));
        self::assertSame($verdict, $pin->verdict());
    }

    #[Test]
    public function aPinWithAVerdictIsNotJudgedAgain(): void
    {
        $pin = new PinToCheck(new PinId(self::PIN), PinSource::Human, null, PinVerdict::Fixed);

        self::assertSame(PinToCheck::VERDICT_GIVEN, self::refusalOf(static fn () => $pin->judge(PinVerdict::StillWrong)));
        self::assertSame(PinVerdict::Fixed, $pin->verdict());
    }

    #[Test]
    public function aPinWithNoVerdictHasNothingToUndo(): void
    {
        $pin = new PinToCheck(new PinId(self::PIN), PinSource::Human, null, null);

        self::assertSame(PinToCheck::NO_VERDICT, self::refusalOf(static fn () => $pin->undo()));
        self::assertNull($pin->verdict());
    }

    /**
     * @param \Closure(): PinToCheck $step
     */
    private static function refusalOf(\Closure $step): string
    {
        try {
            $step();
        } catch (\DomainException $refusal) {
            return $refusal->getMessage();
        }

        self::fail('The step was not refused.');
    }
}
