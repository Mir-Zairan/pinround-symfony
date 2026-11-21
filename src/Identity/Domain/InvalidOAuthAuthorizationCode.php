<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * OAuthAuthorizationCode::issue() refused the PKCE challenge, its method, the scopes, the redirect URI or the lifetime
 * and made no code. The message says which rule, never the value.
 */
final class InvalidOAuthAuthorizationCode extends \InvalidArgumentException
{
}
