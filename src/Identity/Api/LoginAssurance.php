<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * How strongly the person behind a security token proved who they are, for voters that need more than a login, such
 * as the admin pages (ADR-0012, ADR-0013). Every question is an allow-list: a token kind it does not know is refused.
 */
interface LoginAssurance
{
    /**
     * @param mixed $token the security token a voter decides on
     *
     * @return bool true only when the person typed their password in this session (the login form, or the new token
     *              after a password change); false for remember-me, a personal access token and any other token kind
     */
    public function isPasswordSession(mixed $token): bool;

    /**
     * @param mixed $token the security token a voter decides on
     *
     * @return bool true when the token's user has two-step login on now; false for any token that is no password
     *              session (isPasswordSession)
     */
    public function isTwoStepLoginOn(mixed $token): bool;

    /**
     * @param mixed $token   the security token a voter decides on
     * @param int   $seconds how old the proof may be; a proof exactly that old still counts
     *
     * @return bool true only when the token's user has two-step login on now and typed a code in this browser session
     *              at most $seconds ago and not before their current two-step login was turned on (a login with a
     *              code, "Confirm it's you" or the setup); false for any token that is no password session
     *              (isPasswordSession) and when the request has no session
     */
    public function hasConfirmedTwoStepLoginWithin(mixed $token, int $seconds): bool;

    /**
     * @param mixed $token the security token a voter decides on
     *
     * @return bool true when a personal access token authenticated it (the /mcp and /api/v1 firewall); false for a
     *              browser session and anything else
     */
    public function isTokenAuthenticated(mixed $token): bool;

    /**
     * For the scope checks of the MCP tools and the REST API (ADR-0021).
     *
     * @param mixed $token the security token of the request, or the one a voter decides on
     *
     * @return ApiTokenAccess|null the organization and scopes of the personal access token that authenticated it; null
     *                             whenever isTokenAuthenticated() is false
     */
    public function apiTokenAccessOf(mixed $token): ?ApiTokenAccess;
}
