<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\PlacedPinArguments;
use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
use App\AgentApi\Infrastructure\Mcp\ScreenshotContextRequiredOverMcp;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\ToolUploadCompletion;
use App\Identity\Api\CurrentUser;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\MediaPermission;
use App\Media\Api\UploadCompletions;
use App\Media\Api\UploadTarget;
use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Api\RoundCommands;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectQueries;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * create_report (P09.T9, FR-MCP-12, ADR-0039): makes a report draft that only its owner sees, from the text and the
 * pictures the caller uploaded with create_report_upload for the same project. It never makes a ticket: the person opens
 * the draft in pinround, checks it and presses Send. The voter is MediaPermission UPLOAD on the project's new_report
 * target, the same right as the uploads; a key no project has, or one no team owns, is not found. Each upload is
 * finished in the gate's savepoint, so a refusal leaves the uploads unused. Over MCP each picture's title and summary
 * are required (P14.T11); /api/v1 for the browser extension keeps them optional. Holds no state (ADR-0008).
 */
final readonly class CreateReport implements ScreenshotContextRequiredOverMcp
{
    public const string NAME = 'create_report';
    public const string AUDIT_TARGET_TYPE = 'project';
    /** ReportDraftController::PAGE_ROUTE, which this module may not read. */
    public const string DRAFT_PAGE_ROUTE = 'review_report_draft';
    public const string PICTURE_REJECTED = 'A picture was rejected: it is not a PNG, JPEG or WebP screenshot we can read, or it is too big. Upload another one with create_report_upload.';
    public const string UPLOAD_EXPIRED = 'An upload expired before the report was made. Begin a new one with create_report_upload.';
    public const string NEXT_STEP = 'Saved as a private report draft. Open it in pinround, check it and press Send to make the ticket.';

    private const int MAX_PROJECT_KEY_LENGTH = 20;
    private const int MAX_REPORT_TITLE_LENGTH = 200;
    private const int MAX_DETAIL_LENGTH = 50000;

    public function __construct(
        private CurrentUser $currentUser,
        private TicketVisibilityCheck $ticketVisibilityCheck,
        private ProjectQueries $projectQueries,
        private UploadCompletions $uploadCompletions,
        private ReportDrafts $reportDrafts,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * /api/v1's, where each picture's title and summary stay optional.
     */
    public function definition(): Tool
    {
        return self::toolOf(self::pictureSchema(), 'title?, summary?', '');
    }

    public function mcpDefinition(): Tool
    {
        return self::toolOf(ScreenshotContextArguments::requiredIn(self::pictureSchema()), 'title, summary', ' '.ScreenshotContextArguments::RULE);
    }

    /**
     * A report without screenshots needs no picture context; the report's own title is Review's to refuse when blank.
     */
    public function refuseMissingScreenshotContext(array $arguments): void
    {
        $screenshots = $arguments['screenshots'] ?? null;
        if (!\is_array($screenshots) || !array_is_list($screenshots)) {
            return;
        }
        foreach ($screenshots as $position => $screenshot) {
            ScreenshotContextArguments::refuseMissingIn($screenshot, '/screenshots/'.$position);
        }
    }

    /**
     * @return array<string, mixed> one item of screenshots
     */
    private static function pictureSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'upload_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The upload_id create_report_upload gave, after the file was PUT.'],
                'title' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_TITLE_LENGTH, 'description' => \sprintf('What the picture is of, at most %d characters.', RoundCommands::MAX_TITLE_LENGTH)],
                'summary' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_SUMMARY_LENGTH, 'description' => \sprintf('What the picture shows, in plain words: at most %d characters.', RoundCommands::MAX_SUMMARY_LENGTH)],
                'pins' => PlacedPinArguments::schema(),
            ],
            'required' => ['upload_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $pictureSchema one item of screenshots
     * @param string               $pictureFields how the description writes a picture's title and summary
     * @param string               $pictureRule   appended to the description
     */
    private static function toolOf(array $pictureSchema, string $pictureFields, string $pictureRule): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Make a report draft',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_PROJECT_KEY_LENGTH, 'description' => 'The project key, such as SHOP.'],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_REPORT_TITLE_LENGTH, 'description' => 'What is wrong, in one line.'],
                    'detail' => ['type' => 'string', 'maxLength' => self::MAX_DETAIL_LENGTH, 'description' => 'More detail: the steps to see it, what you expected and what happened.'],
                    'screenshots' => [
                        'type' => 'array',
                        'maxItems' => ReportDrafts::MAX_SCREENSHOTS,
                        'description' => 'The pictures in the order to show them, each uploaded with create_report_upload for this project.',
                        'items' => $pictureSchema,
                    ],
                ],
                'required' => ['project', 'title'],
                'additionalProperties' => false,
            ],
            description: 'Makes a private report draft from your text and screenshots: {project, title, detail?,'
                .' screenshots?: [{upload_id, '.$pictureFields.', pins?: [{x_pct, y_pct, note}]}]}.'.$pictureRule.' It never makes a'
                .' ticket: only the person can, by opening the draft at the returned url and pressing Send. Use it'
                .' once the person said to file the report. A picture still processing is fine; Send waits for it.'
                .' Answers draft_id, url and screenshot_count. The texts you send are shown as the person\'s own report.',
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
     * @throws ToolRefusal not found for an upload the caller did not begin for this project; a plain refusal for a
     *                     rejected picture, an expired upload or content ReportDrafts refuses
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $uploadTarget = $target?->subject;
        if (!$uploadTarget instanceof UploadTarget) {
            throw new \LogicException('create_report runs on the upload target its targetOf() made.');
        }
        $callerId = $this->callerId();
        $organizationId = $this->ticketVisibilityCheck->callerOrganizationId();
        $now = $this->clock->now();

        $screenshots = [];
        foreach (self::screenshotArgumentsOf($arguments) as $screenshot) {
            $picture = $this->completedUploadOf($uploadTarget, $callerId, $screenshot['upload_id'], $now);
            if (MediaImageStatus::Rejected === $picture->status) {
                throw ToolRefusal::refused(self::PICTURE_REJECTED);
            }
            $screenshots[] = new ScreenshotWithPins($picture->mediaId, $screenshot['title'], $screenshot['summary'], $screenshot['pins']);
        }
        $detail = $arguments['detail'] ?? '';
        try {
            $draftId = $this->reportDrafts->create(
                $organizationId,
                $callerId,
                $uploadTarget->id,
                new ReportDraftContent(self::stringAt($arguments, 'title'), \is_string($detail) ? $detail : '', $screenshots),
                $now,
            );
        } catch (ReportDraftRefused $refused) {
            throw $refused->isNotFound() ? ToolRefusal::notFound() : ToolRefusal::refused($refused->getMessage());
        }

        $answer = [
            'draft_id' => $draftId,
            'url' => $this->urlGenerator->generate(self::DRAFT_PAGE_ROUTE, ['id' => $draftId], UrlGeneratorInterface::ABSOLUTE_URL),
            'screenshot_count' => \count($screenshots),
            'status' => 'draft',
            'message' => self::NEXT_STEP,
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * @throws ToolRefusal not found when the intent is not the caller's, was begun for anything but this project's new
     *                     report, or its target now refuses them; a plain refusal when it expired unused
     */
    private function completedUploadOf(UploadTarget $reportTarget, UserId $callerId, string $uploadId, \DateTimeImmutable $now): CompletedUpload
    {
        $organizationId = $this->ticketVisibilityCheck->callerOrganizationId();
        $begunFor = $this->uploadCompletions->targetOf($organizationId, $callerId, $uploadId);
        if (null === $begunFor || $begunFor->kind !== $reportTarget->kind || $begunFor->id !== $reportTarget->id) {
            throw ToolRefusal::notFound();
        }

        return ToolUploadCompletion::completed($this->uploadCompletions, $organizationId, $callerId, $uploadId, $now, self::UPLOAD_EXPIRED);
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     *
     * @return list<array{upload_id: string, title: ?string, summary: ?string, pins: list<PlacedPin>}>
     */
    private static function screenshotArgumentsOf(array $arguments): array
    {
        $screenshots = $arguments['screenshots'] ?? [];
        if (!\is_array($screenshots)) {
            throw new \LogicException('The input schema lets create_report run only with "screenshots" as a list.');
        }

        return array_values(array_map(static function (mixed $screenshot): array {
            if (!\is_array($screenshot) || !\is_string($screenshot['upload_id'] ?? null)) {
                throw new \LogicException('The input schema lets create_report run only with screenshots of {upload_id, …}.');
            }
            $title = $screenshot['title'] ?? null;
            $summary = $screenshot['summary'] ?? null;
            if ((null !== $title && !\is_string($title)) || (null !== $summary && !\is_string($summary))) {
                throw new \LogicException('The input schema lets create_report run only with a text title and summary.');
            }

            return [
                'upload_id' => $screenshot['upload_id'],
                'title' => $title,
                'summary' => $summary,
                'pins' => PlacedPinArguments::of($screenshot['pins'] ?? null, self::NAME),
            ];
        }, $screenshots));
    }

    /**
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    private function callerId(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('create_report ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets create_report run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
