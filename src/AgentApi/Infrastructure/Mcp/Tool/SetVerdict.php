<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentUser;
use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistPermission;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistRefused;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundRefused;
use App\Review\Api\RoundView;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * set_verdict (FR-MCP-06, P09.T4): "Looks right" or "Not right yet", and optionally the summary, on a screenshot of the
 * caller's own unshared draft round; or a checklist item marked fixed by the ticket's assignee. Through Review's Api
 * with the voters of the draft round editor (RoundPermission) and the checklist card (ChecklistPermission MARK_FIXED).
 * Holds no state (ADR-0008).
 *
 * A screenshot is looked for among the drafts RoundQueries::forTicket shows the caller, so another person's unshared
 * draft is not found. A shared draft is read-only for everyone who may see it, its owner included (FR-RND-12); one
 * past the newest RoundQueries::MAX_NUMBERED_ROUNDS rounds is not loaded and so not found. A checklist item the voter
 * denies, such as one of a ticket the caller is not the assignee of, is not found too, as every denial of the gate.
 */
final readonly class SetVerdict implements AgentTool
{
    public const string NAME = 'set_verdict';
    public const string LOOKS_RIGHT = 'looks_right';
    public const string NOT_RIGHT_YET = 'not_right_yet';
    public const string FIXED = 'fixed';
    public const string SCREENSHOT_AUDIT_TYPE = 'screenshot';
    public const string CHECK_ITEM_AUDIT_TYPE = 'check_item';

    /** The verdict names of the tool, which the draft round card shows as these labels. */
    public const array VERDICT_LABELS = [self::LOOKS_RIGHT => 'Looks right', self::NOT_RIGHT_YET => 'Not right yet'];

    private const array ROUND_VERDICTS = [self::LOOKS_RIGHT => RoundCommands::VERDICT_LOOKS_RIGHT, self::NOT_RIGHT_YET => RoundCommands::VERDICT_NOT_RIGHT_YET];
    /** A ticket key or an id is far shorter; the cap only keeps a lookup from reading megabytes. */
    private const int MAX_KEY_OR_ID_LENGTH = 64;

    public function __construct(
        private CurrentUser $currentUser,
        private TicketKeyQueries $ticketKeyQueries,
        private RoundQueries $roundQueries,
        private RoundCommands $roundCommands,
        private ChecklistQueries $checklistQueries,
        private ChecklistCommands $checklistCommands,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Two shapes, told apart by screenshot_id or check_item_id; a mix of them is invalid input. Neither oneOf nor
     * anyOf at the top: Claude's tool input schema refuses them there, so dependentRequired and dependentSchemas
     * (JSON Schema 2020-12) carry the rule.
     */
    public function definition(): Tool
    {
        $idOf = static fn (string $description): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_KEY_OR_ID_LENGTH, 'description' => $description];

        return new Tool(
            name: self::NAME,
            title: 'Set a verdict',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => $idOf('The ticket key, such as SHOP-12.'),
                    'screenshot_id' => $idOf('A screenshot of your own draft round on the ticket, as get_round_notes lists it.'),
                    'verdict' => ['type' => 'string', 'enum' => array_keys(self::VERDICT_LABELS), 'description' => 'looks_right is "Looks right", not_right_yet is "Not right yet".'],
                    'summary' => ['type' => 'string', 'description' => 'What the picture shows, in plain words: at most 600 characters after trimming. An empty one removes the summary; leave it out to keep the one there is.'],
                    'check_item_id' => $idOf('A checklist item of the ticket, as get_round_notes lists it.'),
                    'state' => ['type' => 'string', 'enum' => [self::FIXED], 'description' => 'Only "fixed": you mark the item fixed for the tester to check.'],
                ],
                'required' => ['key'],
                'additionalProperties' => false,
                // {key} alone: every other field pulls in screenshot_id or check_item_id below.
                'minProperties' => 2,
                'dependentRequired' => [
                    'screenshot_id' => ['verdict'],
                    'verdict' => ['screenshot_id'],
                    'summary' => ['screenshot_id'],
                    'check_item_id' => ['state'],
                    'state' => ['check_item_id'],
                ],
                'dependentSchemas' => [
                    'screenshot_id' => ['properties' => ['check_item_id' => false, 'state' => false]],
                    'check_item_id' => ['properties' => ['screenshot_id' => false, 'verdict' => false, 'summary' => false]],
                ],
            ],
            description: 'Sets your verdict on a screenshot of your own draft round on a ticket: {key, screenshot_id,'
                .' verdict, summary?}, where verdict is looks_right ("Looks right") or not_right_yet ("Not right yet")'
                .' and summary, when given, replaces the screenshot\'s summary in the same save. Or marks a checklist'
                .' item fixed when you are the ticket\'s assignee: {key, check_item_id, state: "fixed"}. Send one shape,'
                .' never both. A shared draft is read-only. Nothing is saved when the call is refused.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'rounds:write';
    }

    /**
     * Reads the ticket's id by its key, then its drafts the caller may see (four queries at most) or the checklist
     * item (one query). The voter of an unshared draft is RoundPermission EDIT; of a shared one VIEW, as it is
     * read-only and call() refuses it so; of a checklist item ChecklistPermission MARK_FIXED.
     *
     * @throws ToolRefusal     not found for an unknown key, a ticket the caller may not see, or a screenshot or item
     *                         that is not of that ticket's drafts the caller sees or of its checklist
     * @throws \LogicException when the request has no user, which the api_token firewall never lets through
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $ticketId = $this->ticketKeyQueries->idOf(self::stringAt($arguments, 'key')) ?? throw ToolRefusal::notFound();
        if (\array_key_exists('check_item_id', $arguments)) {
            return $this->checkItemTargetOf($ticketId, self::stringAt($arguments, 'check_item_id'));
        }

        return $this->screenshotTargetOf($ticketId, self::stringAt($arguments, 'screenshot_id'));
    }

    /**
     * Verdict and summary are saved in the gate's savepoint, so a refused summary leaves the verdict unsaved too.
     *
     * @throws ToolRefusal     not found, read-only (a shared or closed draft, a checklist item marked fixed or OK
     *                         already), or invalid input (a summary over 600 characters)
     * @throws RoundRefused    DRAFT_CHANGED, when another request saved the draft meanwhile
     * @throws \LogicException without a user or a target of this tool
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $subject = $target?->subject;
        if ($subject instanceof CheckItemAccessFacts) {
            return $this->markFixed($subject);
        }
        if ($subject instanceof RoundAccessFacts) {
            return $this->setScreenshotVerdict($arguments, $subject, $target->auditId);
        }

        throw new \LogicException('set_verdict ran without a target of its own.');
    }

    private function screenshotTargetOf(TicketId $ticketId, string $screenshotId): ToolTarget
    {
        $wantedId = strtolower($screenshotId);
        foreach ($this->roundQueries->forTicket($ticketId, $this->callerId())->rounds as $round) {
            if (RoundView::DRAFT !== $round->kind) {
                continue;
            }
            foreach ($round->screenshots as $screenshot) {
                if ($screenshot->id !== $wantedId) {
                    continue;
                }
                $facts = $this->roundQueries->accessFactsOf($round->id) ?? throw ToolRefusal::notFound();

                return new ToolTarget($facts->isUnsharedDraft ? RoundPermission::EDIT : RoundPermission::VIEW, $facts, self::SCREENSHOT_AUDIT_TYPE, $screenshot->id);
            }
        }

        throw ToolRefusal::notFound();
    }

    private function checkItemTargetOf(TicketId $ticketId, string $checkItemId): ToolTarget
    {
        $item = $this->checklistQueries->accessFactsOf($checkItemId);
        if (null === $item || !$item->ticketId->equals($ticketId)) {
            throw ToolRefusal::notFound();
        }

        return new ToolTarget(ChecklistPermission::MARK_FIXED, $item, self::CHECK_ITEM_AUDIT_TYPE, $item->itemId);
    }

    /**
     * The verdict first: its save locks the draft's row, so the title read after it is the one the summary's save
     * keeps, and no title saved meanwhile in the web is put back.
     *
     * @param array<string, mixed> $arguments
     */
    private function setScreenshotVerdict(array $arguments, RoundAccessFacts $round, string $screenshotId): CallToolResult
    {
        if (!$round->isUnsharedDraft) {
            throw ToolRefusal::readOnly();
        }
        $verdict = self::stringAt($arguments, 'verdict');
        $roundVerdict = self::ROUND_VERDICTS[$verdict] ?? throw new \LogicException('The input schema lets only the known verdicts through.');
        $callerId = $this->callerId();
        $now = $this->clock->now();
        $hasSummary = \array_key_exists('summary', $arguments);

        try {
            $this->roundCommands->setDraftVerdict($round->roundId, $callerId, $screenshotId, $roundVerdict, $now);
            if ($hasSummary) {
                $title = $this->titleOf($round->roundId, $callerId, $screenshotId);
                $this->roundCommands->editDraftScreenshot($round->roundId, $callerId, $screenshotId, $title, self::stringAt($arguments, 'summary'), $now);
            }
        } catch (RoundRefused $refused) {
            throw self::toolRefusalOf($refused);
        }

        $answer = ['screenshot_id' => $screenshotId, 'verdict' => $verdict, 'verdict_label' => self::VERDICT_LABELS[$verdict], 'summary_saved' => $hasSummary];

        return self::answerOf($answer);
    }

    /**
     * @throws ToolRefusal not found when the draft or the screenshot is gone
     */
    private function titleOf(string $roundId, UserId $callerId, string $screenshotId): ?string
    {
        $draft = $this->roundQueries->draftOf($roundId, $callerId) ?? throw ToolRefusal::notFound();
        foreach ($draft->screenshots as $screenshot) {
            if ($screenshot->id === $screenshotId) {
                return $screenshot->title;
            }
        }

        throw ToolRefusal::notFound();
    }

    private function markFixed(CheckItemAccessFacts $item): CallToolResult
    {
        try {
            $change = $this->checklistCommands->markFixed($item->itemId, $this->callerId(), $this->clock->now());
        } catch (ChecklistRefused $refused) {
            throw $refused->isNotFound() ? ToolRefusal::notFound() : ToolRefusal::readOnly();
        }

        return self::answerOf(['check_item_id' => $item->itemId, 'pin_number' => $change->pinNumber, 'state' => self::FIXED]);
    }

    /**
     * Review's refusal as the tool's: the domain wraps a text over its cap in an InvalidArgumentException and a draft
     * that no longer changes (shared or closed) in a DomainException. Any other, such as DRAFT_CHANGED, goes on as a
     * failure.
     */
    private static function toolRefusalOf(RoundRefused $refused): \Throwable
    {
        $reason = $refused->getMessage();

        return match (true) {
            RoundRefused::NOT_FOUND === $reason, RoundRefused::SCREENSHOT_NOT_FOUND === $reason => ToolRefusal::notFound(),
            $refused->getPrevious() instanceof \InvalidArgumentException => ToolRefusal::invalidInput($reason),
            $refused->getPrevious() instanceof \DomainException => ToolRefusal::readOnly(),
            default => $refused,
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
        return $this->currentUser->id() ?? throw new \LogicException('set_verdict ran without a user.');
    }

    /**
     * @param array<string, mixed> $arguments valid against the input schema
     */
    private static function stringAt(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets set_verdict run only with "%s" as a string.', $name));
        }

        return $value;
    }
}
