<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The model answered, but the answer failed the output check (ADR-0020), so nothing of it is shown or stored. When
 * `isRetryAllowed` is true the caller may ask once more, with `AiAnswerCheck::retryInstruction()` added; otherwise it
 * gives up. The message names only the check that failed, never the answer (ADR-0027: no AI content in logs).
 */
final class AiAnswerRejected extends \RuntimeException
{
    private function __construct(public readonly bool $isRetryAllowed, string $message)
    {
        parent::__construct($message);
    }

    public static function blank(bool $isRetryAllowed): self
    {
        return new self($isRetryAllowed, 'The AI answer is blank.');
    }

    public static function oversized(bool $isRetryAllowed, int $maxBytes): self
    {
        return new self($isRetryAllowed, \sprintf('The AI answer is longer than %d bytes.', $maxBytes));
    }

    public static function notJson(bool $isRetryAllowed): self
    {
        return new self($isRetryAllowed, 'The AI answer is not JSON.');
    }

    public static function schemaMismatch(bool $isRetryAllowed, PromptVersion $version): self
    {
        return new self($isRetryAllowed, \sprintf('The AI answer does not match the %s schema.', $version->name()));
    }

    public static function tooManyWords(PromptVersion $version, int $maxWords): self
    {
        return new self(false, \sprintf('The AI answer to %s has more than %d words.', $version->name(), $maxWords));
    }

    public static function tooManySentences(PromptVersion $version, int $maxSentences): self
    {
        return new self(false, \sprintf('The AI answer to %s has more than %d sentences.', $version->name(), $maxSentences));
    }
}
