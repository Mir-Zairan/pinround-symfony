<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The root span of the unit of work in progress (a main web request or a message a worker handles) and the context
 * that holds it. Components start child spans from recordingContext() explicitly: the OpenTelemetry global context
 * is static and would leak between requests in worker mode (ADR-0008). The kernel resets it between requests; RootSpanExporter clears it when the root span ends.
 */
final class CurrentSpan implements ResetInterface
{
    private ?SpanInterface $span = null;
    private ?ContextInterface $context = null;

    public function begin(SpanInterface $span, ContextInterface $context): void
    {
        $this->span = $span;
        $this->context = $context;
    }

    public function span(): ?SpanInterface
    {
        return $this->span;
    }

    /** The context that holds the active span; null when none is active. */
    public function context(): ?ContextInterface
    {
        return $this->context;
    }

    /**
     * The context to pass to a child span builder as its parent; null when no span is active or the active one is not
     * recording (tracing off, or not sampled), so the caller starts nothing for it. One check, no allocation.
     */
    public function recordingContext(): ?ContextInterface
    {
        return true === $this->span?->isRecording() ? $this->context : null;
    }

    /** The span context, only when it is valid and sampled, so a log line never names a trace nobody exports. */
    public function sampledSpanContext(): ?SpanContextInterface
    {
        $spanContext = $this->span?->getContext();

        return null !== $spanContext && $spanContext->isValid() && $spanContext->isSampled() ? $spanContext : null;
    }

    public function reset(): void
    {
        $this->span = null;
        $this->context = null;
    }
}
