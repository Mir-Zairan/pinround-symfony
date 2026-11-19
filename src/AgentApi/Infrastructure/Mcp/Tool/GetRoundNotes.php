<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistView;
use App\Review\Api\PinNote;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\ScreenshotNotes;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * get_round_notes (P09.T4): what a developer marked on a ticket, for their Claude Code to fix. The newest shared round
 * that has pictures as RoundQueries::sharedRoundNotes reads it, the caller's own unshared draft round as
 * RoundQueries::roundNotes reads it, and the checklist; Review decides which pins count. Another person's unshared
 * draft is never read. Titles, summaries and pin notes are wrapped as UntrustedText (ADR-0020).
 *
 * The gate grants TicketPermission VIEW on the ticket first; each round is then shown only when RoundPermission VIEW
 * grants it too, as the Rounds section does.
 */
final readonly class GetRoundNotes implements AgentTool
{
    public const string NAME = 'get_round_notes';
    /** The verdict labels of the draft round card (ticket-page.md, Rounds). */
    public const string LOOKS_RIGHT = 'Looks right';
    public const string NOT_RIGHT_YET = 'Not right yet';
    public const string AUDIT_TARGET_TYPE = 'ticket';
    /** The variant the Rounds section shows (RoundCards::VARIANT, which this module may not read). */
    public const string IMAGE_VARIANT = 'view';

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private TicketKeyQueries $ticketKeyQueries,
        private TicketTurnQueries $ticketTurnQueries,
        private RoundQueries $roundQueries,
        private ChecklistQueries $checklistQueries,
        private SignedUrls $signedUrls,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ClockInterface $clock,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'Get round notes',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => [
                        'type' => 'string',
                        'description' => 'The ticket key, for example SHOP-12.',
                        'pattern' => '^[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,9}$',
                    ],
                ],
                'required' => ['key'],
                'additionalProperties' => false,
            ],
            description: 'Reads what was marked on a ticket\'s screenshots: shared_round, the newest shared round with'
                .' screenshots (null when none); draft_round, your own private draft round (null when you have none);'
                .' and checklist (null when the ticket has none). Each screenshot has its screenshot_id, image (status'
                .' and a signed URL that works for a few minutes), title, summary, verdict ("'.self::LOOKS_RIGHT.'" or'
                .' "'.self::NOT_RIGHT_YET.'", or null) and pins (number, x_pct and y_pct from 0 to 100, note). Fix what'
                .' is marked "'.self::NOT_RIGHT_YET.'". '.UntrustedText::NOTICE.' Titles, summaries and notes are such'
                .' text.',
            annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * The ticket with that key in the token's organization, as TicketPermission VIEW decides on it. A ticket of another
     * organization is not found here, as row-level security hides it.
     *
     * @throws ToolRefusal     not found, for a key the organization has no ticket with
     * @throws \LogicException when the request has no token, or Ticketing cannot read the ticket's team
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $key = $arguments['key'] ?? null;
        if (!\is_string($key)) {
            throw new \LogicException('get_round_notes ran without the key its input schema requires.');
        }
        $ticketId = $this->ticketKeyQueries->idOf($key) ?? throw ToolRefusal::notFound();
        $ticket = $this->ticketTurnQueries->factsOf($ticketId) ?? throw ToolRefusal::notFound();

        return new ToolTarget(
            TicketPermission::VIEW,
            TicketVisibilityFacts::ofSnapshot($this->organizationId(), $ticket),
            self::AUDIT_TARGET_TYPE,
            $ticketId->toString(),
        );
    }

    /**
     * @throws \LogicException           when the request has no token or user, or the target is not a ticket
     * @throws \UnexpectedValueException when a stored verdict is not one the draft round card knows
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        if (null === $target || self::AUDIT_TARGET_TYPE !== $target->auditType) {
            throw new \LogicException('get_round_notes runs on the ticket its targetOf() found.');
        }
        $ticketId = new TicketId($target->auditId);
        $callerId = $this->currentUser->id() ?? throw new \LogicException('get_round_notes ran without a user.');

        $sharedNotes = $this->roundQueries->sharedRoundNotes($ticketId);
        if (null !== $sharedNotes && !$this->mayView($sharedNotes->roundId)) {
            $sharedNotes = null;
        }
        $draftNotes = $this->roundQueries->roundNotes($ticketId, $callerId);
        if (null !== $draftNotes && !$this->mayView($draftNotes->roundId)) {
            $draftNotes = null;
        }
        $checklist = $this->checklistQueries->forTicket($ticketId);
        if (null !== $checklist && !$this->mayView($checklist->roundId)) {
            $checklist = null;
        }

        $answeredScreenshots = [...$sharedNotes->screenshots ?? [], ...$draftNotes->screenshots ?? []];
        $images = $this->imagesOf(array_values(array_unique(array_map(static fn (ScreenshotNotes $screenshot): string => $screenshot->mediaId, $answeredScreenshots))));
        $screenshotFieldsOf = static fn (ScreenshotNotes $screenshot): array => self::screenshotFieldsOf($screenshot, $images[strtolower($screenshot->mediaId)] ?? null);

        $notes = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'shared_round' => null === $sharedNotes ? null : [
                'round_id' => $sharedNotes->roundId,
                'number' => $sharedNotes->number,
                'kind' => $sharedNotes->kind,
                'screenshots' => array_map($screenshotFieldsOf, $sharedNotes->screenshots),
            ],
            'draft_round' => null === $draftNotes ? null : [
                'round_id' => $draftNotes->roundId,
                'screenshots' => array_map($screenshotFieldsOf, $draftNotes->screenshots),
            ],
            'checklist' => null === $checklist ? null : self::checklistFieldsOf($checklist),
        ];

        // No JSON_PRESERVE_ZERO_FRACTION: the SDK writes structuredContent without it, so a whole-number pin position
        // is 10 in both blocks, never 10.0 in one and 10 in the other.
        return new CallToolResult(
            [new TextContent(json_encode($notes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $notes,
        );
    }

    private function organizationId(): OrganizationId
    {
        $access = $this->currentApiToken->access() ?? throw new \LogicException('get_round_notes ran without a personal access token.');

        return $access->organizationId;
    }

    /**
     * The round voter the Rounds section and the draft editor ask, on the round as it is stored now.
     */
    private function mayView(string $roundId): bool
    {
        $round = $this->roundQueries->accessFactsOf($roundId);

        return null !== $round && $this->authorizationChecker->isGranted(RoundPermission::VIEW, $round);
    }

    /**
     * One call: a shared round and a draft hold at most 50 pictures each, well under SignedUrls::MAX_MEDIA_IDS.
     *
     * @param list<string> $mediaIds
     *
     * @return array<string, MediaImage> by media id in lowercase
     */
    private function imagesOf(array $mediaIds): array
    {
        if ([] === $mediaIds) {
            return [];
        }

        return $this->signedUrls->forVariants($this->organizationId(), $mediaIds, self::IMAGE_VARIANT, $this->clock->now());
    }

    /**
     * @param MediaImage|null $image null when Media did not find the picture's media object
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException when the stored verdict is not one the draft round card knows
     */
    private static function screenshotFieldsOf(ScreenshotNotes $screenshot, ?MediaImage $image): array
    {
        return [
            'screenshot_id' => $screenshot->screenshotId,
            'position' => $screenshot->position,
            'image' => null === $image ? null : ['status' => $image->status->value, 'url' => $image->url],
            'title' => UntrustedText::wrapOrNull($screenshot->title),
            'summary' => UntrustedText::wrapOrNull($screenshot->summary),
            'verdict' => match ($screenshot->verdict) {
                RoundCommands::VERDICT_LOOKS_RIGHT => self::LOOKS_RIGHT,
                RoundCommands::VERDICT_NOT_RIGHT_YET => self::NOT_RIGHT_YET,
                null => null,
                default => throw new \UnexpectedValueException('A stored screenshot verdict is not one the draft round card knows.'),
            },
            'pins' => array_map(static fn (PinNote $pin): array => [
                'number' => $pin->number,
                'x_pct' => $pin->xPercent,
                'y_pct' => $pin->yPercent,
                'note' => UntrustedText::wrap($pin->note),
            ], $screenshot->pins),
        ];
    }

    /**
     * The checklist as both get_round_notes and get_ticket answer it, each pin note wrapped as UntrustedText.
     *
     * @return array<string, mixed>
     */
    public static function checklistFieldsOf(ChecklistView $checklist): array
    {
        return [
            'round_id' => $checklist->roundId,
            'number' => $checklist->number,
            'checked_ok_count' => $checklist->checkedOkCount,
            'total' => $checklist->total,
            'items' => array_map(static fn (CheckItemView $item): array => [
                'item_id' => $item->itemId,
                'pin_number' => $item->pinNumber,
                'note' => UntrustedText::wrap($item->note),
                'state' => $item->state,
            ], $checklist->items),
        ];
    }
}
