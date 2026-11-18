<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * What the browser sends to Ollama on the laptop for one AI feature: the template's instructions (Ollama's "system")
 * and the prompt with the ticket inside its delimiters, built as the server path builds them. The package is used once
 * and only until expiresAt.
 */
final readonly class PromptPackage
{
    /**
     * @param string|null $schema the JSON schema of a data answer, for Ollama's "format"; null for a text answer, such
     *                            as a summary
     */
    public function __construct(
        public LocalPackageId $packageId,
        public string $instructions,
        public string $prompt,
        public ?string $schema,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
