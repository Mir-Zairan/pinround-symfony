<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * How an MCP tool call that reached ToolCallGate ended, as its audit row keeps it (data.outcome).
 */
enum ToolCallOutcome: string
{
    case Ok = 'ok';
    case NotFound = 'not_found';
    case ReadOnly = 'read_only';
    case MissingScope = 'missing_scope';
    case InvalidInput = 'invalid_input';
    /** The tool refused for a reason of the domain the caller can act on, such as a rejected picture. */
    case Refused = 'refused';
    case Error = 'error';
}
