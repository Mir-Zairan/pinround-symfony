<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\InvalidOAuthRedirectUri;
use App\Identity\Domain\OAuthRedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthRedirectUriTest extends TestCase
{
    private const string EXTENSION_ID = 'abcdefghijklmnopabcdefghijklmnop';
    private const string SHAPE_RULE = 'A redirect URI starts with https://, or with http:// on localhost, 127.0.0.1 or [::1], and has a host, no user name or password, no space and only valid percent-encoding.';
    private const string HTTP_RULE = 'An http redirect URI points to this computer: localhost, 127.0.0.1 or [::1]. Use https otherwise.';
    private const string CHROMIUM_APP_RULE = 'A chromiumapp.org redirect URI is https://<the extension\'s 32-letter id>.chromiumapp.org/, with no port.';

    /**
     * @return iterable<string, array{string, string}> the URI and its host
     */
    public static function acceptedUris(): iterable
    {
        yield 'https with a path' => ['https://claude.example/api/mcp/auth_callback', 'claude.example'];
        yield 'https with a port and a query' => ['https://app.acme-foods.example:8443/cb?from=pinround&x=%20y', 'app.acme-foods.example'];
        yield 'https on a single-label host' => ['https://x/cb', 'x'];
        yield 'https on an IPv6 address' => ['https://[2001:db8::1]/cb', '[2001:db8::1]'];
        yield 'https without a path' => ['https://claude.example', 'claude.example'];
        yield 'http on 127.0.0.1 with any port' => ['http://127.0.0.1:53127/cb', '127.0.0.1'];
        yield 'http on localhost without a port' => ['http://localhost/cb', 'localhost'];
        yield 'http on [::1]' => ['http://[::1]:8080/callback', '[::1]'];
        yield 'the extension\'s address' => ['https://'.self::EXTENSION_ID.'.chromiumapp.org/', self::EXTENSION_ID.'.chromiumapp.org'];
        yield 'the extension\'s address with a path' => ['https://'.self::EXTENSION_ID.'.chromiumapp.org/oauth', self::EXTENSION_ID.'.chromiumapp.org'];
        yield 'a host in capitals reads in lowercase' => ['https://Claude.Example/cb', 'claude.example'];
        yield 'a dot that starts a segment name' => ['https://claude.example/.well-known/cb', 'claude.example'];
        yield 'a tilde in the path and the query' => ['https://claude.example/~omar/cb?next=~x', 'claude.example'];
    }

    #[Test]
    #[DataProvider('acceptedUris')]
    public function anAcceptedUriIsKeptExactlyAsGiven(string $uri, string $host): void
    {
        $redirectUri = new OAuthRedirectUri($uri);

        self::assertSame($uri, $redirectUri->toString());
        self::assertSame($host, $redirectUri->host());
    }

    /**
     * @return iterable<string, array{string, string}> the URI and the rule it breaks
     */
    public static function refusedUris(): iterable
    {
        yield 'http on another host' => ['http://example.com/cb', self::HTTP_RULE];
        yield 'http on localhost in capitals' => ['http://LOCALHOST/cb', self::HTTP_RULE];
        yield 'http on a host that starts with localhost' => ['http://localhost.example.com/cb', self::HTTP_RULE];
        yield 'http on another loopback address' => ['http://127.0.0.2/cb', self::HTTP_RULE];
        yield 'a custom scheme' => ['myapp://cb', self::SHAPE_RULE];
        yield 'a custom scheme with a host' => ['com.example.app://oauth/cb', self::SHAPE_RULE];
        yield 'a scheme in capitals' => ['HTTPS://claude.example/cb', self::SHAPE_RULE];
        yield 'a fragment' => ['https://x/cb#frag', 'A redirect URI has no fragment (#).'];
        yield 'an empty fragment' => ['https://x/cb#', 'A redirect URI has no fragment (#).'];
        yield 'a wildcard host' => ['https://*.claude.example/cb', 'A redirect URI has no wildcard (*).'];
        yield 'a wildcard path' => ['https://claude.example/*', 'A redirect URI has no wildcard (*).'];
        yield 'the extension\'s domain with a letter past p' => ['https://abcdefghijklmnopabcdefghijklmnoq.chromiumapp.org/', self::CHROMIUM_APP_RULE];
        yield 'the extension\'s domain with a short id' => ['https://abcdefghijklmnop.chromiumapp.org/', self::CHROMIUM_APP_RULE];
        yield 'the extension\'s domain with an id in capitals' => ['https://ABCDEFGHIJKLMNOPABCDEFGHIJKLMNOP.chromiumapp.org/', self::CHROMIUM_APP_RULE];
        yield 'the extension\'s domain with a port' => ['https://'.self::EXTENSION_ID.'.chromiumapp.org:443/', self::CHROMIUM_APP_RULE];
        yield 'the extension\'s domain itself' => ['https://chromiumapp.org/', self::CHROMIUM_APP_RULE];
        yield 'the extension\'s domain one level deeper' => ['https://x.'.self::EXTENSION_ID.'.chromiumapp.org/', self::CHROMIUM_APP_RULE];
        yield 'a user name' => ['https://claude.example@evil.example/cb', self::SHAPE_RULE];
        yield 'a user name and password' => ['https://user:pass@claude.example/cb', self::SHAPE_RULE];
        yield 'a relative URI' => ['/cb', self::SHAPE_RULE];
        yield 'no host' => ['https:///cb', self::SHAPE_RULE];
        yield 'a space' => ['https://claude.example/c b', self::SHAPE_RULE];
        yield 'a line break at the end' => ["https://claude.example/cb\n", self::SHAPE_RULE];
        yield 'a carriage return and line feed' => ["https://claude.example/cb\r\nLocation: x", self::SHAPE_RULE];
        yield 'a backslash' => ['https://claude.example\\evil.example/cb', self::SHAPE_RULE];
        yield 'a non-ASCII host' => ['https://clåude.example/cb', self::SHAPE_RULE];
        yield 'a byte order mark first' => ["\u{FEFF}https://claude.example/cb", self::SHAPE_RULE];
        yield 'bad percent-encoding' => ['https://claude.example/c%zzb', self::SHAPE_RULE];
        yield 'a percent sign at the end' => ['https://claude.example/cb%', self::SHAPE_RULE];
        yield 'a bad IPv6 address' => ['https://[2001:db8::g]/cb', self::SHAPE_RULE];
        yield 'port 0' => ['http://127.0.0.1:0/cb', 'A redirect URI port is from 1 to 65535.'];
        yield 'port 65536' => ['http://127.0.0.1:65536/cb', 'A redirect URI port is from 1 to 65535.'];
        yield 'a dot segment' => ['https://claude.example/a/./cb', 'A redirect URI has no "." or ".." path segment.'];
        yield 'a dot-dot segment at the end' => ['https://claude.example/a/..', 'A redirect URI has no "." or ".." path segment.'];
        yield 'an empty string' => ['', self::SHAPE_RULE];
    }

    #[Test]
    #[DataProvider('refusedUris')]
    public function aUriThatBreaksARuleIsRefusedWithThatRule(string $uri, string $rule): void
    {
        $this->expectException(InvalidOAuthRedirectUri::class);
        $this->expectExceptionMessage($rule);

        new OAuthRedirectUri($uri);
    }

    #[Test]
    public function aUriOfTheMaximumLengthIsAcceptedAndOneMoreCharacterIsRefused(): void
    {
        $prefix = 'https://claude.example/';
        $longest = $prefix.str_repeat('a', OAuthRedirectUri::MAX_LENGTH - \strlen($prefix));

        self::assertSame($longest, new OAuthRedirectUri($longest)->toString());

        $this->expectException(InvalidOAuthRedirectUri::class);
        $this->expectExceptionMessage('A redirect URI has at most 512 characters.');

        new OAuthRedirectUri($longest.'a');
    }
}
