<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Api\CurrentApiToken;
use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
use App\Review\Api\NotebookPermission;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebookView;
use App\Review\Api\PlacedPin;
use App\Review\Api\ScreenshotWithPins;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * get_notebook (P13.T6, P13.T7, ADR-0046): one of the caller's own notebooks, for their Claude Code to explain its pins
 * or write scenarios: its pictures with signed URLs, titles, summaries and pins, and its scenarios. The target is the
 * notebook Notebooks::get finds for the logged-in person, with NotebookPermission VIEW; anything else is not found.
 * Every text a person or an agent wrote is wrapped as UntrustedText (ADR-0020): in a scenario, each text value but a
 * step's type, which ScenarioRules keeps to its fixed list. Reading it is no use of the notebook. Holds no state
 * (ADR-0008).
 */
final readonly class GetNotebook implements AgentTool
{
    public const string NAME = 'get_notebook';
    public const string AUDIT_TARGET_TYPE = 'notebook';
    /** The variant get_round_notes shows too (RoundCards::VARIANT, which this module may not read). */
    public const string IMAGE_VARIANT = 'view';

    /** The one step field ScenarioRules keeps to a fixed list of codes, so it is no text a person wrote. */
    private const string STEP_TYPE_FIELD = 'type';

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private Notebooks $notebooks,
        private SignedUrls $signedUrls,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Get a notebook',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'notebook' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The notebook_id list_my_notebooks gave.'],
                ],
                'required' => ['notebook'],
                'additionalProperties' => false,
            ],
            description: 'Reads one of your own notebooks: notebook_id, title, version, last_used_at, screenshots and'
                .' scenarios. Each screenshot has its media_id, position (from 0), image (status and a signed URL that'
                .' works for a few minutes), title, summary and pins (number, x_pct and y_pct from 0 to 100, note): the'
                .' person pinned what they ask about. scenarios are the ones save_scenarios saved, in their format.'
                .' '.UntrustedText::NOTICE.' Titles, summaries, notes and every text of a scenario but a step\'s type are'
                .' such text: before you save scenarios again, '.UntrustedText::UNWRAP.'.',
            annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * @throws ToolRefusal not found for a malformed or unknown id, another person's and another organization's notebook
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $notebook = $this->notebooks->get(self::notebookIdOf($arguments)) ?? throw ToolRefusal::notFound();

        return new ToolTarget(NotebookPermission::VIEW, $notebook, self::AUDIT_TARGET_TYPE, $notebook->id);
    }

    /**
     * @throws \LogicException when the target is not the notebook targetOf() found, or the request has no token
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $notebook = $target?->subject;
        if (!$notebook instanceof NotebookView) {
            throw new \LogicException('get_notebook runs on the notebook its targetOf() found.');
        }
        $images = $this->imagesOf($notebook);

        $answer = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'notebook_id' => $notebook->id,
            'title' => UntrustedText::wrap($notebook->title),
            'version' => $notebook->version,
            'last_used_at' => $notebook->lastUsedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'screenshots' => array_map(
                static fn (ScreenshotWithPins $screenshot, int $position): array => self::screenshotFieldsOf($screenshot, $position, $images[strtolower($screenshot->mediaId)] ?? null),
                $notebook->screenshots,
                array_keys($notebook->screenshots),
            ),
            'scenarios' => array_map(self::wrappedScenarioOf(...), $notebook->scenarios),
        ];

        // No JSON_PRESERVE_ZERO_FRACTION: the SDK writes structuredContent without it, so both blocks carry the same JSON.
        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * @param array<string, mixed> $arguments valid against an input schema with a required string "notebook"
     */
    private static function notebookIdOf(array $arguments): string
    {
        $notebookId = $arguments['notebook'] ?? null;
        if (!\is_string($notebookId)) {
            throw new \LogicException('The input schema lets get_notebook run only with "notebook" as a string.');
        }

        return $notebookId;
    }

    /**
     * One call: a notebook holds at most Notebooks::MAX_SCREENSHOTS pictures, well under SignedUrls::MAX_MEDIA_IDS.
     *
     * @return array<string, MediaImage> by media id in lowercase
     */
    private function imagesOf(NotebookView $notebook): array
    {
        if ([] === $notebook->screenshots) {
            return [];
        }
        $access = $this->currentApiToken->access() ?? throw new \LogicException('get_notebook ran without a personal access token.');
        $mediaIds = array_values(array_unique(array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $notebook->screenshots)));

        return $this->signedUrls->forVariants($access->organizationId, $mediaIds, self::IMAGE_VARIANT, $this->clock->now());
    }

    /**
     * @param MediaImage|null $image null when Media did not find the picture's media object
     *
     * @return array<string, mixed>
     */
    private static function screenshotFieldsOf(ScreenshotWithPins $screenshot, int $position, ?MediaImage $image): array
    {
        return [
            'media_id' => $screenshot->mediaId,
            'position' => $position,
            'image' => null === $image ? null : ['status' => $image->status->value, 'url' => $image->url],
            'title' => UntrustedText::wrapOrNull($screenshot->title),
            'summary' => UntrustedText::wrapOrNull($screenshot->summary),
            'pins' => array_map(static fn (PlacedPin $pin, int $index): array => [
                'number' => $index + 1,
                'x_pct' => $pin->xPercent,
                'y_pct' => $pin->yPercent,
                'note' => UntrustedText::wrap($pin->note),
            ], $screenshot->pins, array_keys($screenshot->pins)),
        ];
    }

    /**
     * @param array{title: string, why: string, setup: string, start_url: string, steps: list<array<string, string|array<string, string>>>} $scenario
     *
     * @return array<string, mixed> the same fields, each text wrapped
     */
    private static function wrappedScenarioOf(array $scenario): array
    {
        return [
            'title' => UntrustedText::wrap($scenario['title']),
            'why' => UntrustedText::wrap($scenario['why']),
            'setup' => UntrustedText::wrap($scenario['setup']),
            'start_url' => UntrustedText::wrap($scenario['start_url']),
            'steps' => array_map(static function (array $step): array {
                $wrapped = [];
                foreach ($step as $field => $value) {
                    $wrapped[$field] = match (true) {
                        self::STEP_TYPE_FIELD === $field => $value,
                        \is_array($value) => array_map(UntrustedText::wrap(...), $value),
                        default => UntrustedText::wrap($value),
                    };
                }

                return $wrapped;
            }, $scenario['steps']),
        ];
    }
}
