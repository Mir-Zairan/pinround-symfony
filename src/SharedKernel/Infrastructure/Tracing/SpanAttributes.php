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

    public const array ALLOWED = [
        self::HTTP_REQUEST_METHOD,
        self::HTTP_ROUTE,
        self::HTTP_RESPONSE_STATUS_CODE,
        self::ERROR_TYPE,
    ];

    private function __construct()
    {
    }
}
