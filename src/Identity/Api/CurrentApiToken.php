<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * The personal access token the current request authenticated with (ADR-0021), for other modules: the organization it
 * works in and its scopes, for the scope checks of the MCP tools and the REST API.
 */
interface CurrentApiToken
{
    /**
     * @return ApiTokenAccess|null null for a browser session, a request nobody is logged in to, and before the firewall
     *                             has run
     */
    public function access(): ?ApiTokenAccess;
}
