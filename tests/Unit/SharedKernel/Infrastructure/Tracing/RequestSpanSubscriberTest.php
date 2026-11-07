<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\SharedKernel\Infrastructure\Tracing\RequestSpanSubscriber;
use App\SharedKernel\Infrastructure\Tracing\SpanAttributes;
use App\SharedKernel\Infrastructure\Tracing\TracerProviderFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestSpanSubscriberTest extends TestCase
{
    #[Test]
    public function aSubRequestStartsNoSpan(): void
    {
        $currentSpan = new CurrentSpan();
        $provider = new TracerProviderFactory('', 'pinround', '1', 'http/protobuf', new NullLogger(), new InMemoryExporter())->create();
        $subscriber = new RequestSpanSubscriber($provider, $currentSpan, new NullLogger());

        $subscriber->startSpan(new RequestEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST));

        self::assertNull($currentSpan->span());
    }

    #[Test]
    public function aCollectorErrorNeitherFailsTheTerminateStepNorLeavesTheSpanBehind(): void
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request();
        $currentSpan = new CurrentSpan();
        $client = new MockHttpClient(new MockResponse('', ['error' => 'collector-secret-detail']));
        $provider = new TracerProviderFactory('http://collector.test:4318', 'pinround', '1', 'http/protobuf', new NullLogger(), null, $client)->create();
        $subscriber = new RequestSpanSubscriber($provider, $currentSpan, new NullLogger());

        $subscriber->startSpan(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertNotNull($currentSpan->sampledSpanContext());
        $subscriber->endAndExport(new TerminateEvent($kernel, $request, new Response()));

        self::assertNull($currentSpan->span());
    }

    #[Test]
    public function aFlushThatThrowsIsLoggedAsAWarningWithTheClassOnly(): void
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request();
        $currentSpan = new CurrentSpan();
        $loggedContexts = [];
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$loggedContexts): void {
            $loggedContexts[] = $context;
        });
        $provider = new TracerProviderFactory('', 'pinround', '1', 'http/protobuf', new NullLogger(), new InMemoryExporter())->create();
        $failingProvider = self::createStub(TracerProviderInterface::class);
        $failingProvider->method('getTracer')->willReturn($provider->getTracer('probe'));
        $failingProvider->method('forceFlush')->willThrowException(new \RuntimeException('private detail'));
        $subscriber = new RequestSpanSubscriber($failingProvider, $currentSpan, $logger);

        $subscriber->startSpan(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->endAndExport(new TerminateEvent($kernel, $request, new Response()));

        self::assertSame([['exception_class' => \RuntimeException::class]], $loggedContexts);
        self::assertNull($currentSpan->span());
    }

    #[Test]
    public function theAllowListHoldsExactlyTheFourKeys(): void
    {
        self::assertEqualsCanonicalizing(['http.request.method', 'http.route', 'http.response.status_code', 'error.type'], SpanAttributes::ALLOWED);
    }
}
