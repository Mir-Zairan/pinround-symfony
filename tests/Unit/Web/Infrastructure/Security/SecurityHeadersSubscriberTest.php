<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Infrastructure\Security;

use App\SharedKernel\UI\CspConnectSources;
use App\SharedKernel\UI\CspFormActionOrigin;
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
        $response = $this->respond(new SecurityHeadersSubscriber($cspNonce, new CspConnectSources(), self::STORAGE_URL, self::MERCURE_URL, 'dev'));

        self::assertSame(
            ExpectedSecurityHeaders::withoutStrictTransportSecurity($cspNonce->current(), self::STORAGE_URL, self::MERCURE_ORIGIN),
            ExpectedSecurityHeaders::presentIn($response),
        );
    }

    #[Test]
    public function prodAlsoSendsStrictTransportSecurity(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), self::STORAGE_URL, self::MERCURE_URL, 'prod'));

        self::assertSame(ExpectedSecurityHeaders::STRICT_TRANSPORT_SECURITY, $response->headers->get('Strict-Transport-Security'));
    }

    #[Test]
    public function devAndTestSendNoStrictTransportSecurity(): void
    {
        foreach (['dev', 'test'] as $kernelEnvironment) {
            $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), self::STORAGE_URL, self::MERCURE_URL, $kernelEnvironment));

            self::assertFalse($response->headers->has('Strict-Transport-Security'), $kernelEnvironment);
            self::assertTrue($response->headers->has('Content-Security-Policy'), $kernelEnvironment);
        }
    }

    #[Test]
    public function anEmptyStorageOrMercureUrlLeavesThatSourceOut(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), '', '', 'dev'));

        $directives = $this->cspDirectives($response);
        self::assertSame("img-src 'self' data: blob:", $directives['img-src']);
        self::assertSame("connect-src 'self'", $directives['connect-src']);
        self::assertStringNotContainsString('  ', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('*', (string) $response->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function aPublicUrlIsReducedToItsOriginAndKeepsItsPort(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), 'http://localhost:9000/pinround/', 'https://localhost/.well-known/mercure?topic=x', 'dev'));

        $directives = $this->cspDirectives($response);
        self::assertSame("img-src 'self' http://localhost:9000 data: blob:", $directives['img-src']);
        self::assertSame("connect-src 'self' http://localhost:9000 https://localhost", $directives['connect-src']);
    }

    #[Test]
    public function theStorageOriginIsAllowedForPresignedUploadsInConnectSrc(): void
    {
        $withStorage = $this->cspDirectives($this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), 'http://localhost:9000', '', 'dev')));
        $withoutStorage = $this->cspDirectives($this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), '', '', 'dev')));

        self::assertSame("connect-src 'self' http://localhost:9000", $withStorage['connect-src']);
        self::assertSame("connect-src 'self'", $withoutStorage['connect-src']);
    }

    #[Test]
    public function anOriginThePageAllowedJoinsOnlyConnectSrcAndGoesAwayOnReset(): void
    {
        $cspConnectSources = new CspConnectSources();
        $subscriber = new SecurityHeadersSubscriber(new CspNonce(), $cspConnectSources, 'http://localhost:9000', self::MERCURE_URL, 'dev');
        $cspConnectSources->allow('http://localhost:11434');

        $directives = $this->cspDirectives($this->respond($subscriber));
        self::assertSame("connect-src 'self' http://localhost:9000 ".self::MERCURE_ORIGIN.' http://localhost:11434', $directives['connect-src']);
        self::assertSame("img-src 'self' http://localhost:9000 data: blob:", $directives['img-src']);
        self::assertSame("default-src 'self'", $directives['default-src']);

        $cspConnectSources->reset();
        self::assertSame("connect-src 'self' http://localhost:9000 ".self::MERCURE_ORIGIN, $this->cspDirectives($this->respond($subscriber))['connect-src']);
    }

    #[Test]
    public function onlyImgSrcAllowsBlobUrls(): void
    {
        $cspConnectSources = new CspConnectSources();
        $cspConnectSources->allow('http://localhost:11434');
        $directives = $this->cspDirectives($this->respond(new SecurityHeadersSubscriber(new CspNonce(), $cspConnectSources, self::STORAGE_URL, self::MERCURE_URL, 'dev')));

        $directivesWithBlob = array_keys(array_filter($directives, static fn (string $directive): bool => str_contains($directive, 'blob:')));
        self::assertSame(['img-src'], $directivesWithBlob);
        self::assertSame("img-src 'self' ".self::STORAGE_URL.' data: blob:', $directives['img-src']);
        self::assertCount(10, $directives);
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

        new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), $publicUrl, self::MERCURE_URL, 'dev');
    }

    #[Test]
    #[DataProvider('unsafePublicUrls')]
    public function anUnsafeMercureUrlIsRefused(string $publicUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MERCURE_PUBLIC_URL');

        new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), self::STORAGE_URL, $publicUrl, 'dev');
    }

    #[Test]
    public function aSubRequestResponseGetsNoHeaders(): void
    {
        $response = $this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), self::STORAGE_URL, self::MERCURE_URL, 'prod'), HttpKernelInterface::SUB_REQUEST);

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

        $this->respond(new SecurityHeadersSubscriber($cspNonce, new CspConnectSources(), self::STORAGE_URL, self::MERCURE_URL, 'dev'), HttpKernelInterface::MAIN_REQUEST, $response);

        self::assertSame(
            ExpectedSecurityHeaders::withoutStrictTransportSecurity($cspNonce->current(), self::STORAGE_URL, self::MERCURE_ORIGIN),
            ExpectedSecurityHeaders::presentIn($response),
        );
    }

    #[Test]
    public function theFormActionOriginOfTheRequestJoinsOnlyFormActionOfThatResponse(): void
    {
        $cspNonce = new CspNonce();
        $subscriber = new SecurityHeadersSubscriber($cspNonce, new CspConnectSources(), self::STORAGE_URL, self::MERCURE_URL, 'dev');
        $consentRequest = Request::create('/oauth/authorize');
        CspFormActionOrigin::allowOn($consentRequest, 'https://claude.example');

        $consentDirectives = $this->cspDirectives($this->respond($subscriber, request: $consentRequest));
        $plainResponse = $this->respond($subscriber);

        self::assertSame("form-action 'self' https://claude.example", $consentDirectives['form-action']);
        $plainDirectives = $this->cspDirectives(new Response(headers: ExpectedSecurityHeaders::withoutStrictTransportSecurity($cspNonce->current(), self::STORAGE_URL, self::MERCURE_ORIGIN)));
        self::assertSame(array_diff_key($plainDirectives, ['form-action' => true]), array_diff_key($consentDirectives, ['form-action' => true]));
        self::assertCount(10, $consentDirectives);
        self::assertSame("form-action 'self'", $this->cspDirectives($plainResponse)['form-action']);
    }

    #[Test]
    public function anUncheckedFormActionAttributeAddsNoSource(): void
    {
        $request = Request::create('/oauth/authorize');
        $request->attributes->set('_csp_form_action_origin', 'https://*.example');

        self::assertSame("form-action 'self'", $this->cspDirectives($this->respond(new SecurityHeadersSubscriber(new CspNonce(), new CspConnectSources(), '', '', 'dev'), request: $request))['form-action']);
    }

    private function respond(SecurityHeadersSubscriber $subscriber, int $requestType = HttpKernelInterface::MAIN_REQUEST, Response $response = new Response(), Request $request = new Request()): Response
    {
        $subscriber->addSecurityHeaders(new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, $requestType, $response));

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
