<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * ApiToken::issue() refused the name, the scopes or the lifetime and made no token.
 */
final class InvalidApiToken extends \InvalidArgumentException
{
}
