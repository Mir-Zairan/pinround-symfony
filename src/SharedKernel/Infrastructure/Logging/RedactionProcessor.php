<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps only ids and shapes in every record of every channel, so ticket text, prompts, tokens and bound SQL values
 * never reach a log (ADR-0027, security.md "Logging without leaking"). Context and extra keep only ALLOWED_KEYS with a
 * scalar or null value, strings capped; `exception` keeps a throwable's class, code, file and line. The record message
 * loses the message of every throwable in the context, which Symfony quotes in messages it formats itself.
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
        $replacements = [];
        foreach ($context as $value) {
            for ($throwable = $value instanceof \Throwable ? $value : null; null !== $throwable; $throwable = $throwable->getPrevious()) {
                $replacements[$throwable->getMessage()] = self::WITHHELD_EXCEPTION_MESSAGE;
            }
        }

        // strtr ignores an empty message, replaces the longest match first and never inside a replacement, so a message
        // that quotes its previous one goes whole.
        return [] === $replacements ? $message : strtr($message, $replacements);
    }

    private static function capped(string $value): string
    {
        return mb_strlen($value) > self::MAX_STRING_LENGTH ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'…' : $value;
    }
}
