<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A redirect URI, or the list of them, broke the rule of OAuthRedirectUri or OAuthClient::register(), and no client was
 * made. The message says which rule, never the value.
 */
final class InvalidOAuthRedirectUri extends \InvalidArgumentException
{
}
