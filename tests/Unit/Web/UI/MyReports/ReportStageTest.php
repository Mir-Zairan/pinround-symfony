<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\MyReports;

use App\Web\UI\MyReports\ReportStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportStageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ReportStage}>
     */
    public static function stepGroups(): iterable
    {
        yield 'To do is Reported' => ['todo', ReportStage::Reported];
        yield 'Doing is Building' => ['doing', ReportStage::Building];
        yield 'Waiting sits at Building' => ['wait', ReportStage::Building];
        yield 'Checking is Checking' => ['check', ReportStage::Checking];
        yield 'Done is Done' => ['done', ReportStage::Done];
    }

    #[Test]
    #[DataProvider('stepGroups')]
    public function eachStepGroupHasItsStage(string $groupCode, ReportStage $expected): void
    {
        self::assertSame($expected, ReportStage::ofStepGroup($groupCode));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codesThatAreNoStepGroup(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['DOING'];
        yield 'a group label' => ['Checking'];
        yield 'with a trailing newline' => ["done\n"];
    }

    #[Test]
    #[DataProvider('codesThatAreNoStepGroup')]
    public function aCodeThatIsNoStepGroupIsRefused(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('"%s" is not a step group.', $code));

        ReportStage::ofStepGroup($code);
    }

    #[Test]
    public function theLabelsAreTheFourStagesInOrder(): void
    {
        self::assertSame(['Reported', 'Building', 'Checking', 'Done'], ReportStage::labels());
    }

    #[Test]
    public function eachStageIsItsPlaceInTheLabels(): void
    {
        $labels = ReportStage::labels();

        foreach (ReportStage::cases() as $stage) {
            self::assertSame($stage->label(), $labels[$stage->value - 1]);
        }
    }
}
