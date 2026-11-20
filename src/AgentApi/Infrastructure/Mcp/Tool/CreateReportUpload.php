<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaPermission;
use App\Media\Api\UploadBeginLimit;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\ReportDrafts;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectQueries;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * create_report_upload (P09.T9, ADR-0039): begins the upload of one picture for a report draft that create_report will
 * make, by the project's key. It is apart from create_upload because a tool needs one scope: this one is reports:write,
 * which a token made for Claude Code does not hold until its user ticks it. The same upload path as create_upload
 * (Uploads::begin, presigned PUT, the processing worker); MediaPermission UPLOAD on the new_report target lets anyone in
 * the organization upload for a project a team owns, as anyone may report a problem (ADR-0038), and refuses any other
 * key as not found. Holds no state (ADR-0008).
 */
final readonly class CreateReportUpload implements AgentTool
{
    public const string NAME = 'create_report_upload';
    public const string AUDIT_TARGET_TYPE = 'project';
    /** A project key is far shorter; the cap only keeps a lookup from reading megabytes. */
    private const int MAX_PROJECT_KEY_LENGTH = 20;
    /** The allowed types are 10 characters; the cap only keeps a refusal from echoing megabytes. */
    private const int MAX_MIME_LENGTH = 100;

    public function __construct(
        private CurrentUser $currentUser,
        private TicketVisibilityCheck $ticketVisibilityCheck,
        private ProjectQueries $projectQueries,
        private Uploads $uploads,
        private UploadBeginLimit $uploadBeginLimit,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Begin a screenshot upload for a new report',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_PROJECT_KEY_LENGTH, 'description' => 'The project key, such as SHOP.'],
                    'mime' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_MIME_LENGTH, 'description' => 'The file type: image/png, image/jpeg or image/webp.'],
                    'bytes' => ['type' => 'integer', 'description' => 'The exact size of the file in bytes, at most 15 MB.'],
                    // maxLength too: a validator that reads the pattern as PCRE would let a trailing newline past $.
                    'sha256' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[0-9a-fA-F]{64}$', 'description' => 'The SHA-256 of the file, in hex.'],
                ],
                'required' => ['project', 'mime', 'bytes', 'sha256'],
                'additionalProperties' => false,
            ],
            description: 'Begins the upload of one screenshot for a report you will file with create_report: {project,'
                .' mime, bytes, sha256}. Returns upload_id, put_url, headers and expires_at. Send the file itself, never'
                .' base64, with the headers as given, before expires_at: curl -T <file> -H "Content-Type: image/png"'
                .' "<put_url>". Then name the upload_id in create_report for the same project. Upload only when the'
                .' person has decided to file a report. Nothing is saved when the call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'reports:write';
    }

    /**
     * Reads the project by its key, so the audit names its id.
     *
     * @throws ToolRefusal not found for a key the organization has no project with
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $projectKey = self::stringAt($arguments, 'project');
        $project = $this->projectQueries->projectByKey($projectKey) ?? throw ToolRefusal::notFound();

        return new ToolTarget(MediaPermission::UPLOAD, new UploadTarget(ReportDrafts::NEW_REPORT_UPLOAD_TARGET_KIND, $project->key()), self::AUDIT_TARGET_TYPE, $project->id());
    }

    /**
     * Spends one of the caller's begins first, as create_upload does, so a refused call counts too.
     *
     * @throws ToolRefusal refused, with Media's plain message, past UploadBeginLimit or when Uploads refuses the type,
     *                     the size, a twenty-first upload waiting or a full storage; not found when the target is denied
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $uploadTarget = $target?->subject;
        if (!$uploadTarget instanceof UploadTarget) {
            throw new \LogicException('create_report_upload runs on the upload target its targetOf() made.');
        }
        $callerId = $this->callerId();
        try {
            $this->uploadBeginLimit->consume($callerId);
        } catch (UploadRefused $tooFast) {
            throw ToolRefusal::refused($tooFast->getMessage());
        }
        $bytes = $arguments['bytes'] ?? null;
        if (!\is_int($bytes)) {
            throw new \LogicException('The input schema lets create_report_upload run only with "bytes" as an integer.');
        }
        try {
            $slot = $this->uploads->begin($this->ticketVisibilityCheck->callerOrganizationId(), $callerId, $uploadTarget, CreateUpload::SCREENSHOT_PURPOSE, self::stringAt($arguments, 'mime'), $bytes, $this->clock->now());
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
        return $this->currentUser->id() ?? throw new \LogicException('create_report_upload ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets create_report_upload run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
