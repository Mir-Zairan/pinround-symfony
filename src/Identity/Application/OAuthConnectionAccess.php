<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The voter decision EndOAuthConnection asks before it ends a connection (ADR-0013), so a caller that forgot to ask
 * cannot end one; Identity's Application may not use Symfony's security itself.
 */
interface OAuthConnectionAccess
{
    /**
     * Whether the request's logged-in person holds $attribute on the connection; false with nobody logged in.
     *
     * @param string $attribute one of OAuthConnectionPermission's constants
     */
    public function isGranted(string $attribute, OAuthConnectionOwner $connection): bool;
}
