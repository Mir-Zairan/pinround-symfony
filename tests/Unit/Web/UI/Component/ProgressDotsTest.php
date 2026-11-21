<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Component;

use App\Web\UI\Component\ProgressDots;
use App\Web\UI\MyReports\ReportStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgressDotsTest extends TestCase
{
    #[Test]
    public function theTextAlternativeSaysTheStepInWords(): void
    {
        self::assertSame('Step 2 of 4: Building', self::dots(ReportStage::labels(), 2)->summary());
        self::assertSame('Step 1 of 4: Reported', self::dots(ReportStage::labels(), 1)->summary());
        self::assertSame('Step 4 of 4: Done', self::dots(ReportStage::labels(), 4)->summary());
    }

    #[Test]
    public function aWaitingStageSaysSoInTheTextAlternative(): void
    {
        self::assertSame('Step 2 of 4: Building, waiting', self::dots(ReportStage::labels(), 2, true)->summary());
    }

    #[Test]
    public function stagesBeforeTheCurrentOneArePassedAndTheOnesAfterItLater(): void
    {
        self::assertSame(
            [
                ['label' => 'Reported', 'state' => 'passed', 'hasLineAfter' => true],
                ['label' => 'Building', 'state' => 'passed', 'hasLineAfter' => true],
                ['label' => 'Checking', 'state' => 'current', 'hasLineAfter' => true],
                ['label' => 'Done', 'state' => 'later', 'hasLineAfter' => false],
            ],
            self::dots(ReportStage::labels(), 3)->dots(),
        );
    }

    #[Test]
    public function theCurrentStageOfAWaitingTicketIsLabelledWaiting(): void
    {
        self::assertSame(
            ['Reported', 'Waiting', 'Checking', 'Done'],
            array_column(self::dots(ReportStage::labels(), 2, true)->dots(), 'label'),
        );
    }

    #[Test]
    public function onTheLastStageEveryEarlierStageIsPassedAndNoLineFollowsIt(): void
    {
        $dots = self::dots(ReportStage::labels(), 4)->dots();

        self::assertSame(['passed', 'passed', 'passed', 'current'], array_column($dots, 'state'));
        self::assertSame([true, true, true, false], array_column($dots, 'hasLineAfter'));
    }

    /**
     * @return iterable<string, array{list<string>, int, string}>
     */
    public static function invalidRows(): iterable
    {
        yield 'one stage' => [['Reported'], 1, 'ProgressDots needs two or more non-empty stage labels.'];
        yield 'a blank label' => [['Reported', ' '], 1, 'ProgressDots needs two or more non-empty stage labels.'];
        yield 'stage 0' => [['Reported', 'Done'], 0, 'ProgressDots\' current stage is from 1 to its number of stages.'];
        yield 'a stage past the last' => [['Reported', 'Done'], 3, 'ProgressDots\' current stage is from 1 to its number of stages.'];
    }

    /**
     * @param list<string> $labels
     */
    #[Test]
    #[DataProvider('invalidRows')]
    public function aRowThatCannotShowAStageIsRefusedAtMount(array $labels, int $current, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        self::dots($labels, $current);
    }

    /**
     * Mounted as the component system does: props set, then the PostMount check.
     *
     * @param list<string> $labels
     */
    private static function dots(array $labels, int $current, bool $isWaiting = false): ProgressDots
    {
        $dots = new ProgressDots();
        $dots->labels = $labels;
        $dots->current = $current;
        $dots->isWaiting = $isWaiting;
        $dots->assertIsAStageOfTheRow();

        return $dots;
    }
}
