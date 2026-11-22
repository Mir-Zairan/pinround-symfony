<?php

declare(strict_types=1);

namespace App\Review\UI\DraftPage;

/**
 * The draft page's "For Claude Code" box: the line its owner gives Claude Code, which fits their part on the ticket,
 * the tools Claude Code uses on AgentApi's MCP server, and the command that connects it with sign-in (ADR-0050).
 */
final readonly class ClaudeCodeBox
{
    /** The assignee's line, with the ticket's key: Claude Code fixes what their own check marked. */
    public const string FIX_PROMPT = 'Read my pinround notes for %s and fix what is marked “Not right yet”.';
    /**
     * Anyone else's line, with the ticket's key: a tester's or reviewer's draft holds findings (ADR-0044), so Claude Code
     * checks the ticket and adds its pictures and verdicts to the draft (add_screenshot, set_verdict).
     */
    public const string CHECK_PROMPT = 'Check %s and add what you find to my pinround draft round.';
    public const string TOOLS_NOTE = 'Claude Code can read these notes and verdicts and add screenshots. Tools: get_round_notes, add_screenshot, set_verdict.';
    /** With the MCP address; Claude Code then opens pinround's sign-in (docs/guides/claude-code.md). */
    public const string CONNECT_COMMAND = 'claude mcp add --transport http pinround %s';
    /** AgentApi's MCP endpoint (config/packages/mcp.yaml), on the address the person opened pinround at. */
    public const string MCP_PATH = '/mcp';

    public function __construct(
        public string $prompt,
        public string $connectCommand,
    ) {
    }

    /**
     * @param bool   $isAssignee whether the draft's owner is the ticket's assignee now
     * @param string $baseUrl    the scheme and host the page was opened at, for example "https://pinround.acme-foods.example"
     */
    public static function forDraftOwner(string $ticketKey, bool $isAssignee, string $baseUrl): self
    {
        return new self(
            \sprintf($isAssignee ? self::FIX_PROMPT : self::CHECK_PROMPT, $ticketKey),
            \sprintf(self::CONNECT_COMMAND, $baseUrl.self::MCP_PATH),
        );
    }
}
