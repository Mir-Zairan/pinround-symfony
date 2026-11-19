<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Review\Api\NotebookPermission;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebookView;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * save_scenarios (P13.T7, ADR-0046): replaces all the scenarios of one of the caller's own notebooks with the ones their
 * Claude Code wrote, through Notebooks::replaceScenarios, which checks them with the scenario rules and saves all of
 * them or none. The target is the notebook Notebooks::get finds for the logged-in person, with NotebookPermission
 * EDIT; anything else is not found. Holds no state (ADR-0008).
 *
 * The SDK decodes the arguments with $associative true, so a JSON object arrives as a PHP array: {} and [] arrive alike,
 * and so do {"0": …} and [ … ]. The schema has no maxItems on scenarios, so a list over Notebooks::MAX_SCENARIOS gets
 * the rule's plain message rather than a schema error.
 */
final readonly class SaveScenarios implements AgentTool
{
    public const string NAME = 'save_scenarios';
    public const string AUDIT_TARGET_TYPE = 'notebook';
    public const string SAVED = 'saved';

    public function __construct(private Notebooks $notebooks, private ClockInterface $clock)
    {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Save scenarios',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'notebook' => ['type' => 'string', 'minLength' => 1, 'maxLength' => GetNotebook::MAX_NOTEBOOK_ID_LENGTH, 'description' => 'The notebook_id list_my_notebooks gave.'],
                    'scenarios' => ['type' => 'array', 'description' => \sprintf('At most %d scenarios in scenario.v1.json\'s format, in the order to show them. An empty list removes them all.', Notebooks::MAX_SCENARIOS)],
                ],
                'required' => ['notebook', 'scenarios'],
                'additionalProperties' => false,
            ],
            description: 'Replaces all the scenarios of one of your own notebooks: {notebook, scenarios: [{title, why,'
                .' setup, start_url, steps: [{type, say, …}]}]}, at most '.Notebooks::MAX_SCENARIOS.' scenarios of at most'
                .' 30 steps. A step\'s type is one of go_to, click, type, select, wait_for, expect_text, expect_visible,'
                .' pause and capture; a target is {role, name?}, {text} or {label}, never a CSS or XPath string; every'
                .' go_to stays on the scheme, host and port of start_url. One broken rule refuses the whole list with'
                .' the first problem ("Scenario 2, step 5: …") and the old scenarios stay. An empty list, and an empty'
                .' object too, removes them all. Send the texts as plain text: for a text get_notebook gave, '
                .UntrustedText::UNWRAP.', or a start_url with &amp; is saved as written and plays the wrong address.'
                .' Answers notebook_id and scenario_count.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'notebooks:write';
    }

    /**
     * @throws ToolRefusal not found for a malformed or unknown id, another person's and another organization's notebook
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $notebookId = $arguments['notebook'] ?? null;
        if (!\is_string($notebookId)) {
            throw new \LogicException('The input schema lets save_scenarios run only with "notebook" as a string.');
        }
        $notebook = $this->notebooks->get($notebookId) ?? throw ToolRefusal::notFound();

        return new ToolTarget(NotebookPermission::EDIT, $notebook, self::AUDIT_TARGET_TYPE, $notebook->id);
    }

    /**
     * @throws ToolRefusal     not found when the notebook went meanwhile; a plain refusal with the first scenario
     *                         rule broken, or when another request saved the notebook meanwhile
     * @throws \LogicException when the target is not the notebook targetOf() found
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $notebook = $target?->subject;
        if (!$notebook instanceof NotebookView) {
            throw new \LogicException('save_scenarios runs on the notebook its targetOf() found.');
        }
        $scenarios = $arguments['scenarios'] ?? null;
        if (!\is_array($scenarios)) {
            throw new \LogicException('The input schema lets save_scenarios run only with "scenarios" as a list.');
        }
        try {
            $this->notebooks->replaceScenarios($notebook->id, self::withObjects($scenarios), $this->clock->now());
        } catch (NotebookRefused $refused) {
            throw $refused->isNotFound() ? ToolRefusal::notFound() : ToolRefusal::refused($refused->getMessage());
        }

        $answer = ['notebook_id' => $notebook->id, 'status' => self::SAVED, 'scenario_count' => \count($scenarios)];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * The arguments in the shape json_decode($json) gives, which Notebooks::replaceScenarios asks for: each array with
     * a key that breaks a list becomes a stdClass, at every depth; a list, the empty one included, stays a list.
     */
    private static function withObjects(mixed $decoded): mixed
    {
        if (!\is_array($decoded)) {
            return $decoded;
        }
        $converted = array_map(self::withObjects(...), $decoded);

        return array_is_list($converted) ? $converted : (object) $converted;
    }
}
