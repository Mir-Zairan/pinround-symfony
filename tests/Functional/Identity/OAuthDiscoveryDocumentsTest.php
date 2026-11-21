<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /.well-known/oauth-authorization-server and /.well-known/oauth-protected-resource (ADR-0050) through the real
 * firewall, for a visitor and for a logged-in person, on an origin with a port so the absolute URLs show they are built
 * from the request. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as
 * idle.
 */
final class OAuthDiscoveryDocumentsTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private const string ORIGIN = 'https://pinround.example:8443';
    private const array AUTHORIZATION_SERVER_METADATA = [
        'issuer' => self::ORIGIN,
        'authorization_endpoint' => self::ORIGIN.'/oauth/authorize',
        'token_endpoint' => self::ORIGIN.'/oauth/token',
        'registration_endpoint' => self::ORIGIN.'/oauth/register',
        'revocation_endpoint' => self::ORIGIN.'/oauth/revoke',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'revocation_endpoint_auth_methods_supported' => ['none'],
    ];
    private const array PROTECTED_RESOURCE_METADATA = [
        'resource' => self::ORIGIN.'/mcp',
        'authorization_servers' => [self::ORIGIN],
    ];
    private const string SHARED_FOR_AN_HOUR = 'max-age=3600, public';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'HTTP_HOST' => 'pinround.example:8443']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable('2026-11-06 12:00:00 UTC')));
    }

    #[Test]
    public function aVisitorReadsTheAuthorizationServerMetadataWithAbsoluteUrlsThatSharedCachesMayKeep(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::AUTHORIZATION_SERVER_METADATA, $this->document());
        $this->assertSharedCachesMayKeepItForAnHour();
    }

    #[Test]
    public function aVisitorReadsTheProtectedResourceMetadataOfMcpThatSharedCachesMayKeep(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::PROTECTED_RESOURCE_METADATA, $this->document());
        $this->assertSharedCachesMayKeepItForAnHour();
    }

    #[Test]
    public function theUrlsFollowTheOriginTheDocumentIsAskedOn(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource', [], [], ['HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['resource' => 'https://localhost/mcp', 'authorization_servers' => ['https://localhost']], $this->document());
    }

    #[Test]
    public function aLoggedInPersonWithTwoOrganizationsAndNoneChosenReadsBothDocumentsWithoutBeingSentToThePicker(): void
    {
        $user = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($user, $this->createOrganization('Acme Foods'));
        $this->addMembership($user, $this->createOrganization('Beta Bakery'));
        $this->logIn($this->client, $user);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/org/choose', 302);

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::AUTHORIZATION_SERVER_METADATA, $this->document());
        $this->assertSharedCachesMayKeepItForAnHour();

        $this->client->request('GET', '/.well-known/oauth-protected-resource');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::PROTECTED_RESOURCE_METADATA, $this->document());
        $this->assertSharedCachesMayKeepItForAnHour();
    }

    /**
     * @return array<string, mixed> the JSON document
     */
    private function document(): array
    {
        $response = $this->client->getResponse();
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $document = json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $fields = [];
        foreach ($document as $name => $value) {
            self::assertIsString($name);
            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * A cookie on a response a shared cache keeps would be handed to everyone.
     */
    private function assertSharedCachesMayKeepItForAnHour(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame(self::SHARED_FOR_AN_HOUR, $headers->get('Cache-Control'));
        self::assertSame([], $headers->getCookies());
    }
}
