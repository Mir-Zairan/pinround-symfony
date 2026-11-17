<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * Which provider and model one call goes to, and the organization's own key when it has one.
 */
final readonly class ProviderChoice
{
    /**
     * @param non-empty-string $model
     */
    private function __construct(
        public AiProviderName $provider,
        public string $model,
        #[\SensitiveParameter]
        private ?string $organizationKey,
    ) {
    }

    /**
     * Reads the provider and model from the organization's AI settings. No provider set means Hugging Face; no model set
     * means the provider's default model.
     *
     * @param string|null $organizationKey the organization's decrypted key; null means the operator's key from the environment
     *
     * @throws UnknownAiProvider when the provider is set but not one pinround knows (an empty string included), or the
     *                           model is an empty string
     */
    public static function fromSettings(?string $provider, ?string $model = null, #[\SensitiveParameter] ?string $organizationKey = null): self
    {
        $providerName = null === $provider ? AiProviderName::HuggingFace : AiProviderName::tryFrom($provider);
        if (null === $providerName) {
            throw UnknownAiProvider::provider();
        }
        if ('' === $model) {
            throw UnknownAiProvider::emptyModel();
        }

        return new self($providerName, $model ?? $providerName->defaultModel(), $organizationKey);
    }

    public function organizationKey(): ?string
    {
        return $this->organizationKey;
    }
}
