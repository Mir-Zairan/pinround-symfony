<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistView;
use App\Review\Api\PinNote;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\ScreenshotNotes;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
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
 * that has pictures (a shared draft round, or the report, Round 0), the caller's own unshared draft round exactly as
 * RoundQueries::roundNotes reads it, and the checklist. Another person's unshared draft is never read. Titles,
 * summaries and pin notes are wrapped as UntrustedText (ADR-0020).
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

    private const string REPORT_KIND = 'report';
    private const string AI_PIN = 'ai';
    private const string KEPT_AI_PIN = 'kept';

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

        $rounds = $this->roundQueries->forTicket($ticketId, $callerId)->rounds;
        $sharedRound = $this->latestSharedRoundOf($rounds);
        if (null !== $sharedRound && !$this->mayView($sharedRound->id)) {
            $sharedRound = null;
        }
        $draftNotes = $this->roundQueries->roundNotes($ticketId, $callerId);
        if (null !== $draftNotes && !$this->mayView($draftNotes->roundId)) {
            $draftNotes = null;
        }
        $checklist = $this->checklistQueries->forTicket($ticketId);
        if (null !== $checklist && !$this->mayView($checklist->roundId)) {
            $checklist = null;
        }

        $mediaIdsByScreenshot = self::mediaIdsOf($sharedRound, self::ownUnsharedDraftOf($rounds, $callerId), $draftNotes);
        $images = $this->imagesOf(array_values(array_unique($mediaIdsByScreenshot)));
        $imageOf = static function (string $screenshotId) use ($mediaIdsByScreenshot, $images): ?array {
            $mediaId = $mediaIdsByScreenshot[$screenshotId] ?? null;

            return null === $mediaId ? null : self::imageFieldsOf($images[strtolower($mediaId)] ?? null);
        };

        $notes = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'shared_round' => null === $sharedRound ? null : [
                'round_id' => $sharedRound->id,
                'number' => $sharedRound->number,
                'kind' => $sharedRound->kind,
                'screenshots' => array_map(static fn (RoundScreenshotView $screenshot): array => self::sharedScreenshotFieldsOf($screenshot, $imageOf($screenshot->id)), $sharedRound->screenshots),
            ],
            'draft_round' => null === $draftNotes ? null : [
                'round_id' => $draftNotes->roundId,
                'screenshots' => array_map(static fn (ScreenshotNotes $screenshot): array => self::draftScreenshotFieldsOf($screenshot, $imageOf($screenshot->screenshotId)), $draftNotes->screenshots),
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
     * Only the report and draft rounds come with their pictures from RoundQueries::forTicket; a checklist is answered
     * on its own, and a review round compares areas, which this tool does not read.
     *
     * @param list<RoundView> $rounds by number, as forTicket orders them
     */
    private function latestSharedRoundOf(array $rounds): ?RoundView
    {
        $latest = null;
        foreach ($rounds as $round) {
            $hasPictures = self::REPORT_KIND === $round->kind || RoundView::DRAFT === $round->kind;
            if ($hasPictures && null !== $round->number && (null === $latest || $round->number > $latest->number)) {
                $latest = $round;
            }
        }

        return $latest;
    }

    /**
     * @param list<RoundView> $rounds
     */
    private static function ownUnsharedDraftOf(array $rounds, UserId $callerId): ?RoundView
    {
        foreach ($rounds as $round) {
            if ($round->isUnsharedDraft() && $round->ownerId->equals($callerId)) {
                return $round;
            }
        }

        return null;
    }

    /**
     * RoundNotes carry no media id, so the draft's pictures are found in forTicket's copy of the same draft. A picture
     * added between the two reads has none and is answered without an image.
     *
     * @return array<string, string> media id by screenshot id, of the screenshots answered only
     */
    private static function mediaIdsOf(?RoundView $sharedRound, ?RoundView $ownDraft, ?RoundNotes $draftNotes): array
    {
        $mediaIds = [];
        foreach (null === $sharedRound ? [] : $sharedRound->screenshots as $screenshot) {
            $mediaIds[$screenshot->id] = $screenshot->mediaId;
        }
        if (null === $draftNotes || null === $ownDraft || $ownDraft->id !== $draftNotes->roundId) {
            return $mediaIds;
        }
        $answered = array_flip(array_map(static fn (ScreenshotNotes $screenshot): string => $screenshot->screenshotId, $draftNotes->screenshots));
        foreach ($ownDraft->screenshots as $screenshot) {
            if (isset($answered[$screenshot->id])) {
                $mediaIds[$screenshot->id] = $screenshot->mediaId;
            }
        }

        return $mediaIds;
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
     * @return array{status: string, url: ?string}|null null when the picture's media object was not found
     */
    private static function imageFieldsOf(?MediaImage $image): ?array
    {
        return null === $image ? null : ['status' => $image->status->value, 'url' => $image->url];
    }

    /**
     * @param array{status: string, url: ?string}|null $image
     *
     * @return array<string, mixed>
     */
    private static function sharedScreenshotFieldsOf(RoundScreenshotView $screenshot, ?array $image): array
    {
        $pins = [];
        foreach ($screenshot->pins as $pin) {
            if (self::counts($pin)) {
                $pins[] = self::pinFieldsOf($pin->number, $pin->xPercent, $pin->yPercent, $pin->note);
            }
        }

        return self::screenshotFieldsOf($screenshot->id, $screenshot->position, $image, $screenshot->title, $screenshot->summary, $screenshot->verdict, $pins);
    }

    /**
     * @param array{status: string, url: ?string}|null $image
     *
     * @return array<string, mixed>
     */
    private static function draftScreenshotFieldsOf(ScreenshotNotes $screenshot, ?array $image): array
    {
        $pins = array_map(static fn (PinNote $pin): array => self::pinFieldsOf($pin->number, $pin->xPercent, $pin->yPercent, $pin->note), $screenshot->pins);

        return self::screenshotFieldsOf($screenshot->screenshotId, $screenshot->position, $image, $screenshot->title, $screenshot->summary, $screenshot->verdict, $pins);
    }

    /**
     * A person's pin, or an AI pin a person kept (FR-RND-07, ADR-0020); an AI suggestion is no one's mark yet.
     */
    private static function counts(RoundPinView $pin): bool
    {
        return self::AI_PIN !== $pin->source || self::KEPT_AI_PIN === $pin->aiState;
    }

    /**
     * @param array{status: string, url: ?string}|null $image
     * @param list<array<string, mixed>>               $pins
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException when $verdict is not one the draft round card knows
     */
    private static function screenshotFieldsOf(string $screenshotId, int $position, ?array $image, ?string $title, ?string $summary, ?string $verdict, array $pins): array
    {
        return [
            'screenshot_id' => $screenshotId,
            'position' => $position,
            'image' => $image,
            'title' => UntrustedText::wrapOrNull($title),
            'summary' => UntrustedText::wrapOrNull($summary),
            'verdict' => match ($verdict) {
                RoundCommands::VERDICT_LOOKS_RIGHT => self::LOOKS_RIGHT,
                RoundCommands::VERDICT_NOT_RIGHT_YET => self::NOT_RIGHT_YET,
                null => null,
                default => throw new \UnexpectedValueException('A stored screenshot verdict is not one the draft round card knows.'),
            },
            'pins' => $pins,
        ];
    }

    /**
     * @return array{number: int, x_pct: float, y_pct: float, note: string}
     */
    private static function pinFieldsOf(int $number, float $xPercent, float $yPercent, string $note): array
    {
        return ['number' => $number, 'x_pct' => $xPercent, 'y_pct' => $yPercent, 'note' => UntrustedText::wrap($note)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checklistFieldsOf(ChecklistView $checklist): array
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
