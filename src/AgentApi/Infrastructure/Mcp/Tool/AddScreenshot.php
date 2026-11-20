<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\PlacedPinArguments;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\ToolUploadCompletion;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadCompletions;
use App\Review\Api\DraftScreenshotCommands;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * add_screenshot (P09.T5 step 3, FR-RND-16): puts a picture the caller uploaded through create_upload into their own
 * unshared draft round of the ticket, with its title, summary and pins, through Review's DraftScreenshotCommands and the
 * voter of the draft round editor (RoundPermission), once TicketVisibilityCheck grants the ticket, as set_verdict and
 * create_upload ask it: a ticket the caller may no longer see is not found, also for the draft's owner, and the upload
 * stays unused. Holds no state (ADR-0008).
 *
 * The upload is finished with Media's UploadCompletions in the gate's savepoint. A picture still processing is a
 * non-error answer, not a refusal, so the completion is kept and the next call with the same upload_id finds it. Review
 * answers a picture already in the draft as it is, so the same upload_id never adds a second screenshot.
 */
final readonly class AddScreenshot implements AgentTool
{
    public const string NAME = 'add_screenshot';
    public const string AUDIT_TARGET_TYPE = 'round';
    public const string ADDED = 'added';
    public const string ADDED_BEFORE = 'added_before';
    public const string PROCESSING = 'processing';
    public const string STILL_PROCESSING = 'Still processing the picture. Call add_screenshot again with the same upload_id in a few seconds.';
    public const string PICTURE_REJECTED = 'The picture was rejected: it is not a PNG, JPEG or WebP screenshot we can read, or it is too big. Upload another one with create_upload.';
    public const string UPLOAD_EXPIRED = 'This upload expired before it was added. Begin a new one with create_upload.';

    public function __construct(
        private CurrentUser $currentUser,
        private TicketKeyQueries $ticketKeyQueries,
        private TicketVisibilityCheck $ticketVisibilityCheck,
        private UploadCompletions $uploadCompletions,
        private RoundQueries $roundQueries,
        private DraftScreenshotCommands $draftScreenshotCommands,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Add a screenshot',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The ticket key, such as SHOP-12.'],
                    'upload_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The upload_id create_upload gave, after the file was PUT to its put_url.'],
                    'title' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_TITLE_LENGTH, 'description' => \sprintf('What the picture is of, at most %d characters.', RoundCommands::MAX_TITLE_LENGTH)],
                    'summary' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_SUMMARY_LENGTH, 'description' => \sprintf('What the picture shows, in plain words: at most %d characters.', RoundCommands::MAX_SUMMARY_LENGTH)],
                    'pins' => PlacedPinArguments::schema(),
                ],
                'required' => ['key', 'upload_id'],
                'additionalProperties' => false,
            ],
            description: 'Adds a picture you uploaded with create_upload to your own private draft round on a ticket:'
                .' {key, upload_id, title?, summary?, pins?: [{x_pct, y_pct, note}]}. While the picture is still being'
                .' processed the answer has status "processing" and nothing is added yet: call again with the same'
                .' upload_id in a few seconds. Calling again after it was added adds nothing and answers status'
                .' "added_before" with the screenshot_id; the title, summary and pins of that call are not used. A'
                .' rejected picture or a shared draft is refused. Nothing is saved when the call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'rounds:write';
    }

    /**
     * Reads the ticket's id by its key, the ticket as TicketPermission VIEW decides on it, what the upload was begun
     * for, and that round, one query each. The ticket is asked here because RoundPermission grants an unshared draft
     * to its owner without reading it. The voter of an unshared draft is RoundPermission EDIT; of any other round
     * VIEW, as call() refuses it as read-only.
     *
     * @throws ToolRefusal     not found for an unknown key, a ticket the caller may not see, an upload the caller
     *                         did not begin, one of another organization, one begun for anything but a round, or a
     *                         round of another ticket
     * @throws \LogicException when the request has no token or user, or Ticketing cannot read the ticket's team
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $ticketId = $this->ticketKeyQueries->idOf(ToolArguments::stringAt($arguments, 'key', self::NAME)) ?? throw ToolRefusal::notFound();
        $this->ticketVisibilityCheck->refuseUnlessVisible($ticketId);
        $uploadTarget = $this->uploadCompletions->targetOf($this->ticketVisibilityCheck->callerOrganizationId(), $this->callerId(), ToolArguments::stringAt($arguments, 'upload_id', self::NAME));
        if (null === $uploadTarget || DraftScreenshotCommands::UPLOAD_TARGET_KIND !== $uploadTarget->kind) {
            throw ToolRefusal::notFound();
        }
        $round = $this->roundQueries->accessFactsOf($uploadTarget->id);
        if (null === $round || !$round->ticketId->equals($ticketId)) {
            throw ToolRefusal::notFound();
        }

        return new ToolTarget($round->isUnsharedDraft ? RoundPermission::EDIT : RoundPermission::VIEW, $round, self::AUDIT_TARGET_TYPE, $round->roundId);
    }

    /**
     * Finishes the upload, then adds the ready picture with its title, summary and pins in one Review command; the
     * gate's savepoint takes the completion back when Review refuses.
     *
     * @throws ToolRefusal     not found, read-only (a round that is not an unshared draft, or one shared meanwhile),
     *                         invalid input (content Review refuses), or a plain refusal (a rejected picture, an
     *                         expired upload, a draft that is full or changed meanwhile)
     * @throws \LogicException without a token, a user or a round target
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $round = $target?->subject;
        if (!$round instanceof RoundAccessFacts) {
            throw new \LogicException('add_screenshot runs on the round its targetOf() found.');
        }
        if (!$round->isUnsharedDraft) {
            throw ToolRefusal::readOnly();
        }
        $uploadId = ToolArguments::stringAt($arguments, 'upload_id', self::NAME);
        $picture = ToolUploadCompletion::completed($this->uploadCompletions, $this->ticketVisibilityCheck->callerOrganizationId(), $this->callerId(), $uploadId, $this->clock->now(), self::UPLOAD_EXPIRED);

        return match ($picture->status) {
            MediaImageStatus::Pending => self::answerOf(['upload_id' => $picture->mediaId, 'status' => self::PROCESSING, 'message' => self::STILL_PROCESSING]),
            MediaImageStatus::Rejected => throw ToolRefusal::refused(self::PICTURE_REJECTED),
            MediaImageStatus::Ready => $this->addOnce($arguments, $round->roundId, $picture->mediaId),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal as call() says, for Review's refusal
     */
    private function addOnce(array $arguments, string $roundId, string $mediaId): CallToolResult
    {
        $screenshot = new ScreenshotWithPins($mediaId, ToolArguments::optionalStringAt($arguments, 'title', self::NAME), ToolArguments::optionalStringAt($arguments, 'summary', self::NAME), PlacedPinArguments::of($arguments['pins'] ?? null, self::NAME));
        try {
            $added = $this->draftScreenshotCommands->addDraftScreenshotOnce($roundId, $this->callerId(), $screenshot, $this->clock->now());
        } catch (RoundRefused $refused) {
            throw self::toolRefusalOf($refused);
        }

        return self::answerOf([
            'screenshot_id' => $added->screenshotId,
            'round_id' => $roundId,
            'status' => $added->wasAddedBefore ? self::ADDED_BEFORE : self::ADDED,
            'pin_count' => $added->pinCount,
        ]);
    }

    /**
     * Review's refusal as the tool's, by the kind Review gave it. One of no kind, such as DRAFT_CHANGED for a draft
     * another request saved meanwhile, is plain text for the person, so it goes to the client as it is.
     */
    private static function toolRefusalOf(RoundRefused $refused): ToolRefusal
    {
        return match (true) {
            $refused->isNotFound() => ToolRefusal::notFound(),
            $refused->isReadOnly() => ToolRefusal::readOnly(),
            $refused->isInvalidContent() => ToolRefusal::invalidInput($refused->getMessage()),
            default => ToolRefusal::refused($refused->getMessage()),
        };
    }

    /**
     * @param array<string, mixed> $answer
     */
    private static function answerOf(array $answer): CallToolResult
    {
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
        return $this->currentUser->id() ?? throw new \LogicException('add_screenshot ran without a user.');
    }
}
