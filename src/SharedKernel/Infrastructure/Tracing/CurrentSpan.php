<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The span of the current main web request and the context that holds it. Components start child spans from
 * context() explicitly: the OpenTelemetry global context is static and would leak between requests in worker
 * mode (ADR-0008). The kernel resets it between requests; the request span subscriber clears it when the
 * span ends.
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

    /** The context to pass to a child span builder as its parent; null when no request span is active. */
    public function context(): ?ContextInterface
    {
        return $this->context;
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
