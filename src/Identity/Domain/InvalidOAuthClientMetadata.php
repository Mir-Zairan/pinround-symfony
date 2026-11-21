<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * OAuthClient::register() refused the client's name or grant types and made no client. The message says which rule,
 * never the value.
 */
final class InvalidOAuthClientMetadata extends \InvalidArgumentException
{
}
