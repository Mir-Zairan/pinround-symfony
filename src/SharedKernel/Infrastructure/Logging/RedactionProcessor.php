<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps only ids and shapes in every record of every channel, so ticket text, prompts, tokens and bound SQL values
 * never reach a log (ADR-0027, security.md "Logging without leaking"). Context and extra keep only ALLOWED_KEYS with a
 * scalar or null value, strings capped; `exception` keeps a throwable's class, code, file and line.
 *
 * Symfony quotes a throwable's message in record messages it formats itself, so the record message loses the message
 * of every throwable in the context (and of their previous ones): each quoted copy is replaced; a copy left outside
 * quotes is replaced too when the exception message is long enough not to match unrelated text, and otherwise, when it
 * stands as a whole word, the whole record message is withheld.
 *
 * Runs after the other logger processors; each handler's PSR-3 message processor runs later still, so a placeholder
 * of a dropped key stays unfilled. Log a constant message with placeholders, never one built from content.
 */
#[AsMonologProcessor(priority: self::PRIORITY)]
final class RedactionProcessor implements ProcessorInterface
{
    // Monolog runs logger processors from the highest priority down, so this one sees every key the others add.
    public const int PRIORITY = \PHP_INT_MIN;

    /** In characters; ids, route names and class names fit. */
    public const int MAX_STRING_LENGTH = 255;

    public const string WITHHELD_EXCEPTION_MESSAGE = '[exception message withheld]';

    public const string WITHHELD_LOG_MESSAGE = '[log message withheld: it quoted an exception message]';

    /** In characters; a shorter exception message outside quotes could be part of a class name or other words. */
    public const int MIN_UNQUOTED_EXCEPTION_MESSAGE_LENGTH = 16;

    /**
     * Correlation ids (CorrelationIdsProcessor; trace_id and span_id come with OpenTelemetry), the HTTP keys of
     * Symfony's RouterListener, the Messenger keys of its worker and retry listener, and the keys RequestTransaction
     * logs a failed rollback with.
     */
    public const array ALLOWED_KEYS = [
        'request_id', 'trace_id', 'span_id', 'organization_id', 'user_id',
        'route', 'method', 'status_code', 'duration_ms',
        'class', 'message_id', 'transport', 'retryCount', 'delay',
        'exception', 'exception_class', 'sqlstate', 'code',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::withoutExceptionMessages($record->message, $record->context),
            context: self::keepAllowed($record->context),
            extra: self::keepAllowed($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @return array<array-key, mixed>
     */
    private static function keepAllowed(array $fields): array
    {
        $allowed = [];
        foreach ($fields as $key => $value) {
            if (!\in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            if ('exception' === $key) {
                if ($value instanceof \Throwable) {
                    $allowed[$key] = [
                        'class' => $value::class,
                        'code' => $value->getCode(),
                        'file' => self::capped($value->getFile()),
                        'line' => $value->getLine(),
                    ];
                }
            } elseif (\is_string($value)) {
                $allowed[$key] = self::capped($value);
            } elseif (null === $value || \is_scalar($value)) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function withoutExceptionMessages(string $message, array $context): string
    {
        $exceptionMessages = [];
        foreach ($context as $value) {
            for ($throwable = $value instanceof \Throwable ? $value : null; null !== $throwable; $throwable = $throwable->getPrevious()) {
                if ('' !== $throwable->getMessage()) {
                    $exceptionMessages[] = $throwable->getMessage();
                }
            }
        }
        if ([] === $exceptionMessages) {
            return $message;
        }

        $quotedReplacements = [];
        foreach ($exceptionMessages as $exceptionMessage) {
            $quotedReplacements['"'.$exceptionMessage.'"'] = '"'.self::WITHHELD_EXCEPTION_MESSAGE.'"';
        }
        // strtr replaces the longest match first and never inside a replacement, so a message that quotes its previous
        // one goes whole. The pieces between replacements are what is left to check.
        $pieces = explode(self::WITHHELD_EXCEPTION_MESSAGE, strtr($message, $quotedReplacements));

        $unquotedReplacements = [];
        foreach (array_unique($exceptionMessages) as $exceptionMessage) {
            if (mb_strlen($exceptionMessage) >= self::MIN_UNQUOTED_EXCEPTION_MESSAGE_LENGTH) {
                $unquotedReplacements[$exceptionMessage] = self::WITHHELD_EXCEPTION_MESSAGE;
            } elseif (self::containsAsWholeWord($pieces, $exceptionMessage)) {
                return self::WITHHELD_LOG_MESSAGE;
            }
        }

        return implode(self::WITHHELD_EXCEPTION_MESSAGE, array_map(
            static fn (string $piece): string => strtr($piece, $unquotedReplacements),
            $pieces,
        ));
    }

    /**
     * @param list<string> $pieces
     */
    private static function containsAsWholeWord(array $pieces, string $exceptionMessage): bool
    {
        // Word boundaries need UTF-8 on both sides, and a /u pattern built from invalid UTF-8 would not even compile
        // (a warning raised inside the logger); without them any copy counts, so the message is never let through.
        $isUtf8 = mb_check_encoding($exceptionMessage, 'UTF-8');
        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($exceptionMessage, '/').'(?![\p{L}\p{N}_])/u';
        foreach ($pieces as $piece) {
            $isWord = $isUtf8 && mb_check_encoding($piece, 'UTF-8')
                ? 1 === preg_match($pattern, $piece)
                : str_contains($piece, $exceptionMessage);
            if ($isWord) {
                return true;
            }
        }

        return false;
    }

    private static function capped(string $value): string
    {
        return mb_strlen($value) > self::MAX_STRING_LENGTH ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'…' : $value;
    }
}
