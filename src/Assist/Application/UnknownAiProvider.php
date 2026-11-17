<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The organization's AI settings name a provider or model pinround cannot use. Thrown instead of falling back to the
 * default provider, so ticket text never goes somewhere the admin did not choose.
 */
final class UnknownAiProvider extends \InvalidArgumentException
{
    public static function provider(): self
    {
        return new self('The AI settings name a provider that is not huggingface, anthropic or ollama.');
    }

    public static function emptyModel(): self
    {
        return new self('The AI settings name an empty model.');
    }
}
