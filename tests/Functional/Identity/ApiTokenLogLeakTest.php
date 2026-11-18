<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\ApiToken;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Token requests that succeed and fail (security.md "Logging without leaking", P09.T1): CapturedLogRecords keeps each
 * record of every channel before RedactionProcessor runs, so neither a plain token nor its random part may be in what
 * the code logs at all, not only in what the handlers write.
 */
final class ApiTokenLogLeakTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    protected function tearDown(): void
    {
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function neitherALiveNorARevokedNorAnUnknownNorAMalformedTokenIsLogged(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [, $liveToken] = $this->issueApiToken($omar, $acmeFoods);
        [$revokedTokenId, $revokedToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->revokeApiToken($revokedTokenId, $acmeFoods);
        $unknownToken = ApiToken::PLAIN_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $capturedLogRecords->clear();

        $statuses = [];
        foreach (['live' => $liveToken, 'revoked' => $revokedToken, 'unknown' => $unknownToken, 'malformed' => $unknownToken.'!'] as $case => $token) {
            $client->request('POST', '/mcp/_test/token-probe', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            $statuses[$case] = $client->getResponse()->getStatusCode();
        }

        self::assertSame(['live' => 200, 'revoked' => 401, 'unknown' => 401, 'malformed' => 401], $statuses);
        $messages = array_map(static fn (LogRecord $record): string => $record->message, $capturedLogRecords->all());
        self::assertContains('Authenticator successful!', $messages, 'the token login was not logged, so nothing was checked');
        self::assertContains('Authenticator failed.', $messages, 'no refused token was logged, so nothing was checked');
        $logged = implode("\n", array_map(LoginLogLeakTest::textOf(...), $capturedLogRecords->all()));
        foreach (['live' => $liveToken, 'revoked' => $revokedToken, 'unknown' => $unknownToken] as $case => $token) {
            self::assertStringNotContainsString($token, $logged, 'a log record holds the '.$case.' token');
            self::assertStringNotContainsString(substr($token, \strlen(ApiToken::PLAIN_PREFIX)), $logged, 'a log record holds the random part of the '.$case.' token');
        }
    }
}
