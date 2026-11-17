<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * What the gateway gives back: text (summary, explanation) or validated data (intake suggestions), never both, with
 * what made it, so every stored and shown result can be labeled (ADR-0018, ADR-0020). A call the provider was asked
 * but that gave nothing usable comes back as rejected or failed, with no text and no data, so the caller can store
 * the outcome and keep the usage row Metering wrote in its transaction.
 */
final readonly class AiResult
{
    /**
     * @param array<string, mixed>|null $data
     * @param string|null               $failureReason null when answered; never the prompt or the answer
     */
    private function __construct(
        public AiCallStatus $status,
        public ?string $text,
        public ?array $data,
        public string $model,
        public string $provider,
        public string $promptVersion,
        public string $label,
        public ?string $failureReason,
    ) {
    }

    public static function withText(string $text, string $model, string $provider, string $promptVersion, string $label): self
    {
        return new self(AiCallStatus::Answered, $text, null, $model, $provider, $promptVersion, $label, null);
    }

    /**
     * @param array<string, mixed> $data the answer after schema validation
     */
    public static function withData(array $data, string $model, string $provider, string $promptVersion, string $label): self
    {
        return new self(AiCallStatus::Answered, null, $data, $model, $provider, $promptVersion, $label, null);
    }

    /**
     * The provider answered, but the answer failed the output check, after the one retry a data answer may get.
     *
     * @param string $reason the check that failed, as AiAnswerRejected names it
     */
    public static function rejected(string $reason, string $model, string $provider, string $promptVersion, string $label): self
    {
        return new self(AiCallStatus::Rejected, null, null, $model, $provider, $promptVersion, $label, $reason);
    }

    /**
     * The provider was asked and gave no answer: not configured, unreachable, failed, timed out, or refused it (429).
     *
     * @param string $reason as AiUnavailable or AiRateLimited names it
     */
    public static function failed(string $reason, string $model, string $provider, string $promptVersion, string $label): self
    {
        return new self(AiCallStatus::Failed, null, null, $model, $provider, $promptVersion, $label, $reason);
    }

    public function isAnswered(): bool
    {
        return AiCallStatus::Answered === $this->status;
    }
}
