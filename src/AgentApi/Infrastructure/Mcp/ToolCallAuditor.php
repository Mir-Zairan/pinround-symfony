<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Domain\UserId;

/**
 * The audit row of one MCP tool call (ADR-0021, ADR-0026): the token and its user, the target, and as data exactly the
 * tool's name and the outcome. Never the call's input or output, and never the token itself.
 */
final readonly class ToolCallAuditor
{
    public const string ACTION = 'mcp.tool_called';
    /** The target type of a row whose call reached no target of its own. */
    public const string TOOL_TARGET_TYPE = 'mcp_tool';

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
}
