<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\ApiToken;
use App\Identity\Infrastructure\Security\TokenAuthenticator;
use App\Organization\UI\EnterChosenOrganization;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * The stateless api_token firewall end to end (ADR-0021, P09.T1): the real TokenAuthenticator, EnterChosenOrganization
 * and request transaction, through the test-only probe /mcp/_test/token-probe (config/routes/test_fixtures.yaml). The
 * client keeps its kernel, so the frozen clock is the one every request reads; the tokens are dated ahead of the
 * database clock, which decides whether one is live, and the expired one long before it.
 */
final class TokenAuthenticatorTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string TOKEN_PROBE = '/mcp/_test/token-probe';
    private const string API_TOKEN_PROBE = '/api/v1/_test/token-probe';
    private const string NOW = '2099-03-20 09:00:00 UTC';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar is in two organizations, so a browser session without a choice would go to the picker; the token's
     * organization is entered at once, and the request sees no token row of the other one.
     */
    #[Test]
    public function aLiveTokenWorksAsItsUserInItsOrganizationOnlyAndSetsNoCookie(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);
        $this->addMembership($lena, $acmeFoods);
        [$omarsTokenId, $omarsToken] = $this->issueApiToken($omar, $betaBakery);
        [$lenasTokenId] = $this->issueApiToken($lena, $acmeFoods);

        $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$omarsToken]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'userId' => $omar->id()->toString(),
            'organizationId' => $betaBakery->toString(),
            'tokenId' => $omarsTokenId,
            'scopes' => ['read', 'rounds:write'],
            'visibleTokenOrganizationIds' => [$betaBakery->toString()],
        ], $this->responseJson());
        $this->assertNoCookieWasSet();
        self::assertSame(['last_used_at' => '2099-03-20 09:00:00', 'revoked_at' => null], $this->usageOf($omarsTokenId, $betaBakery));
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($lenasTokenId, $acmeFoods));

        // The scheme name is case-insensitive (RFC 7235), and /api/v1 is behind the same firewall.
        $this->client->request('GET', self::API_TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'bearer '.$omarsToken]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($betaBakery->toString(), $this->responseJson()['organizationId'] ?? null);
        $this->assertNoCookieWasSet();
    }

    #[Test]
    public function aUseIsRecordedAtMostOnceAMinute(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$tokenId, $token] = $this->issueApiToken($omar, $acmeFoods);
        $lastUses = [];

        foreach (['first' => 0, '59 seconds later' => 59, '61 seconds after the first' => 2] as $when => $secondsToWait) {
            $this->clock->sleep($secondsToWait);
            $this->client->request('GET', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(200);
            $lastUses[$when] = $this->usageOf($tokenId, $acmeFoods)['last_used_at'];
        }

        self::assertSame(['first' => '2099-03-20 09:00:00', '59 seconds later' => '2099-03-20 09:00:00', '61 seconds after the first' => '2099-03-20 09:01:01'], $lastUses);
    }

    /**
     * The malformed headers carry the live token too, so only the header's shape refuses them.
     */
    #[Test]
    public function aRevokedExpiredUnknownMalformedOrMissingTokenGetsTheSame401AndNoUseIsRecorded(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$liveTokenId, $liveToken] = $this->issueApiToken($omar, $acmeFoods);
        [$revokedTokenId, $revokedToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->revokeApiToken($revokedTokenId, $acmeFoods);
        [$expiredTokenId, $expiredToken] = $this->issueApiToken($omar, $acmeFoods, '2020-01-01 10:00:00 UTC', 1);
        $unknownToken = ApiToken::PLAIN_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $authorizationHeaders = [
            'revoked' => 'Bearer '.$revokedToken,
            'expired' => 'Bearer '.$expiredToken,
            'unknown' => 'Bearer '.$unknownToken,
            'cut short' => 'Bearer '.substr($liveToken, 0, -1),
            'with a line break after it' => 'Bearer '.$liveToken."\n",
            'without the scheme' => $liveToken,
            'in another scheme' => 'Basic '.$liveToken,
            'twice' => 'Bearer '.$liveToken.', Bearer '.$liveToken,
            'missing' => null,
        ];
        foreach ($authorizationHeaders as $case => $authorization) {
            $this->client->request('POST', self::TOKEN_PROBE, server: null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]);

            self::assertResponseStatusCodeSame(401, $case);
            self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson(), $case);
            self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer', $case);
            $this->assertNoCookieWasSet();
        }

        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($liveTokenId, $acmeFoods));
        self::assertSame(['last_used_at' => null, 'revoked_at' => '2099-03-16 10:00:00'], $this->usageOf($revokedTokenId, $acmeFoods));
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($expiredTokenId, $acmeFoods));
    }

    #[Test]
    public function aBrowserSessionCookieAloneGets401(): void
    {
        $omar = $this->createUserWithPassword('Omar Haddad');
        // EndExpiredSession measures the session's age on this clock from the wall-clock times the session keeps, so
        // a clock before the wall clock keeps the browser session young; 2099 would end it at once.
        $this->clock->modify('2026-11-10 09:00:00 UTC');
        $this->client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Log in', ['email' => $omar->email()->toString(), 'password' => self::PASSWORD]);
        self::assertResponseRedirects('/', 302);
        $this->client->request('GET', '/_test/actor');
        self::assertResponseStatusCodeSame(200);
        $actor = $this->responseJson()['actor'] ?? null;
        self::assertIsArray($actor, 'the session cookie does not log Omar in, so nothing was checked');
        self::assertSame($omar->id()->toString(), $actor['userId'] ?? null);

        $this->client->request('POST', self::TOKEN_PROBE);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson());
    }

    #[Test]
    public function aBlockedUsersTokenGets401AndNoUseIsRecorded(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$tokenId, $token] = $this->issueApiToken($omar, $acmeFoods);
        $this->blockUser($omar);
        // A request starts with an empty entity manager; this one still holds Omar as Foundry created him, unblocked.
        $this->tokenEntityManager()->clear();

        $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson());
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($tokenId, $acmeFoods));
    }

    /**
     * Omar is still in Acme Foods, which a browser session would enter as his only organization.
     */
    #[Test]
    public function aTokenWhoseUserLeftItsOrganizationGets401(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);
        [, $token] = $this->issueApiToken($omar, $betaBakery);
        $this->removeMembership($omar, $betaBakery);

        $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => EnterChosenOrganization::NOT_A_MEMBER_OF_THE_TOKENS_ORGANIZATION], $this->responseJson());
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        $this->assertNoCookieWasSet();
    }

    private function assertNoCookieWasSet(): void
    {
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'a token request set a cookie');
    }

    /**
     * @return array<mixed>
     */
    private function responseJson(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }
}
