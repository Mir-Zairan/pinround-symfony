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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestSpanSubscriberTest extends TestCase
{
    #[Test]
    public function aSubRequestStartsNoSpan(): void
    {
        $currentSpan = new CurrentSpan();
        $subscriber = new RequestSpanSubscriber(new TracerProviderFactory('', 'pinround', '1', 'http/protobuf', new InMemoryExporter())->create(), $currentSpan, new NullLogger());

        $subscriber->startSpan(new RequestEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST));

        self::assertNull($currentSpan->span());
    }

    #[Test]
    public function anUnreachableCollectorNeitherFailsNorHoldsTheTerminateStepLong(): void
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request();
        $response = new Response();
        $currentSpan = new CurrentSpan();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $provider = new TracerProviderFactory('http://127.0.0.1:9', 'pinround', '1', 'http/protobuf')->create();
        $subscriber = new RequestSpanSubscriber($provider, $currentSpan, $logger);

        $subscriber->startSpan(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->recordResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertTrue($currentSpan->sampledSpanContext()?->isValid());
        $startedAt = hrtime(true);
        $subscriber->endAndExport(new TerminateEvent($kernel, $request, $response));
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1e9;

        self::assertLessThan(1.5, $elapsedSeconds);
        self::assertNull($currentSpan->span());
    }

    #[Test]
    public function aFailingExportIsLoggedAsAWarningWithTheClassOnly(): void
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request();
        $currentSpan = new CurrentSpan();
        $loggedContexts = [];
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$loggedContexts): void {
            $loggedContexts[] = $context;
        });
        $provider = new TracerProviderFactory('', 'pinround', '1', 'http/protobuf', new InMemoryExporter())->create();
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
