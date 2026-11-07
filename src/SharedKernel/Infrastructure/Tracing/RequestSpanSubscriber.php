<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
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
 * a collector that is down or slow never fails the request. Only a response with status 500 or above marks the span
 * as an error, with the exception class as error.type when one was thrown.
 */
final readonly class RequestSpanSubscriber
{
    // Before the router (32) and the firewall (8), so the span covers them; the route is read later, on terminate.
    private const int START_PRIORITY = 4000;
    private const string INSTRUMENTATION_NAME = 'pinround.http';
    private const array KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'];
    // The OpenTelemetry value for a method outside the known list; the raw one is client input.
    private const string OTHER_METHOD = '_OTHER';
    // Request attribute that carries a thrown exception's class from kernel.exception to the response, which decides
    // whether the request failed.
    private const string THROWN_CLASS_ATTRIBUTE = '_tracing_thrown_class';
    private const int FIRST_SERVER_ERROR_STATUS = 500;

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private CurrentSpan $currentSpan,
        private RootSpanExporter $rootSpanExporter,
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

        $statusCode = $event->getResponse()->getStatusCode();
        $span->setAttribute(SpanAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
        // A 4xx (not found, access denied, bad input) is the caller's mistake, not a failure of ours.
        if ($statusCode >= self::FIRST_SERVER_ERROR_STATUS) {
            $thrownClass = $event->getRequest()->attributes->get(self::THROWN_CLASS_ATTRIBUTE);
            if (\is_string($thrownClass)) {
                $span->setAttribute(SpanAttributes::ERROR_TYPE, $thrownClass);
            }
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    // Before other exception listeners, so the class is noted even when one of them turns the exception into a response.
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2048)]
    public function noteThrown(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::THROWN_CLASS_ATTRIBUTE, $event->getThrowable()::class);
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
    public function endAndExport(TerminateEvent $event): void
    {
        $span = $this->currentSpan->span();
        if (!$event->isMainRequest() || null === $span) {
            return;
        }

        $this->describeRequest($span, $event->getRequest());
        $this->rootSpanExporter->endAndExport($span);
    }

    /** The route is only known after routing, so name and route attribute are set here, not on start. */
    private function describeRequest(SpanInterface $span, Request $request): void
    {
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
