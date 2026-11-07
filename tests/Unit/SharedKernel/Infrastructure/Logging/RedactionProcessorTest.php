<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Logging;

use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedactionProcessorTest extends TestCase
{
    private const string MARKER = 'marker-0f3c9a';
    private const string REQUEST_ID = '0197a3c0-0000-7000-8000-0000000000aa';
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    #[Test]
    public function itDropsAnUnknownKeyAndASensitiveKeyAndKeepsTheAllowedIds(): void
    {
        $redacted = self::redact(self::record(
            context: ['summary' => self::MARKER, 'description' => self::MARKER, 'route' => 'home', 'retryCount' => 2, 'code' => null],
            extra: ['request_id' => self::REQUEST_ID, 'organization_id' => self::ORG_A, 'body' => self::MARKER],
        ));

        self::assertSame(['route' => 'home', 'retryCount' => 2, 'code' => null], $redacted->context);
        self::assertSame(['request_id' => self::REQUEST_ID, 'organization_id' => self::ORG_A], $redacted->extra);
    }

    #[Test]
    public function itDropsTheSqlParamsAndTypesOfAQueryRecord(): void
    {
        $redacted = self::redact(self::record(context: ['sql' => 'SELECT ?', 'params' => [self::MARKER], 'types' => [2]]));

        self::assertSame([], $redacted->context);
    }

    #[Test]
    public function itDropsAnAllowedKeyWhoseValueIsNotAScalar(): void
    {
        $redacted = self::redact(self::record(context: [
            'route' => [self::MARKER],
            'class' => new \ArrayObject([self::MARKER]),
            'exception' => self::MARKER,
            0 => self::MARKER,
        ]));

        self::assertSame([], $redacted->context);
    }

    #[Test]
    public function itReducesTheExceptionToItsClassCodeFileAndLine(): void
    {
        $exception = new \RuntimeException('Saving '.self::MARKER.' failed.', 42, new \LogicException(self::MARKER));

        $redacted = self::redact(self::record(context: ['exception' => $exception]));

        self::assertSame(
            ['exception' => ['class' => \RuntimeException::class, 'code' => 42, 'file' => __FILE__, 'line' => $exception->getLine()]],
            $redacted->context,
        );
    }

    #[Test]
    public function itCapsALongStringValue(): void
    {
        $redacted = self::redact(self::record(context: [
            'route' => str_repeat('é', RedactionProcessor::MAX_STRING_LENGTH + 1),
            'method' => str_repeat('a', RedactionProcessor::MAX_STRING_LENGTH),
        ]));

        self::assertSame(
            ['route' => str_repeat('é', RedactionProcessor::MAX_STRING_LENGTH).'…', 'method' => str_repeat('a', RedactionProcessor::MAX_STRING_LENGTH)],
            $redacted->context,
        );
    }

    #[Test]
    public function itWithholdsTheMessageOfAnExceptionAndOfItsPreviousOnesFromTheRecordMessage(): void
    {
        $previous = new \LogicException('inner '.self::MARKER);
        $exception = new \RuntimeException('outer '.self::MARKER, 0, $previous);
        $message = \sprintf('Uncaught PHP Exception RuntimeException: "%s" at x.php line 1. Caused by "%s".', $exception->getMessage(), $previous->getMessage());

        $redacted = self::redact(self::record(message: $message, context: ['error' => $exception]));

        self::assertSame(
            'Uncaught PHP Exception RuntimeException: "'.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.'" at x.php line 1. Caused by "'.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.'".',
            $redacted->message,
        );
    }

    #[Test]
    public function aShortQuotedExceptionMessageGoesAndTheClassNameThatHoldsItsWordStays(): void
    {
        $redacted = self::redact(self::record(
            message: 'Uncaught PHP Exception App\InvalidThing: "Invalid" at x.php line 1',
            context: ['exception' => new \RuntimeException('Invalid')],
        ));

        self::assertSame('Uncaught PHP Exception App\InvalidThing: "'.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.'" at x.php line 1', $redacted->message);
    }

    #[Test]
    public function aShortUnquotedExceptionMessageWithholdsTheWholeLogMessage(): void
    {
        $redacted = self::redact(self::record(
            message: 'Exception thrown when handling an exception (RuntimeException: Invalid at x.php line 1)',
            context: ['exception' => new \RuntimeException('Invalid')],
        ));

        self::assertSame(RedactionProcessor::WITHHELD_LOG_MESSAGE, $redacted->message);
    }

    #[Test]
    public function aShortExceptionMessageInTextThatIsNotUtf8WithholdsTheWholeLogMessage(): void
    {
        $redacted = self::redact(self::record(message: "Failed \xff Invalid", context: ['exception' => new \RuntimeException('Invalid')]));

        self::assertSame(RedactionProcessor::WITHHELD_LOG_MESSAGE, $redacted->message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unquotedExceptionMessagesAroundTheCutOff(): iterable
    {
        yield 'exactly 16 characters' => ['Sixteen chars ok', 'Failed (RuntimeException: '.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.' at x.php line 1)'];
        yield 'exactly 15 characters' => ['Fifteen chars!!', RedactionProcessor::WITHHELD_LOG_MESSAGE];
    }

    #[Test]
    #[DataProvider('unquotedExceptionMessagesAroundTheCutOff')]
    public function anUnquotedExceptionMessageOfSixteenCharactersOrMoreIsReplacedInPlaceAndAShorterOneWithholdsTheLogMessage(string $exceptionMessage, string $expectedMessage): void
    {
        $redacted = self::redact(self::record(
            message: 'Failed (RuntimeException: '.$exceptionMessage.' at x.php line 1)',
            context: ['exception' => new \RuntimeException($exceptionMessage)],
        ));

        self::assertSame($expectedMessage, $redacted->message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function logMessagesWithAShortExceptionMessageThatIsNotUtf8(): iterable
    {
        yield 'quoted' => ["Failed: \"Bad \xff\"", 'Failed: "'.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.'"'];
        yield 'unquoted' => ["Failed: Bad \xff here", RedactionProcessor::WITHHELD_LOG_MESSAGE];
    }

    /**
     * A pattern built from text that is not UTF-8 would raise a warning inside the logger, which debug mode throws.
     */
    #[Test]
    #[DataProvider('logMessagesWithAShortExceptionMessageThatIsNotUtf8')]
    public function aShortExceptionMessageThatIsNotUtf8IsWithheldWithoutAWarning(string $message, string $expectedMessage): void
    {
        set_error_handler(static function (int $level, string $error): never {
            throw new \ErrorException($error, 0, $level);
        });
        try {
            $redacted = self::redact(self::record(message: $message, context: ['exception' => new \RuntimeException("Bad \xff")]));
        } finally {
            restore_error_handler();
        }

        self::assertSame($expectedMessage, $redacted->message);
    }

    #[Test]
    public function aLongUnquotedExceptionMessageIsReplacedInPlace(): void
    {
        $exception = new \RuntimeException('Leak probe failed on '.self::MARKER);

        $redacted = self::redact(self::record(message: 'Handling failed (RuntimeException: '.$exception->getMessage().' at x.php line 1)', context: ['exception' => $exception]));

        self::assertSame('Handling failed (RuntimeException: '.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.' at x.php line 1)', $redacted->message);
    }

    /**
     * "withheld" is part of the replacement text; only what is left around the replacements is checked again.
     */
    #[Test]
    public function aShortExceptionMessageInsideTheReplacementTextDoesNotWithholdTheLogMessage(): void
    {
        $redacted = self::redact(self::record(message: 'Failed: "withheld"', context: ['exception' => new \RuntimeException('withheld')]));

        self::assertSame('Failed: "'.RedactionProcessor::WITHHELD_EXCEPTION_MESSAGE.'"', $redacted->message);
    }

    #[Test]
    public function anExceptionWithAnEmptyMessageLeavesTheLogMessageAsWritten(): void
    {
        $redacted = self::redact(self::record(message: 'Uncaught PHP Exception RuntimeException: "" at x.php line 1', context: ['exception' => new \RuntimeException()]));

        self::assertSame('Uncaught PHP Exception RuntimeException: "" at x.php line 1', $redacted->message);
    }

    #[Test]
    public function aMessageWithoutAnExceptionStaysAsWritten(): void
    {
        $redacted = self::redact(self::record(message: 'Matched route "{route}".', context: ['route' => 'home']));

        self::assertSame('Matched route "{route}".', $redacted->message);
    }

    /**
     * Each handler's PSR-3 message processor runs after the logger's processors, so it only sees the allowed keys.
     */
    #[Test]
    public function aPlaceholderOfADroppedKeyStaysUnfilled(): void
    {
        $redacted = self::redact(self::record(
            message: 'Error thrown while handling message {class}. Error: "{error}"',
            context: ['class' => 'App\Message', 'error' => self::MARKER],
        ));

        $interpolated = new PsrLogMessageProcessor()($redacted);

        self::assertSame('Error thrown while handling message App\Message. Error: "{error}"', $interpolated->message);
    }

    #[Test]
    public function itAllowsExactlyTheIdsAndShapesOfTheSecurityDoc(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'request_id', 'trace_id', 'span_id', 'organization_id', 'user_id',
                'route', 'method', 'status_code', 'duration_ms',
                'class', 'message_id', 'transport', 'retryCount', 'delay',
                'exception', 'exception_class', 'sqlstate', 'code',
                'check',
            ],
            RedactionProcessor::ALLOWED_KEYS,
        );
    }

    private static function redact(LogRecord $record): LogRecord
    {
        return new RedactionProcessor()($record);
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private static function record(string $message = 'Probe.', array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, $message, $context, $extra);
    }
}
