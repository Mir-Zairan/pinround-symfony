<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The server-side providers pinround can call (ADR-0018). The value is what the organization's AI settings store.
 */
enum AiProviderName: string
{
    case HuggingFace = 'huggingface';
    case Anthropic = 'anthropic';
    case Ollama = 'ollama';

    /**
     * The model used when the organization names none: small, cheap ones that write plain summaries. The Hugging Face and
     * Anthropic names are in their bridge's model catalog; the Ollama one must be pulled on the operator's server.
     *
     * @return non-empty-string
     */
    public function defaultModel(): string
    {
        return match ($this) {
            self::HuggingFace => 'meta-llama/Llama-3.1-8B-Instruct',
            self::Anthropic => 'claude-haiku-4-5',
            self::Ollama => 'qwen3:4b',
        };
    }
}
