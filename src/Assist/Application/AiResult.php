<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * What the gateway gives back: text (summary, explanation) or validated data (intake suggestions), never both, with
 * what made it, so every stored and shown result can be labeled (ADR-0018, ADR-0020).
 */
final readonly class AiResult
{
    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(
        public ?string $text,
        public ?array $data,
        public string $model,
        public string $provider,
        public string $promptVersion,
        public string $label,
    ) {
    }

    public static function withText(string $text, string $model, string $provider, string $promptVersion, string $label): self
    {
        return new self($text, null, $model, $provider, $promptVersion, $label);
    }

    /**
     * @param array<string, mixed> $data the answer after schema validation
     */
    public static function withData(array $data, string $model, string $provider, string $promptVersion, string $label): self
    {
        return new self(null, $data, $model, $provider, $promptVersion, $label);
    }
}
