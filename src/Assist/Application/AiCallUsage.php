<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * What one AI call cost, as Metering writes it: never the prompt or the answer. Token counts are null when unknown,
 * never 0, so a sum never passes off a gap as free.
 */
final readonly class AiCallUsage
{
    private function __construct(
        public AiCallStatus $status,
        public string $provider,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $durationMs,
    ) {
    }

    public static function answered(ProviderAnswer $answer): self
    {
        return self::fromAnswer(AiCallStatus::Answered, $answer);
    }

    /**
     * The provider answered and was paid, but the answer was not used.
     */
    public static function rejected(ProviderAnswer $answer): self
    {
        return self::fromAnswer(AiCallStatus::Rejected, $answer);
    }

    /**
     * The provider was asked and gave no answer, so it reported no tokens.
     *
     * @param int $durationMs from asking the provider until the failure
     */
    public static function failed(ProviderChoice $choice, int $durationMs): self
    {
        return new self(AiCallStatus::Failed, $choice->provider->value, $choice->model, null, null, $durationMs);
    }

    private static function fromAnswer(AiCallStatus $status, ProviderAnswer $answer): self
    {
        return new self($status, $answer->provider, $answer->model, $answer->inputTokens, $answer->outputTokens, $answer->durationMs);
    }
}
