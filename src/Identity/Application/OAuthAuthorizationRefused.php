<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * AuthorizeOAuthClient refused an authorize request and issued no code (RFC 6749, section 4.1.2.1). When the client is
 * unknown or the redirect URI is not one it registered, nothing may go to that address: errorRedirectUrl is null and the
 * person sees the message. Otherwise the answer goes back to the client: errorRedirectUrl is its redirect URI with the
 * OAuth error, its description and the request's state. The message never quotes a value of the request.
 */
final class OAuthAuthorizationRefused extends \RuntimeException
{
    public const string INVALID_REQUEST = 'invalid_request';
    public const string UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';
    public const string INVALID_SCOPE = 'invalid_scope';

    private function __construct(string $description, public readonly ?string $errorRedirectUrl)
    {
        parent::__construct($description);
    }

    /**
     * @param string $description for the person, on pinround's own page
     */
    public static function withoutRedirect(string $description): self
    {
        return new self($description, null);
    }

    /**
     * @param string      $redirectUri a registered redirect URI of the client, exactly as the request carried it
     * @param string      $error       one of the constants
     * @param string      $description for the client's developer: printable ASCII without '"' or '\' (RFC 6749)
     * @param string|null $state       the request's state, to send back; null when it had none or broke its rule
     */
    public static function toClient(string $redirectUri, string $error, string $description, ?string $state): self
    {
        return new self($description, AuthorizeOAuthClient::answerUrl($redirectUri, ['error' => $error, 'error_description' => $description, 'state' => $state]));
    }
}
