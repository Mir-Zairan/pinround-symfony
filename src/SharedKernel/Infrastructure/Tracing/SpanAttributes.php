<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

/**
 * The only attribute keys a span may carry (ADR-0029): never a URL, query string, header, body, user agent, IP,
 * exception message, title, description, comment, prompt or answer. Every span the app starts stays within ALLOWED.
 */
final class SpanAttributes
{
    public const string HTTP_REQUEST_METHOD = 'http.request.method';
    /** The route name, not the path. */
    public const string HTTP_ROUTE = 'http.route';
    public const string HTTP_RESPONSE_STATUS_CODE = 'http.response.status_code';
    /** The exception class of a failure; never its message. */
    public const string ERROR_TYPE = 'error.type';

    /** Always "postgresql". */
    public const string DB_SYSTEM_NAME = 'db.system.name';
    /** The first SQL keyword (SqlKind); never the statement. */
    public const string DB_OPERATION_NAME = 'db.operation.name';
    /** Always "symfony_messenger". */
    public const string MESSAGING_SYSTEM = 'messaging.system';
    /** "send" or "process". */
    public const string MESSAGING_OPERATION_TYPE = 'messaging.operation.type';
    /** The bus name. */
    public const string MESSAGING_DESTINATION_NAME = 'messaging.destination.name';
    /** The short class name of the message; never its content. */
    public const string MESSAGING_MESSAGE_CLASS = 'messaging.message.class';

    public const array ALLOWED = [
        self::HTTP_REQUEST_METHOD,
        self::HTTP_ROUTE,
        self::HTTP_RESPONSE_STATUS_CODE,
        self::ERROR_TYPE,
        self::DB_SYSTEM_NAME,
        self::DB_OPERATION_NAME,
        self::MESSAGING_SYSTEM,
        self::MESSAGING_OPERATION_TYPE,
        self::MESSAGING_DESTINATION_NAME,
        self::MESSAGING_MESSAGE_CLASS,
    ];

    private function __construct()
    {
    }
}
