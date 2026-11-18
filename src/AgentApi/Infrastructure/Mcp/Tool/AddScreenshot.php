<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadCompletions;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * add_screenshot (P09.T5 step 3, FR-RND-16): puts a picture the caller uploaded through create_upload into their own
 * unshared draft round of the ticket, with its title, summary and pins, through Review's draft commands and the voter of
 * the draft round editor (RoundPermission). Holds no state (ADR-0008).
 *
 * The upload is finished with Media's UploadCompletions in the gate's savepoint. A picture still processing is a
 * non-error answer, not a refusal, so the completion is kept and the next call with the same upload_id finds it. A
 * picture already in the draft is answered as it is, so the same upload_id never adds a second screenshot.
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

    /** RoundMediaTarget::KIND, which this module may not read. */
    private const string ROUND_UPLOAD_KIND = 'round';
    /** Review's caps (Screenshot and Pin), which this module may not read; Review checks them again after trimming. */
    private const int MAX_TITLE_LENGTH = 120;
    private const int MAX_SUMMARY_LENGTH = 600;
    private const int MAX_PIN_NOTE_LENGTH = 1000;
    /** A ticket key or an id is far shorter; the cap only keeps a lookup from reading megabytes. */
    private const int MAX_KEY_OR_ID_LENGTH = 64;

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private TicketKeyQueries $ticketKeyQueries,
        private UploadCompletions $uploadCompletions,
        private RoundQueries $roundQueries,
        private RoundCommands $roundCommands,
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
                    'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_KEY_OR_ID_LENGTH, 'description' => 'The ticket key, such as SHOP-12.'],
                    'upload_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_KEY_OR_ID_LENGTH, 'description' => 'The upload_id create_upload gave, after the file was PUT to its put_url.'],
                    'title' => ['type' => 'string', 'maxLength' => self::MAX_TITLE_LENGTH, 'description' => 'What the picture is of, at most 120 characters.'],
                    'summary' => ['type' => 'string', 'maxLength' => self::MAX_SUMMARY_LENGTH, 'description' => 'What the picture shows, in plain words: at most 600 characters.'],
                    'pins' => [
                        'type' => 'array',
                        'maxItems' => RoundCommands::MAX_PINS_PER_SCREENSHOT,
                        'description' => 'Pins on the picture, numbered 1, 2, … in this order.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'x_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'From the left edge, in percent of the width.'],
                                'y_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'From the top edge, in percent of the height.'],
                                'note' => ['type' => 'string', 'maxLength' => self::MAX_PIN_NOTE_LENGTH, 'description' => 'What is at the pin, at most 1,000 characters.'],
                            ],
                            'required' => ['x_pct', 'y_pct', 'note'],
                            'additionalProperties' => false,
                        ],
                    ],
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
     * Reads the ticket's id by its key, what the upload was begun for, and that round, one query each. The voter of an
     * unshared draft is RoundPermission EDIT; of any other round VIEW, as call() refuses it as read-only.
     *
     * @throws ToolRefusal     not found for an unknown key, an upload the caller did not begin, one of another
     *                         organization, one begun for anything but a round, or a round of another ticket
     * @throws \LogicException when the request has no token or user
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $ticketId = $this->ticketKeyQueries->idOf(self::stringAt($arguments, 'key')) ?? throw ToolRefusal::notFound();
        $uploadTarget = $this->uploadCompletions->targetOf($this->organizationId(), $this->callerId(), self::stringAt($arguments, 'upload_id'));
        if (null === $uploadTarget || self::ROUND_UPLOAD_KIND !== $uploadTarget->kind) {
            throw ToolRefusal::notFound();
        }
        $round = $this->roundQueries->accessFactsOf($uploadTarget->id);
        if (null === $round || !$round->ticketId->equals($ticketId)) {
            throw ToolRefusal::notFound();
        }

        return new ToolTarget($round->isUnsharedDraft ? RoundPermission::EDIT : RoundPermission::VIEW, $round, self::AUDIT_TARGET_TYPE, $round->roundId);
    }

    /**
     * Finishes the upload, then adds the ready picture with its title, summary and pins, each through its own Review
     * command; the gate's savepoint takes all of them back when one refuses.
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
        $uploadId = self::stringAt($arguments, 'upload_id');
        $picture = $this->completedUploadOf($uploadId);

        return match ($picture->status) {
            MediaImageStatus::Pending => self::answerOf(['upload_id' => $picture->mediaId, 'status' => self::PROCESSING, 'message' => self::STILL_PROCESSING]),
            MediaImageStatus::Rejected => throw ToolRefusal::refused(self::PICTURE_REJECTED),
            MediaImageStatus::Ready => $this->addOnce($arguments, $round->roundId, $picture->mediaId),
        };
    }

    /**
     * @throws ToolRefusal not found when Media does not find the caller's intent or its target now refuses them; a
     *                     plain refusal when it expired unused
     */
    private function completedUploadOf(string $uploadId): CompletedUpload
    {
        try {
            return $this->uploadCompletions->completeInTransaction($this->organizationId(), $this->callerId(), $uploadId, $this->clock->now());
        } catch (UploadIntentNotFound|UploadTargetDenied) {
            throw ToolRefusal::notFound();
        } catch (UploadRefused) {
            throw ToolRefusal::refused(self::UPLOAD_EXPIRED);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function addOnce(array $arguments, string $roundId, string $mediaId): CallToolResult
    {
        $callerId = $this->callerId();
        $draft = $this->roundQueries->draftOf($roundId, $callerId) ?? throw ToolRefusal::notFound();
        foreach ($draft->screenshots as $screenshot) {
            if (strtolower($screenshot->mediaId) === strtolower($mediaId)) {
                return self::answerOf(['screenshot_id' => $screenshot->id, 'round_id' => $draft->id, 'status' => self::ADDED_BEFORE, 'pin_count' => \count($screenshot->pins)]);
            }
        }

        $title = self::optionalStringAt($arguments, 'title');
        $summary = self::optionalStringAt($arguments, 'summary');
        $pins = self::placedPinsOf($arguments);
        $now = $this->clock->now();
        try {
            $screenshotId = $this->roundCommands->addDraftScreenshot($roundId, $callerId, $mediaId, $now);
            if (null !== $title || null !== $summary) {
                $this->roundCommands->editDraftScreenshot($roundId, $callerId, $screenshotId, $title, $summary, $now);
            }
            if ([] !== $pins) {
                $this->roundCommands->setDraftPins($roundId, $callerId, $screenshotId, $pins, $now);
            }
        } catch (RoundRefused $refused) {
            throw self::toolRefusalOf($refused);
        }

        return self::answerOf(['screenshot_id' => $screenshotId, 'round_id' => $roundId, 'status' => self::ADDED, 'pin_count' => \count($pins)]);
    }

    /**
     * Review's refusal as the tool's, by the kind Review gave it. One of no kind, such as a full draft or one another
     * request saved meanwhile, is plain text for the person, so it goes to the client as it is.
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
     * @param array<string, mixed> $arguments valid against the input schema
     *
     * @return list<PlacedPin>
     */
    private static function placedPinsOf(array $arguments): array
    {
        $pins = $arguments['pins'] ?? [];
        if (!\is_array($pins)) {
            throw new \LogicException('The input schema lets add_screenshot run only with "pins" as a list.');
        }

        return array_values(array_map(static function (mixed $pin): PlacedPin {
            $xPercent = \is_array($pin) ? ($pin['x_pct'] ?? null) : null;
            $yPercent = \is_array($pin) ? ($pin['y_pct'] ?? null) : null;
            $note = \is_array($pin) ? ($pin['note'] ?? null) : null;
            if (!(\is_int($xPercent) || \is_float($xPercent)) || !(\is_int($yPercent) || \is_float($yPercent)) || !\is_string($note)) {
                throw new \LogicException('The input schema lets add_screenshot run only with pins of {x_pct, y_pct, note}.');
            }

            return new PlacedPin((float) $xPercent, (float) $yPercent, $note);
        }, $pins));
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
     * @throws \LogicException when the request has no token, which the api_token firewall never lets through
     */
    private function organizationId(): OrganizationId
    {
        $access = $this->currentApiToken->access() ?? throw new \LogicException('add_screenshot ran without a personal access token.');

        return $access->organizationId;
    }

    /**
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    private function callerId(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('add_screenshot ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        return self::optionalStringAt($arguments, $name) ?? throw new \LogicException(\sprintf('The input schema lets add_screenshot run only with "%s".', $name));
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function optionalStringAt(array $arguments, string $name): ?string
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets add_screenshot run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
