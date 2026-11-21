<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\ApiTokenScope;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * A code traded for tokens, the access token used, a refresh, a refused reuse and both revocations (security.md
 * "Logging without leaking", ADR-0027, ADR-0050). CapturedLogRecords keeps each record before RedactionProcessor runs,
 * so no record may hold the code, the PKCE verifier or a token anywhere, bound SQL values included: the code and the
 * tokens are looked up and saved only as hashes, and the verifier only hashed against the challenge. The client_id is
 * bound in the same statements, so finding it in the records proves the check reads them. The code is issued on 20 Mar
 * 2099 with the clock frozen then, as its resolver compares with the database clock.
 */
final class OAuthTokenLogLeakTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;

    private const string NOW = '2099-03-20 09:00:00 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';

    protected function tearDown(): void
    {
        $this->removeOAuthClientsAndCodes();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function tradingRefreshingUsingAndRevokingTokensLogsNoCodeVerifierOrToken(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $clock);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $clientId = $this->registerClient($client, 'Claude', [self::CALLBACK], ['authorization_code', 'refresh_token']);
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $this->issueAuthorizationCode($omar, $acmeFoods, $clientId, self::CALLBACK, $challenge, [ApiTokenScope::Read], $clock->now());
        $capturedLogRecords->clear();

        $client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $clientId, 'code_verifier' => $verifier]);
        $first = self::tokensOf($client->getResponse()->getContent());
        $client->request('GET', '/mcp/_test/token-probe', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$first['access_token']]);
        self::assertResponseStatusCodeSame(200);
        $client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $clientId]);
        $second = self::tokensOf($client->getResponse()->getContent());
        $client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $clientId]);
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $clientId, 'code_verifier' => $verifier]);
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/oauth/revoke', ['token' => $second['access_token'], 'client_id' => $clientId]);
        self::assertResponseStatusCodeSame(200);
        $client->request('POST', '/oauth/revoke', ['token' => $second['refresh_token'], 'client_id' => $clientId]);
        self::assertResponseStatusCodeSame(200);

        $raw = implode("\n", array_map(static fn (LogRecord $record): string => LoginLogLeakTest::textOf($record), $capturedLogRecords->all()));
        self::assertStringContainsString($clientId, $raw, 'no record holds the client_id bound beside the hashes, so nothing was checked');
        foreach ([
            'code' => $code,
            'verifier' => $verifier,
            'first access token' => $first['access_token'],
            'first refresh token' => $first['refresh_token'],
            'second access token' => $second['access_token'],
            'second refresh token' => $second['refresh_token'],
        ] as $what => $secret) {
            self::assertStringNotContainsString($secret, $raw, 'a log record holds the '.$what);
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private static function tokensOf(string|false $content): array
    {
        self::assertResponseStatusCodeSame(200);
        $answer = json_decode((string) $content, true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsString($answer['access_token'] ?? null);
        self::assertIsString($answer['refresh_token'] ?? null);

        return ['access_token' => $answer['access_token'], 'refresh_token' => $answer['refresh_token']];
    }
}
