<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel;

use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use OpenTelemetry\API\Trace\NonRecordingSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * In worker mode (ADR-0008) the kernel resets services between requests; the active span must not survive that.
 */
final class CurrentSpanResetTest extends KernelTestCase
{
    #[Test]
    public function theKernelResetBetweenRequestsClearsTheSpanAndItsContext(): void
    {
        $container = self::getContainer();
        $currentSpan = $container->get(CurrentSpan::class);
        $currentSpan->begin(
            new NonRecordingSpan(SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', TraceFlags::SAMPLED)),
            Context::getRoot(),
        );
        self::assertNotNull($currentSpan->span());
        self::assertNotNull($currentSpan->context());

        $container->get('services_resetter')->reset();

        self::assertNull($currentSpan->span());
        self::assertNull($currentSpan->context());
        self::assertNull($currentSpan->sampledSpanContext());
    }
}
