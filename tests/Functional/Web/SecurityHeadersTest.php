<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Tests\Fixtures\Security\ExpectedSecurityHeaders;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * The headers of the CSP example in docs/02-architecture/security.md, with the storage and Mercure origins
 * of .env.test.
 */
final class SecurityHeadersTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    #[Test]
    public function theHomePageCarriesTheHeadersWithTheNonceItsScriptsPrint(): void
    {
        $this->logIn($this->client, $this->createUserWithPassword('Lena Berg'));
        $page = $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $scriptNonces = array_unique($page->filter('script')->each(static fn (Crawler $script): string => (string) $script->attr('nonce')));
        self::assertCount(1, $scriptNonces);
        [$nonce] = array_values($scriptNonces);
        self::assertNotSame('', $nonce);
        self::assertSame($this->expectedHeaders($nonce), ExpectedSecurityHeaders::presentIn($this->client->getResponse()));
    }

    #[Test]
    public function twoRequestsOnOneKernelGetDifferentNonces(): void
    {
        $this->client->disableReboot();
        $this->logIn($this->client, $this->createUserWithPassword('Lena Berg'));

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
        $this->logIn($this->client, $this->createUserWithPassword('Lena Berg'));
        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHasHeader('Content-Security-Policy');
        self::assertResponseNotHasHeader('Strict-Transport-Security');
    }

    #[Test]
    public function aNotFoundPageWithDebugOffCarriesTheHeaders(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClientWithFreshNonDebugContainer();

        $client->request('GET', '/no-such-page');
        self::assertResponseStatusCodeSame(404);

        $headers = ExpectedSecurityHeaders::presentIn($client->getResponse());
        self::assertSame($this->expectedHeaders($this->cspNonce($headers['content-security-policy'] ?? '')), $headers);
    }

    #[Test]
    public function aNotFoundPageWithDebugOnLosesOnlyTheCspAfterTheSubscriberSetIt(): void
    {
        // ErrorListener::removeCspHeader (priority -128) drops the CSP when kernel.debug is on, so the exception page
        // can run inline code; this listener reads the headers before it runs.
        $headersBeforeDebugCspRemoval = [];
        $this->eventDispatcher()->addListener(
            KernelEvents::RESPONSE,
            static function (ResponseEvent $event) use (&$headersBeforeDebugCspRemoval): void {
                if ($event->isMainRequest()) {
                    $headersBeforeDebugCspRemoval = ExpectedSecurityHeaders::presentIn($event->getResponse());
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
        self::assertSame($headersWithoutCsp, ExpectedSecurityHeaders::presentIn($this->client->getResponse()));
    }

    private static function createClientWithFreshNonDebugContainer(): KernelBrowser
    {
        // Debug off never revalidates the cached container or bundle list, so stale ones would load after a change.
        $kernel = static::createKernel(['debug' => false]);
        $containerClass = new \ReflectionMethod($kernel, 'getContainerClass')->invoke($kernel);
        self::assertIsString($containerClass);
        $cachedContainer = $kernel->getBuildDir().'/'.$containerClass.'.php';
        foreach ([$cachedContainer, $kernel->getBuildDir().'/'.$containerClass.'.bundles.php'] as $cachedFile) {
            if (is_file($cachedFile)) {
                unlink($cachedFile);
            }
        }

        $client = self::createClient(['debug' => false]);
        self::assertFileExists($cachedContainer, 'the kernel compiled its container somewhere else, so the stale one was not removed');

        return $client;
    }

    /**
     * @return array<string, string>
     */
    private function expectedHeaders(string $nonce): array
    {
        return ExpectedSecurityHeaders::withoutStrictTransportSecurity($nonce, 'http://localhost:9000', 'https://localhost');
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
