<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\IssuedOAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthRefreshTokenTest extends TestCase
{
    private const string TOKEN_ID = '0197a3c0-0000-7000-8000-000000000d01';
    private const string FAMILY_ID = '0197a3c0-0000-7000-8000-000000000d02';
    private const string ACCESS_TOKEN_ID = '0197a3c0-0000-7000-8000-000000000d03';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000d11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000d21';
    private const string CLIENT_ID = 'abcdefghijklmnopqrstuvwxyz012345';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    /** A fraction of a second and a zone east of UTC, with the start of daylight saving inside the 30 days. */
    private const string ISSUED_AT = '2027-03-15 10:00:00.700 Europe/Berlin';

    #[Test]
    public function theTokenIsShownOnceAsPrrAndThirtyTwoRandomBytesInBase64Url(): void
    {
        $plainToken = self::issue()->reveal();

        self::assertMatchesRegularExpression('/\Aprr_[A-Za-z0-9_-]{43}\z/', $plainToken);
        self::assertMatchesRegularExpression(OAuthRefreshToken::PLAIN_SHAPE, $plainToken);
        self::assertSame(32, \strlen((string) base64_decode(strtr(substr($plainToken, 4), '-_', '+/'), true)));
        self::assertNotSame($plainToken, self::issue()->reveal());
    }

    #[Test]
    public function theEntityKeepsOnlyThePepperedHashNeverThePlainToken(): void
    {
        $issued = self::issue();
        $plainToken = $issued->reveal();
        $token = $issued->token();

        self::assertSame(hash_hmac('sha256', $plainToken, self::PEPPER, true), $token->tokenHash());
        self::assertNotSame(hash('sha256', $plainToken, true), $token->tokenHash());
        $everyField = var_export((array) $token, true);
        self::assertStringNotContainsString($plainToken, $everyField);
        self::assertStringNotContainsString(substr($plainToken, 4), $everyField);
    }

    #[Test]
    public function theTokenIsBoundToItsFamilyClientPersonOrganizationScopesAndAccessToken(): void
    {
        $token = self::issue([ApiTokenScope::RoundsWrite, ApiTokenScope::Read, ApiTokenScope::RoundsWrite])->token();

        self::assertSame(self::TOKEN_ID, $token->id());
        self::assertSame(self::FAMILY_ID, $token->familyId());
        self::assertTrue($token->organizationId()->equals(new OrganizationId(self::ACME_FOODS)));
        self::assertTrue($token->userId()->equals(new UserId(self::OMAR)));
        self::assertSame(self::CLIENT_ID, $token->clientId());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $token->scopes());
        self::assertSame(self::ACCESS_TOKEN_ID, $token->accessTokenId());
        self::assertNull($token->usedAt());
        self::assertNull($token->revokedAt());
    }

    #[Test]
    public function itLastsThirtyUtcDaysFromTheWholeSecondOfItsIssue(): void
    {
        $token = self::issue()->token();

        self::assertSame('2027-03-15 09:00:00.000000 +00:00', $token->createdAt()->format('Y-m-d H:i:s.u P'));
        self::assertSame('2027-04-14 09:00:00.000000 +00:00', $token->expiresAt()->format('Y-m-d H:i:s.u P'));
        self::assertSame(OAuthRefreshToken::LIFETIME_DAYS * 86400, $token->expiresAt()->getTimestamp() - $token->createdAt()->getTimestamp());
    }

    #[Test]
    public function noScopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A refresh token grants at least one scope.');

        self::issue([]);
    }

    #[Test]
    public function thePlainTokenIsNeverDumpedButTheIdIs(): void
    {
        $issued = self::issue();

        $dump = print_r($issued, true);

        self::assertStringNotContainsString($issued->reveal(), $dump);
        self::assertStringContainsString(self::TOKEN_ID, $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    #[Test]
    public function anIssuedTokenIsNeverSerialized(): void
    {
        $issued = self::issue();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('An issued OAuth refresh token is never serialized.');

        serialize($issued);
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private static function issue(array $scopes = [ApiTokenScope::Read]): IssuedOAuthRefreshToken
    {
        return OAuthRefreshToken::issue(
            self::TOKEN_ID,
            self::FAMILY_ID,
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            self::CLIENT_ID,
            $scopes,
            self::ACCESS_TOKEN_ID,
            new HmacApiTokenHashing(self::PEPPER),
            new \DateTimeImmutable(self::ISSUED_AT),
        );
    }
}
