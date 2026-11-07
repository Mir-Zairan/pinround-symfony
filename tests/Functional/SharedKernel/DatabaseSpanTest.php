<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\SharedKernel\Infrastructure\Tracing\SpanAttributes;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The phase criterion "a trace shows one span per DB query for GET /", through the real kernel (ADR-0029).
 */
final class DatabaseSpanTest extends WebTestCase
{
    #[Test]
    public function aRealQueryInsideTheRequestGetsItsOwnSpanBetweenBeginAndCommit(): void
    {
        $client = self::createClient();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $client->request('GET', '/_test/tracing/query');

        self::assertResponseStatusCodeSame(200);
        $spans = ExportedSpans::of($exporter);
        $roots = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId())));
        self::assertCount(1, $roots);
        $children = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => $span !== $roots[0]));
        self::assertSame(['db BEGIN', 'db SELECT', 'db COMMIT'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $children));
        foreach ($children as $child) {
            self::assertSame($roots[0]->getSpanId(), $child->getParentSpanId());
        }
    }

    #[Test]
    public function theHomePageExportsOneChildSpanPerStatementItSendsUnderTheRequestSpan(): void
    {
        $client = self::createClient();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $spans = ExportedSpans::of($exporter);
        $roots = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId())));
        self::assertCount(1, $roots);
        $children = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => $span !== $roots[0]));
        self::assertSame(['db BEGIN', 'db COMMIT'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $children));
        foreach ($children as $child) {
            self::assertSame($roots[0]->getTraceId(), $child->getTraceId());
            self::assertSame($roots[0]->getSpanId(), $child->getParentSpanId());
            self::assertSame('postgresql', $child->getAttributes()->get(SpanAttributes::DB_SYSTEM_NAME));
            self::assertSame(substr($child->getName(), 3), $child->getAttributes()->get(SpanAttributes::DB_OPERATION_NAME));
            self::assertCount(2, $child->getAttributes());
        }
    }
}
