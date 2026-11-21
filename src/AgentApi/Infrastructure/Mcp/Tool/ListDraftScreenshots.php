<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolArguments;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Review\Api\DraftScreenshotChain;
use App\Review\Api\OpenDraftQueries;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * list_draft_screenshots (P14.T6 step 4): the screenshots of the caller's own open draft round on a ticket, one per
 * chain of tries, numbered as the draft round card numbers them, so a client can offer "Retake of screenshot N" and send
 * that chain's first try as add_screenshot's retake_of. Review's OpenDraftQueries reads only the caller's own draft, so
 * another person's draft and a shared round are never listed. Titles are wrapped as UntrustedText (ADR-0020). Holds no
 * state (ADR-0008).
 *
 * The gate grants TicketPermission VIEW on the ticket first, so a ticket the caller may not see is not found, also for
 * the owner of a draft on it; the draft is then listed only when RoundPermission VIEW grants it too.
 */
final readonly class ListDraftScreenshots implements AgentTool
{
    public const string NAME = 'list_draft_screenshots';
    public const string AUDIT_TARGET_TYPE = 'ticket';

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private TicketKeyQueries $ticketKeyQueries,
        private TicketTurnQueries $ticketTurnQueries,
        private OpenDraftQueries $openDraftQueries,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'List my draft screenshots',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ToolArguments::MAX_ID_LENGTH, 'description' => 'The ticket key, such as SHOP-12.'],
                ],
                'required' => ['key'],
                'additionalProperties' => false,
            ],
            description: 'Lists the screenshots of your own private draft round on a ticket, one per screenshot with its'
                .' tries: screenshot_id (the id add_screenshot takes as retake_of), number (the "Screenshot n" the'
                .' draft round shows), title of the latest try (or null) and try_count. The list is empty when you have'
                .' no draft round on the ticket; another person\'s draft is never listed. '.UntrustedText::NOTICE
                .' Titles are such text.',
            annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * The ticket with that key in the token's organization, as TicketPermission VIEW decides on it, in two queries.
     *
     * @throws ToolRefusal     not found, for a key the organization has no ticket with
     * @throws \LogicException when the request has no token, or Ticketing cannot read the ticket's team
     */
    public function targetOf(array $arguments): ToolTarget
    {
        $ticketId = $this->ticketKeyQueries->idOf(ToolArguments::stringAt($arguments, 'key', self::NAME)) ?? throw ToolRefusal::notFound();
        $ticket = $this->ticketTurnQueries->factsOf($ticketId) ?? throw ToolRefusal::notFound();
        $access = $this->currentApiToken->access() ?? throw new \LogicException('list_draft_screenshots ran without a personal access token.');

        return new ToolTarget(TicketPermission::VIEW, TicketVisibilityFacts::ofSnapshot($access->organizationId, $ticket), self::AUDIT_TARGET_TYPE, $ticketId->toString());
    }

    /**
     * One query for the draft and its screenshots.
     *
     * @throws \LogicException when the request has no user, or the target is not a ticket
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        if (null === $target || self::AUDIT_TARGET_TYPE !== $target->auditType) {
            throw new \LogicException('list_draft_screenshots runs on the ticket its targetOf() found.');
        }
        $callerId = $this->currentUser->id() ?? throw new \LogicException('list_draft_screenshots ran without a user.');
        $draft = $this->openDraftQueries->openDraftScreenshotsOf(new TicketId($target->auditId), $callerId);
        $chains = null !== $draft && $this->authorizationChecker->isGranted(RoundPermission::VIEW, $draft->draft) ? $draft->chains : [];

        $answer = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'screenshots' => array_map(static fn (DraftScreenshotChain $chain): array => [
                'screenshot_id' => $chain->screenshotId,
                'number' => $chain->number,
                'title' => UntrustedText::wrapOrNull($chain->title),
                'try_count' => $chain->tryCount,
            ], $chains),
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }
}
