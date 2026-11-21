<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A redirect URI an OAuth client registers (ADR-0050): an absolute https URI, an http URI on a loopback host with any
 * port (RFC 8252, as native clients such as Claude Code use), or the extension's https://<id>.chromiumapp.org/ address.
 * The string is kept exactly as given, because authorize and token compare it as an exact string; so whatever a browser
 * or a client could read differently is refused here: user info, fragments, wildcards, dot segments, bad
 * percent-encoding, non-ASCII and spaces.
 */
final readonly class OAuthRedirectUri
{
    public const int MAX_LENGTH = 512;
    public const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private const string SHAPE = '~\A(?<scheme>https?)://'
        .'(?<host>\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)'
        .'(?::(?<port>[0-9]{1,5}))?'
        // RFC 3986 path and query characters without "*", which would read as a wildcard, and without "#". The "~" is
        // escaped because it is also the delimiter; unescaped, the pattern fails to compile and refuses every URI.
        .'(?<path>/[A-Za-z0-9._\~!$&\'()+,;=:@/%-]*)?'
        .'(?:\?[A-Za-z0-9._\~!$&\'()+,;=:@/?%-]*)?\z~';
    private const string BAD_PERCENT_ENCODING = '/%(?![0-9A-Fa-f]{2})/';
    private const string DOT_SEGMENT = '~/\.{1,2}(?:/|\z)~';
    private const string CHROMIUM_APP_DOMAIN = 'chromiumapp.org';
    // Chrome writes an extension's id with the 16 letters a to p.
    private const string CHROMIUM_APP_HOST = '/\A[a-p]{32}\.chromiumapp\.org\z/';

    private const string SHAPE_RULE = 'A redirect URI starts with https://, or with http:// on localhost, 127.0.0.1 or [::1], and has a host, no user name or password, no space and only valid percent-encoding.';

    private string $uri;
    private string $host;

    /**
     * @throws InvalidOAuthRedirectUri when $uri breaks a rule of the class description or is over MAX_LENGTH bytes
     */
    public function __construct(string $uri)
    {
        if (\strlen($uri) > self::MAX_LENGTH) {
            throw new InvalidOAuthRedirectUri(\sprintf('A redirect URI has at most %d characters.', self::MAX_LENGTH));
        }
        if (str_contains($uri, '#')) {
            throw new InvalidOAuthRedirectUri('A redirect URI has no fragment (#).');
        }
        if (str_contains($uri, '*')) {
            throw new InvalidOAuthRedirectUri('A redirect URI has no wildcard (*).');
        }
        if (1 !== preg_match(self::SHAPE, $uri, $parts) || 1 === preg_match(self::BAD_PERCENT_ENCODING, $uri)) {
            throw new InvalidOAuthRedirectUri(self::SHAPE_RULE);
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && false === filter_var(substr($host, 1, -1), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            throw new InvalidOAuthRedirectUri(self::SHAPE_RULE);
        }
        $port = $parts['port'] ?? '';
        if ('' !== $port && ((int) $port < 1 || (int) $port > 65535)) {
            throw new InvalidOAuthRedirectUri('A redirect URI port is from 1 to 65535.');
        }
        if (1 === preg_match(self::DOT_SEGMENT, $parts['path'] ?? '')) {
            throw new InvalidOAuthRedirectUri('A redirect URI has no "." or ".." path segment.');
        }
        if ('http' === $parts['scheme'] && !\in_array($parts['host'], self::LOOPBACK_HOSTS, true)) {
            throw new InvalidOAuthRedirectUri('An http redirect URI points to this computer: localhost, 127.0.0.1 or [::1]. Use https otherwise.');
        }
        $isChromiumApp = self::CHROMIUM_APP_DOMAIN === $host || str_ends_with($host, '.'.self::CHROMIUM_APP_DOMAIN);
        if ($isChromiumApp && ('' !== $port || 1 !== preg_match(self::CHROMIUM_APP_HOST, $parts['host']))) {
            throw new InvalidOAuthRedirectUri('A chromiumapp.org redirect URI is https://<the extension\'s 32-letter id>.chromiumapp.org/, with no port.');
        }

        $this->uri = $uri;
        $this->host = $host;
    }

    public function toString(): string
    {
        return $this->uri;
    }

    /**
     * @return string the host in lowercase, without port; an IPv6 address keeps its brackets
     */
    public function host(): string
    {
        return $this->host;
    }
}
