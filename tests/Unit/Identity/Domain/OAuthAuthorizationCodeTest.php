<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\InvalidOAuthAuthorizationCode;
use App\Identity\Domain\IssuedOAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthAuthorizationCodeTest extends TestCase
{
    private const string CODE_ID = '0197a3c0-0000-7000-8000-000000000e01';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000e11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000e21';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string ISSUED_AT = '2027-03-15 10:00:00.700 Europe/Berlin';
    private const string CALLBACK = 'http://127.0.0.1:53127/callback';
    /** The S256 challenge of RFC 7636 Appendix B. */
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    /** The code_verifier of RFC 7636 Appendix B, whose S256 challenge is CHALLENGE. */
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    #[Test]
    public function theCodeIsShownOnceAsThirtyTwoRandomBytesInBase64Url(): void
    {
        $plainCode = self::issue()->reveal();

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $plainCode);
        self::assertSame(32, \strlen((string) base64_decode(strtr($plainCode, '-_', '+/'), true)));
        self::assertNotSame($plainCode, self::issue()->reveal());
    }

    #[Test]
    public function theEntityKeepsOnlyThePepperedHashNeverThePlainCode(): void
    {
        $issued = self::issue();
        $plainCode = $issued->reveal();
        $code = $issued->code();

        self::assertSame(hash_hmac('sha256', $plainCode, self::PEPPER, true), $code->codeHash());
        self::assertNotSame($plainCode, $code->codeHash());
        self::assertNotSame(hash('sha256', $plainCode, true), $code->codeHash());
        self::assertStringNotContainsString($plainCode, var_export((array) $code, true));
    }

    #[Test]
    public function theCodeIsBoundToItsClientRedirectUriChallengeScopesPersonAndOrganization(): void
    {
        $client = self::client();
        $code = self::issue(client: $client, scopes: [ApiTokenScope::RoundsWrite, ApiTokenScope::Read, ApiTokenScope::RoundsWrite])->code();

        self::assertSame(self::CODE_ID, $code->id());
        self::assertTrue($code->organizationId()->equals(new OrganizationId(self::ACME_FOODS)));
        self::assertTrue($code->userId()->equals(new UserId(self::OMAR)));
        self::assertSame($client->clientId(), $code->clientId());
        self::assertSame(self::CALLBACK, $code->redirectUri());
        self::assertSame(self::CHALLENGE, $code->codeChallenge());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $code->scopes());
        self::assertNull($code->usedAt());
    }

    #[Test]
    public function itLastsSixtySecondsFromTheWholeSecondOfItsIssueInUtc(): void
    {
        $code = self::issue()->code();

        self::assertSame('2027-03-15 09:00:00.000000 +00:00', $code->createdAt()->format('Y-m-d H:i:s.u P'));
        self::assertSame('2027-03-15 09:01:00.000000 +00:00', $code->expiresAt()->format('Y-m-d H:i:s.u P'));
    }

    #[Test]
    public function aShorterLifeIsKept(): void
    {
        $code = self::issue(lifetimeSeconds: 1)->code();

        self::assertSame('2027-03-15 09:00:01', $code->expiresAt()->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function challengesOutsideTheShape(): iterable
    {
        yield 'empty' => [''];
        yield '42 characters' => [substr(self::CHALLENGE, 1)];
        yield '129 characters' => [str_repeat('a', 129)];
        yield 'a plus sign' => ['+'.substr(self::CHALLENGE, 1)];
        yield 'a slash' => ['/'.substr(self::CHALLENGE, 1)];
        yield 'padding' => [self::CHALLENGE.'='];
        yield 'a space' => [' '.substr(self::CHALLENGE, 1)];
        yield 'a final newline' => [self::CHALLENGE."\n"];
        yield 'a final CRLF' => [self::CHALLENGE."\r\n"];
        yield 'a multibyte letter' => ["\u{00E9}".substr(self::CHALLENGE, 2)];
    }

    #[Test]
    #[DataProvider('challengesOutsideTheShape')]
    public function aChallengeOutsideTheShapeIsRefused(string $challenge): void
    {
        $this->expectException(InvalidOAuthAuthorizationCode::class);
        $this->expectExceptionMessage('A code_challenge is 43 to 128 characters of A-Z, a-z, 0-9, ".", "_", "~" and "-".');

        self::issue(codeChallenge: $challenge);
    }

    #[Test]
    public function aChallengeAtEitherEndOfTheShapeIsTaken(): void
    {
        $longest = str_repeat('aZ0._~-', 18).'ab';

        self::assertSame(128, \strlen($longest));
        self::assertSame($longest, self::issue(codeChallenge: $longest)->code()->codeChallenge());
        self::assertSame(self::CHALLENGE, self::issue(codeChallenge: self::CHALLENGE)->code()->codeChallenge());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodsOtherThanS256(): iterable
    {
        yield 'plain' => ['plain'];
        yield 'lowercase s256' => ['s256'];
        yield 'empty' => [''];
        yield 'S256 with a space' => ['S256 '];
    }

    #[Test]
    #[DataProvider('methodsOtherThanS256')]
    public function aMethodOtherThanS256IsRefused(string $method): void
    {
        $this->expectException(InvalidOAuthAuthorizationCode::class);
        $this->expectExceptionMessage('The code_challenge_method is S256.');

        self::issue(codeChallengeMethod: $method);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lifetimesOutOfRange(): iterable
    {
        yield '61 seconds' => [61];
        yield 'an hour' => [3600];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    #[DataProvider('lifetimesOutOfRange')]
    public function aLifeOutsideOneToSixtySecondsIsRefused(int $lifetimeSeconds): void
    {
        $this->expectException(InvalidOAuthAuthorizationCode::class);
        $this->expectExceptionMessage('A code lasts from 1 to 60 seconds.');

        self::issue(lifetimeSeconds: $lifetimeSeconds);
    }

    #[Test]
    public function noScopeIsRefused(): void
    {
        $this->expectException(InvalidOAuthAuthorizationCode::class);
        $this->expectExceptionMessage('A code grants at least one scope.');

        self::issue(scopes: []);
    }

    #[Test]
    public function aRedirectUriBreakingItsRuleIsRefusedWithThatRule(): void
    {
        $this->expectException(InvalidOAuthAuthorizationCode::class);
        $this->expectExceptionMessage('A redirect URI has no fragment (#).');

        self::issue(redirectUri: self::CALLBACK.'#here');
    }

    #[Test]
    public function theVerifierOfRfc7636AppendixBVerifiesItsChallenge(): void
    {
        self::assertTrue(self::issue()->code()->isVerifiedBy(self::VERIFIER));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function verifiersThatDoNotVerify(): iterable
    {
        yield 'another verifier of the same shape' => ['eBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'];
        yield 'the challenge itself' => [self::CHALLENGE];
        yield 'empty' => [''];
        yield 'with a final newline' => [self::VERIFIER."\n"];
        yield 'with a final CRLF' => [self::VERIFIER."\r\n"];
        yield 'with a leading byte order mark' => ["\u{FEFF}".self::VERIFIER];
        yield 'with a multibyte letter' => ["\u{00E9}".substr(self::VERIFIER, 2)];
        yield '42 characters' => [substr(self::VERIFIER, 1)];
        yield '129 characters' => [str_repeat('a', 129)];
    }

    #[Test]
    #[DataProvider('verifiersThatDoNotVerify')]
    public function aVerifierThatIsNotTheChallengesDoesNotVerifyIt(string $codeVerifier): void
    {
        self::assertFalse(self::issue()->code()->isVerifiedBy($codeVerifier));
    }

    /**
     * Without the shape check, a 129-character verifier whose hash is the challenge would verify; RFC 7636 allows only
     * 43 to 128 characters.
     */
    #[Test]
    public function aVerifierOutsideTheShapeDoesNotVerifyEvenItsOwnChallenge(): void
    {
        $tooLong = str_repeat('a', 129);
        $longest = str_repeat('a', 128);

        self::assertFalse(self::issue(codeChallenge: self::s256($tooLong))->code()->isVerifiedBy($tooLong));
        self::assertTrue(self::issue(codeChallenge: self::s256($longest))->code()->isVerifiedBy($longest));
    }

    private static function s256(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private static function issue(
        ?OAuthClient $client = null,
        string $redirectUri = self::CALLBACK,
        string $codeChallenge = self::CHALLENGE,
        string $codeChallengeMethod = 'S256',
        array $scopes = [ApiTokenScope::Read],
        int $lifetimeSeconds = OAuthAuthorizationCode::MAX_LIFETIME_SECONDS,
    ): IssuedOAuthAuthorizationCode {
        return OAuthAuthorizationCode::issue(
            self::CODE_ID,
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            $client ?? self::client(),
            $redirectUri,
            $codeChallenge,
            $codeChallengeMethod,
            $scopes,
            new HmacApiTokenHashing(self::PEPPER),
            new \DateTimeImmutable(self::ISSUED_AT),
            $lifetimeSeconds,
        );
    }

    private static function client(): OAuthClient
    {
        return OAuthClient::register('Claude Code', [self::CALLBACK], [OAuthGrantType::AuthorizationCode], new \DateTimeImmutable('2027-03-15 08:00:00 UTC'));
    }
}
