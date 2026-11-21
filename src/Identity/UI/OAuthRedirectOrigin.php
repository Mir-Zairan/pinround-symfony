<?php

declare(strict_types=1);

namespace App\Identity\UI;

/**
 * The origin the consent page's form-action must allow so its Allow and Deny can redirect to the app (ADR-0050).
 */
final class OAuthRedirectOrigin
{
    /**
     * @param string $redirectUri a redirect URI AuthorizeOAuthClient checked against the client's registered ones
     *
     * @return string|null scheme, lowercase host and the port the URI names, without path or query; the port is kept
     *                     on a loopback host too, as the answer goes to the port this request named. Null for an IPv6
     *                     host such as [::1], which a CSP source cannot name, and for anything that is not an http(s)
     *                     URI with a host, so the page then keeps form-action 'self' alone.
     */
    public static function of(string $redirectUri): ?string
    {
        $parts = parse_url($redirectUri);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array($parts['scheme'], ['http', 'https'], true) || str_starts_with($parts['host'], '[')) {
            return null;
        }

        return $parts['scheme'].'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
