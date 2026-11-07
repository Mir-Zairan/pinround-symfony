<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tracing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\SharedKernel\Infrastructure\Tracing\SpanAttributes;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The DBAL tracing wrapper against the real connection, with an in-memory exporter (ADR-0029).
 */
final class DatabaseSpanTest extends KernelTestCase
{
    private const string ORGANIZATION = '0197a3c0-0000-7000-8000-00000000000a';

    #[Test]
    public function aTenantTransactionTracesBeginTheTenantStatementAndCommit(): void
    {
        $connection = $this->connection();
        $root = $this->startRootSpan();
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ORGANIZATION));

        $connection->beginTransaction();
        $connection->commit();

        self::assertSame(['db BEGIN', 'db SELECT', 'db COMMIT'], $this->childNames($root));
        self::assertStringNotContainsString(self::ORGANIZATION, serialize($this->allVisibleText()));
        self::assertStringNotContainsString('set_config', serialize($this->allVisibleText()));
    }

    #[Test]
    public function everyPathToTheDatabaseGetsOneSpanNamedByKindOnly(): void
    {
        $connection = $this->connection();
        $root = $this->startRootSpan();
        $marker = 'bound-marker-'.bin2hex(random_bytes(6));

        $connection->executeQuery('SELECT 1 AS column_marker');
        $connection->executeStatement('DO $$ BEGIN END $$');
        $connection->executeQuery('select ? AS prepared_marker', [$marker]);

        self::assertSame(['db SELECT', 'db DO', 'db SELECT'], $this->childNames($root));
        $text = serialize($this->allVisibleText());
        foreach ([$marker, 'column_marker', 'prepared_marker', 'BEGIN END'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text);
        }
        foreach ($this->children($root) as $child) {
            self::assertSame('postgresql', $child->getAttributes()->get(SpanAttributes::DB_SYSTEM_NAME));
            self::assertSame(substr($child->getName(), 3), $child->getAttributes()->get(SpanAttributes::DB_OPERATION_NAME));
            self::assertSame([], $child->getEvents());
            self::assertSame(StatusCode::STATUS_UNSET, $child->getStatus()->getCode());
            foreach (array_keys($child->getAttributes()->toArray()) as $key) {
                self::assertContains($key, SpanAttributes::ALLOWED);
            }
        }
    }

    #[Test]
    public function aFailingStatementGivesAnErrorSpanWithTheExceptionClassAndTheExceptionPropagates(): void
    {
        $connection = $this->connection();
        $root = $this->startRootSpan();

        try {
            $connection->executeQuery('SELECT * FROM table_that_is_missing_marker');
            self::fail('The statement must fail.');
        } catch (TableNotFoundException) {
        }

        $children = $this->children($root);
        self::assertCount(1, $children);
        self::assertSame('db SELECT', $children[0]->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $children[0]->getStatus()->getCode());
        self::assertSame('', $children[0]->getStatus()->getDescription());
        $errorType = $children[0]->getAttributes()->get(SpanAttributes::ERROR_TYPE);
        self::assertIsString($errorType);
        self::assertTrue(class_exists($errorType) && is_a($errorType, \Throwable::class, true));
        self::assertSame([], $children[0]->getEvents());
        self::assertStringNotContainsString('table_that_is_missing_marker', serialize($this->allVisibleText()));
    }

    #[Test]
    public function aFailingPreparedStatementGivesAnErrorSpan(): void
    {
        $connection = $this->connection();
        $root = $this->startRootSpan();

        try {
            $connection->executeQuery('SELECT * FROM table_that_is_missing_marker WHERE id = ?', [1]);
            self::fail('The statement must fail.');
        } catch (TableNotFoundException) {
        }

        $children = $this->children($root);
        self::assertCount(1, $children);
        self::assertSame(StatusCode::STATUS_ERROR, $children[0]->getStatus()->getCode());
        self::assertIsString($children[0]->getAttributes()->get(SpanAttributes::ERROR_TYPE));
    }

    #[Test]
    public function aRolledBackTransactionGetsABeginAndARollbackSpan(): void
    {
        $connection = $this->connection();
        $root = $this->startRootSpan();

        $connection->beginTransaction();
        $connection->rollBack();

        self::assertSame(['db BEGIN', 'db ROLLBACK'], $this->childNames($root));
    }

    #[Test]
    public function withoutAnActiveSpanNoSpanIsCreated(): void
    {
        $connection = $this->connection();
        $exporter = $this->exporter();
        self::assertNull(self::getContainer()->get(CurrentSpan::class)->span());

        $connection->executeQuery('SELECT 1');
        $connection->beginTransaction();
        $connection->commit();
        self::getContainer()->get(TracerProviderInterface::class)->forceFlush();

        self::assertSame([], ExportedSpans::of($exporter));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function exporter(): InMemoryExporter
    {
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        return $exporter;
    }

    private function startRootSpan(): SpanInterface
    {
        $span = self::getContainer()->get(TracerProviderInterface::class)->getTracer('test')->spanBuilder('test root')->setParent(false)->startSpan();
        self::getContainer()->get(CurrentSpan::class)->begin($span, $span->storeInContext(Context::getRoot()));

        return $span;
    }

    /**
     * @return list<SpanDataInterface> the spans exported for the root span, in the order they ended
     */
    private function children(SpanInterface $root): array
    {
        self::getContainer()->get(TracerProviderInterface::class)->forceFlush();

        return array_values(array_filter(
            ExportedSpans::of($this->exporter()),
            static fn (SpanDataInterface $span): bool => $span->getParentSpanId() === $root->getContext()->getSpanId()
                && $span->getTraceId() === $root->getContext()->getTraceId(),
        ));
    }

    /**
     * @return list<string>
     */
    private function childNames(SpanInterface $root): array
    {
        return array_map(static fn (SpanDataInterface $span): string => $span->getName(), $this->children($root));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allVisibleText(): array
    {
        return array_map(static fn (SpanDataInterface $span): array => [
            'name' => $span->getName(),
            'attributes' => $span->getAttributes()->toArray(),
            'events' => $span->getEvents(),
            'status' => $span->getStatus()->getDescription(),
        ], ExportedSpans::of($this->exporter()));
    }
}
