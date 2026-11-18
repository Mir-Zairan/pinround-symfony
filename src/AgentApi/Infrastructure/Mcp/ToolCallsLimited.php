<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * An MCP call refused by ToolCallLimiter: the message says which limit, and the wait counts to the end of its clock
 * minute or hour, for the Retry-After header.
 */
final class ToolCallsLimited extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
