<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * No answer could be had from the provider: it is not configured, cannot be reached, failed, timed out, or answered with
 * something that is not text or with blank text. The message names only the provider and an exception class, never the library's message, which
 * may quote the prompt or the answer (ADR-0027: no AI content in logs).
 */
final class AiUnavailable extends \RuntimeException
{
    private function __construct(public readonly string $provider, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $provider): self
    {
        return new self($provider, \sprintf('The AI provider %s has no key or URL set.', $provider));
    }

    public static function emptyAnswer(string $provider): self
    {
        return new self($provider, \sprintf('The AI provider %s answered with no text.', $provider));
    }

    /**
     * @param class-string<\Throwable> $failureClass the class of the library or HTTP client exception, which is not chained
     */
    public static function failed(string $provider, string $failureClass): self
    {
        return new self($provider, \sprintf('The AI provider %s did not answer (%s).', $provider, $failureClass));
    }
}
