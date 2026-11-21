<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI;

use App\Identity\UI\OAuthEndpointCors;
use App\SharedKernel\Infrastructure\Http\ExtensionOrigins;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * What the functional OAuthEndpointCorsTest cannot set up: another EXTENSION_ORIGINS, another path, a sub-request.
 */
final class OAuthEndpointCorsTest extends TestCase
{
    private const string EXTENSION_ORIGIN = 'chrome-extension://pinroundtestextensionidaaaaaaaaa';

    /**
     * REACH: with EXTENSION_ORIGINS unset or empty, no origin gets a preflight or a readable answer.
     */
    #[Test]
    public function withoutExtensionOriginsNoOriginGetsAPreflightOrCorsHeaders(): void
    {
        foreach ([null, '', ' , '] as $list) {
            $cors = new OAuthEndpointCors(new ExtensionOrigins($list));

            self::assertNull($this->preflightAnswer($cors, self::request('OPTIONS', '/oauth/token')), var_export($list, true));
            $headers = $this->answerHeaders($cors, self::request('POST', '/oauth/token'));
            self::assertFalse($headers->has('Access-Control-Allow-Origin'), var_export($list, true));
            self::assertSame(['Origin'], $headers->all('Vary'), var_export($list, true));
        }
    }

    #[Test]
    public function otherPathsAndOtherMethodsAreLeftAlone(): void
    {
        $cors = new OAuthEndpointCors(new ExtensionOrigins(self::EXTENSION_ORIGIN));

        foreach (['/oauth/authorize', '/.well-known/oauth-protected-resource', '/oauth/token/', '/api/v1/notebooks', '/login'] as $path) {
            self::assertNull($this->preflightAnswer($cors, self::request('OPTIONS', $path)), $path);
            $headers = $this->answerHeaders($cors, self::request('POST', $path));
            self::assertFalse($headers->has('Access-Control-Allow-Origin'), $path);
            self::assertFalse($headers->has('Vary'), $path);
        }
        self::assertNull($this->preflightAnswer($cors, self::request('POST', '/oauth/token')));
        self::assertNotNull($this->preflightAnswer($cors, self::request('OPTIONS', '/oauth/token')));
    }

    #[Test]
    public function aSubRequestGetsNoPreflightAndNoCorsHeaders(): void
    {
        $cors = new OAuthEndpointCors(new ExtensionOrigins(self::EXTENSION_ORIGIN));

        self::assertNull($this->preflightAnswer($cors, self::request('OPTIONS', '/oauth/token'), HttpKernelInterface::SUB_REQUEST));
        $headers = $this->answerHeaders($cors, self::request('POST', '/oauth/token'), HttpKernelInterface::SUB_REQUEST);
        self::assertFalse($headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($headers->has('Vary'));
    }

    private function preflightAnswer(OAuthEndpointCors $cors, Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): ?Response
    {
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);
        $cors->answerPreflight($event);

        return $event->getResponse();
    }

    private function answerHeaders(OAuthEndpointCors $cors, Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseHeaderBag
    {
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, $requestType, new Response());
        $cors->addCorsHeaders($event);

        return $event->getResponse()->headers;
    }

    private static function request(string $method, string $path): Request
    {
        return Request::create($path, $method, server: ['HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);
    }
}
