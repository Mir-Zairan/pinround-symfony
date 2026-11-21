<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * RegisterOAuthClient refused the request and stored nothing. The error is the RFC 7591 error code; the message is
 * its error_description, which names the rule broken and never repeats what the request sent.
 */
final class OAuthClientRegistrationRefused extends \RuntimeException
{
    public const string INVALID_CLIENT_METADATA = 'invalid_client_metadata';
    public const string INVALID_REDIRECT_URI = 'invalid_redirect_uri';

    /**
     * @param self::INVALID_* $error
     */
    public function __construct(public readonly string $error, string $description, ?\Throwable $previous = null)
    {
        parent::__construct($description, 0, $previous);
    }
}
