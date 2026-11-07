<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One root span per main web request (sub-requests start none), named "HTTP {METHOD} {route name}" and carrying
 * only the keys of SpanAttributes. The span ends and is exported on kernel.terminate, after the response is sent;
 * a collector that is down or slow never fails the request.
 */
final readonly class RequestSpanSubscriber
{
    // Before the router (32) and the firewall (8), so the span covers them; the route is read later, on the response.
    private const int START_PRIORITY = 4000;
    private const string INSTRUMENTATION_NAME = 'pinround.http';
    private const array KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'];
    // The OpenTelemetry value for a method outside the known list; the raw one is client input.
    private const string OTHER_METHOD = '_OTHER';

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private CurrentSpan $currentSpan,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::START_PRIORITY)]
    public function startSpan(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $span = $this->tracerProvider->getTracer(self::INSTRUMENTATION_NAME)
            ->spanBuilder('HTTP '.$this->methodOf($event->getRequest()))
            ->setParent(false)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $this->currentSpan->begin($span, $span->storeInContext(Context::getRoot()));
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -2048)]
    public function recordResponse(ResponseEvent $event): void
    {
        $span = $this->currentSpan->span();
        if (!$event->isMainRequest() || null === $span) {
            return;
        }

        $this->describeRequest($event->getRequest());
        $span->setAttribute(SpanAttributes::HTTP_RESPONSE_STATUS_CODE, $event->getResponse()->getStatusCode());
    }

    // Before other exception listeners, so the failure is recorded even when one of them turns it into a response.
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2048)]
    public function recordFailure(ExceptionEvent $event): void
    {
        $span = $this->currentSpan->span();
        if (!$event->isMainRequest() || null === $span) {
            return;
        }

        $this->describeRequest($event->getRequest());
        $span->setAttribute(SpanAttributes::ERROR_TYPE, $event->getThrowable()::class);
        $span->setStatus(StatusCode::STATUS_ERROR);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
    public function endAndExport(TerminateEvent $event): void
    {
        $span = $this->currentSpan->span();
        if (!$event->isMainRequest() || null === $span) {
            return;
        }

        try {
            $this->describeRequest($event->getRequest());
            $span->end();
            $this->tracerProvider->forceFlush();
        } catch (\Throwable $exportFailure) {
            // Class only: the message can quote the collector URL or a payload.
            $this->logger->warning('Exporting the request trace failed; it was dropped.', [
                'exception_class' => $exportFailure::class,
            ]);
        } finally {
            $this->currentSpan->reset();
        }
    }

    /** The route is only known after routing, so name and route attribute are set here, not on start. */
    private function describeRequest(Request $request): void
    {
        $span = $this->currentSpan->span();
        if (null === $span) {
            return;
        }

        $method = $this->methodOf($request);
        $route = $request->attributes->get('_route');
        $span->setAttribute(SpanAttributes::HTTP_REQUEST_METHOD, $method);
        if (\is_string($route) && '' !== $route) {
            $span->setAttribute(SpanAttributes::HTTP_ROUTE, $route);
            $span->updateName('HTTP '.$method.' '.$route);
        }
    }

    private function methodOf(Request $request): string
    {
        $method = $request->getMethod();

        return \in_array($method, self::KNOWN_METHODS, true) ? $method : self::OTHER_METHOD;
    }
}
