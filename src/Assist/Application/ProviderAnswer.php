<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * A provider's answer to one prompt, with what metering needs. Token counts are null when the provider did not report
 * them, never a made-up zero.
 */
final readonly class ProviderAnswer
{
    /**
     * @param string $model    the model that answered, as the provider names it
     * @param string $provider for example "huggingface"; "fake" in tests
     */
    public function __construct(
        public string $text,
        public string $model,
        public string $provider,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $durationMs,
    ) {
    }
}
