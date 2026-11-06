<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Fixtures\Messaging\SaveProbeNote;
use App\Tests\Fixtures\Messaging\SaveProbeNoteHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * A message whose handler always fails is tried once and retried 3 times (the async retry strategy; when@test drops
 * the waits), then waits in the failed transport with its error. Its note, standing for ticket text, never reaches a
 * log record of any channel (ADR-0027), as the prod JSON formatter or the dev line formatter would write it.
 */
final class FailedMessageTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    private CapturedLogRecords $capturedLogRecords;

    protected function setUp(): void
    {
        $container = self::getContainer();
        // Rows a failed earlier run left; the test database only.
        $container->get('doctrine.dbal.default_connection')->executeStatement('DELETE FROM messenger_failed');

        $capturedLogRecords = $container->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $this->capturedLogRecords = $capturedLogRecords;
        $this->capturedLogRecords->clear();
    }

    #[Test]
    public function aHandlerThatAlwaysFailsLandsInFailedWithItsErrorAndItsNoteInNoLogRecord(): void
    {
        $note = 'probe-note-'.bin2hex(random_bytes(8));
        self::getContainer()->get('command.bus')->dispatch(new SaveProbeNote($note), [
            new TenantStamp(new OrganizationId(self::ORG_A)),
            new TransportNamesStamp(['async_notify']),
        ]);
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(4, $this->handler()->attemptCount());
        $failed = $this->failedEnvelopes();
        self::assertCount(1, $failed);
        self::assertEquals(new SaveProbeNote($note), $failed[0]->getMessage());
        // Retries 1 to 3, then the 0 Messenger stamps on a message it moves to the failure transport.
        self::assertSame([1, 2, 3, 0], array_map(static fn (RedeliveryStamp $stamp): int => $stamp->getRetryCount(), $failed[0]->all(RedeliveryStamp::class)));
        $errorDetails = $failed[0]->last(ErrorDetailsStamp::class);
        self::assertInstanceOf(ErrorDetailsStamp::class, $errorDetails);
        self::assertSame(\RuntimeException::class, $errorDetails->getExceptionClass());
        self::assertSame('Saving the probe note failed.', $errorDetails->getExceptionMessage());
        self::assertSame([], iterator_to_array($this->asyncNotify()->get(), false));

        self::assertSame(
            [
                [Level::Warning, 1], [Level::Warning, 2], [Level::Warning, 3], [Level::Critical, 3],
            ],
            $this->retryAndRemovalRecordsOf(SaveProbeNote::class),
        );
        $checkedRecordCount = 0;
        foreach ($this->capturedLogRecords->all() as $record) {
            if (self::isDebugSqlRecord($record)) {
                continue;
            }
            ++$checkedRecordCount;
            self::assertStringNotContainsString($note, self::asWritten($record), \sprintf('The %s record "%s" holds the note.', $record->channel, $record->message));
        }
        self::assertGreaterThanOrEqual(4, $checkedRecordCount);
    }

    /**
     * DBAL's SQL logger writes every bound value, so the envelope the failed transport inserts too. DoctrineBundle runs
     * it only with kernel.debug (dbal.logging defaults to it and doctrine.yaml does not set it), so prod never has it.
     */
    private static function isDebugSqlRecord(LogRecord $record): bool
    {
        return 'doctrine' === $record->channel && \array_key_exists('sql', $record->context);
    }

    /** Proves the check above can fail: a record with the message in its context does show the note. */
    #[Test]
    public function aRecordWithTheMessageInItsContextWouldShowTheNote(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'messenger', Level::Warning, 'Handling failed.', ['message' => new SaveProbeNote('probe-note-visible')]);

        self::assertStringContainsString('probe-note-visible', self::asWritten($record));
    }

    /**
     * @return list<array{Level, mixed}> level and retry count of Messenger's retry and removal records for the class
     */
    private function retryAndRemovalRecordsOf(string $messageClass): array
    {
        $records = array_filter(
            $this->capturedLogRecords->all(),
            static fn (LogRecord $record): bool => 'messenger' === $record->channel
                && ($record->context['class'] ?? null) === $messageClass
                && \array_key_exists('retryCount', $record->context),
        );

        return array_values(array_map(static fn (LogRecord $record): array => [$record->level, $record->context['retryCount']], $records));
    }

    private static function asWritten(LogRecord $record): string
    {
        $json = new JsonFormatter();
        $json->includeStacktraces();
        $line = new LineFormatter();
        $line->includeStacktraces();

        return $json->format($record).$line->format($record);
    }

    private function handler(): SaveProbeNoteHandler
    {
        $handler = self::getContainer()->get(SaveProbeNoteHandler::class);
        self::assertInstanceOf(SaveProbeNoteHandler::class, $handler);

        return $handler;
    }

    /**
     * @return list<Envelope>
     */
    private function failedEnvelopes(): array
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);

        return iterator_to_array($failed->all(), false);
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
