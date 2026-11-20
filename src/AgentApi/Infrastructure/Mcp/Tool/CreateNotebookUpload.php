<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\UploadBeginLimit;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\NotebookPermission;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebookView;
use App\SharedKernel\Domain\UserId;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * create_notebook_upload (P13.T8, ADR-0046, ADR-0011): begins the upload of one picture for one of the caller's own
 * notebooks, by Uploads::begin with the target kind Notebooks::UPLOAD_TARGET_KIND and the notebook's id: a presigned PUT,
 * then the processing worker, as create_upload does. The target is the notebook Notebooks::get finds for the logged-in
 * person, with NotebookPermission EDIT; anything else is not found. Holds no state (ADR-0008).
 */
final readonly class CreateNotebookUpload implements AgentTool
{
    public const string NAME = 'create_notebook_upload';
    public const string AUDIT_TARGET_TYPE = 'notebook';
    /** The allowed types are 10 characters; the cap only keeps a refusal from echoing megabytes. */
    private const int MAX_MIME_LENGTH = 100;

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private Notebooks $notebooks,
        private Uploads $uploads,
        private UploadBeginLimit $uploadBeginLimit,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Begin a screenshot upload for a notebook',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'notebook' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The notebook_id create_notebook or list_my_notebooks gave.'],
                    'mime' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_MIME_LENGTH, 'description' => 'The file type: image/png, image/jpeg or image/webp.'],
                    'bytes' => ['type' => 'integer', 'description' => 'The exact size of the file in bytes, at most 15 MB.'],
                    // maxLength too: a validator that reads the pattern as PCRE would let a trailing newline past $.
                    'sha256' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[0-9a-fA-F]{64}$', 'description' => 'The SHA-256 of the file, in hex.'],
                ],
                'required' => ['notebook', 'mime', 'bytes', 'sha256'],
                'additionalProperties' => false,
            ],
            description: 'Begins the upload of one screenshot for one of your own notebooks: {notebook, mime, bytes,'
                .' sha256}. Returns upload_id, put_url, headers and expires_at. Send the file itself, never base64, with'
                .' the headers as given, before expires_at: curl -T <file> -H "Content-Type: image/png" "<put_url>".'
                .' Then name the upload_id in add_notebook_screenshot for the same notebook. Nothing is saved when the'
                .' call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
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
        $notebook = $this->notebooks->get(self::stringAt($arguments, 'notebook')) ?? throw ToolRefusal::notFound();

        return new ToolTarget(NotebookPermission::EDIT, $notebook, self::AUDIT_TARGET_TYPE, $notebook->id);
    }

    /**
     * Spends one of the caller's begins first, as create_upload does, so a refused call counts too.
     *
     * @throws ToolRefusal refused, with Media's plain message, past UploadBeginLimit or when Uploads refuses the type,
     *                     the size, a twenty-first upload waiting or a full storage; not found when the target is denied
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $notebook = $target?->subject;
        if (!$notebook instanceof NotebookView) {
            throw new \LogicException('create_notebook_upload runs on the notebook its targetOf() found.');
        }
        $callerId = $this->callerId();
        try {
            $this->uploadBeginLimit->consume($callerId);
        } catch (UploadRefused $tooFast) {
            throw ToolRefusal::refused($tooFast->getMessage());
        }
        $bytes = $arguments['bytes'] ?? null;
        if (!\is_int($bytes)) {
            throw new \LogicException('The input schema lets create_notebook_upload run only with "bytes" as an integer.');
        }
        $access = $this->currentApiToken->access() ?? throw new \LogicException('create_notebook_upload ran without a personal access token.');
        try {
            $slot = $this->uploads->begin($access->organizationId, $callerId, new UploadTarget(Notebooks::UPLOAD_TARGET_KIND, $notebook->id), CreateUpload::SCREENSHOT_PURPOSE, self::stringAt($arguments, 'mime'), $bytes, $this->clock->now());
        } catch (UploadRefused $refused) {
            throw ToolRefusal::refused($refused->getMessage());
        } catch (UploadTargetDenied) {
            throw ToolRefusal::notFound();
        }

        $answer = ['upload_id' => $slot->intentId, 'put_url' => $slot->url, 'headers' => $slot->headers, 'expires_at' => $slot->expiresAt->format(\DATE_ATOM)];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    private function callerId(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('create_notebook_upload ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets create_notebook_upload run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
