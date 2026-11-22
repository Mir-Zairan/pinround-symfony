<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\OwnIdentityTool;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Api\CurrentUser;
use App\Review\Api\ReportDrafts;
use App\Review\Api\ReportDraftSummary;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * list_my_report_drafts (ADR-0039): the caller's own report drafts, newest change first, from the first page of
 * ReportDrafts::forOwner, which reads only the logged-in person's rows of the token's organization in one query on the
 * owner index, at most ReportDrafts::DRAFTS_PER_PAGE. The browser extension offers them as targets to add a capture
 * to (add_report_screenshot). Titles are wrapped as UntrustedText (ADR-0020). Holds no state (ADR-0008).
 */
final readonly class ListMyReportDrafts implements OwnIdentityTool
{
    public const string NAME = 'list_my_report_drafts';

    public function __construct(
        private CurrentUser $currentUser,
        private ReportDrafts $reportDrafts,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: 'List my report drafts',
            inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            description: 'Lists your own private report drafts, the last changed first, at most '.ReportDrafts::DRAFTS_PER_PAGE
                .': each with its draft_id (for add_report_screenshot), project, title, screenshot_count (at most '
                .ReportDrafts::MAX_SCREENSHOTS.'), updated_at (ISO 8601, UTC) and the url of its page, where you check'
                .' and send it. has_more is true when you have more drafts than are listed. Nobody else\'s drafts are'
                .' ever listed. '.UntrustedText::NOTICE.' Titles are such text.',
            annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        );
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * No target: it lists only the caller's own drafts, which ReportDrafts::forOwner reads by the logged-in person, so
     * no voter on one draft applies (ADR-0013).
     */
    public function targetOf(array $arguments): ?ToolTarget
    {
        return null;
    }

    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        $owner = $this->currentUser->id() ?? throw new \LogicException('list_my_report_drafts ran without a user.');
        $page = $this->reportDrafts->forOwner($owner);
        $answer = [
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'report_drafts' => array_map(fn (ReportDraftSummary $draft): array => [
                'draft_id' => $draft->id,
                'project' => $draft->projectKey,
                'title' => UntrustedText::wrap($draft->title),
                'screenshot_count' => $draft->screenshotCount,
                'updated_at' => $draft->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                'url' => $this->urlGenerator->generate(CreateReport::DRAFT_PAGE_ROUTE, ['id' => $draft->id], UrlGeneratorInterface::ABSOLUTE_URL),
            ], $page->drafts),
            'has_more' => null !== $page->nextCursor,
        ];

        return new CallToolResult(
            [new TextContent(json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))],
            structuredContent: $answer,
        );
    }
}
