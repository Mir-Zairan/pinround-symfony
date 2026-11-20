<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\PlacedPinArguments;
use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
use App\AgentApi\Infrastructure\Mcp\ScreenshotContextRequiredOverMcp;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\ToolUploadCompletion;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadCompletions;
use App\Review\Api\NotebookPermission;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebookView;
use App\Review\Api\RoundCommands;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * add_notebook_screenshot (P13.T8, ADR-0046): puts a picture the caller uploaded with create_notebook_upload at the end
 * of one of their own notebooks, with its title, summary and pins, through Notebooks::addScreenshot. The target is the
 * notebook Notebooks::get finds for the logged-in person, with NotebookPermission EDIT, and the upload must be one the
 * caller began for that very notebook; anything else is not found. The upload is finished with Media's
 * UploadCompletions in the gate's savepoint, so a refusal leaves it unused. A picture still processing is added as it
 * is, as the notebook page adds it. MCP has no Idempotency-Key, so the same call sent again, as after a lost answer,
 * finds the picture in the notebook and answers already_in_notebook, as add_report_screenshot does; it never adds a
 * second copy. Over MCP the title and the summary are required (P14.T11); /api/v1 keeps them optional. Holds no state
 * (ADR-0008).
 */
final readonly class AddNotebookScreenshot implements ScreenshotContextRequiredOverMcp
{
    public const string NAME = 'add_notebook_screenshot';
    public const string AUDIT_TARGET_TYPE = 'notebook';
    public const string ADDED = 'added';
    /** The picture was in the notebook already, as when the same call is sent again; nothing changed. */
    public const string ALREADY_IN_NOTEBOOK = 'already_in_notebook';
    public const string PICTURE_REJECTED = 'The picture was rejected: it is not a PNG, JPEG or WebP screenshot we can read, or it is too big. Upload another one with create_notebook_upload.';
    public const string UPLOAD_EXPIRED = 'This upload expired before it was added. Begin a new one with create_notebook_upload.';

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private Notebooks $notebooks,
        private UploadCompletions $uploadCompletions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * /api/v1's, where the title and the summary stay optional.
     */
    public function definition(): Tool
    {
        return self::toolOf(self::inputSchema(), 'title?, summary?', '');
    }

    public function mcpDefinition(): Tool
    {
        return self::toolOf(ScreenshotContextArguments::requiredIn(self::inputSchema()), 'title, summary', ' '.ScreenshotContextArguments::RULE);
    }

    public function refuseMissingScreenshotContext(array $arguments): void
    {
        ScreenshotContextArguments::refuseMissingIn($arguments, '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'notebook' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The notebook_id create_notebook or list_my_notebooks gave.'],
                'upload_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The upload_id create_notebook_upload gave for this notebook, after the file was PUT to its put_url.'],
                'title' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_TITLE_LENGTH, 'description' => \sprintf('What the picture is of, at most %d characters.', RoundCommands::MAX_TITLE_LENGTH)],
                'summary' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_SUMMARY_LENGTH, 'description' => \sprintf('What the picture shows, in plain words: at most %d characters.', RoundCommands::MAX_SUMMARY_LENGTH)],
                'pins' => PlacedPinArguments::schema(),
            ],
            'required' => ['notebook', 'upload_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $inputSchema
     * @param string               $pictureFields how the description writes the title and the summary
     * @param string               $pictureRule   appended to the description
     */
    private static function toolOf(array $inputSchema, string $pictureFields, string $pictureRule): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Add a screenshot to a notebook',
            inputSchema: $inputSchema,
            description: 'Adds a picture you uploaded with create_notebook_upload at the end of one of your own'
                .' notebooks: {notebook, upload_id, '.$pictureFields.', pins?: [{x_pct, y_pct, note}]}.'.$pictureRule.' A notebook holds at'
                .' most '.Notebooks::MAX_SCREENSHOTS.' pictures; a picture in it already is not added twice, so the same'
                .' call sent again answers status already_in_notebook and changes nothing. A picture still being'
                .' processed is added and shows once it is ready. A rejected picture is refused. Answers notebook_id,'
                .' media_id, status and screenshot_count. Nothing is saved when the call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'notebooks:write';
    }

    /**
     * Reads the notebook, then what the upload was begun for, one query each.
     *
     * @throws ToolRefusal not found for a malformed or unknown notebook id, another person's and another
     *                     organization's notebook, an upload the caller did not begin, and one begun for anything but
     *                     this notebook
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $notebook = $this->notebooks->get(ToolArguments::stringAt($arguments, 'notebook', self::NAME)) ?? throw ToolRefusal::notFound();
        $begunFor = $this->uploadCompletions->targetOf($this->organizationId(), $this->callerId(), ToolArguments::stringAt($arguments, 'upload_id', self::NAME));
        if (null === $begunFor || Notebooks::UPLOAD_TARGET_KIND !== $begunFor->kind || strtolower($begunFor->id) !== $notebook->id) {
            throw ToolRefusal::notFound();
        }

        return new ToolTarget(NotebookPermission::EDIT, $notebook, self::AUDIT_TARGET_TYPE, $notebook->id);
    }

    /**
     * Finishes the upload, then adds the picture; the gate's savepoint takes the completion back when Notebooks refuses.
     *
     * @throws ToolRefusal not found when the upload or the notebook went meanwhile; a plain refusal for a rejected
     *                     picture, an expired upload, or a notebook rule (full, a title, summary or pin rule, changed
     *                     meanwhile)
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $notebook = $target?->subject;
        if (!$notebook instanceof NotebookView) {
            throw new \LogicException('add_notebook_screenshot runs on the notebook its targetOf() found.');
        }
        $picture = ToolUploadCompletion::completed($this->uploadCompletions, $this->organizationId(), $this->callerId(), ToolArguments::stringAt($arguments, 'upload_id', self::NAME), $this->clock->now(), self::UPLOAD_EXPIRED);
        if (MediaImageStatus::Rejected === $picture->status) {
            throw ToolRefusal::refused(self::PICTURE_REJECTED);
        }
        $screenshot = new ScreenshotWithPins($picture->mediaId, ToolArguments::optionalStringAt($arguments, 'title', self::NAME), ToolArguments::optionalStringAt($arguments, 'summary', self::NAME), PlacedPinArguments::of($arguments['pins'] ?? null, self::NAME));
        try {
            $added = $this->notebooks->addScreenshot($notebook->id, $screenshot, $this->clock->now());
        } catch (NotebookRefused $refused) {
            throw $refused->isNotFound() ? ToolRefusal::notFound() : ToolRefusal::refused($refused->getMessage());
        }

        $answer = [
            'notebook_id' => $notebook->id,
            'media_id' => $picture->mediaId,
            'status' => $added->wasAddedBefore ? self::ALREADY_IN_NOTEBOOK : self::ADDED,
            'screenshot_count' => $added->screenshotCount,
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * @throws \LogicException when the request has no token, which the api_token firewall never lets through
     */
    private function organizationId(): OrganizationId
    {
        return ($this->currentApiToken->access() ?? throw new \LogicException('add_notebook_screenshot ran without a personal access token.'))->organizationId;
    }

    /**
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    private function callerId(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('add_notebook_screenshot ran without a user.');
    }
}
