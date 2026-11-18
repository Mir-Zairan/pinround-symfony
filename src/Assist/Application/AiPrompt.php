<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;

/**
 * One rendered prompt: the fixed instructions (system message) and the content (user message), in which ticket text
 * is already wrapped as data by the prompt builder.
 */
final readonly class AiPrompt
{
    public function __construct(
        public AiFeature $feature,
        public string $instructions,
        public string $content,
    ) {
    }
}
