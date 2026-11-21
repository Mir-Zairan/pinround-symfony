<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\IssueApiToken;
use App\Identity\Application\IssueOAuthTokenPair;
use App\Identity\Application\OAuthTokenRefused;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The token cap as a connected app meets it: the OAuth access token counts every live token of the person, the
 * connected apps' ones that the tokens page does not list included, and the refusal says so.
 */
final class IssueOAuthTokenPairTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000ea1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000ed1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000ed2';
    private const string FAMILY = '0197a3c0-0000-7000-8003-000000000001';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string DATABASE_NOW = '2027-01-01 00:00:00 UTC';
    private const string REFUSAL = 'You have 20 tokens that still work in this organization, counting the ones your connected apps hold. Revoke a personal token on the tokens page or sign out of a connected app, then connect again.';

    private int $seededTokens = 0;

    #[Test]
    public function aConnectOverTheCapCountingAConnectedAppsTokenIsRefusedWithBothWaysToFreeOneAndAddsNothing(): void
    {
        $apiTokens = $this->omarsTokens(personal: IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 1, connectedApp: 1);
        $refreshTokens = new AddRecordingOAuthRefreshTokens();
        $idsBefore = $apiTokens->ids();

        try {
            $this->issuePair($apiTokens, $refreshTokens)(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $this->client(), [ApiTokenScope::Read], self::FAMILY, new \DateTimeImmutable(self::NOW));
            self::fail('A 21st live token was issued.');
        } catch (OAuthTokenRefused $refusal) {
            self::assertSame(OAuthTokenRefused::INVALID_GRANT, $refusal->error);
            self::assertSame(self::REFUSAL, $refusal->getMessage());
        }

        self::assertSame($idsBefore, $apiTokens->ids());
        self::assertSame([], $refreshTokens->added);
    }

    #[Test]
    public function aRefreshOverTheCapIsRefusedWithTheSameReasonAndAddsNothing(): void
    {
        $apiTokens = $this->omarsTokens(personal: IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 1, connectedApp: 1);
        $refreshTokens = new AddRecordingOAuthRefreshTokens();
        $idsBefore = $apiTokens->ids();
        $client = $this->client();
        $traded = OAuthRefreshToken::issue('0197a3c0-0000-7000-8003-000000000002', self::FAMILY, new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $client->clientId(), [ApiTokenScope::Read], '0197a3c0-0000-7000-8002-000000000001', new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable(self::NOW))->token();

        try {
            $this->issuePair($apiTokens, $refreshTokens)->renewal($traded, $client, new \DateTimeImmutable(self::NOW));
            self::fail('A 21st live token was issued.');
        } catch (OAuthTokenRefused $refusal) {
            self::assertSame(OAuthTokenRefused::INVALID_GRANT, $refusal->error);
            self::assertSame(self::REFUSAL, $refusal->getMessage());
        }

        self::assertSame($idsBefore, $apiTokens->ids());
        self::assertSame([], $refreshTokens->added);
    }

    #[Test]
    public function underTheCapTheConnectIssuesThePairAndAnotherPersonsTokensDoNotCount(): void
    {
        $apiTokens = $this->omarsTokens(personal: IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 2, connectedApp: 1);
        $refreshTokens = new AddRecordingOAuthRefreshTokens();
        $idsBefore = $apiTokens->ids();

        $issued = $this->issuePair($apiTokens, $refreshTokens)(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $this->client(), [ApiTokenScope::Read], self::FAMILY, new \DateTimeImmutable(self::NOW));

        self::assertNotNull($issued->revealRefreshToken());
        self::assertCount(\count($idsBefore) + 1, $apiTokens->ids());
        self::assertCount(1, $refreshTokens->added);
    }

    private function issuePair(InMemoryApiTokens $apiTokens, OAuthRefreshTokens $refreshTokens): IssueOAuthTokenPair
    {
        $hashing = new HmacApiTokenHashing(self::PEPPER);

        return new IssueOAuthTokenPair(new IssueApiToken($apiTokens, $hashing, new RecordingIdentityEvents()), $refreshTokens, $hashing);
    }

    private function client(): OAuthClient
    {
        return OAuthClient::register('pinround browser extension', ['https://abcdefghijklmnopabcdefghijklmnop.chromiumapp.org/'], [OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken], new \DateTimeImmutable(self::NOW));
    }

    /**
     * Omar's live personal tokens and connected-app access tokens at Acme Foods, beside two of Lena's, which never count
     * for him.
     */
    private function omarsTokens(int $personal, int $connectedApp): InMemoryApiTokens
    {
        $tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::DATABASE_NOW));
        $hashing = new HmacApiTokenHashing(self::PEPPER);
        $issuedAt = new \DateTimeImmutable(self::NOW)->modify('-1 minute');
        for ($i = 0; $i < $personal; ++$i) {
            $tokens->seed(ApiToken::issue($this->nextSeededId(), new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), 'Seeded', ApiTokenScope::claudeCodeDefaults(), $hashing, $issuedAt)->token());
        }
        for ($i = 0; $i < $connectedApp; ++$i) {
            $tokens->seed(ApiToken::issueForOAuthClient($this->nextSeededId(), new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), 'Claude', [ApiTokenScope::Read], $hashing, $issuedAt)->token());
        }
        $tokens->seed(ApiToken::issue($this->nextSeededId(), new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), 'Seeded', ApiTokenScope::claudeCodeDefaults(), $hashing, $issuedAt)->token());
        $tokens->seed(ApiToken::issueForOAuthClient($this->nextSeededId(), new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), 'Claude', [ApiTokenScope::Read], $hashing, $issuedAt)->token());

        return $tokens;
    }

    private function nextSeededId(): string
    {
        return \sprintf('0197a3c0-0000-7000-8001-%012d', ++$this->seededTokens);
    }
}
