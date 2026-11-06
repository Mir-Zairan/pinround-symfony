<?php

declare(strict_types=1);

namespace App\Audit\Api;

/**
 * Who did an audited action: a person in the app, a person's personal access token (MCP or CLI), or the system itself.
 */
enum ActorKind: string
{
    case User = 'user';
    case Token = 'token';
    case System = 'system';
}
