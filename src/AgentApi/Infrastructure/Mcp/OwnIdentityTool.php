<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * An AgentTool that reads nothing but the caller's own identity, which every token may see, so no voter guards it
 * (ADR-0013). Only such a tool may answer null from targetOf(); ToolCallGate refuses a null target from any other.
 */
interface OwnIdentityTool extends AgentTool
{
}
