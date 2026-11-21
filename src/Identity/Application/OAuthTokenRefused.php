<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The token or revocation endpoint refused the request (RFC 6749 section 5.2, RFC 7009 section 2.2.1) and issued
 * nothing. The error is the RFC error code; the message is its error_description, which names the rule broken and
 * never repeats what the request sent.
 */
final class OAuthTokenRefused extends \RuntimeException
{
    public const string INVALID_REQUEST = 'invalid_request';
    public const string INVALID_CLIENT = 'invalid_client';
    public const string INVALID_GRANT = 'invalid_grant';
    public const string INVALID_SCOPE = 'invalid_scope';
    public const string UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';

    public const string UNKNOWN_CLIENT = 'pinround does not know this client_id.';

    /**
     * @param self::INVALID_*|self::UNSUPPORTED_GRANT_TYPE $error
     */
    public function __construct(public readonly string $error, string $description, ?\Throwable $previous = null)
    {
        parent::__construct($description, 0, $previous);
    }

    public static function invalidGrant(string $description): self
    {
        return new self(self::INVALID_GRANT, $description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self(self::INVALID_REQUEST, $description);
    }
}
