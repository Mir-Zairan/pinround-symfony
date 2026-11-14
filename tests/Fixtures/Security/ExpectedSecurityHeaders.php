<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Security;

use Symfony\Component\HttpFoundation\Response;

/**
 * The headers of the CSP example in docs/02-architecture/security.md, shared by the unit and functional tests of
 * SecurityHeadersSubscriber.
 */
final class ExpectedSecurityHeaders
{
    public const string STRICT_TRANSPORT_SECURITY = 'max-age=63072000; includeSubDomains';

    private const array NAMES = [
        'content-security-policy',
        'strict-transport-security',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
        'cross-origin-opener-policy',
    ];

    /**
     * Every header but Strict-Transport-Security, which only prod sends.
     *
     * @return array<string, string>
     */
    public static function withoutStrictTransportSecurity(string $nonce, string $objectStorageOrigin, string $mercureOrigin): array
    {
        return [
            'content-security-policy' => "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' {$objectStorageOrigin} data:; connect-src 'self' {$objectStorageOrigin} {$mercureOrigin}; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'",
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            'permissions-policy' => 'camera=(), microphone=(), geolocation=()',
            'cross-origin-opener-policy' => 'same-origin',
        ];
    }

    /**
     * @return array<string, string|null> the security headers the response carries, in the order of NAMES
     */
    public static function presentIn(Response $response): array
    {
        $headers = [];
        foreach (self::NAMES as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->get($name);
            }
        }

        return $headers;
    }
}
