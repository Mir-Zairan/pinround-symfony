<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\SharedKernel\Infrastructure\Tracing\SpanAttributes;
use App\Tests\Fixtures\Http\TracingProbeController;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The request span, through the real kernel with an in-memory exporter (ADR-0029 confirmation tests).
 */
final class RequestSpanTest extends WebTestCase
{
    #[Test]
    public function eachRequestOnOneWorkerExportsOneRootSpanOfItsOwnTrace(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $exporter = self::exporter();
        $currentSpan = self::currentSpan();

        $client->request('GET', '/_test/tracing');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($currentSpan->span());
        $firstSpans = self::rootSpans($exporter);
        self::assertCount(1, $firstSpans);

        $client->request('GET', '/_test/tracing');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($currentSpan->span());
        $spans = self::rootSpans($exporter);
        self::assertCount(2, $spans);

        self::assertFalse(SpanContextValidator::isValidSpanId($spans[0]->getParentSpanId()));
        self::assertFalse(SpanContextValidator::isValidSpanId($spans[1]->getParentSpanId()));
        self::assertNotSame($spans[0]->getTraceId(), $spans[1]->getTraceId());
        self::assertNotSame($spans[0]->getSpanId(), $spans[1]->getSpanId());
    }

    #[Test]
    public function theRootSpanIsNamedAfterTheMethodAndRouteAndCarriesOnlyTheAllowedAttributes(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('GET', '/_test/tracing');

        self::assertResponseStatusCodeSame(200);
        $span = self::onlySpan($exporter);
        self::assertSame('HTTP GET test_tracing_respond', $span->getName());
        self::assertEqualsCanonicalizing([
            SpanAttributes::HTTP_REQUEST_METHOD => 'GET',
            SpanAttributes::HTTP_ROUTE => 'test_tracing_respond',
            SpanAttributes::HTTP_RESPONSE_STATUS_CODE => 200,
        ], $span->getAttributes()->toArray());
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    #[Test]
    public function aRequestWithoutARouteIsNamedAfterTheMethodOnly(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('GET', '/_test/no-such-page');

        self::assertResponseStatusCodeSame(404);
        $span = self::onlySpan($exporter);
        self::assertSame('HTTP GET', $span->getName());
        self::assertFalse($span->getAttributes()->has(SpanAttributes::HTTP_ROUTE));
        self::assertSame(404, $span->getAttributes()->get(SpanAttributes::HTTP_RESPONSE_STATUS_CODE));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertFalse($span->getAttributes()->has(SpanAttributes::ERROR_TYPE));
    }

    #[Test]
    public function aMethodOutsideTheKnownListIsNotCopiedIntoTheSpan(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('MARKERMETHOD', '/_test/tracing');

        self::assertResponseStatusCodeSame(405);

        $span = self::onlySpan($exporter);
        self::assertSame('_OTHER', $span->getAttributes()->get(SpanAttributes::HTTP_REQUEST_METHOD));
        self::assertStringNotContainsString('MARKERMETHOD', $span->getName());
    }

    #[Test]
    public function aThrownExceptionMarksTheSpanAsAnErrorWithItsClassAndNoMessage(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $exporter = self::exporter();

        $client->request('GET', '/_test/tracing/fail');

        self::assertResponseStatusCodeSame(500);
        $span = self::onlySpan($exporter);
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('', $span->getStatus()->getDescription());
        self::assertSame(\DomainException::class, $span->getAttributes()->get(SpanAttributes::ERROR_TYPE));
        self::assertSame('test_tracing_fail', $span->getAttributes()->get(SpanAttributes::HTTP_ROUTE));
        self::assertSame(500, $span->getAttributes()->get(SpanAttributes::HTTP_RESPONSE_STATUS_CODE));
        self::assertSame([], $span->getEvents());
        self::assertStringNotContainsString(TracingProbeController::FAILURE_MESSAGE, serialize(self::visibleText($span)));
    }

    #[Test]
    public function aClientErrorThrownAsAnHttpExceptionLeavesTheSpanUnmarked(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('GET', '/_test/tracing/forbid');

        self::assertResponseStatusCodeSame(403);
        $span = self::onlySpan($exporter);
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertFalse($span->getAttributes()->has(SpanAttributes::ERROR_TYPE));
        self::assertSame(403, $span->getAttributes()->get(SpanAttributes::HTTP_RESPONSE_STATUS_CODE));
        self::assertSame('HTTP GET test_tracing_forbid', $span->getName());
    }

    #[Test]
    public function aReturnedServerErrorResponseMarksTheSpanAsAnErrorWithoutAnErrorType(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('GET', '/_test/tracing/server-error');

        self::assertResponseStatusCodeSame(500);
        $span = self::onlySpan($exporter);
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(500, $span->getAttributes()->get(SpanAttributes::HTTP_RESPONSE_STATUS_CODE));
        self::assertFalse($span->getAttributes()->has(SpanAttributes::ERROR_TYPE));
    }

    #[Test]
    public function aMessageDispatchedDuringTheRequestGetsASendSpanUnderTheRootSpan(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('GET', '/_test/tracing/dispatch');

        self::assertResponseStatusCodeSame(200);
        $root = self::onlySpan($exporter);
        $sendSpans = array_values(array_filter(
            ExportedSpans::of($exporter),
            static fn (SpanDataInterface $span): bool => 'messenger send stdClass' === $span->getName(),
        ));
        self::assertCount(1, $sendSpans);
        self::assertSame($root->getSpanId(), $sendSpans[0]->getParentSpanId());
        self::assertSame($root->getTraceId(), $sendSpans[0]->getTraceId());
    }

    #[Test]
    public function aSubRequestAddsNoSpanAndItsFailureDoesNotMarkTheMainSpan(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();

        $client->request('GET', '/_test/tracing/sub-request');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['subRequestStatus' => 500], json_decode((string) $client->getResponse()->getContent(), true));
        $span = self::onlySpan($exporter);
        self::assertSame('HTTP GET test_tracing_sub_request', $span->getName());
        self::assertSame('test_tracing_sub_request', $span->getAttributes()->get(SpanAttributes::HTTP_ROUTE));
        self::assertSame(200, $span->getAttributes()->get(SpanAttributes::HTTP_RESPONSE_STATUS_CODE));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertFalse($span->getAttributes()->has(SpanAttributes::ERROR_TYPE));
    }

    #[Test]
    public function aMarkerInTheQueryStringAHeaderAndTheBodyReachesNoSpan(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->catchExceptions(true);
        $exporter = self::exporter();
        $marker = 'span-marker-'.bin2hex(random_bytes(8));

        $client->request('POST', '/_test/tracing?q='.$marker, ['field' => $marker], [], [
            'HTTP_X_PROBE' => $marker,
            'HTTP_USER_AGENT' => $marker,
            'HTTP_REFERER' => 'https://example.test/'.$marker,
        ]);
        self::assertResponseStatusCodeSame(200);
        $client->request('GET', '/_test/tracing/fail?q='.$marker, [], [], ['HTTP_X_PROBE' => $marker]);
        self::assertResponseStatusCodeSame(500);

        $spans = self::rootSpans($exporter);
        self::assertCount(2, $spans);
        foreach ($spans as $span) {
            self::assertStringNotContainsString($marker, serialize(self::visibleText($span)));
            foreach (array_keys($span->getAttributes()->toArray()) as $key) {
                self::assertContains($key, SpanAttributes::ALLOWED);
            }
            self::assertSame([], $span->getEvents());
            self::assertSame([], $span->getLinks());
            self::assertSame(0, $span->getInstrumentationScope()->getAttributes()->count());
        }
    }

    #[Test]
    public function aRecordLoggedDuringTheRequestCarriesTheTraceAndSpanIdOfTheRootSpan(): void
    {
        $client = self::createClient();
        $exporter = self::exporter();
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $capturedLogRecords->clear();

        $client->request('GET', '/_test/tracing');

        self::assertResponseStatusCodeSame(200);
        $span = self::onlySpan($exporter);
        $probeRecords = array_values(array_filter(
            $capturedLogRecords->all(),
            static fn ($record): bool => TracingProbeController::LOG_MESSAGE === $record->message,
        ));
        self::assertCount(1, $probeRecords);
        self::assertSame($span->getTraceId(), $probeRecords[0]->extra['trace_id'] ?? null);
        self::assertSame($span->getSpanId(), $probeRecords[0]->extra['span_id'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->getTraceId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->getSpanId());
    }

    private static function exporter(): InMemoryExporter
    {
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        return $exporter;
    }

    private static function currentSpan(): CurrentSpan
    {
        return self::getContainer()->get(CurrentSpan::class);
    }

    /**
     * The exported spans that start a trace; the database spans of the request are covered by DatabaseSpanTest.
     *
     * @return list<SpanDataInterface>
     */
    private static function rootSpans(InMemoryExporter $exporter): array
    {
        return array_values(array_filter(
            ExportedSpans::of($exporter),
            static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId()),
        ));
    }

    private static function onlySpan(InMemoryExporter $exporter): SpanDataInterface
    {
        $spans = self::rootSpans($exporter);
        self::assertCount(1, $spans);

        return $spans[0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function visibleText(SpanDataInterface $span): array
    {
        return [
            'name' => $span->getName(),
            'attributes' => $span->getAttributes()->toArray(),
            'events' => $span->getEvents(),
            'statusDescription' => $span->getStatus()->getDescription(),
            'resource' => $span->getResource()->getAttributes()->toArray(),
        ];
    }
}
