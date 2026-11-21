<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Functional\Organization\LogsInAdmins;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * A whole authorize on the consent page, from the app's request to the code sent back (security.md "Logging without
 * leaking", ADR-0027, ADR-0050). CapturedLogRecords keeps each record before RedactionProcessor runs. The state and
 * the PKCE challenge are in the page's query, which Symfony's RouterListener puts in its "Matched route" record;
 * RedactionProcessor drops those keys, so no record reaches a handler with them, and no other record holds them or
 * the code at all, but for one: the challenge is saved as it is, as the token endpoint compares the verifier's hash
 * with it, so the save's statement record holds it among its bound values, which RedactionProcessor drops too
 * (LogLeakMarkerTest). The code is saved only as a hash, so no record holds it, bound values included. The state and
 * challenge are URL-safe, so they read the same in a logged URL as in the request. The clock is fixed on 17 Nov 2026, before the day this was written, as a clock ahead of the wall clock would end the
 * session.
 */
final class OAuthConsentLogLeakTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;
    use TurnsOnTwoStepLogin;

    /** The keys of RouterListener's record that hold the request path, which RedactionProcessor drops. */
    private const array ROUTER_PATH_KEYS = ['route_parameters', 'request_uri'];
    /** The key of Doctrine's "Executing statement" record that holds the bound values, which RedactionProcessor drops. */
    private const string SQL_PARAMETERS_KEY = 'params';
    private const string NOW = '2026-11-17 09:00:00 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';

    protected function tearDown(): void
    {
        $this->removeOAuthClientsAndCodes();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function anAuthorizeLogsNeitherTheCodeNorTheStateNorTheChallenge(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $clock);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $clientId = $this->registerClient($client, 'Claude', [self::CALLBACK]);
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        $this->logInWithACode($client, $clock, $omar);
        $state = 'state-'.bin2hex(random_bytes(16));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', random_bytes(32), true)), '+/', '-_'), '=');
        $capturedLogRecords->clear();

        $page = $client->request('GET', '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', \PHP_QUERY_RFC3986));
        self::assertResponseStatusCodeSame(200);
        $form = $page->selectButton('Allow')->form();
        $form->setValues(['organization_id' => $acmeFoods->toString()]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('~\A'.preg_quote(self::CALLBACK, '~').'\?code=([A-Za-z0-9_-]{43})&state='.$state.'\z~', $location), $location);
        $code = substr($location, \strlen(self::CALLBACK.'?code='), 43);

        $records = $capturedLogRecords->all();
        $redaction = new RedactionProcessor();
        $redacted = implode("\n", array_map(static fn (LogRecord $record): string => LoginLogLeakTest::textOf($redaction($record)), $records));
        $raw = self::textWithout($records, []);
        self::assertStringContainsString($state, $raw, 'the consent page request was not logged, so nothing was checked');
        self::assertStringContainsString($challenge, $raw, 'the consent page request was not logged, so nothing was checked');
        foreach (['code' => $code, 'state' => $state, 'challenge' => $challenge] as $what => $secret) {
            self::assertStringNotContainsString($secret, $redacted, 'a written log record holds the '.$what);
        }
        $withoutRouterPaths = self::textWithout($records, self::ROUTER_PATH_KEYS);
        self::assertStringNotContainsString($code, $withoutRouterPaths, 'a log record outside the router\'s path keys holds the code');
        self::assertStringNotContainsString($state, $withoutRouterPaths, 'a log record outside the router\'s path keys holds the state');
        self::assertStringNotContainsString($challenge, self::textWithout($records, [...self::ROUTER_PATH_KEYS, self::SQL_PARAMETERS_KEY]), 'a log record outside the router\'s path keys and the bound SQL values holds the challenge');
    }

    /**
     * @param list<LogRecord> $records
     * @param list<string>    $droppedKeys context keys left out of every record
     */
    private static function textWithout(array $records, array $droppedKeys): string
    {
        return implode("\n", array_map(
            static fn (LogRecord $record): string => LoginLogLeakTest::textOf($record->with(context: array_diff_key($record->context, array_flip($droppedKeys)))),
            $records,
        ));
    }
}
