<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;

/**
 * There is no usable prompt template or output schema for a feature and version. Thrown instead of sending an empty or
 * half-built prompt, or passing an answer that nothing checked. The message never quotes what a client sent.
 */
final class UnknownPromptTemplate extends \InvalidArgumentException
{
    public static function malformedName(): self
    {
        return new self('The prompt version is not a feature name and a version number, like summary.v1.');
    }

    public static function noCurrentVersion(AiFeature $feature): self
    {
        return new self(\sprintf('The %s feature has no prompt template yet.', $feature->value));
    }

    public static function noTemplate(PromptVersion $version): self
    {
        return new self(\sprintf('There is no prompt template %s.', $version->name()));
    }

    public static function incomplete(PromptVersion $version): self
    {
        return new self(\sprintf('The prompt template %s renders no instructions, no content or no ticket.', $version->name()));
    }

    public static function noSchema(PromptVersion $version): self
    {
        return new self(\sprintf('The prompt template %s has no output schema.', $version->name()));
    }
}
