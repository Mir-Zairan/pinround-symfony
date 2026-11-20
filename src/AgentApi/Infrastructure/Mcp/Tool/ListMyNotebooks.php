<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\OwnIdentityTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Review\Api\NotebookCursor;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebookSummary;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * list_my_notebooks (P13.T6, ADR-0046): one page of the caller's own notebooks, last used first, from
 * Notebooks::forOwner, which reads only the logged-in person's rows of the token's organization in one query. Titles
 * are wrapped as UntrustedText (ADR-0020). Holds no state (ADR-0008).
 */
final readonly class ListMyNotebooks implements OwnIdentityTool
{
    public const string NAME = 'list_my_notebooks';
    public const string INVALID_CURSOR = 'cursor must be a next_cursor that list_my_notebooks gave.';

    /** The microseconds keep the place exact: notebooks used in the same second still page in order. */
    private const string CURSOR_TIME_FORMAT = 'Y-m-d\TH:i:s.uP';
    private const string CURSOR_SEPARATOR = '/';
    /** A cursor is 32 characters of time, a slash and 36 of id; the cap only keeps a parse from reading megabytes. */
    private const int MAX_CURSOR_LENGTH = 100;

    public function __construct(private Notebooks $notebooks)
    {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'List my notebooks',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'cursor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CURSOR_LENGTH, 'description' => 'The next_cursor of the page before, for the next page; leave it out for the first page.'],
                ],
                'additionalProperties' => false,
            ],
            description: 'Lists your own private notebooks, the last used first: each with its notebook_id (for'
                .' get_notebook and save_scenarios), title, screenshot_count, scenario_count and last_used_at (ISO'
                .' 8601, UTC). At most '.Notebooks::NOTEBOOKS_PER_PAGE.' a page; next_cursor is null on the last page,'
                .' else pass it as cursor for the next one. Nobody else\'s notebooks are ever listed. '
                .UntrustedText::NOTICE.' Titles are such text.',
            annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * No target: it lists only the caller's own notebooks, which Notebooks::forOwner reads by the logged-in person, so
     * no voter on one notebook applies (ADR-0013).
     */
    public function targetOf(array $arguments): ?ToolTarget
    {
        return null;
    }

    /**
     * @throws ToolRefusal invalid input, for a cursor no page gave
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $page = $this->notebooks->forOwner(self::cursorOf($arguments));
        $answer = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'notebooks' => array_map(static fn (NotebookSummary $notebook): array => [
                'notebook_id' => $notebook->id,
                'title' => UntrustedText::wrap($notebook->title),
                'screenshot_count' => $notebook->screenshotCount,
                'scenario_count' => $notebook->scenarioCount,
                'last_used_at' => $notebook->lastUsedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            ], $page->notebooks),
            'next_cursor' => null === $page->nextCursor ? null : self::encodedCursor($page->nextCursor),
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    private static function encodedCursor(NotebookCursor $cursor): string
    {
        return $cursor->lastUsedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::CURSOR_TIME_FORMAT).self::CURSOR_SEPARATOR.$cursor->notebookId;
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     *
     * @throws ToolRefusal invalid input, when the cursor is not one encodedCursor() makes
     */
    private static function cursorOf(array $arguments): ?NotebookCursor
    {
        $encoded = $arguments['cursor'] ?? null;
        if (null === $encoded) {
            return null;
        }
        if (!\is_string($encoded)) {
            throw new \LogicException('The input schema lets list_my_notebooks run only with "cursor" as a string.');
        }
        $parts = explode(self::CURSOR_SEPARATOR, $encoded, 2);
        $lastUsedAt = \DateTimeImmutable::createFromFormat('!'.self::CURSOR_TIME_FORMAT, $parts[0], new \DateTimeZone('UTC'));
        // Formatting back must give the same text, so an overflowing date such as 2026-02-30 is refused, not rolled over.
        if (2 !== \count($parts) || false === $lastUsedAt || $lastUsedAt->format(self::CURSOR_TIME_FORMAT) !== $parts[0]) {
            throw ToolRefusal::invalidInput(self::INVALID_CURSOR);
        }
        try {
            return new NotebookCursor($lastUsedAt, $parts[1]);
        } catch (\InvalidArgumentException) {
            throw ToolRefusal::invalidInput(self::INVALID_CURSOR);
        }
    }
}
