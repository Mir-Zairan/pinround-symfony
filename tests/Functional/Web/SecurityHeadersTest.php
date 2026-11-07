<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The headers of the CSP example in docs/02-architecture/security.md, with the storage and Mercure origins
 * of .env.test.
 */
final class SecurityHeadersTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    #[Test]
    public function theHomePageCarriesTheHeadersWithTheNonceItsScriptsPrint(): void
    {
        $page = $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $scriptNonces = array_unique($page->filter('script')->each(static fn (Crawler $script): string => (string) $script->attr('nonce')));
        self::assertCount(1, $scriptNonces);
        [$nonce] = array_values($scriptNonces);
        self::assertNotSame('', $nonce);
        self::assertSame($this->expectedHeaders($nonce), $this->securityHeaders($this->client->getResponse()));
    }

    #[Test]
    public function twoRequestsOnOneKernelGetDifferentNonces(): void
    {
        $this->client->disableReboot();

        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $firstNonce = $this->cspNonce((string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $secondNonce = $this->cspNonce((string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        self::assertNotSame($firstNonce, $secondNonce);
    }

    #[Test]
    public function theTestEnvironmentSendsNoStrictTransportSecurity(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHasHeader('Content-Security-Policy');
        self::assertResponseNotHasHeader('Strict-Transport-Security');
    }

    #[Test]
    public function aNotFoundPageCarriesTheHeadersToo(): void
    {
        // With kernel.debug on, ErrorListener::removeCspHeader (priority -128) drops the CSP so the exception page
        // can run inline code; prod has debug off, so the headers are read before that listener runs.
        $headersBeforeDebugCspRemoval = [];
        $this->eventDispatcher()->addListener(
            KernelEvents::RESPONSE,
            function (ResponseEvent $event) use (&$headersBeforeDebugCspRemoval): void {
                if ($event->isMainRequest()) {
                    $headersBeforeDebugCspRemoval = $this->securityHeaders($event->getResponse());
                }
            },
            -64,
        );

        $this->client->request('GET', '/no-such-page');
        self::assertResponseStatusCodeSame(404);

        $nonce = $this->cspNonce($headersBeforeDebugCspRemoval['content-security-policy'] ?? '');
        self::assertSame($this->expectedHeaders($nonce), $headersBeforeDebugCspRemoval);
        $headersWithoutCsp = $this->expectedHeaders($nonce);
        unset($headersWithoutCsp['content-security-policy']);
        self::assertSame($headersWithoutCsp, $this->securityHeaders($this->client->getResponse()));
    }

    /**
     * @return array<string, string>
     */
    private function expectedHeaders(string $nonce): array
    {
        return [
            'content-security-policy' => "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' http://localhost:9000 data:; connect-src 'self' https://localhost; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'",
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            'permissions-policy' => 'camera=(), microphone=(), geolocation=()',
            'cross-origin-opener-policy' => 'same-origin',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function securityHeaders(Response $response): array
    {
        $headers = [];
        foreach (['content-security-policy', 'strict-transport-security', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'cross-origin-opener-policy'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->get($name);
            }
        }

        return $headers;
    }

    private function cspNonce(string $contentSecurityPolicy): string
    {
        self::assertSame(1, preg_match("#script-src 'self' 'nonce-([A-Za-z0-9+/]+={0,2})';#", $contentSecurityPolicy, $match), $contentSecurityPolicy);

        return $match[1];
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        return $this->client->getContainer()->get('event_dispatcher');
    }
}
