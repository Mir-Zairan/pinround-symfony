<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * What "Check with AI" suggests for a draft report, as the intake schema checked it, with the model and prompt
 * version that made it (ADR-0020: every AI output is labeled). It suggests only: the person decides what to take. It
 * has no visibility: the AI never sets one.
 */
final readonly class IntakeSuggestion
{
    public const string LABEL = 'AI suggestion';

    /**
     * @param list<string> $duplicateKeys keys of open tickets the draft may repeat, each one that was sent as a
     *                                    candidate
     */
    public function __construct(
        public string $summary,
        public string $type,
        public string $priority,
        public array $duplicateKeys,
        public bool $isMissingDetails,
        public bool $isRisky,
        public string $model,
        public string $promptVersion,
    ) {
    }
}
