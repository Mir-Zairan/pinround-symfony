<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\Tests\Fixtures\Http\LogLeakProbeController;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Uid\Uuid;

/**
 * Sends a unique marker in a request body to LogLeakProbeController, which logs it under an unlisted key, under a
 * sensitive key, as a bound SQL value and in an exception message (security.md "Logging without leaking"). The marker
 * reaches the records the loggers get, and no line the test env's log handler writes.
 */
final class LogLeakMarkerTest extends WebTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string SQL_MESSAGE = 'Executing statement: {sql} (parameters: {params}, types: {types})';
    private const string UNCAUGHT_EXCEPTION_MESSAGE_START = 'Uncaught PHP Exception RuntimeException: "'.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.'" at ';

    #[Test]
    public function theMarkerReachesTheLogRecordsButNoWrittenLogLine(): void
    {
        $client = self::createClient();
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $capturedLogRecords->clear();
        $logFile = self::logFile();
        $writtenBefore = self::sizeOf($logFile);
        $marker = 'leak-marker-'.bin2hex(random_bytes(8));

        $client->request('POST', '/_test/log-leak/'.self::ORG_A, ['summary' => $marker]);

        self::assertResponseStatusCodeSame(500);
        $records = $capturedLogRecords->all();
        self::assertSame($marker, self::recordWithMessage($records, LogLeakProbeController::SUMMARY_MESSAGE)->context['summary'] ?? null);
        self::assertSame($marker, self::recordWithMessage($records, LogLeakProbeController::DESCRIPTION_MESSAGE)->context['description'] ?? null);
        self::assertSame([1 => $marker], self::recordWithMessage($records, self::SQL_MESSAGE)->context['params'] ?? null);
        self::assertStringContainsString($marker, self::recordWithMessageStart($records, 'Uncaught PHP Exception RuntimeException: ')->message);

        $written = (string) file_get_contents($logFile, offset: $writtenBefore);
        self::assertStringNotContainsString($marker, $written);

        $lines = self::jsonLines($written);
        $summaryLine = self::lineWithMessage($lines, LogLeakProbeController::SUMMARY_MESSAGE);
        self::assertSame([], $summaryLine['context']);
        self::assertSame([], self::lineWithMessage($lines, LogLeakProbeController::DESCRIPTION_MESSAGE)['context']);
        self::assertSame([], self::lineWithMessage($lines, self::SQL_MESSAGE)['context']);
        $exceptionLine = self::lineWithMessageStart($lines, self::UNCAUGHT_EXCEPTION_MESSAGE_START);
        self::assertSame('CRITICAL', $exceptionLine['level_name']);
        $writtenException = $exceptionLine['context']['exception'] ?? null;
        self::assertIsArray($writtenException);
        self::assertSame(['class', 'code', 'file', 'line'], array_keys($writtenException));
        self::assertSame(\RuntimeException::class, $writtenException['class']);

        $requestId = $summaryLine['extra']['request_id'] ?? null;
        self::assertIsString($requestId);
        self::assertTrue(Uuid::isValid($requestId));
        self::assertSame(['request_id' => $requestId, 'organization_id' => self::ORG_A], $summaryLine['extra']);
        self::assertSame(['request_id' => $requestId, 'organization_id' => self::ORG_A], $exceptionLine['extra']);
    }

    /**
     * One kernel handles both requests, as a worker does (ADR-0008).
     */
    #[Test]
    public function eachRequestOnOneWorkerGetsItsOwnRequestId(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $logFile = self::logFile();
        $writtenBefore = self::sizeOf($logFile);

        $client->request('POST', '/_test/log-leak/'.self::ORG_A, ['summary' => 'first']);
        self::assertResponseStatusCodeSame(500);
        $client->request('POST', '/_test/log-leak/'.self::ORG_A, ['summary' => 'second']);
        self::assertResponseStatusCodeSame(500);

        $summaryLines = array_values(array_filter(
            self::jsonLines((string) file_get_contents($logFile, offset: $writtenBefore)),
            static fn (array $line): bool => LogLeakProbeController::SUMMARY_MESSAGE === $line['message'],
        ));
        self::assertCount(2, $summaryLines);
        self::assertNotSame($summaryLines[0]['extra']['request_id'] ?? null, $summaryLines[1]['extra']['request_id'] ?? null);
    }

    #[Test]
    public function everyChannelRedactsAfterAllItsOtherProcessors(): void
    {
        self::bootKernel();
        $privateServices = self::getContainer()->get('test.private_services_locator');
        self::assertInstanceOf(ServiceLocator::class, $privateServices);
        $channelLoggerIds = array_filter(
            array_unique([...array_keys($privateServices->getProvidedServices()), ...self::getContainer()->getServiceIds()]),
            static fn (string $id): bool => 'monolog.logger' === $id || str_starts_with($id, 'monolog.logger.'),
        );

        $checkedChannels = [];
        foreach ($channelLoggerIds as $loggerId) {
            $logger = self::getContainer()->get($loggerId);
            self::assertInstanceOf(Logger::class, $logger);
            $processors = $logger->getProcessors();
            self::assertInstanceOf(RedactionProcessor::class, end($processors), $logger->getName());
            $checkedChannels[] = $logger->getName();
        }

        self::assertContains('app', $checkedChannels);
        self::assertContains('doctrine', $checkedChannels);
        self::assertContains('request', $checkedChannels);
        self::assertContains('messenger', $checkedChannels);
        self::assertContains('deprecation', $checkedChannels);
    }

    /**
     * Where the test env's nested stream handler writes (config/packages/monolog.yaml).
     */
    private static function logFile(): string
    {
        return self::getContainer()->getParameter('kernel.logs_dir').'/test.log';
    }

    private static function sizeOf(string $logFile): int
    {
        clearstatcache();

        return is_file($logFile) ? (int) filesize($logFile) : 0;
    }

    /**
     * @param list<LogRecord> $records
     */
    private static function recordWithMessage(array $records, string $message): LogRecord
    {
        foreach ($records as $record) {
            if ($message === $record->message) {
                return $record;
            }
        }

        self::fail(\sprintf('No record "%s" was logged.', $message));
    }

    /**
     * @param list<LogRecord> $records
     */
    private static function recordWithMessageStart(array $records, string $messageStart): LogRecord
    {
        foreach ($records as $record) {
            if (str_starts_with($record->message, $messageStart)) {
                return $record;
            }
        }

        self::fail(\sprintf('No record starting "%s" was logged.', $messageStart));
    }

    /**
     * @return list<array{message: string, level_name: string, context: array<string, mixed>, extra: array<string, mixed>}>
     */
    private static function jsonLines(string $written): array
    {
        $lines = [];
        foreach (explode("\n", trim($written)) as $json) {
            /** @var array{message: string, level_name: string, context: array<string, mixed>, extra: array<string, mixed>} $line */
            $line = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<array{message: string, level_name: string, context: array<string, mixed>, extra: array<string, mixed>}> $lines
     *
     * @return array{message: string, level_name: string, context: array<string, mixed>, extra: array<string, mixed>}
     */
    private static function lineWithMessage(array $lines, string $message): array
    {
        foreach ($lines as $line) {
            if ($message === $line['message']) {
                return $line;
            }
        }

        self::fail(\sprintf('No line "%s" was written.', $message));
    }

    /**
     * @param list<array{message: string, level_name: string, context: array<string, mixed>, extra: array<string, mixed>}> $lines
     *
     * @return array{message: string, level_name: string, context: array<string, mixed>, extra: array<string, mixed>}
     */
    private static function lineWithMessageStart(array $lines, string $messageStart): array
    {
        foreach ($lines as $line) {
            if (str_starts_with($line['message'], $messageStart)) {
                return $line;
            }
        }

        self::fail(\sprintf('No line starting "%s" was written.', $messageStart));
    }
}
