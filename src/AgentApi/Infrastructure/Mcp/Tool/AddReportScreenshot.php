<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\PlacedPinArguments;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentUser;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadCompletions;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\ReportDraftPermission;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Api\ReportDraftView;
use App\Review\Api\RoundCommands;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\UserId;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * add_report_screenshot (P13.T5, ADR-0039): puts a picture the caller uploaded with create_report_upload for the
 * draft's project at the end of one of their own report drafts, with its title, summary and pins, through
 * ReportDrafts::addScreenshot. The upload is the same one create_report takes (kind new_report, id the project key), so
 * the browser extension needs no upload path of its own for a later capture. The target is the draft
 * ReportDrafts::ofOwner finds for the logged-in person, with ReportDraftPermission EDIT; another person's draft, an
 * upload the caller did not begin and one begun for anything but the draft's project are not found. The upload is
 * finished in the gate's savepoint, so a refusal leaves it unused. It never makes a ticket. Holds no state (ADR-0008).
 */
final readonly class AddReportScreenshot implements AgentTool
{
    public const string NAME = 'add_report_screenshot';
    public const string AUDIT_TARGET_TYPE = 'report_draft';
    public const string ADDED = 'added';
    /** The picture was in the draft already, as when the same call is sent twice; nothing changed. */
    public const string ALREADY_IN_DRAFT = 'already_in_draft';
    public const string PICTURE_REJECTED = 'The picture was rejected: it is not a PNG, JPEG or WebP screenshot we can read, or it is too big. Upload another one with create_report_upload.';
    public const string UPLOAD_EXPIRED = 'This upload expired before it was added. Begin a new one with create_report_upload.';

    /** A draft or upload id is 36 characters; the cap only keeps a lookup from reading megabytes. */
    private const int MAX_ID_LENGTH = 64;

    public function __construct(
        private CurrentUser $currentUser,
        private TicketVisibilityCheck $ticketVisibilityCheck,
        private ReportDrafts $reportDrafts,
        private UploadCompletions $uploadCompletions,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Add a screenshot to a report draft',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'report' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_ID_LENGTH, 'description' => 'The draft_id create_report gave.'],
                    'upload_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_ID_LENGTH, 'description' => 'The upload_id create_report_upload gave for the draft\'s project, after the file was PUT to its put_url.'],
                    'title' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_TITLE_LENGTH, 'description' => \sprintf('What the picture is of, at most %d characters.', RoundCommands::MAX_TITLE_LENGTH)],
                    'summary' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_SUMMARY_LENGTH, 'description' => \sprintf('What the picture shows, in plain words: at most %d characters.', RoundCommands::MAX_SUMMARY_LENGTH)],
                    'pins' => PlacedPinArguments::schema(),
                ],
                'required' => ['report', 'upload_id'],
                'additionalProperties' => false,
            ],
            description: 'Adds a picture at the end of one of your own report drafts: {report, upload_id, title?,'
                .' summary?, pins?: [{x_pct, y_pct, note}]}. Begin the upload with create_report_upload for the'
                .' draft\'s project, as for create_report. A draft holds at most '.ReportDrafts::MAX_SCREENSHOTS
                .' pictures; a picture in it already is not added twice. A picture still being processed is fine; a'
                .' rejected one is refused. It never makes a ticket. Answers draft_id, media_id, status and'
                .' screenshot_count. Nothing is saved when the call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'reports:write';
    }

    /**
     * Reads the draft, then what the upload was begun for, one query each.
     *
     * @throws ToolRefusal not found for a malformed or unknown draft id, another person's and another organization's
     *                     draft, an upload the caller did not begin, and one begun for anything but the draft's project
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $callerId = $this->callerId();
        $draft = $this->reportDrafts->ofOwner(self::stringAt($arguments, 'report'), $callerId) ?? throw ToolRefusal::notFound();
        $begunFor = $this->uploadCompletions->targetOf($this->ticketVisibilityCheck->callerOrganizationId(), $callerId, self::stringAt($arguments, 'upload_id'));
        if (null === $begunFor || ReportDrafts::NEW_REPORT_UPLOAD_TARGET_KIND !== $begunFor->kind || $begunFor->id !== $draft->projectKey) {
            throw ToolRefusal::notFound();
        }

        return new ToolTarget(ReportDraftPermission::EDIT, $draft, self::AUDIT_TARGET_TYPE, $draft->id);
    }

    /**
     * Finishes the upload, then adds the picture; the gate's savepoint takes the completion back when ReportDrafts
     * refuses.
     *
     * @throws ToolRefusal not found when the upload or the draft went meanwhile; a plain refusal for a rejected picture,
     *                     an expired upload, or a draft rule (full, a title, summary or pin rule, changed meanwhile)
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $draft = $target?->subject;
        if (!$draft instanceof ReportDraftView) {
            throw new \LogicException('add_report_screenshot runs on the report draft its targetOf() found.');
        }
        $picture = $this->completedUploadOf(self::stringAt($arguments, 'upload_id'));
        if (MediaImageStatus::Rejected === $picture->status) {
            throw ToolRefusal::refused(self::PICTURE_REJECTED);
        }
        $screenshot = new ScreenshotWithPins($picture->mediaId, self::optionalStringAt($arguments, 'title'), self::optionalStringAt($arguments, 'summary'), PlacedPinArguments::of($arguments['pins'] ?? null, self::NAME));
        try {
            $this->reportDrafts->addScreenshot($draft->id, $this->callerId(), $screenshot, $this->clock->now());
        } catch (ReportDraftRefused $refused) {
            throw $refused->isNotFound() ? ToolRefusal::notFound() : ToolRefusal::refused($refused->getMessage());
        }

        $isInDraftAlready = \in_array(strtolower($picture->mediaId), array_map(static fn (ScreenshotWithPins $saved): string => $saved->mediaId, $draft->screenshots), true);
        $answer = [
            'draft_id' => $draft->id,
            'media_id' => $picture->mediaId,
            'status' => $isInDraftAlready ? self::ALREADY_IN_DRAFT : self::ADDED,
            'screenshot_count' => \count($draft->screenshots) + ($isInDraftAlready ? 0 : 1),
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * @throws ToolRefusal not found when Media does not find the caller's intent or its target now refuses them; a
     *                     plain refusal when it expired unused
     */
    private function completedUploadOf(string $uploadId): CompletedUpload
    {
        try {
            return $this->uploadCompletions->completeInTransaction($this->ticketVisibilityCheck->callerOrganizationId(), $this->callerId(), $uploadId, $this->clock->now());
        } catch (UploadIntentNotFound|UploadTargetDenied) {
            throw ToolRefusal::notFound();
        } catch (UploadRefused) {
            throw ToolRefusal::refused(self::UPLOAD_EXPIRED);
        }
    }

    /**
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    private function callerId(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('add_report_screenshot ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        return self::optionalStringAt($arguments, $name) ?? throw new \LogicException(\sprintf('The input schema lets add_report_screenshot run only with "%s".', $name));
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function optionalStringAt(array $arguments, string $name): ?string
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets add_report_screenshot run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
