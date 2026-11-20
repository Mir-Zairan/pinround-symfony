<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * An AgentTool that names no ticket, round or other target and reads only the caller's own data: their identity, which
 * every token may see; their own list filtered by their own ticket visibility predicate (TicketVisibilitySql), as the
 * tickets list is; or rows only they own, read by their own user id in the token's organization, as their notebooks
 * (Notebooks::forOwner). No voter guards it (ADR-0013), so such a tool never writes and never reads a row that the
 * caller's own predicate or ownership has not let through. Only such a tool may answer null from targetOf();
 * ToolCallGate refuses a null target from any other.
 */
interface OwnIdentityTool extends AgentTool
{
}
