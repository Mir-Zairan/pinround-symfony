<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\SharedKernel\Infrastructure\Tracing\RootSpanExporter;
use App\SharedKernel\Infrastructure\Tracing\TracerProviderFactory;
use App\SharedKernel\Infrastructure\Tracing\TracingDbalMiddleware;
use App\SharedKernel\Infrastructure\Tracing\TracingMessengerMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * While the active span is not recording (tracing off, or the request not sampled) the database and Messenger
 * wrappers start no span of their own: the tracer is never asked for one, so no SqlKind, builder or span is made.
 */
final class NonRecordingSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonRecordingProviders(): iterable
    {
        yield 'tracing off' => ['', ''];
        yield 'sampling ratio 0' => ['', '0'];
    }

    #[Test]
    #[DataProvider('nonRecordingProviders')]
    public function everyDatabaseCallPassesThroughWithoutAskingTheTracerForASpan(string $endpoint, string $samplingRatio): void
    {
        $currentSpan = $this->currentSpanThatIsNotRecording($endpoint, $samplingRatio);
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider->expects(self::never())->method('getTracer');
        $inner = $this->createMock(Connection::class);
        $statement = $this->createMock(Statement::class);
        $statement->expects(self::once())->method('execute')->willReturn(self::createStub(Result::class));
        $inner->expects(self::once())->method('query')->willReturn(self::createStub(Result::class));
        $inner->expects(self::once())->method('exec')->willReturn(1);
        $inner->expects(self::once())->method('prepare')->willReturn($statement);
        $inner->expects(self::once())->method('beginTransaction');
        $inner->expects(self::once())->method('commit');
        $inner->expects(self::once())->method('rollBack');
        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturn($inner);

        $connection = new TracingDbalMiddleware($tracerProvider, $currentSpan)->wrap($driver)->connect([]);
        $connection->query('SELECT 1');
        $connection->exec('DELETE FROM probe');
        $connection->prepare('SELECT 2')->execute();
        $connection->beginTransaction();
        $connection->commit();
        $connection->rollBack();
    }

    #[Test]
    #[DataProvider('nonRecordingProviders')]
    public function aMessageDispatchedDuringAnUnrecordedSpanPassesThroughWithoutASpan(string $endpoint, string $samplingRatio): void
    {
        $currentSpan = $this->currentSpanThatIsNotRecording($endpoint, $samplingRatio);
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider->expects(self::never())->method('getTracer');
        $middleware = new TracingMessengerMiddleware($tracerProvider, $currentSpan, new RootSpanExporter($tracerProvider, $currentSpan, new NullLogger()));
        $envelope = new Envelope(new \stdClass(), [new BusNameStamp('command.bus'), new ReceivedStamp('async')]);
        $terminal = new class implements MiddlewareInterface {
            public int $calls = 0;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ++$this->calls;

                return $envelope;
            }
        };

        $result = $middleware->handle($envelope, new StackMiddleware($terminal));

        self::assertSame($envelope, $result);
        self::assertSame(1, $terminal->calls);
        self::assertNotNull($currentSpan->span(), 'The unrecorded span stays the active one.');
    }

    private function currentSpanThatIsNotRecording(string $endpoint, string $samplingRatio): CurrentSpan
    {
        $exporter = '' === $samplingRatio ? null : new InMemoryExporter();
        $provider = new TracerProviderFactory($endpoint, 'pinround', '' === $samplingRatio ? '1' : $samplingRatio, 'http/protobuf', new NullLogger(), $exporter)->create();
        $span = $provider->getTracer('probe')->spanBuilder('root')->startSpan();
        self::assertFalse($span->isRecording());
        $currentSpan = new CurrentSpan();
        $currentSpan->begin($span, $span->storeInContext(Context::getRoot()));
        self::assertNull($currentSpan->recordingContext());

        return $currentSpan;
    }
}
