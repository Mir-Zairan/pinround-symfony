<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The voter attributes for OAuth connections (ADR-0013, ADR-0050). OAuthConnectionVoter decides them.
 */
final class OAuthConnectionPermission
{
    /** End one connection. Its subject is the OAuthConnectionOwner of the family. */
    public const string END = 'OAUTH_CONNECTION_END';
}
