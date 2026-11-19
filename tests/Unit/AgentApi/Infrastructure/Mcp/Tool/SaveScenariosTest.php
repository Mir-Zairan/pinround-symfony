<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\SaveScenarios;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Review\Api\NotebookPermission;
use App\Review\Api\NotebookRefused;
use App\Review\Api\NotebookView;
use App\Review\Domain\Scenario\ScenarioRules;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SaveScenariosTest extends TestCase
{
    private const string NOTEBOOK = '0197a3c0-0000-7000-8000-0000000b0001';
    private const string NOW = '2026-11-10 09:00:20 UTC';

    #[Test]
    public function theTargetIsTheNotebookGetFindsWithEditAndItsAuditIsTheNotebook(): void
    {
        $notebook = self::notebook();

        $target = self::tool(new ScriptedNotebooks($notebook))->targetOf(['notebook' => self::NOTEBOOK, 'scenarios' => []]);

        self::assertEquals(new ToolTarget(NotebookPermission::EDIT, $notebook, 'notebook', self::NOTEBOOK), $target);
    }

    #[Test]
    public function aNotebookGetDoesNotFindIsNotFound(): void
    {
        $this->expectExceptionObject(ToolRefusal::notFound());

        self::tool(new ScriptedNotebooks(self::notebook()))->targetOf(['notebook' => '0197a3c0-0000-7000-8000-0000000b0002', 'scenarios' => []]);
    }

    /**
     * The SDK gives JSON objects as PHP arrays; Notebooks::replaceScenarios wants json_decode($json)'s shape, so each
     * keyed array is a stdClass at every depth and each list, the empty one included, stays a list.
     */
    #[Test]
    public function theScenariosGoToReplaceScenariosWithTheirObjectsAsStdClassAndTheClocksTime(): void
    {
        $notebooks = new ScriptedNotebooks(self::notebook());
        $scenarios = [[
            'title' => 'Coupon',
            'steps' => [
                ['type' => 'click', 'say' => 'Apply it.', 'target' => ['role' => 'button', 'name' => 'Apply']],
                ['type' => 'pause', 'say' => 'Look.', 'target' => []],
            ],
        ]];

        $result = self::tool($notebooks)->call(['notebook' => self::NOTEBOOK, 'scenarios' => $scenarios], self::target($notebooks));

        $expected = [(object) [
            'title' => 'Coupon',
            'steps' => [
                (object) ['type' => 'click', 'say' => 'Apply it.', 'target' => (object) ['role' => 'button', 'name' => 'Apply']],
                (object) ['type' => 'pause', 'say' => 'Look.', 'target' => []],
            ],
        ]];
        self::assertCount(1, $notebooks->replaced);
        [$notebookId, $decoded, $at] = $notebooks->replaced[0];
        self::assertSame(self::NOTEBOOK, $notebookId);
        self::assertEquals($expected, $decoded);
        self::assertIsArray($decoded);
        self::assertInstanceOf(\stdClass::class, $decoded[0]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $at);
        self::assertSame(['notebook_id' => self::NOTEBOOK, 'status' => 'saved', 'scenario_count' => 1], $result->structuredContent);
    }

    /**
     * @return iterable<string, array{NotebookRefused, ToolRefusal}>
     */
    public static function refusals(): iterable
    {
        yield 'not found' => [NotebookRefused::notFound(), ToolRefusal::notFound()];
        yield 'a scenario rule' => [NotebookRefused::because('Scenario 2, step 5: add at least one step.'), ToolRefusal::refused('Scenario 2, step 5: add at least one step.')];
        yield 'changed meanwhile' => [NotebookRefused::because(NotebookRefused::NOTEBOOK_CHANGED), ToolRefusal::refused(NotebookRefused::NOTEBOOK_CHANGED)];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function reviewsRefusalIsTheToolsByItsKindAndNothingIsSaved(NotebookRefused $refusal, ToolRefusal $expected): void
    {
        $notebooks = new ScriptedNotebooks(self::notebook(), $refusal);

        try {
            self::tool($notebooks)->call(['notebook' => self::NOTEBOOK, 'scenarios' => []], self::target($notebooks));
            self::fail('save_scenarios answered a refused save.');
        } catch (ToolRefusal $refused) {
            self::assertSame([$expected->getMessage(), $expected->outcome], [$refused->getMessage(), $refused->outcome]);
        }
        self::assertSame([], $notebooks->replaced);
    }

    /**
     * The description repeats the rules for the agent; AgentApi may not read ScenarioRules, so this keeps them in step.
     */
    #[Test]
    public function theDescriptionNamesTheStepCapAndEveryStepTypeOfTheRules(): void
    {
        $description = self::tool(new ScriptedNotebooks(null))->definition()->description;

        self::assertIsString($description);
        self::assertStringContainsString(\sprintf('at most %d scenarios of at most %d steps', ScenarioRules::MAX_SCENARIOS, ScenarioRules::MAX_STEPS), $description);
        $stepTypes = array_keys(ScenarioRules::STEP_FIELDS);
        $last = array_pop($stepTypes);
        self::assertStringContainsString(implode(', ', $stepTypes).' and '.$last, $description);
    }

    private static function tool(ScriptedNotebooks $notebooks): SaveScenarios
    {
        return new SaveScenarios($notebooks, new MockClock(self::NOW));
    }

    private static function target(ScriptedNotebooks $notebooks): ToolTarget
    {
        return self::tool($notebooks)->targetOf(['notebook' => self::NOTEBOOK, 'scenarios' => []]);
    }

    private static function notebook(): NotebookView
    {
        $at = new \DateTimeImmutable('2026-11-05 08:00:00 UTC');

        return new NotebookView(self::NOTEBOOK, new OrganizationId('0197a3c0-0000-7000-8000-00000000a001'), new UserId('0197a3c0-0000-7000-8000-00000000a002'), 'Coupon questions', [], [], 3, $at, $at, $at);
    }
}
