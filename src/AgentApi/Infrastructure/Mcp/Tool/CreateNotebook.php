<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
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
 * create_notebook (P13.T8, ADR-0046): saves a new, empty notebook of the caller through Notebooks::create. The notebook
 * does not exist yet, so the gate's target is the one it will be: the token's organization and the caller as owner,
 * with NotebookPermission EDIT, which NotebookVoter decides on those two alone; the audit row names the caller. Holds
 * no state (ADR-0008).
 */
final readonly class CreateNotebook implements AgentTool
{
    public const string NAME = 'create_notebook';
    public const string AUDIT_TARGET_TYPE = 'user';

    /** The id of a notebook not saved yet; NotebookVoter never reads it. */
    private const string PROSPECTIVE_NOTEBOOK_ID = '00000000-0000-0000-0000-000000000000';
    /** The title rule allows far fewer characters and says so; the cap only keeps a check from reading megabytes. */
    private const int MAX_TITLE_LENGTH = 1000;

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private Notebooks $notebooks,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Make a notebook',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TITLE_LENGTH, 'description' => 'What the notebook is about, at most 120 characters.'],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            description: 'Makes a new, empty private notebook of yours: {title}. Answers notebook_id, for'
                .' create_notebook_upload, add_notebook_screenshot and save_scenarios. Only you see it. Each call makes'
                .' another notebook, so make one only when the person asked for it.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'notebooks:write';
    }

    /**
     * @throws \LogicException when the request has no token or user, which the api_token firewall never lets through
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $access = $this->currentApiToken->access() ?? throw new \LogicException('create_notebook ran without a personal access token.');
        $callerId = $this->currentUser->id() ?? throw new \LogicException('create_notebook ran without a user.');
        $now = $this->clock->now();
        $prospectiveNotebook = new NotebookView(self::PROSPECTIVE_NOTEBOOK_ID, $access->organizationId, $callerId, '', [], [], 1, $now, $now, $now);

        return new ToolTarget(NotebookPermission::EDIT, $prospectiveNotebook, self::AUDIT_TARGET_TYPE, $callerId->toString());
    }

    /**
     * @throws ToolRefusal a plain refusal with the title rule broken, or when Notebooks refuses the caller
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        if (!$target?->subject instanceof NotebookView) {
            throw new \LogicException('create_notebook runs on the notebook its targetOf() made.');
        }
        $title = $arguments['title'] ?? null;
        if (!\is_string($title)) {
            throw new \LogicException('The input schema lets create_notebook run only with "title" as a string.');
        }
        try {
            $notebookId = $this->notebooks->create($title, $this->clock->now());
        } catch (NotebookRefused $refused) {
            throw ToolRefusal::refused($refused->getMessage());
        }

        $answer = ['notebook_id' => $notebookId];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }
}
