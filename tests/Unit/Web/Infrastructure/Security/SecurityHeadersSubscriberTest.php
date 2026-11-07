<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Infrastructure\Security;

use App\Tests\Fixtures\Security\ExpectedSecurityHeaders;
use App\Web\Infrastructure\Security\CspNonce;
use App\Web\Infrastructure\Security\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    private const string STORAGE_URL = 'https://acme-pinround.s3.eu-central-1.amazonaws.com';
    private const string MERCURE_ORIGIN = 'https://pinround.example';
    private const string MERCURE_URL = self::MERCURE_ORIGIN.'/.well-known/mercure';

    #[Test]
    public function itSetsTheCspExampleHeadersWithTheRequestNonce(): void
    {
        $cspNonce = new CspNonce();
        $response = $this->respond(new SecurityHeadersSubscriber($cspNonce, self::STORAGE_URL, self::MERCURE_URL, 'dev'));

        self::assertSame(
            ExpectedSecurityHeaders::withoutStrictTransportSecurity($cspNonce->current(), self::STORAGE_URL, self::MERCURE_ORIGIN),
            ExpectedSecurityHeaders::presentIn($response),
        );
    }

    #[Test]
    public function prodAlsoSendsStrictTransportSecurity(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), self::STORAGE_URL, self::MERCURE_URL, 'prod'));

        self::assertSame(ExpectedSecurityHeaders::STRICT_TRANSPORT_SECURITY, $response->headers->get('Strict-Transport-Security'));
    }

    #[Test]
    public function devAndTestSendNoStrictTransportSecurity(): void
    {
        foreach (['dev', 'test'] as $kernelEnvironment) {
            $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), self::STORAGE_URL, self::MERCURE_URL, $kernelEnvironment));

            self::assertFalse($response->headers->has('Strict-Transport-Security'), $kernelEnvironment);
            self::assertTrue($response->headers->has('Content-Security-Policy'), $kernelEnvironment);
        }
    }

    #[Test]
    public function anEmptyStorageOrMercureUrlLeavesThatSourceOut(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), '', '', 'dev'));

        $directives = $this->cspDirectives($response);
        self::assertSame("img-src 'self' data:", $directives['img-src']);
        self::assertSame("connect-src 'self'", $directives['connect-src']);
        self::assertStringNotContainsString('  ', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('*', (string) $response->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function aPublicUrlIsReducedToItsOriginAndKeepsItsPort(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), 'http://localhost:9000/pinround/', 'https://localhost/.well-known/mercure?topic=x', 'dev'));

        $directives = $this->cspDirectives($response);
        self::assertSame("img-src 'self' http://localhost:9000 data:", $directives['img-src']);
        self::assertSame("connect-src 'self' https://localhost", $directives['connect-src']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePublicUrls(): iterable
    {
        yield 'host without scheme' => ['acme-pinround.s3.amazonaws.com'];
        yield 'wildcard host' => ['https://*.amazonaws.com'];
        yield 'bare wildcard' => ['*'];
        yield 'other scheme' => ['ftp://files.example'];
        yield 'no host' => ['https://'];
        yield 'directive injection' => ["https://files.example; script-src 'unsafe-inline'"];
        yield 'second source' => ['https://files.example https://evil.example'];
        yield 'trailing newline' => ["https://files.example\n"];
    }

    #[Test]
    #[DataProvider('unsafePublicUrls')]
    public function anUnsafeStorageUrlIsRefused(string $publicUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('OBJECT_STORAGE_PUBLIC_URL');

        new SecurityHeadersSubscriber(new CspNonce(), $publicUrl, self::MERCURE_URL, 'dev');
    }

    #[Test]
    #[DataProvider('unsafePublicUrls')]
    public function anUnsafeMercureUrlIsRefused(string $publicUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MERCURE_PUBLIC_URL');

        new SecurityHeadersSubscriber(new CspNonce(), self::STORAGE_URL, $publicUrl, 'dev');
    }

    #[Test]
    public function aSubRequestResponseGetsNoHeaders(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), self::STORAGE_URL, self::MERCURE_URL, 'prod'), HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], ExpectedSecurityHeaders::presentIn($response));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    #[Test]
    public function aWeakerHeaderAControllerSetIsReplaced(): void
    {
        $cspNonce = new CspNonce();
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "default-src *; script-src 'unsafe-inline'");
        $response->headers->set('Referrer-Policy', 'unsafe-url');

        $this->respond(new SecurityHeadersSubscriber($cspNonce, self::STORAGE_URL, self::MERCURE_URL, 'dev'), HttpKernelInterface::MAIN_REQUEST, $response);

        self::assertSame(
            ExpectedSecurityHeaders::withoutStrictTransportSecurity($cspNonce->current(), self::STORAGE_URL, self::MERCURE_ORIGIN),
            ExpectedSecurityHeaders::presentIn($response),
        );
    }

    private function respond(SecurityHeadersSubscriber $subscriber, int $requestType = HttpKernelInterface::MAIN_REQUEST, Response $response = new Response()): Response
    {
        $subscriber->addSecurityHeaders(new ResponseEvent(self::createStub(HttpKernelInterface::class), Request::create('/'), $requestType, $response));

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function cspDirectives(Response $response): array
    {
        $directives = [];
        foreach (explode('; ', (string) $response->headers->get('Content-Security-Policy')) as $directive) {
            $directives[explode(' ', $directive, 2)[0]] = $directive;
        }

        return $directives;
    }
}
