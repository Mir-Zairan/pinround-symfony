<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Domain\UserId;

/**
 * The audit row of one MCP tool call or prompt read (ADR-0021, ADR-0026): the token and its user, the target, and as
 * data exactly the tool's or prompt's name and the outcome. Never the call's input or output, and never the token.
 */
final readonly class ToolCallAuditor
{
    public const string ACTION = 'mcp.tool_called';
    /** The target type of a row whose call reached no target of its own. */
    public const string TOOL_TARGET_TYPE = 'mcp_tool';
    /** The target type of a prompts/get row. */
    public const string PROMPT_TARGET_TYPE = 'mcp_prompt';

    public function __construct(private AuditLog $auditLog)
    {
    }

    /**
     * Writes in the request's open transaction, so the row is kept only if the call's change is.
     *
     * @param string $toolName the name of one of the AgentTools, never what the client sent for an unknown one
     *
     * @throws \Throwable what AuditLog::record throws, such as when no transaction is open or Audit's allow-list for
     *                    the action no longer holds exactly these keys
     */
    public function record(ApiTokenAccess $access, UserId $userId, string $toolName, ?ToolTarget $target, ToolCallOutcome $outcome): void
    {
        $this->auditLog->record(AuditEntry::byToken(
            $access->organizationId,
            $userId->toString(),
            $access->tokenId,
            self::ACTION,
            $target->auditType ?? self::TOOL_TARGET_TYPE,
            $target?->auditId,
            ['tool' => $toolName, 'outcome' => $outcome->value],
        ));
    }

    /**
     * The row of one prompts/get: the same action and data keys as a tool call, as Audit's allow-list holds no action
     * of its own for prompts, with the target type PROMPT_TARGET_TYPE and the prompt's name under "tool". Writes in the
     * request's open transaction, as record() does.
     *
     * @param string $promptName the name of a registered prompt, never what the client sent for an unknown one
     *
     * @throws \Throwable what AuditLog::record throws
     */
    public function recordPromptRead(ApiTokenAccess $access, UserId $userId, string $promptName, ToolCallOutcome $outcome): void
    {
        $this->auditLog->record(AuditEntry::byToken(
            $access->organizationId,
            $userId->toString(),
            $access->tokenId,
            self::ACTION,
            self::PROMPT_TARGET_TYPE,
            null,
            ['tool' => $promptName, 'outcome' => $outcome->value],
        ));
    }
}
