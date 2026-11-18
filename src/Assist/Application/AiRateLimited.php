<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * Too many AI calls: either pinround's own limit per person refused the call before any provider was asked (AiCallLimit),
 * or the provider refused it (HTTP 429). The message holds no prompt or answer text.
 */
final class AiRateLimited extends \RuntimeException
{
    /** Shown to the person; the per-person window may in fact end later than a minute from now. */
    public const string PER_PERSON_MESSAGE = 'Too many AI requests. Try again in a minute.';

    /**
     * @param string|null $provider          the provider that refused, for example "huggingface"; null when pinround's
     *                                       per-person limit refused the call
     * @param int|null    $retryAfterSeconds what the provider's Retry-After said, null when it said nothing usable
     */
    public function __construct(public readonly ?string $provider, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct(null === $provider
            ? self::PER_PERSON_MESSAGE
            : \sprintf('The AI provider %s refused the call: too many requests.', $provider));
    }
}
