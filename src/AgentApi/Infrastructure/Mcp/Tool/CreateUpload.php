<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaPermission;
use App\Media\Api\UploadBeginLimit;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\OpenDraftQueries;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * create_upload (P09.T5 steps 1-2): begins a direct upload of one screenshot into the caller's own open unshared draft
 * round of a ticket, through Media's Uploads::begin with the checks of POST /media/intents, and answers where the agent
 * PUTs the file. The picture never passes through PHP (ADR-0011): this tool only stores the intent and signs the URL.
 * Holds no state (ADR-0008).
 *
 * A caller with a draft is guarded by MediaPermission UPLOAD on that draft, as the web's upload field is, once
 * TicketPermission VIEW grants the ticket, as set_verdict asks it: the draft's owner is granted it without the ticket
 * being read, so a ticket the caller may no longer see is not found. A caller without one is guarded by
 * RoundPermission DRAFT_CREATE on the ticket, and call() starts the draft before the upload begins, as an upload needs
 * a round to target; the gate's savepoint takes the new draft back when the begin is refused. Another person's draft
 * and a shared one are never a target: OpenDraftQueries::openDraftOf reads only the caller's own open unshared draft.
 *
 * The sha256 is checked for its form only: Media's PUT URL pins the type and the exact size, not the checksum.
 */
final readonly class CreateUpload implements AgentTool
{
    public const string NAME = 'create_upload';
    public const string ROUND_AUDIT_TYPE = 'round';
    public const string TICKET_AUDIT_TYPE = 'ticket';
    /** RoundMediaTarget::KIND, which this module may not read. */
    public const string ROUND_UPLOAD_KIND = 'round';
    /** UploadPurpose::Screenshot, which this module may not read. */
    public const string SCREENSHOT_PURPOSE = 'screenshot';

    /** The allowed types are 10 characters; the cap only keeps a refusal from echoing megabytes. */
    private const int MAX_MIME_LENGTH = 100;

    public function __construct(
        private CurrentUser $currentUser,
        private TicketKeyQueries $ticketKeyQueries,
        private TicketVisibilityCheck $ticketVisibilityCheck,
        private OpenDraftQueries $openDraftQueries,
        private RoundCommands $roundCommands,
        private Uploads $uploads,
        private AuthorizationCheckerInterface $authorizationChecker,
        private UploadBeginLimit $uploadBeginLimit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The type and size are left to Uploads::begin, so a wrong one gets the web's own plain message, not the schema's.
     */
    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Begin a screenshot upload',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The ticket key, such as SHOP-12.'],
                    'mime' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_MIME_LENGTH, 'description' => 'The file type: image/png, image/jpeg or image/webp.'],
                    'bytes' => ['type' => 'integer', 'description' => 'The exact size of the file in bytes, at most 15 MB.'],
                    // maxLength too: a validator that reads the pattern as PCRE would let a trailing newline past $.
                    'sha256' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[0-9a-fA-F]{64}$', 'description' => 'The SHA-256 of the file, in hex.'],
                ],
                'required' => ['key', 'mime', 'bytes', 'sha256'],
                'additionalProperties' => false,
            ],
            description: 'Begins the upload of one screenshot to your own draft round on a ticket: {key, mime, bytes,'
                .' sha256}. When you have no draft round there and you are the ticket\'s assignee or it is your turn on it, it starts one.'
                .' Returns upload_id, put_url, headers and expires_at. Then send the file itself, never base64, with'
                .' the headers as given, before expires_at: curl -T <file> -H "Content-Type: image/png" "<put_url>".'
                .' Then call add_screenshot with the upload_id. Nothing is saved when the call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'rounds:write';
    }

    /**
     * Reads the ticket's id by its key, then the caller's open unshared draft there, one query each. With a draft the
     * ticket is read for TicketPermission VIEW, and the voter is MediaPermission UPLOAD on the draft; without one,
     * RoundPermission DRAFT_CREATE on the ticket, which grants its assignee and the person whose turn it is (ADR-0044).
     *
     * @throws ToolRefusal     not found, for a key the organization has no ticket with, or a draft on a ticket
     *                         TicketPermission VIEW denies
     * @throws \LogicException without a token or a user, or when Ticketing cannot read the ticket's team
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $ticketId = $this->ticketKeyQueries->idOf(self::stringAt($arguments, 'key')) ?? throw ToolRefusal::notFound();
        $draft = $this->openDraftQueries->openDraftOf($ticketId, $this->callerId());
        if (null === $draft) {
            return new ToolTarget(RoundPermission::DRAFT_CREATE, $ticketId, self::TICKET_AUDIT_TYPE, $ticketId->toString());
        }
        $this->ticketVisibilityCheck->refuseUnlessVisible($ticketId);

        return new ToolTarget(MediaPermission::UPLOAD, new UploadTarget(self::ROUND_UPLOAD_KIND, $draft->roundId), self::ROUND_AUDIT_TYPE, $draft->roundId);
    }

    /**
     * Spends one of the caller's begins first, as the web does, so a refused call counts too: the limit is not
     * rolled back with the savepoint.
     *
     * @throws ToolRefusal     refused, with Media's plain message, past UploadBeginLimit or when Uploads refuses
     *                         the type, the size, a twenty-first upload waiting or a full storage; not found when the
     *                         new draft or the upload is denied
     * @throws \Throwable      what startDraft throws, such as RoundRefused DRAFT_EXISTS when another request
     *                         started the caller's draft meanwhile, and the begin counter's storage error
     * @throws \LogicException without a token, a user or a target of this tool
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $callerId = $this->callerId();
        try {
            $this->uploadBeginLimit->consume($callerId);
        } catch (UploadRefused $tooFast) {
            throw ToolRefusal::refused($tooFast->getMessage());
        }
        $uploadTarget = $this->uploadTargetOf($target, $callerId);
        $bytes = $arguments['bytes'] ?? null;
        if (!\is_int($bytes)) {
            throw new \LogicException('The input schema lets create_upload run only with "bytes" as an integer.');
        }
        try {
            $slot = $this->uploads->begin($this->ticketVisibilityCheck->callerOrganizationId(), $callerId, $uploadTarget, self::SCREENSHOT_PURPOSE, self::stringAt($arguments, 'mime'), $bytes, $this->clock->now());
        } catch (UploadRefused $refused) {
            throw ToolRefusal::refused($refused->getMessage());
        } catch (UploadTargetDenied) {
            throw ToolRefusal::notFound();
        }

        $answer = [
            'upload_id' => $slot->intentId,
            'put_url' => $slot->url,
            'headers' => $slot->headers,
            'expires_at' => $slot->expiresAt->format(\DATE_ATOM),
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }

    /**
     * The draft targetOf() found, or a new one on the ticket, which MediaPermission UPLOAD is asked on too.
     *
     * @throws ToolRefusal not found, when the voter denies an upload to the new draft
     */
    private function uploadTargetOf(?ToolTarget $target, UserId $callerId): UploadTarget
    {
        $subject = $target?->subject;
        if ($subject instanceof UploadTarget) {
            return $subject;
        }
        if (!$subject instanceof TicketId) {
            throw new \LogicException('create_upload ran without a target of its own.');
        }

        $draftId = $this->roundCommands->startDraft($subject, $callerId, $this->clock->now());
        $uploadTarget = new UploadTarget(self::ROUND_UPLOAD_KIND, $draftId);
        if (!$this->authorizationChecker->isGranted(MediaPermission::UPLOAD, $uploadTarget)) {
            throw ToolRefusal::notFound();
        }

        return $uploadTarget;
    }

    /**
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    private function callerId(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('create_upload ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets create_upload run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
