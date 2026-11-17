<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The provider refused the call because too many were made (HTTP 429). The message holds no prompt or answer text.
 */
final class AiRateLimited extends \RuntimeException
{
    /**
     * @param string   $provider          the provider that refused, for example "huggingface"
     * @param int|null $retryAfterSeconds what the provider's Retry-After said, null when it said nothing usable
     */
    public function __construct(public readonly string $provider, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct(\sprintf('The AI provider %s refused the call: too many requests.', $provider));
    }
}
