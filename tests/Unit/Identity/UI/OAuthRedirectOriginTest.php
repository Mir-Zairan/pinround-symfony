<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI;

use App\Identity\UI\OAuthRedirectOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthRedirectOriginTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirectUrisAndTheirOrigins(): iterable
    {
        yield 'the extension' => ['https://abcdefghijklmnopabcdefghijklmnop.chromiumapp.org/', 'https://abcdefghijklmnopabcdefghijklmnop.chromiumapp.org'];
        yield 'path and query stripped' => ['https://claude.example/api/mcp/auth_callback?from=pinround&x=1', 'https://claude.example'];
        yield 'loopback with a port' => ['http://127.0.0.1:53127/callback', 'http://127.0.0.1:53127'];
        yield 'loopback without a port' => ['http://localhost/callback', 'http://localhost'];
        yield 'https with a port' => ['https://claude.example:8443/cb', 'https://claude.example:8443'];
        yield 'host in capitals' => ['https://Claude.Example/cb', 'https://claude.example'];
    }

    #[Test]
    #[DataProvider('redirectUrisAndTheirOrigins')]
    public function itIsTheSchemeLowercaseHostAndPortOfTheRedirectUri(string $redirectUri, string $origin): void
    {
        self::assertSame($origin, OAuthRedirectOrigin::of($redirectUri));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redirectUrisWithoutACspOrigin(): iterable
    {
        yield 'IPv6 loopback' => ['http://[::1]:53127/callback'];
        yield 'no host' => ['https:///cb'];
        yield 'another scheme' => ['ftp://files.example/cb'];
        yield 'relative' => ['/callback'];
    }

    #[Test]
    #[DataProvider('redirectUrisWithoutACspOrigin')]
    public function aUriNoCspSourceCanNameGivesNone(string $redirectUri): void
    {
        self::assertNull(OAuthRedirectOrigin::of($redirectUri));
    }
}
