<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * What an MCP tool call acts on, such as a ticket found by its key or a round: the voter check the web UI runs for the
 * same action, and the target its audit row names.
 */
final readonly class ToolTarget
{
    /**
     * @param string $voterAttribute the attribute the web UI checks for the same action, such as TICKET_VIEW
     * @param mixed  $subject        what that attribute's voter decides on
     * @param string $auditType      the audit target type code, such as "ticket" or "round"
     * @param string $auditId        the target's id, a canonical UUID
     */
    public function __construct(
        public string $voterAttribute,
        public mixed $subject,
        public string $auditType,
        public string $auditId,
    ) {
    }
}
