<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\InvalidOAuthClientMetadata;
use App\Identity\Domain\InvalidOAuthRedirectUri;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthClientTest extends TestCase
{
    private const string REGISTERED_AT = '2026-11-06 17:30:00.900 +05:30';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    private const string ONE_LINE_RULE = 'A client_name is one line of plain text.';
    private const string BLANK_RULE = 'A client_name has a character that shows; leave it out for none.';
    private const string LENGTH_RULE = 'A client_name has at most 100 characters.';

    #[Test]
    public function theClientIdIsThirtyTwoBase64UrlCharactersAndDiffersEachTime(): void
    {
        $clientIds = [];
        for ($registration = 1; $registration <= 20; ++$registration) {
            $clientIds[] = self::register()->clientId();
        }

        self::assertNotEmpty($clientIds);
        foreach ($clientIds as $clientId) {
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{32}\z/', $clientId);
        }
        self::assertCount(20, array_unique($clientIds));
    }

    #[Test]
    public function aClientWithoutANameHasNone(): void
    {
        self::assertNull(self::register(null)->clientName());
    }

    #[Test]
    public function theNameIsKeptWithoutTheUnicodeSpaceAtItsEdges(): void
    {
        self::assertSame("Claude Code \u{00E9}dition", self::register(" \u{00A0}Claude Code \u{00E9}dition\u{3000}\t")->clientName());
    }

    #[Test]
    public function aNameOfAHundredCharactersIsKeptEvenWhenEachTakesTwoBytes(): void
    {
        $hundredCharacters = str_repeat("\u{00E9}", 100);

        self::assertSame($hundredCharacters, self::register($hundredCharacters)->clientName());
    }

    /**
     * @return iterable<string, array{string, string}> the name and the rule it breaks
     */
    public static function refusedNames(): iterable
    {
        yield 'empty' => ['', self::BLANK_RULE];
        yield 'only spaces' => [" \u{00A0}\u{3000} ", self::BLANK_RULE];
        yield 'a line break' => ["Claude\nCode", self::ONE_LINE_RULE];
        yield 'a carriage return' => ["Claude\rCode", self::ONE_LINE_RULE];
        yield 'an escape' => ["Claude\u{001B}[31mCode", self::ONE_LINE_RULE];
        yield 'a C1 control' => ["Claude\u{0085}Code", self::ONE_LINE_RULE];
        yield 'a line separator' => ["Claude\u{2028}Code", self::ONE_LINE_RULE];
        yield 'a paragraph separator' => ["Claude\u{2029}Code", self::ONE_LINE_RULE];
        yield 'a zero-width space' => ["Claude\u{200B}Code", self::ONE_LINE_RULE];
        yield 'a right-to-left override' => ["Claude\u{202E}edoC", self::ONE_LINE_RULE];
        yield 'only a byte order mark' => ["\u{FEFF}", self::ONE_LINE_RULE];
        yield 'not UTF-8' => ["Claude \xC3\x28", self::ONE_LINE_RULE];
        yield '101 characters' => [str_repeat("\u{00E9}", 101), self::LENGTH_RULE];
    }

    #[Test]
    #[DataProvider('refusedNames')]
    public function aNameThatIsNotOneShortLineOfPlainTextIsRefused(string $clientName, string $rule): void
    {
        $this->expectException(InvalidOAuthClientMetadata::class);
        $this->expectExceptionMessageIsOrContains($rule);

        self::register($clientName);
    }

    #[Test]
    public function noRedirectUriIsRefused(): void
    {
        $this->expectException(InvalidOAuthRedirectUri::class);
        $this->expectExceptionMessageIsOrContains('Give at least one redirect URI.');

        OAuthClient::register(null, [], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::REGISTERED_AT));
    }

    #[Test]
    public function fiveRedirectUrisAreKeptInTheOrderGiven(): void
    {
        $redirectUris = self::callbacks(5);

        self::assertSame($redirectUris, self::registerWith($redirectUris)->redirectUris());
    }

    #[Test]
    public function sixRedirectUrisAreRefused(): void
    {
        $this->expectException(InvalidOAuthRedirectUri::class);
        $this->expectExceptionMessageIsOrContains('A client has at most 5 redirect URIs.');

        self::registerWith(self::callbacks(6));
    }

    #[Test]
    public function aRepeatedRedirectUriCountsOnceAndKeepsItsFirstPlace(): void
    {
        [$first, $second, $third, $fourth, $fifth] = self::callbacks(5);

        $client = self::registerWith([$third, $first, $third, $second, $fourth, $first, $fifth]);

        self::assertSame([$third, $first, $second, $fourth, $fifth], $client->redirectUris());
    }

    #[Test]
    public function aRedirectUriThatBreaksTheRuleIsRefused(): void
    {
        $this->expectException(InvalidOAuthRedirectUri::class);
        $this->expectExceptionMessageIsOrContains('An http redirect URI points to this computer: localhost, 127.0.0.1 or [::1]. Use https otherwise.');

        self::registerWith([self::CALLBACK, 'http://claude.example/cb']);
    }

    /**
     * @return iterable<string, array{list<OAuthGrantType>}>
     */
    public static function grantTypesWithoutTheCodeGrant(): iterable
    {
        yield 'none' => [[]];
        yield 'only refresh_token' => [[OAuthGrantType::RefreshToken]];
    }

    /**
     * @param list<OAuthGrantType> $grantTypes
     */
    #[Test]
    #[DataProvider('grantTypesWithoutTheCodeGrant')]
    public function aClientWithoutTheAuthorizationCodeGrantIsRefused(array $grantTypes): void
    {
        $this->expectException(InvalidOAuthClientMetadata::class);
        $this->expectExceptionMessageIsOrContains('A client uses the authorization_code grant.');

        OAuthClient::register(null, [self::CALLBACK], $grantTypes, new \DateTimeImmutable(self::REGISTERED_AT));
    }

    #[Test]
    public function theGrantTypesAreStoredOnceEachInTheOrderOfTheCases(): void
    {
        $client = OAuthClient::register(
            null,
            [self::CALLBACK],
            [OAuthGrantType::RefreshToken, OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken],
            new \DateTimeImmutable(self::REGISTERED_AT),
        );

        self::assertSame([OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken], $client->grantTypes());
    }

    #[Test]
    public function theCreationTimeIsCutToTheWholeSecondInUtc(): void
    {
        $createdAt = self::register()->createdAt();

        self::assertSame('2026-11-06 12:00:00.000000 +00:00', $createdAt->format('Y-m-d H:i:s.u P'));
    }

    private static function register(?string $clientName = 'Claude Code'): OAuthClient
    {
        return OAuthClient::register($clientName, [self::CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::REGISTERED_AT));
    }

    /**
     * @param list<string> $redirectUris
     */
    private static function registerWith(array $redirectUris): OAuthClient
    {
        return OAuthClient::register(null, $redirectUris, [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable(self::REGISTERED_AT));
    }

    /**
     * @return list<string> distinct accepted redirect URIs, not in sorted order
     */
    private static function callbacks(int $count): array
    {
        return \array_slice([
            'https://claude.example/cb',
            'http://127.0.0.1:53127/cb',
            'https://abcdefghijklmnopabcdefghijklmnop.chromiumapp.org/',
            'http://localhost/cb',
            'https://app.acme-foods.example/cb?from=pinround',
            'http://[::1]:8080/callback',
        ], 0, $count);
    }
}
